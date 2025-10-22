<?php

namespace App\Console\Commands;

use App\Models\Tenant as OldTenant;
use App\Models\MultiTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateToMultiTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:migrate-clusters';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing cluster data to the multi-tenant system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting migration of clusters to multi-tenant system');
        
        // First, check if the old cluster table exists
        if (Schema::hasTable('cluster')) {
            $oldTenants = OldTenant::all();
            
            $bar = $this->output->createProgressBar(count($oldTenants));
            $bar->start();
            
            foreach ($oldTenants as $oldTenant) {
                // Create a new tenant using the stancl/tenancy system
                $tenant = MultiTenant::firstOrCreate(
                    ['id' => $oldTenant->pkey],
                    [
                        'pkey' => $oldTenant->pkey,
                        'description' => $oldTenant->description ?? '',
                        'abstimeout' => $oldTenant->abstimeout ?? 14400,
                        'chanmax' => $oldTenant->chanmax ?? '30',
                        'masteroclo' => $oldTenant->masteroclo ?? 'AUTO',
                    ]
                );
                
                // Initialize the tenant (creates the database)
                try {
                    // Create a domain for this tenant
                    $tenant->domains()->firstOrCreate(['domain' => $oldTenant->pkey . '.localhost']);
                    
                    // Now migrate the data from the old database to the new tenant database
                    $this->migrateTenantData($oldTenant->pkey);
                    
                    $this->line("\nMigrated tenant: {$tenant->pkey}");
                } catch (\Exception $e) {
                    $this->error("\nError migrating tenant {$oldTenant->pkey}: " . $e->getMessage());
                }
                
                $bar->advance();
            }
            
            $bar->finish();
            $this->info("\nMigration completed!");
        } else {
            $this->warn("The 'cluster' table does not exist. Skipping tenant migration.");
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
            // Skip if table doesn't exist in the tenant database
            if (!Schema::connection('tenant')->hasTable($table)) {
                $this->line("  Creating table {$table} in tenant database");
                
                // Copy the schema from the central database
                $structure = DB::connection('sqlite')->getSchemaBuilder()->getTableDefinition($table);
                Schema::connection('tenant')->create($table, function ($tableObj) use ($structure) {
                    foreach ($structure as $column => $definition) {
                        // Add each column with its type
                        $tableObj->addColumn(
                            $definition['type'],
                            $column,
                            $definition
                        );
                    }
                });
            }
            
            // Get records that belong to this tenant
            $records = DB::connection('sqlite')
                ->table($table)
                ->where('cluster', $tenantId)
                ->orWhere('cluster', 'default') // Also include default cluster records if needed
                ->get();
                
            // Copy the records to the tenant database
            foreach ($records as $record) {
                $record = (array) $record;
                try {
                    DB::connection('tenant')->table($table)->insert($record);
                } catch (\Exception $e) {
                    $this->warn("  Error inserting record in {$table}: " . $e->getMessage());
                }
            }
            
            $this->line("  Migrated {$records->count()} records from table {$table}");
        }
        
        // End tenancy to clean up
        tenancy()->end();
    }
}