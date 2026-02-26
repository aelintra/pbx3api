<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpPhoneCosClosed extends Model
{
    protected $table = 'ipphonecosclosed';
    public $timestamps = false;
    protected $fillable = ['ipphone_pkey', 'cos_pkey', 'cluster'];

    public function extension()
    {
        return $this->belongsTo(IpPhone::class, 'ipphone_pkey', 'pkey');
    }
}
