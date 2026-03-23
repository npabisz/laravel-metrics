<?php

namespace Npabisz\LaravelMetrics\Models;

use Illuminate\Database\Eloquent\Model;

class MetricHourly extends Model
{
    public $timestamps = false;

    protected $table = 'metrics_hourly';

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
