# Multi-Database Tenancy Implementation for PBX3API

This document captures the implementation process for converting the PBX3API application from a single SQLite database to a multi-database tenancy model using the tenancyforlaravel package.

## Table of Contents

1. [Understanding the Application](#understanding-the-application)
2. [Implementation Approach](#implementation-approach)
   - [Package Installation](#package-installation)
   - [Configuration Setup](#configuration-setup)
   - [Model Creation](#model-creation)
   - [Database Migrations](#database-migrations)
   - [Middleware Implementation](#middleware-implementation)
   - [Migration Scripts](#migration-scripts)
   - [Testing Tools](#testing-tools)
3. [Validation Process](#validation-process)
4. [Code Reference](#code-reference)
5. [Troubleshooting](#troubleshooting)

## Understanding the Application

The PBX3API is a Laravel-based REST API for managing an Asterisk PBX system. Key components include:

- **Framework**: Laravel (PHP framework), version 11.x
- **Authentication**: Laravel Sanctum for API authentication using tokens
- **Database**: Currently using a single SQLite database with "clusters" as tenants
- **Models**: Various models for PBX components (Extension, Trunk, Agent, Queue, IVR, etc.)
- **API Structure**: RESTful API with consistent patterns and authentication middleware
- **Multi-Tenant Support**: Currently implemented through a "cluster" field in database tables

The goal is to migrate from a single-database approach to a multi-database approach where each tenant (cluster) has its own SQLite database.

## Implementation Approach

### Package Installation

We installed the tenancyforlaravel package:

```bash
composer require stancl/tenancy
```

Then published the package configuration and migrations:

```bash
php artisan tenancy:install
```

### Configuration Setup

We updated the tenancy configuration:

```php
// config/tenancy.php
use App\Models\MultiTenant;

return [
    'tenant_model' => MultiTenant::class,
    // ...
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'sqlite'),
        'template_tenant_connection' => 'tenant_template',
        'prefix' => 'tenant_',
        'suffix' => '',
        // ...
    ],
    // ...
];
```

And database configuration:

```php
// config/database.php
'tenant_template' => [
    'driver' => 'sqlite',
    'database' => database_path('tenant_template.sqlite'),
    'prefix' => '',
    'foreign_key_constraints' => true,
],
```

### Model Creation

We created a custom tenant model that extends the package's base tenant model:

```php
<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class MultiTenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;
    
    // In our case, tenant ID will be the cluster name/pkey
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'pkey',
            'description',
            'abstimeout',
            'chanmax',
            'masteroclo',
            // Add other columns from your cluster table that you want to keep in the central tenants table
        ];
    }
    
    // Map the cluster pkey to the tenant ID
    public function getTenantKeyName(): string
    {
        return 'pkey';
    }
    
    public function getTenantKey()
    {
        return $this->pkey;
    }
}
```

### Database Migrations

We modified the tenants table migration to include our custom columns:

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->string('id')->primary();

    // Custom columns from cluster table
    $table->string('pkey')->unique();
    $table->string('description')->nullable();
    $table->integer('abstimeout')->default(14400);
    $table->string('chanmax')->default('30');
    $table->string('masteroclo')->default('AUTO');

    $table->timestamps();
    $table->json('data')->nullable();
});
```

And created a tenant migration to set up the schema in each tenant database:

```php
// database/migrations/tenant/2025_09_25_215714_create_tenant_tables.php
public function up(): void
{
    // We need to recreate all the tenant-specific tables
    // This is just an example - you'll need to add all your tables
    
    // Recreate the ipphone table for extensions
    if (!Schema::hasTable('ipphone')) {
        Schema::create('ipphone', function (Blueprint $table) {
            $table->string('pkey')->primary();
            $table->string('cluster')->default('default');
            $table->string('active')->default('YES');
            $table->integer('abstimeout')->default(14400);
            $table->string('basemacaddr')->nullable();
            $table->string('callbackto')->default('desk');
            $table->string('devicerec')->default('default');
            $table->string('protocol')->default('IPV4');
            $table->string('provisionwith')->default('IP');
            $table->string('sndcreds')->default('Always');
            $table->string('transport')->default('udp');
            $table->string('technology')->default('SIP');
            // Add other ipphone fields
        });
    }
    
    // Recreate trunks table
    if (!Schema::hasTable('trunks')) {
        Schema::create('trunks', function (Blueprint $table) {
            $table->string('pkey')->primary();
            $table->string('cluster')->default('default');
            $table->string('active')->default('YES');
            $table->string('callprogress')->default('NO');
            $table->string('closeroute')->default('Operator');
            $table->string('faxdetect')->default('NO');
            $table->string('lcl')->default('NO');
            $table->string('moh')->default('NO');
            $table->string('monitor')->default('NO');
            $table->string('openroute')->default('Operator');
            $table->string('routeable')->default('NO');
            $table->integer('routeclassopen')->default(100);
            $table->integer('routeclassclosed')->default(100);
            $table->string('swoclip')->default('YES');
            // Add other trunks fields
        });
    }
    
    // Add all other tables needed for tenant-specific data
}
```

### Middleware Implementation

We created a middleware to identify tenants from request headers or query parameters:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenantFromClusterHeader
{
    protected $resolver;
    
    public function __construct(RequestDataTenantResolver $resolver)
    {
        $this->resolver = $resolver;
    }
    
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if a cluster header is provided
        $cluster = $request->header('X-Cluster') ?? $request->query('cluster');
        
        if ($cluster) {
            // Set the tenant ID in the tenant resolver
            $this->resolver->resolve($cluster);
        }
        
        return $next($request);
    }
}
```

And updated the existing ValidateClusterAccess middleware:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Helpers\Helper;
use Stancl\Tenancy\Tenancy;

class ValidateClusterAccess
{
    protected $tenancy;
    
    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }
    
    public function handle(Request $request, Closure $next)
    {
        // Get the cluster/tenant from the request
        $cluster = $request->header('X-Cluster') ?? $request->query('cluster');
        
        if ($cluster) {
            // Get the user's token abilities from the helper
            $abilities = Helper::getTokenAbilities($request->bearerToken());
            
            // Check if the user has access to this cluster
            if (!in_array('cluster:' . $cluster, $abilities)) {
                return response()->json([
                    'error' => 'Unauthorized cluster access',
                    'message' => 'You do not have permission to access this cluster'
                ], 403);
            }
            
            // If tenant isn't already initialized, initialize it
            if (!$this->tenancy->initialized && $this->tenancy->getTenant() === null) {
                $tenant = app(\App\Models\MultiTenant::class)::where('pkey', $cluster)->first();
                
                if ($tenant) {
                    $this->tenancy->initialize($tenant);
                } else {
                    return response()->json([
                        'error' => 'Invalid cluster',
                        'message' => 'The requested cluster does not exist'
                    ], 404);
                }
            }
        }
        
        return $next($request);
    }
}
```

We registered the middleware in the Kernel.php file:

```php
protected $middlewareGroups = [
    'api' => [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        // Add the tenant identification middleware to the API group
        \App\Http\Middleware\IdentifyTenantFromClusterHeader::class,
    ],
];
```

### Migration Scripts

We created a command to migrate existing tenants:

```php
<?php

namespace App\Console\Commands;

use App\Models\Tenant as OldTenant;
use App\Models\MultiTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateToMultiTenant extends Command
{
    protected $signature = 'tenancy:migrate-clusters';
    protected $description = 'Migrate existing cluster data to the multi-tenant system';

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
    
    protected function migrateTenantData($tenantId): void
    {
        // Initialize tenancy for this tenant to set up the database connection
        $tenant = MultiTenant::find($tenantId);
        tenancy()->initialize($tenant);
        
        // Get a list of all tables that need to be migrated
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
```

Created a trait to make models tenant-aware:

```php
<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Stancl\Tenancy\Tenancy;

trait TenantAware
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootTenantAware()
    {
        // Apply tenant scoping automatically for all operations
        if (tenancy()->initialized) {
            static::addGlobalScope('tenant', function (Builder $builder) {
                $builder->where('cluster', tenant('pkey'));
            });
            
            // Set the tenant ID automatically when creating a model
            static::creating(function ($model) {
                if (!$model->isDirty('cluster') && tenancy()->initialized) {
                    $model->cluster = tenant('pkey');
                }
            });
        }
    }
}
```

### Testing Tools

We created a test script to validate our implementation:

```bash
#!/bin/bash

echo "=========================================="
echo "   Multi-Tenancy Implementation Test      "
echo "=========================================="

# 1. Validate the multi-tenancy setup
echo "\n1. Validating Multi-Tenancy Setup"
php artisan tenant:validate

# 2. Create a test tenant
echo "\n2. Creating Test Tenant"
php artisan tenant:create test-tenant --description="Test Tenant"

# 3. Validate the specific tenant
echo "\n3. Validating Test Tenant"
php artisan tenant:validate test-tenant

# 4. Test API endpoint with tenant header
echo "\n4. Testing API Endpoint with Tenant Header"
echo "This would typically be done with a tool like curl or Postman:"
echo "curl -H 'X-Cluster: test-tenant' -H 'Authorization: Bearer YOUR_TOKEN' http://your-api.com/api/tenancy-test"

echo "\nTest complete! You can now manually test the API with Postman or similar tool."
```

And we created commands to help with validation:

```php
<?php

namespace App\Console\Commands;

use App\Models\MultiTenant;
use Illuminate\Console\Command;

class CreateTestTenant extends Command
{
    protected $signature = 'tenant:create {id} {--description=Test Tenant}';
    protected $description = 'Create a test tenant for validation purposes';

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
```

```php
<?php

namespace App\Console\Commands;

use App\Models\MultiTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ValidateMultiTenancy extends Command
{
    protected $signature = 'tenant:validate {id?}';
    protected $description = 'Validate the multi-tenancy setup';

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
```

## Validation Process

To validate our multi-database tenancy implementation, follow these steps:

### 1. Run Database Migrations

First, run the migrations to set up the central database tables:

```bash
php artisan migrate
```

Create the tenant template database:

```bash
touch database/tenant_template.sqlite
```

### 2. Run the Migration Script

Use the migration script to convert the existing tenants:

```bash
./migrate_to_multitenant.sh
```

### 3. Run the Testing Script

Use the testing script to validate our implementation:

```bash
./test_multitenant.sh
```

### 4. Test API Endpoints

Test API endpoints with tenant identification:

```bash
curl -H 'X-Cluster: test-tenant' -H 'Authorization: Bearer YOUR_TOKEN' http://your-api.com/api/tenancy-test
```

### 5. Apply the TenantAware Trait

Update your models to use the TenantAware trait:

```php
use App\Traits\TenantAware;

class Extension extends Model
{
    use TenantAware;
    
    // Existing model code...
}
```

## Code Reference

### Key Files Created/Modified

1. `/app/Models/MultiTenant.php` - Custom tenant model
2. `/app/Http/Middleware/IdentifyTenantFromClusterHeader.php` - Middleware to identify tenants
3. `/app/Http/Middleware/ValidateClusterAccess.php` - Updated middleware to handle tenants
4. `/app/Traits/TenantAware.php` - Trait to make models tenant-aware
5. `/app/Console/Commands/MigrateToMultiTenant.php` - Command to migrate existing tenants
6. `/app/Console/Commands/CreateTestTenant.php` - Command to create test tenants
7. `/app/Console/Commands/ValidateMultiTenancy.php` - Command to validate setup
8. `/database/migrations/tenant/2025_09_25_215714_create_tenant_tables.php` - Tenant migrations
9. `/migrate_to_multitenant.sh` - Migration script
10. `/test_multitenant.sh` - Testing script
11. `/TESTING_MULTI_TENANCY.md` - Documentation

### Key Configuration Changes

1. `config/tenancy.php` - Main tenancy configuration
2. `config/database.php` - Database configuration for tenants
3. `app/Http/Kernel.php` - Middleware registration

## Troubleshooting

### Database Connection Issues

If you encounter database connection issues:

1. Check that the database files exist and are writable
2. Verify the database configuration in `config/database.php`
3. Ensure the tenant template database has been created

### Tenant Initialization Issues

If tenant initialization fails:

1. Check that the tenant exists in the central database
2. Verify that the tenant database has been created
3. Check for any errors in the Laravel logs

### API Access Issues

If API endpoints don't work with tenancy:

1. Confirm the `X-Cluster` header is being sent
2. Verify the tenant exists in the database
3. Check that the tenant middleware is properly registered
4. Ensure the routes have the correct middleware applied

## Domain Routing and Subdomain Storage

### Domain Storage

In the tenancyforlaravel package, domains (including subdomains) are stored in the `domains` table in your central database. The `tenancy:install` command created two migrations:
- `2019_09_15_000010_create_tenants_table.php` - For storing tenant data
- `2019_09_15_000020_create_domains_table.php` - For storing domain data

The domains table structure includes:
- A unique `domain` field that stores the actual domain/subdomain (e.g., `tenant1.localhost`)
- A `tenant_id` foreign key that links to the corresponding tenant in the tenants table

```php
Schema::create('domains', function (Blueprint $table) {
    $table->increments('id');
    $table->string('domain', 255)->unique();
    $table->string('tenant_id');

    $table->timestamps();
    $table->foreign('tenant_id')->references('id')->on('tenants')
        ->onUpdate('cascade')->onDelete('cascade');
});
```

### How Domain Routing Works

The domain routing functionality in tenancyforlaravel works as follows:

1. **Domain Registration**: When you create a tenant, you associate one or more domains with it:
   ```php
   $tenant = MultiTenant::create(['id' => 'tenant1', /* other fields */]);
   $tenant->domains()->create(['domain' => 'tenant1.localhost']);
   ```

2. **Middleware Detection**: The package provides middleware that detects the current domain and initializes the correct tenant:
   ```php
   use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
   ```

3. **Route Configuration**: In the `routes/tenant.php` file, routes are defined that will be available for each tenant domain:
   ```php
   Route::middleware([
       'api',
       InitializeTenancyByDomain::class,
       PreventAccessFromCentralDomains::class,
   ])->prefix('api')->group(function () {
       // These routes will be available at tenant1.domain.com/api/...
   });
   ```

4. **Domain Resolution**: When a request comes in, the middleware:
   - Checks the requested domain name
   - Looks up the domain in the domains table
   - Finds the associated tenant
   - Initializes the tenancy context for that tenant
   - This switches the database connection to the tenant's database

### Subdomain Configuration

To use subdomains with tenancyforlaravel:

1. **Local Development**: For local testing, you can configure your hosts file to map test domains:
   ```
   127.0.0.1 tenant1.localhost tenant2.localhost tenant3.localhost
   ```

2. **Production**: In production, you would:
   - Set up a wildcard DNS record (like `*.yourdomain.com`)
   - Configure your web server (nginx/Apache) to handle wildcard subdomains
   - Ensure your SSL certificate supports wildcard subdomains

### Domain vs Header-Based Identification

The package supports two main ways to identify tenants:

1. **Domain-based** (`InitializeTenancyByDomain`): Uses the request domain to identify the tenant
2. **Header-based** (`IdentifyTenantFromClusterHeader`): Uses request headers to identify the tenant

In our implementation, we've set up both:
- Header-based for API requests where a header like `X-Cluster` can be used
- Domain-based for potentially supporting a web interface or specialized endpoints

### Example Flow

When a request comes in to `tenant1.yourdomain.com/api/extensions`:

1. The `InitializeTenancyByDomain` middleware identifies that this request is for the tenant with domain `tenant1.yourdomain.com`
2. It looks up this domain in the `domains` table and finds the associated tenant ID
3. The middleware initializes the tenant context, which:
   - Switches the default database connection to that tenant's database (e.g., `/database/tenant_tenant1.sqlite`)
   - Sets up tenant-specific caching, filesystem paths, etc.
4. The request continues to your API controller, which now operates in the context of that tenant

This approach allows each tenant to have its own isolated database while maintaining a single codebase.

## Resuming This Conversation in a Future Chat

When you want to continue this conversation about multi-database tenancy in the PBX3API application in a future chat, here are the best approaches to quickly bring the context back:

### 1. Reference the Documentation File

The most effective way is to reference this markdown documentation file:

```
I'd like to continue our discussion about multi-database tenancy implementation in PBX3API. 
Previously, we created a comprehensive documentation in `/Users/jeffstokoe/GiT/pbx3api/multi_tenancy_implementation.md` 
that outlines all the changes and approaches we discussed.
```

### 2. Provide Key Context Files

Share the most important files we created or modified. For example:

1. The `MultiTenant.php` model
2. The middleware files
3. The migration files
4. Any specific part of the implementation you want to discuss

You can do this by:
- Opening the file in VS Code and then starting a chat with that file in context
- Using file attachments in the chat
- Pasting small snippets of the most relevant code

### 3. Summarize Previous Progress

Give a brief summary of what we've accomplished:

```
We previously implemented multi-database tenancy in PBX3API using the tenancyforlaravel package. 
We created:
- A custom MultiTenant model
- Middleware for tenant identification
- Database migrations
- Migration scripts and testing tools
- Comprehensive documentation

I'd like to continue by discussing [specific area of interest].
```

### 4. Mention Specific Questions or Next Steps

If you have specific questions or want to move forward with particular aspects:

```
In our previous conversation about implementing multi-database tenancy in PBX3API, 
we set up the basic structure. I now want to focus on:
1. Testing the implementation with actual tenant data
2. Setting up domain routing for production
3. Addressing issues with [specific component]
```

### Best Approach for Continuity

For optimal continuity, combine approaches #1 and #4:

1. Reference this documentation file
2. Specify exactly what you want to continue working on
3. Include any relevant files or code snippets that are central to your current question

## List of Modified and Created Files

### New Files Created

1. **Models**
   - `/app/Models/MultiTenant.php` - Custom tenant model that extends the base tenant model

2. **Middleware**
   - `/app/Http/Middleware/IdentifyTenantFromClusterHeader.php` - Middleware to identify tenants from request headers

3. **Console Commands**
   - `/app/Console/Commands/MigrateToMultiTenant.php` - Command to migrate existing tenants to the new system
   - `/app/Console/Commands/CreateTestTenant.php` - Command to create test tenants
   - `/app/Console/Commands/ValidateMultiTenancy.php` - Command to validate the multi-tenancy setup

4. **Controllers**
   - `/app/Http/Controllers/TenancyTestController.php` - Test controller for tenant API endpoints

5. **Traits**
   - `/app/Traits/TenantAware.php` - Trait to make models tenant-aware with automatic scoping

6. **Scripts**
   - `/migrate_to_multitenant.sh` - Script to migrate the database structure and data
   - `/test_multitenant.sh` - Script to test the multi-tenancy implementation

7. **Documentation**
   - `/multi_tenancy_implementation.md` - This comprehensive documentation file
   - `/TESTING_MULTI_TENANCY.md` - Guide for testing the implementation

8. **Database Migrations**
   - `/database/migrations/tenant/2025_09_25_215714_create_tenant_tables.php` - Tenant-specific table schemas

### Modified Files

1. **Configuration**
   - `/config/tenancy.php` - Updated to use our custom model and SQLite configuration
   - `/config/database.php` - Added tenant database connection configurations

2. **Middleware**
   - `/app/Http/Middleware/ValidateClusterAccess.php` - Updated to work with the tenancy system
   - `/app/Http/Kernel.php` - Added the tenant identification middleware

3. **Routes**
   - `/routes/tenant.php` - Updated with tenant-specific routes
   - `/routes/api.php` - Added test endpoint for tenancy validation

## Additional Considerations

### 1. Performance Monitoring and Optimization

Consider implementing performance monitoring for your multi-tenant setup:

- Add query logging in development to understand how database queries are being routed
- Set up monitoring to track tenant database growth and resource usage
- Optimize tenant migration for large datasets, potentially with chunking or queuing
- Consider implementing caching strategies specific to each tenant

### 2. Security Considerations

- Ensure proper data isolation between tenants through rigorous testing
- Implement strict validation on tenant identification to prevent tenant hopping
- Consider implementing rate limiting per tenant to prevent resource abuse
- Review access control to ensure tenants can only access their own data

### 3. Error Handling and Debugging

Add tenant-specific error handling:

```php
// In your exception handler
public function report(Throwable $e)
{
    if (tenancy()->initialized) {
        // Add tenant information to error reports
        $e->tenant = tenant('id');
    }
    
    parent::report($e);
}
```

- Implement tenant-specific logging to isolate issues
- Consider creating custom debug pages/responses that include tenant context

### 4. Backup and Restore Strategy

Develop a strategy for tenant-specific backups:

- Schedule regular backups per tenant database
- Create tools for selective tenant restoration
- Test the backup/restore process thoroughly
- Consider implementing point-in-time recovery options

### 5. Tenant Lifecycle Management

Add more commands or tools for tenant lifecycle management:

- Suspending/reactivating tenants
- Archiving inactive tenants
- Tenant data export/import utilities
- Tenant provisioning workflows

### 6. API Documentation Update

Update your API documentation to include information about:

- How to specify tenants in API requests
- Any tenant-specific rate limits or quotas
- Sample requests with tenant headers or domains
- Error responses related to tenant identification or access

### 7. Testing Expansion

Expand your testing approach to include:

- Load testing with multiple concurrent tenants
- Edge cases like tenant switching mid-request
- Testing for data isolation between tenants
- Simulating tenant database failures and recovery

## Next Steps

1. Update all models to use the TenantAware trait
2. Implement comprehensive testing for all API endpoints
3. Optimize the data migration process for production use
4. Consider implementing domain-based tenant identification for web interfaces
5. Document the API changes for client applications