<?php

namespace App\Console\Commands;

use App\Models\MultiTenant;
use Illuminate\Console\Command;

class CreateTestTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:create {id} {--description=Test Tenant}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a test tenant for validation purposes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $id = $this->argument('id');
        $description = $this->option('description');

        $this->info("Creating tenant with ID: {$id}");

        try {
            // Create the tenant
            $tenant = MultiTenant::create([
                'id' => $id,
                'pkey' => $id,
                'description' => $description,
                'abstimeout' => 14400,
                'chanmax' => '30',
                'masteroclo' => 'AUTO'
            ]);

            // Create a domain
            $domain = $tenant->domains()->create([
                'domain' => "{$id}.localhost"
            ]);

            $this->info("✓ Tenant created successfully");
            $this->info("✓ Domain {$domain->domain} created");

            // Run migrations for this tenant
            $this->info("Running migrations for tenant {$id}...");
            $tenant->run(function () {
                $this->call('migrate', [
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);
            });

            $this->info("✓ Migrations completed for tenant {$id}");
            
            // Return the tenant for any further operations
            return $tenant;

        } catch (\Exception $e) {
            $this->error("Error creating tenant: {$e->getMessage()}");
            return 1;
        }
    }
}