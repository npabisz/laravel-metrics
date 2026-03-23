<?php

namespace Npabisz\LaravelMetrics\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    public $timestamps = false;

    protected $table = 'metrics';

    protected $guarded = ['id'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'custom' => 'array',
    ];

    public function getConnectionName()
    {
        return config('metrics.connection') ?? parent::getConnectionName();
    }
}
