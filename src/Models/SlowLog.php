<?php

namespace Npabisz\LaravelMetrics\Models;

use Illuminate\Database\Eloquent\Model;

class SlowLog extends Model
{
    public $timestamps = false;

    protected $table = 'metrics_slow_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'bindings' => 'array',
        'context' => 'array',
    ];

    public const TYPE_QUERY = 'query';
    public const TYPE_REQUEST = 'request';

    public function getConnectionName()
    {
        return config('metrics.connection') ?? parent::getConnectionName();
    }
}
