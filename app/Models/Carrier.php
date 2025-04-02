<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Carrier extends Model
{
    protected $table = 'carrier';
    protected $primaryKey = 'pkey';
    public $timestamps = false;

    public function trunks()
    {
        return $this->hasMany(Trunk::class, 'carrier', 'pkey');
    }
}
