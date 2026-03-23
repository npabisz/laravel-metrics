<?php

namespace Npabisz\LaravelMetrics\Collectors;

interface CollectorInterface
{
    /**
     * Return an array of metric key => value pairs.
     *
     * Keys should match column names in metrics table,
     * or be placed under the 'custom' key for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function collect(): array;
}
