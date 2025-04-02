<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpPhoneCosOpen extends Model
{
    protected $table = 'IPphoneCOSopen';
    public $timestamps = false;
    protected $fillable = ['IPphone_pkey', 'COS_pkey'];

    public function extension()
    {
        return $this->belongsTo(IpPhone::class, 'IPphone_pkey', 'pkey');
    }
}
