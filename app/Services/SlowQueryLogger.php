<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

class SlowQueryLogger
{
    public function context(QueryExecuted $query): array
    {
        // SQL can also contain literals from raw queries. Remove these before logging.
        $sql = preg_replace("/'(?:''|[^'])*'/s", '?', $query->sql);
        $sql = preg_replace('/"(?:""|[^"])*"/s', '?', $sql);
        $sql = preg_replace('/--[^\r\n]*|\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
        return [
            'query_hash' => hash('sha256', $sql),
            'duration_ms' => $query->time,
            'binding_count' => count($query->bindings),
            'binding_types' => array_map(fn ($value) => get_debug_type($value), $query->bindings),
            'sql' => mb_substr($sql, 0, 2000),
        ];
    }

    public function __invoke(QueryExecuted $query): void
    {
        if ($query->time > 500) {
            Log::channel('slow')->warning('Slow query', $this->context($query));
        }
    }
}
