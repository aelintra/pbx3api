<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Instance `globals` table: single-row system settings for this PBX (sqlite_create_instance.sql).
 * Column names are lowercase in the API.
 *
 * Many limits and telephony options are duplicated per tenant on `cluster` (Tenant model / GET tenants).
 * Instance columns such as bindaddr, bindport, nat*, logging, and global maxin/maxout/voipmax are
 * system-wide; per-tenant abstimeout, syspass (TEXT on cluster; INTEGER here), LDAP, play_*, etc. are
 * edited on each tenant, not on this row.
 */
class Sysglobal extends Model
{
    protected $table = 'globals';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    /**
     * Mass-assignable (whitelist). All schema columns except pkey and z_*.
     */
    protected $fillable = [
        'abstimeout',
        'bindaddr',
        'bindport',
        'cosstart',
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

    /** pkey: identity. fqdninspect, fqdnprov, mycommit, userotp (deprecated), vcl: hidden from API response. staticipv4 exposed for IP Settings panel. */
    protected $hidden = [
        'pkey',
        'fqdninspect',
        'fqdnprov',
        'mycommit',
        'userotp',
        'vcl',
    ];
}
