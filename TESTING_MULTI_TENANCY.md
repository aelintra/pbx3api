# Multi-Database Tenancy Testing Guide

This guide will help you test and validate the multi-database tenancy implementation in the PBX3API application.

## Prerequisites

Before running the tests, make sure:

1. The SQLite database file is accessible and writable
2. You have run the migrations for the central database
3. You have created the tenant template database

## Testing Steps

### 1. Run the Migration Script

Run the migration script to set up the multi-tenant infrastructure:

```bash
./migrate_to_multitenant.sh
```

This script will:
- Migrate the central database tables
- Create the tenant template database
- Migrate the tenant tables to the template database
- Run the tenant data migration command to convert existing clusters

### 2. Run the Test Script

Run the test script to validate the multi-tenancy implementation:

```bash
./test_multitenant.sh
```

This script will:
- Validate the multi-tenancy setup
- Create a test tenant
- Validate the specific tenant
- Show how to test the API endpoint

### 3. Manual API Testing

Use a tool like Postman or curl to test the API endpoints:

```bash
# Example curl command
curl -H 'X-Cluster: [tenant-id]' -H 'Authorization: Bearer [your-token]' http://your-api.com/api/tenancy-test
```

### 4. Command-Line Validation

You can validate the multi-tenancy setup using the following commands:

```bash
# Validate the overall setup
php artisan tenant:validate

# Validate a specific tenant
php artisan tenant:validate [tenant-id]

# Create a test tenant
php artisan tenant:create [tenant-id] --description="Test Tenant"
```

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

## Next Steps

After successful testing, you can:

1. Update the models to use the `TenantAware` trait
2. Update controllers to properly handle tenant-specific data
3. Implement any tenant-specific business logic
4. Set up proper domain/subdomain routing for production