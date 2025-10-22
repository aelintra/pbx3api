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
