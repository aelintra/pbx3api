<?php

namespace App\Console\Commands;

use App\Models\MultiTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ValidateMultiTenancy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:validate {id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate the multi-tenancy setup';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Validating Multi-Tenancy Setup");
        $this->line("-----------------------");
        
        // 1. Check if tenancy package is properly installed
        $this->info("1. Checking Tenancy Package");
        if (class_exists('\Stancl\Tenancy\Tenancy')) {
            $this->line("✓ Tenancy package is properly installed");
        } else {
            $this->error("✗ Tenancy package is not properly installed");
            return 1;
        }
        
        // 2. Check if the central tenants table exists
        $this->info("\n2. Checking Central Database Tables");
        try {
            $tenantsExist = DB::table('tenants')->exists();
            $domainsExist = DB::table('domains')->exists();
            
            $this->line("✓ Tenants table exists");
            $this->line("✓ Domains table exists");
        } catch (\Exception $e) {
            $this->error("✗ Central database tables not found: " . $e->getMessage());
            $this->warn("Did you run the migrations? Try: php artisan migrate");
            return 1;
        }
        
        // 3. Check MultiTenant model
        $this->info("\n3. Checking MultiTenant Model");
        try {
            $tenant = new MultiTenant();
            $this->line("✓ MultiTenant model is properly defined");
        } catch (\Exception $e) {
            $this->error("✗ Error with MultiTenant model: " . $e->getMessage());
            return 1;
        }
        
        // 4. If tenant ID provided, validate that tenant
        if ($id = $this->argument('id')) {
            $this->info("\n4. Validating Specific Tenant: {$id}");
            try {
                $tenant = MultiTenant::find($id);
                
                if (!$tenant) {
                    $this->error("✗ Tenant {$id} not found");
                    return 1;
                }
                
                $this->line("✓ Tenant {$id} found in central database");
                
                // Check domain
                $domain = $tenant->domains()->first();
                if ($domain) {
                    $this->line("✓ Domain {$domain->domain} associated with tenant");
                } else {
                    $this->warn("! No domain associated with tenant");
                }
                
                // Initialize tenancy
                $this->info("Initializing tenancy for {$id}...");
                tenancy()->initialize($tenant);
                
                // Check if we can access tenant database
                try {
                    $tables = DB::connection('tenant')->select("SELECT name FROM sqlite_master WHERE type='table'");
                    $tableCount = count($tables);
                    
                    $this->line("✓ Successfully connected to tenant database");
                    $this->line("✓ Found {$tableCount} tables in tenant database");
                    
                    // List all tables
                    $this->line("\nTables in tenant database:");
                    foreach ($tables as $table) {
                        $this->line("  - {$table->name}");
                    }
                    
                } catch (\Exception $e) {
                    $this->error("✗ Error connecting to tenant database: " . $e->getMessage());
                    return 1;
                }
                
                // End tenancy
                tenancy()->end();
                
            } catch (\Exception $e) {
                $this->error("✗ Error validating tenant: " . $e->getMessage());
                return 1;
            }
        } else {
            $this->info("\n4. Checking All Tenants");
            $tenants = MultiTenant::all();
            $count = $tenants->count();
            
            if ($count > 0) {
                $this->line("✓ Found {$count} tenants in the central database");
                
                $table = [];
                foreach ($tenants as $tenant) {
                    $domains = $tenant->domains->pluck('domain')->join(', ');
                    $table[] = [$tenant->id, $tenant->pkey, $tenant->description, $domains];
                }
                
                $this->table(['ID', 'PKEY', 'Description', 'Domains'], $table);
            } else {
                $this->warn("! No tenants found in the database");
                $this->line("Create a test tenant with: php artisan tenant:create test");
            }
        }
        
        $this->info("\nValidation Complete!");
    }
}