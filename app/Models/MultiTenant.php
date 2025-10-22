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