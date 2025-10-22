#!/bin/bash

echo "Starting migration to multi-tenant system"

# Step 1: Migrate central database tables
echo "1. Migrating central database tables..."
php artisan migrate

# Step 2: Create tenant template database
echo "2. Creating tenant template database..."
touch database/tenant_template.sqlite
chmod 666 database/tenant_template.sqlite

# Step 3: Migrate tenant tables to the template database
echo "3. Migrating tenant tables to the template database..."
php artisan migrate --database=tenant_template --path=database/migrations/tenant

# Step 4: Run the tenant data migration command
echo "4. Running tenant data migration command..."
php artisan tenancy:migrate-clusters

echo "Migration completed! Check the output for any errors."
