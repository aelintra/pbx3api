<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpPhoneFkey extends Model
{
    protected $table = 'IPphone_Fkey';
    public $timestamps = false;
    protected $fillable = ['pkey', 'type', 'value'];

    public function extension()
    {
        return $this->belongsTo(IpPhone::class, 'pkey', 'pkey');
    }
}
