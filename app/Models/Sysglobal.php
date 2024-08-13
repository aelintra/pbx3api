<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sysglobal extends Model
{
    //
    protected $table = 'globals';
    protected $primaryKey = 'pkey';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [

    ];

    // none user updateable columns
    protected $guarded = [
    		'pkey',
    		'ASTDLIM',
    		'ATTEMPTRESTART',
    		'BINDADDR',
    		'CDR',
    		'CFEXTERN',
    		'DIGITS',
    		'EDOMAIN',
    		'EXTBLKLST',
    		'EXTLEN',
    		'EXTLIM',
    		'FAX',
    		'FAXDETECT',
    		'FOPPASS',
    		'FQDNDROPBUFF',
    		"FQDNHTTP",
    		"FQDNTRUST",
    		'G729',
    		'HAAUTOFAILBACK',
    		'HACLUSTERIP',
    		'HAENCRYPT',
    		'HAMODE',
    		'HAPRINODE',
    		'HESECNODE',
    		'HASYNCH',
    		'HAUSECLUSTER',
    		'LANGUAGE',
    		'LKEY',
    		'LOCALAREA',
    		'LOCALDLEN',
    		'LOGOPTS',
    		'MEETMEDIAL',
    		'MISDNRUN',
    		'MYCOMMIT',
    		'NUMGROUPS',
    		'ONBOARDMENU',
    		'OPRT',
    		'PKTINSPECT',
    		'PCICARDS',
    		'PROXY',
    		'PROXYIGNORE',
    		'RECRSYNCPARMS',
    		'RESTART',
    		'RHINOSPF',
    		'RUNFOP',
    		'SIPMULTICAST',
    		'SMSALERT',
    		'SMSC',
    		'SNO',
    		'TFTP',
    		'UNDO',
    		'UNDONUM',
    		'UNDOONOFF',
    		'USBRECDISK',
    		'VCL',
    		'VCLFULL',
    		'VLIBS',
    		'XMPP',
    		'XMPPSERV',
    		'z_created',
    		'z_updated',
    ];

    // hidden columns (mostly no longer used), some system protected
    protected $hidden = [
    		'pkey',
    		'ASTDLIM',
    		'ATTEMPTRESTART',
    		'BINDADDR',
    		'CDR',
    		'CFEXTRN',
    		'DIGITS',
    		'EDOMAIN',
    		'EXTBLKLST',
    		'EXTLEN',
    		'EXTLIM',
    		'FAX',
    		'FAXDETECT',
    		'FOPPASS',
    		'FQDNDROPBUFF',
    		'FQDNHTTP',
    		'FQDNTRUST',
    		'G729',
    		'HAAUTOFAILBACK',
    		'HACLUSTERIP',
    		'HAENCRYPT',
    		'HAMODE',
    		'HAPRINODE',
    		'HESECNODE',
    		'HASYNCH',
    		'HAUSECLUSTER',
    		'LANGUAGE',
    		'LKEY',
    		'LOCALAREA',
    		'LOCALDLEN',
    		'LOGOPTS',
    		'MEETMEDIAL',
    		'MISDNRUN',
    		'MYCOMMIT',
    		'NUMGROUPS',
    		'ONBOARDMENU',
    		'OPRT',
    		'PKTINSPECT',
    		'PCICARDS',
    		'PROXY',
    		'PROXYIGNORE',
    		'RECRSYNCPARMS',
    		'RESTART',
    		'RHINOSPF',
    		'RUNFOP',
    		'SIPMULTICAST',
    		'SMSALERT',
    		'SMSC',
    		'SNO',
    		'TFTP',
    		'UNDO',
    		'UNDONUM',
    		'UNDOONOFF',
    		'USBRECDISK',
//    		'VCL',
    		'VCLFULL',
    		'VLIBS',
    		'XMPP',
    		'XMPPSERV'
    ];

}
