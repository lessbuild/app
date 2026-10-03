<?php

declare(strict_types=1);

namespace App\Support\Infrastructure;

use Illuminate\Support\Number;

/** Turns a MySQL server's counters into plain tuning suggestions. */
final class DatabaseTuning
{
    /**
     * Suggest changes from the server's status and settings: buffer pool size and hit rate, temporary tables on disk,
     * connections near the limit, joins without indexes, and the slow query log being off.
     *
     * @param  array<string, int>  $status  lower-case counter and setting names
     * @param  bool|null  $slowLogEnabled
     * @return list<string>
     */
    public static function suggestions(array $status, ?bool $slowLogEnabled): array
    {
        $value = fn (string $key): int => $status[$key] ?? 0;
        $suggestions = [];
        $pool = $value('innodb_buffer_pool_size');
        $data = $value('innodb_data_bytes');
        if ($pool > 0 && $data > $pool * 1.2) {
            $suggestions[] = __('The InnoDB buffer pool (:pool) is smaller than your data (:data). Raise innodb_buffer_pool_size towards the data size, up to about 70% of the server’s memory.', ['pool' => Number::fileSize($pool), 'data' => Number::fileSize($data)]);
        }
        $requests = $value('innodb_buffer_pool_read_requests');
        if ($requests > 100000 && $value('innodb_buffer_pool_reads') / $requests > 0.01) {
            $suggestions[] = __(':percent% of reads miss the buffer pool and go to disk. A bigger innodb_buffer_pool_size would keep more in memory.', ['percent' => round($value('innodb_buffer_pool_reads') / $requests * 100, 1)]);
        }
        $temporary = $value('created_tmp_tables');
        if ($temporary > 1000 && $value('created_tmp_disk_tables') / $temporary > 0.25) {
            $suggestions[] = __(':percent% of temporary tables are written to disk. Add indexes for the GROUP BY and ORDER BY in slow queries, or raise tmp_table_size and max_heap_table_size.', ['percent' => round($value('created_tmp_disk_tables') / $temporary * 100)]);
        }
        $max = $value('max_connections');
        if ($max > 0 && $value('max_used_connections') >= $max * 0.85) {
            $suggestions[] = __('Connections peaked at :used of the :max allowed. Raise max_connections, or use fewer, longer-lived connections.', ['used' => $value('max_used_connections'), 'max' => $max]);
        }
        $hours = max(1, $value('uptime') / 3600);
        if ($value('select_full_join') / $hours > 100) {
            $suggestions[] = __('Joins without a usable index ran about :count times an hour. Add indexes on the columns your joins match on.', ['count' => number_format($value('select_full_join') / $hours)]);
        }
        if ($slowLogEnabled === false && $value('slow_queries') > 0) {
            $suggestions[] = __(':count queries were slow since the server started. Turn on the slow query log to see which.', ['count' => number_format($value('slow_queries'))]);
        }

        return $suggestions;
    }
}
