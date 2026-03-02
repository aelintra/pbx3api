<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'cluster';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'abstimeout' => 14400,
        'chanmax'    => 30,
        'masteroclo' => 'AUTO',
    ];

    /**
     * Mass-assignable (whitelist). Schema: cluster.
     * Excludes id, shortuid, pkey, z_* and system-only/deprecated fields:
     *  - acl, extblklist, include, leasedhdtime (future), monitor_stage, name, number_range_regex,
     *    oclo, padminpass, puserpass, pickupgroup, recused, routeoverride, vxt.
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
        'masteroclo',
        'maxin',
        'maxout',
        'mixmonitor',
        'monitor_out',
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
