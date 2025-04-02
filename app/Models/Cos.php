<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cos extends Model
{
    protected $table = 'cos';
    protected $primaryKey = 'pkey';
    public $timestamps = false;

    public function openExtensions()
    {
        return $this->hasMany(IpPhoneCosOpen::class, 'cos', 'pkey');
    }

    public function closedExtensions()
    {
        return $this->hasMany(IpPhoneCosClosed::class, 'cos', 'pkey');
    }
}
