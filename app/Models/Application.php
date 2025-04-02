<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $table = 'appl';
    protected $primaryKey = 'pkey';
    public $timestamps = false;
}
