<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteProfileLine extends Model
{
    protected $table = 'route_profile_line';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'profile',
        'cluster',
        'mode',
        'destination',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RouteProfile::class, 'profile', 'shortuid');
    }
}
