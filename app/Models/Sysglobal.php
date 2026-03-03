<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * globals table: single-row system settings (instance-scoped).
 * Schema: pbx3/db/db_sql/sqlite_create_instance.sql (lowercase column names).
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

    /** pkey: identity. fqdninspect, fqdnprov, mycommit, staticipv4, userotp (deprecated), vcl: hidden from API response. */
    protected $hidden = [
        'pkey',
        'fqdninspect',
        'fqdnprov',
        'mycommit',
        'staticipv4',
        'userotp',
        'vcl',
    ];
}
