<?php

namespace Npabisz\LaravelMetrics\Trace;

/**
 * What one request spends its time on, kept in memory while it runs and
 * saved with the slow log entry only if the request turns out slow.
 *
 * RequestMonitor starts it when the request reaches the middleware and ends
 * it once the response is back. Outside that window - queue jobs, console
 * commands, work after the response - every call does nothing, so a
 * long-running process collects nothing. One instance serves all requests of
 * a process, one after another: start() clears what the previous one left.
 *
 * Times are milliseconds from the start of the trace.
 */
class RequestTrace
{
    /** Queries closer together than this (ms) count as one stretch of database time. */
    protected const QUERY_MERGE_GAP_MS = 1.0;

    /** Intervals kept for working out how much of the request is accounted for. */
    protected const MAX_INTERVALS = 1000;

    /** Distinct SQL statements kept apart; the rest add up under OTHER_STATEMENTS. */
    protected const MAX_DISTINCT_STATEMENTS = 100;

    protected const OTHER_STATEMENTS = '(other statements)';

    /** A REQUEST_TIME_FLOAT further back than this is not this request's arrival. */
    protected const MAX_BEFORE_MS = 60000;

    protected const MAX_NAME_LENGTH = 100;
    protected const MAX_META_KEYS = 8;
    protected const MAX_STRING_LENGTH = 100;
    protected const MAX_MARK_LENGTH = 200;
    protected const MAX_SQL_LENGTH = 500;
    protected const MAX_TAGS = 20;

    protected bool $active = false;
    protected ?string $id = null;
    protected float $startedAt = 0.0;
    protected ?float $beforeMs = null;
    protected int $depth = 0;

    /** @var list<array{name: string, at: float, ms: float, depth: int, meta: array}> */
    protected array $spans = [];

    /** @var array<string, array{count: int, ms: float}> spans over the limit, by name */
    protected array $droppedSpans = [];

    /** @var list<array{0: float, 1: float}> start and end of every span */
    protected array $spanIntervals = [];

    /** @var list<array{at: float, msg: string}> */
    protected array $marks = [];
    protected int $droppedMarks = 0;

    /** @var array<string, scalar|null> */
    protected array $tags = [];

    protected int $queryCount = 0;
    protected float $queryMs = 0.0;

    /** @var array<string, array{count: int, ms: float, max: float}> by statement */
    protected array $statements = [];

    /** @var list<array{0: float, 1: float}> stretches of database time, in order */
    protected array $queryIntervals = [];

    /** Set once an interval list hit MAX_INTERVALS and unaccounted_ms became an estimate. */
    protected bool $approximate = false;

    public function __construct(
        protected int $maxSpans = 50,
        protected int $maxMarks = 50,
        protected int $topQueries = 5,
        protected int $maxBytes = 16384,
    ) {
    }

    /**
     * @param array{max_spans?: int, max_marks?: int, top_queries?: int, max_bytes?: int} $config
     */
    public static function fromConfig(array $config): static
    {
        return new static(
            max(0, (int) ($config['max_spans'] ?? 50)),
            max(0, (int) ($config['max_marks'] ?? 50)),
            max(0, (int) ($config['top_queries'] ?? 5)),
            max(1024, (int) ($config['max_bytes'] ?? 16384)),
        );
    }

    /**
     * Begin a trace, dropping whatever the previous one left.
     *
     * @param float|null $startedAt   microtime(true) the trace counts from, now by default
     * @param float|null $requestTime when the server received the request
     *                                (REQUEST_TIME_FLOAT), for before_ms
     */
    public function start(?float $startedAt = null, ?float $requestTime = null): void
    {
        $this->reset();

        $this->active = true;
        $this->id = bin2hex(random_bytes(8));
        $this->startedAt = $startedAt ?? microtime(true);

        if ($requestTime !== null) {
            $beforeMs = ($this->startedAt - $requestTime) * 1000;

            if ($beforeMs >= 0 && $beforeMs < static::MAX_BEFORE_MS) {
                $this->beforeMs = $beforeMs;
            }
        }
    }

    /**
     * Stop collecting and drop what was collected.
     */
    public function end(): void
    {
        $this->reset();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Identifier of the running trace, also put in the context of slow
     * queries logged during it. Null when no trace is running.
     */
    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * Run the callback and record how long it took. Spans recorded inside
     * it are one level deeper. A callback that throws is recorded with
     * 'failed' => true in its meta.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @param array<string, scalar|null> $meta
     *
     * @return T
     */
    public function measure(string $name, callable $callback, array $meta = []): mixed
    {
        if (!$this->active) {
            return $callback();
        }

        $id = $this->id;
        $depth = $this->depth++;
        $start = microtime(true);
        $failed = true;

        try {
            $result = $callback();
            $failed = false;

            return $result;
        } finally {
            // The callback may have ended this trace or started another.
            if ($this->id === $id) {
                $this->depth = $depth;
                $this->addSpan($name, $start, microtime(true), $depth, $failed ? $meta + ['failed' => true] : $meta);
            }
        }
    }

    /**
     * Record something that has just finished and took $durationMs - for
     * code that times itself already.
     *
     * @param array<string, scalar|null> $meta
     */
    public function record(string $name, float $durationMs, array $meta = []): void
    {
        if (!$this->active) {
            return;
        }

        $end = microtime(true);

        $this->addSpan($name, $end - max(0.0, $durationMs) / 1000, $end, $this->depth, $meta);
    }

    /**
     * Note what happened at this moment ("cache miss", "retrying").
     */
    public function mark(string $message): void
    {
        if (!$this->active) {
            return;
        }

        if (count($this->marks) >= $this->maxMarks) {
            $this->droppedMarks++;

            return;
        }

        $this->marks[] = [
            'at'  => $this->offset(microtime(true)),
            'msg' => $this->cut($message, static::MAX_MARK_LENGTH),
        ];
    }

    /**
     * Attach a value describing the whole request (a mode, a feature flag).
     */
    public function tag(string $key, string|int|float|bool|null $value): void
    {
        if (!$this->active) {
            return;
        }

        if (!array_key_exists($key, $this->tags) && count($this->tags) >= static::MAX_TAGS) {
            return;
        }

        $this->tags[$key] = is_string($value) ? $this->cut($value, static::MAX_STRING_LENGTH) : $value;
    }

    /**
     * Count a database query that has just finished.
     */
    public function query(string $sql, float $durationMs): void
    {
        if (!$this->active) {
            return;
        }

        $end = $this->offset(microtime(true));
        $start = max(0.0, $end - $durationMs);

        $this->queryCount++;
        $this->queryMs += $durationMs;

        $key = $this->statementKey($sql);

        if (!isset($this->statements[$key]) && count($this->statements) >= static::MAX_DISTINCT_STATEMENTS) {
            $key = static::OTHER_STATEMENTS;
        }

        $statement = $this->statements[$key] ?? ['count' => 0, 'ms' => 0.0, 'max' => 0.0];
        $this->statements[$key] = [
            'count' => $statement['count'] + 1,
            'ms'    => $statement['ms'] + $durationMs,
            'max'   => max($statement['max'], $durationMs),
        ];

        $this->addQueryInterval($start, $end);
    }

    /**
     * What the running trace collected, ready to be stored; null when no
     * trace is running.
     *
     * @param float|null $endedAt microtime(true) the trace ends at, now by default
     */
    public function snapshot(?float $endedAt = null): ?array
    {
        if (!$this->active) {
            return null;
        }

        $totalMs = max(0.0, (($endedAt ?? microtime(true)) - $this->startedAt) * 1000);

        $trace = array_filter([
            'total_ms'       => round($totalMs, 1),
            'before_ms'      => $this->beforeMs !== null ? round($this->beforeMs, 1) : null,
            'unaccounted_ms' => round(max(0.0, $totalMs - $this->coveredMs($totalMs)), 1),
            'approximate'    => $this->approximate ?: null,
            'spans'          => $this->spansForSnapshot(),
            'spans_dropped'  => $this->droppedSpansForSnapshot(),
            'db'             => $this->databaseForSnapshot(),
            'marks'          => array_map(fn (array $mark) => ['at' => round($mark['at'], 1), 'msg' => $mark['msg']], $this->marks),
            'marks_dropped'  => $this->droppedMarks ?: null,
            'tags'           => $this->tags,
        ], fn ($value) => $value !== null && $value !== []);

        return $this->fit($trace);
    }

    protected function reset(): void
    {
        $this->active = false;
        $this->id = null;
        $this->startedAt = 0.0;
        $this->beforeMs = null;
        $this->depth = 0;
        $this->spans = [];
        $this->droppedSpans = [];
        $this->spanIntervals = [];
        $this->marks = [];
        $this->droppedMarks = 0;
        $this->tags = [];
        $this->queryCount = 0;
        $this->queryMs = 0.0;
        $this->statements = [];
        $this->queryIntervals = [];
        $this->approximate = false;
    }

    protected function addSpan(string $name, float $start, float $end, int $depth, array $meta): void
    {
        $at = $this->offset($start);
        $ms = max(0.0, ($end - $start) * 1000);

        if (count($this->spanIntervals) < static::MAX_INTERVALS) {
            $this->spanIntervals[] = [max(0.0, $at), $at + $ms];
        } else {
            $this->approximate = true;
        }

        $name = $this->cut($name, static::MAX_NAME_LENGTH);

        if (count($this->spans) >= $this->maxSpans) {
            $dropped = $this->droppedSpans[$name] ?? ['count' => 0, 'ms' => 0.0];
            $this->droppedSpans[$name] = ['count' => $dropped['count'] + 1, 'ms' => $dropped['ms'] + $ms];

            return;
        }

        $this->spans[] = [
            'name'  => $name,
            'at'    => $at,
            'ms'    => $ms,
            'depth' => $depth,
            'meta'  => $this->cleanMeta($meta),
        ];
    }

    /**
     * Queries run one after another, so each new one either continues the
     * last stretch of database time or starts a new one.
     */
    protected function addQueryInterval(float $start, float $end): void
    {
        $last = array_key_last($this->queryIntervals);

        if ($last !== null && $start - $this->queryIntervals[$last][1] <= static::QUERY_MERGE_GAP_MS) {
            $this->queryIntervals[$last][1] = max($this->queryIntervals[$last][1], $end);

            return;
        }

        if ($last !== null && count($this->queryIntervals) >= static::MAX_INTERVALS) {
            // Stretch the last one instead: the gap counts as covered.
            $this->queryIntervals[$last][1] = max($this->queryIntervals[$last][1], $end);
            $this->approximate = true;

            return;
        }

        $this->queryIntervals[] = [$start, $end];
    }

    /**
     * How much of the trace spans and queries cover, overlaps counted once.
     */
    protected function coveredMs(float $totalMs): float
    {
        $intervals = array_merge($this->queryIntervals, $this->spanIntervals);

        if ($intervals === []) {
            return 0.0;
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $covered = 0.0;
        [$currentStart, $currentEnd] = $intervals[0];

        foreach ($intervals as [$start, $end]) {
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $covered += $currentEnd - $currentStart;
            [$currentStart, $currentEnd] = [$start, $end];
        }

        $covered += $currentEnd - $currentStart;

        return min($covered, $totalMs);
    }

    protected function spansForSnapshot(): array
    {
        $spans = $this->spans;

        // Inner spans finish first; list them in the order they started.
        usort($spans, fn (array $a, array $b) => [$a['at'], $a['depth']] <=> [$b['at'], $b['depth']]);

        return array_map(fn (array $span) => array_filter([
            'name'  => $span['name'],
            'at'    => round($span['at'], 1),
            'ms'    => round($span['ms'], 1),
            'depth' => $span['depth'],
            'meta'  => $span['meta'] ?: null,
        ], fn ($value) => $value !== null), $spans);
    }

    protected function droppedSpansForSnapshot(): array
    {
        return array_map(
            fn (array $dropped) => ['count' => $dropped['count'], 'ms' => round($dropped['ms'], 1)],
            $this->droppedSpans
        );
    }

    protected function databaseForSnapshot(): ?array
    {
        if ($this->queryCount === 0) {
            return null;
        }

        $statements = $this->statements;
        uasort($statements, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);

        $top = [];

        foreach (array_slice($statements, 0, $this->topQueries, true) as $sql => $statement) {
            $top[] = [
                'sql'    => (string) $sql,
                'count'  => $statement['count'],
                'ms'     => round($statement['ms'], 1),
                'max_ms' => round($statement['max'], 1),
            ];
        }

        return [
            'count'    => $this->queryCount,
            'ms'       => round($this->queryMs, 1),
            'distinct' => count($statements),
            'top'      => $top,
        ];
    }

    /**
     * Keep the stored trace within max_bytes, giving up detail step by step:
     * span meta and most marks, then most spans, then the SQL text, then
     * everything but the totals. Strings that are not valid UTF-8 (SQL with
     * binary literals, say) are repaired, so the context always encodes.
     */
    protected function fit(array $trace): array
    {
        $steps = [
            function (array $trace): array {
                $trace['spans'] = array_map(fn (array $span) => array_diff_key($span, ['meta' => true]), $trace['spans'] ?? []);
                $trace['marks'] = array_slice($trace['marks'] ?? [], 0, 10);

                return $trace;
            },
            function (array $trace): array {
                foreach (array_slice($trace['spans'] ?? [], 20) as $span) {
                    $dropped = $trace['spans_dropped'][$span['name']] ?? ['count' => 0, 'ms' => 0.0];
                    $trace['spans_dropped'][$span['name']] = ['count' => $dropped['count'] + 1, 'ms' => round($dropped['ms'] + $span['ms'], 1)];
                }

                $trace['spans'] = array_slice($trace['spans'] ?? [], 0, 20);

                return $trace;
            },
            function (array $trace): array {
                foreach ($trace['db']['top'] ?? [] as $index => $statement) {
                    $trace['db']['top'][$index]['sql'] = $this->cut($statement['sql'], 100);
                }

                return $trace;
            },
            fn (array $trace): array => array_diff_key($trace, array_flip(['spans', 'spans_dropped', 'marks', 'tags'])),
        ];

        $encoded = json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        foreach ($steps as $step) {
            if ($encoded !== false && strlen($encoded) <= $this->maxBytes) {
                break;
            }

            $trace = $step($trace);
            $trace['truncated'] = true;
            $encoded = json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }

        if ($encoded === false) {
            return ['total_ms' => $trace['total_ms'] ?? null, 'truncated' => true];
        }

        return json_decode($encoded, true);
    }

    /**
     * The statement a query ran, with variable-length lists of placeholders
     * ("in (?, ?, ?)") shortened so that they count as one statement.
     */
    protected function statementKey(string $sql): string
    {
        if (str_contains($sql, '?,')) {
            $sql = preg_replace('/\?(?:\s*,\s*\?)+/', '?, …', $sql) ?? $sql;
        }

        return $this->cut($sql, static::MAX_SQL_LENGTH);
    }

    /**
     * Meta for one span: at most MAX_META_KEYS scalar values.
     */
    protected function cleanMeta(array $meta): array
    {
        $clean = [];

        foreach (array_slice($meta, 0, static::MAX_META_KEYS, true) as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = $this->cut($value, static::MAX_STRING_LENGTH);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    protected function offset(float $microtime): float
    {
        return ($microtime - $this->startedAt) * 1000;
    }

    protected function cut(string $value, int $length): string
    {
        // strlen() first: most values are short, and it is cheaper.
        if (strlen($value) <= $length || mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . '…';
    }
}
