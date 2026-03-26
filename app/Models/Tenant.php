<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant row: `cluster` table (sqlite_create_tenant.sql). Per-tenant telephony, LDAP, recording, and limits.
 * Instance-wide defaults live in `globals` (Sysglobal). CAGI reads this table for dialplan behaviour.
 */
class Tenant extends Model
{
    protected $table = 'cluster';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'abstimeout' => 14400,
        'chanmax'    => 3,
        'masteroclo' => 'AUTO',
    ];

    /**
     * JSON/API types align with sqlite_create_tenant.sql (INTEGER and BOOLEAN columns).
     * TEXT tokens (e.g. syspass, spy_pass, usemohcustom, allow_hash_xfer) are uncast strings.
     */
    protected $casts = [
        'abstimeout' => 'integer',
        'chanmax' => 'integer',
        'countrycode' => 'integer',
        'ext_lim' => 'integer',
        'ext_len' => 'integer',
        'fqdninspect' => 'boolean',
        'int_ring_delay' => 'integer',
        'ivr_key_wait' => 'integer',
        'ivr_digit_wait' => 'integer',
        'leasedhdtime' => 'integer',
        'lterm' => 'integer',
        'maxin' => 'integer',
        'maxout' => 'integer',
        'operator' => 'integer',
        'play_beep' => 'integer',
        'play_busy' => 'integer',
        'play_congested' => 'integer',
        'play_transfer' => 'integer',
        'rec_age' => 'integer',
        'rec_grace' => 'integer',
        'rec_limit' => 'integer',
        'ringdelay' => 'integer',
        'VDELAY' => 'integer',
        'vmail_age' => 'integer',
        'voice_instr' => 'integer',
        'voip_max' => 'integer',
        'sysop' => 'integer',
    ];

    /**
     * Mass-assignable (whitelist). Schema: cluster.
     * Excludes id, shortuid, pkey, z_* and system-only/deprecated fields:
     *  acl, extblklist, include, name, number_range_regex, oclo, padminpass, puserpass,
     *  pickupgroup, recused, routeoverride, vxt.
     */
    protected $fillable = [
        'pkey',
        'abstimeout',
        'active',
        'allow_hash_xfer',
        'blind_busy',
        'bounce_alert',
        'callrecord_1',
        'camp_on_q_onoff',
        'camp_on_q_opt',
        'cfwdextern_rule',
        'cfwd_progress',
        'cfwd_answer',
        'clusterclid',
        'chanmax',
        'cname',
        'countrycode',
        'dynamicfeatures',
        'description',
        'devicerec',
        'emailalert',
        'emergency',
        'ext_lim',
        'ext_len',
        'fqdn',
        'fqdninspect',
        'int_ring_delay',
        'ivr_key_wait',
        'ivr_digit_wait',
        'language',
        'ldapanonbind',
        'ldapbase',
        'ldaphost',
        'ldapou',
        'ldapuser',
        'ldappass',
        'ldaptls',
        'localarea',
        'localdplan',
        'lterm',
        'leasedhdtime',
        'masteroclo',
        'maxin',
        'maxout',
        'mixmonitor',
        'monitor_out',
        'monitor_stage',
        'operator',
        'play_beep',
        'play_busy',
        'play_congested',
        'play_transfer',
        'rec_age',
        'rec_final_dest',
        'rec_file_dlim',
        'rec_grace',
        'rec_limit',
        'rec_mount',
        'recmaxage',
        'recmaxsize',
        'ringdelay',
        'spy_pass',
        'sysop',
        'syspass',
        'usemohcustom',
        'VDELAY',
        'vmail_age',
        'voice_instr',
        'voip_max',
    ];

    /**
     * Hidden / deprecated fields.
     * - name, oclo: legacy, not for API/SPA.
     * - acl: deprecated; no longer used.
     * - vxt: reserved for future use; not exposed via API/SPA.
     */
    protected $hidden = [
        'name',
        'oclo',
        'acl',
        'vxt',
    ];

    /**
     * Resolve route model binding by shortuid (globally unique) then pkey (human key).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $model = static::where('shortuid', $value)->first();
        if ($model) {
            return $model;
        }

        return static::where('pkey', $value)->first();
    }
}
