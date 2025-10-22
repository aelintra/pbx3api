<?php

namespace Database\Seeders;

use App\Models\Tenant as OldTenant;
use App\Models\MultiTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantMigrationSeeder extends Seeder
{
    /**
     * Run the database seeds to migrate existing tenants.
     */
    public function run(): void
    {
        // First, check if the old cluster table exists
        if (Schema::hasTable('cluster')) {
            $oldTenants = OldTenant::all();
            
            foreach ($oldTenants as $oldTenant) {
                // Create a new tenant using the stancl/tenancy system
                $tenant = MultiTenant::create([
                    'id' => $oldTenant->pkey, // Use the cluster pkey as the tenant ID
                    'pkey' => $oldTenant->pkey,
                    'description' => $oldTenant->description ?? '',
                    'abstimeout' => $oldTenant->abstimeout ?? 14400,
                    'chanmax' => $oldTenant->chanmax ?? '30',
                    'masteroclo' => $oldTenant->masteroclo ?? 'AUTO',
                ]);
                
                // Initialize the tenant (creates the database)
                $tenant->domains()->create(['domain' => $oldTenant->pkey . '.localhost']);
                
                $this->command->info("Created tenant: {$tenant->pkey}");
                
                // Now migrate the data from the old database to the new tenant database
                $this->migrateTenantData($oldTenant->pkey);
            }
        } else {
            $this->command->warn("The 'cluster' table does not exist. Skipping tenant migration.");
        }
    }
    
    /**
     * Migrate data for a specific tenant from the central database to its own database
     */
    protected function migrateTenantData($tenantId): void
    {
        // Initialize tenancy for this tenant to set up the database connection
        $tenant = MultiTenant::find($tenantId);
        tenancy()->initialize($tenant);
        
        // Get a list of all tables that need to be migrated
        // Exclude tables that shouldn't be migrated (like central tables, migrations, etc.)
        $excludedTables = ['migrations', 'tenants', 'domains', 'users', 'password_reset_tokens', 
                           'cache', 'jobs', 'failed_jobs', 'personal_access_tokens'];
        
        $tables = collect(DB::connection('sqlite')->select('SELECT name FROM sqlite_master WHERE type = "table"'))
            ->pluck('name')
            ->filter(function($table) use ($excludedTables) {
                return !in_array($table, $excludedTables);
            });
            
        foreach ($tables as $table) {
            // Get records that belong to this tenant
            $records = DB::connection('sqlite')
                ->table($table)
                ->where('cluster', $tenantId)
                ->orWhere('cluster', 'default') // Also include default cluster records if needed
                ->get();
                
            // Copy the records to the tenant database
            foreach ($records as $record) {
                $record = (array) $record;
                DB::connection('tenant')->table($table)->insert($record);
            }
            
            $this->command->info("Migrated {$records->count()} records from table {$table} for tenant {$tenantId}");
        }
        
        // End tenancy to clean up
        tenancy()->end();
    }
}