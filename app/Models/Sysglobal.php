<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Instance `globals` table: single-row system settings for this PBX (sqlite_create_instance.sql).
 * Column names are lowercase in the API. Primary key is `id` (KSUID); `pkey` is a fixed row marker (`global`).
 *
 * Many limits and telephony options are duplicated per tenant on `cluster` (Tenant model / GET tenants).
 * Instance columns such as bindaddr, bindport, nat*, logging, and global maxin/maxout/voipmax are
 * system-wide; per-tenant abstimeout, syspass (TEXT on cluster; INTEGER here), LDAP, play_*, etc. are
 * edited on each tenant, not on this row.
 *
 * `fqdninspect` is included in JSON (not hidden) for Option A / firewall panels; `domain` and `fqdn`
 * remain visible and are not mass-assigned from API updates (installer sets them).
 */
class Sysglobal extends Model
{
    protected $table = 'globals';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    /**
     * Mass-assignable (whitelist). All schema columns except pkey and z_*.
     */
    protected $fillable = [
        'id',
        'shortuid',
        'pkey',
        'abstimeout',
        'bindaddr',
        'bindport',
        'cosstart',
        'domain',
        'edomain',
        'emergency',
        'fqdn',
        'fqdninspect',
        'fqdnprov',
        'language',
        'localip',
        'loglevel',
        'logopts',
        'logsipdispsize',
        'logsipnumfiles',
        'logsipfilesize',
        'maxin',
        'maxout',
        'mycommit',
        'natdefault',
        'natparams',
        'operator',
        'pwdlen',
        'recfiledlim',
        'reclimit',
        'recmount',
        'recqdither',
        'recqsearchlim',
        'sessiontimout',
        'sendedomain',
        'sipflood',
        'sipdriver',
        'sitename',
        'staticipv4',
        'sysop',
        'syspass',
        'tlsport',
        'userotp',
        'vcl',
        'voipmax',
    ];

    /** pkey: identity. fqdnprov, mycommit, userotp (deprecated), vcl: hidden from API response. staticipv4 exposed for IP Settings panel. */
    protected $hidden = [
        'pkey',
        'fqdnprov',
        'mycommit',
        'userotp',
        'vcl',
    ];
}
