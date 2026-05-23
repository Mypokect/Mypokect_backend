<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Schema introspection with a 1-hour in-process cache.
 *
 * Schema::hasColumn / Schema::hasTable hit the database every call.
 * In a typical request, TaxController alone calls hasColumn 13 times.
 * This helper caches the result per column/table name so the first call pays
 * the DB round-trip and every subsequent call in the same request (and across
 * requests for the next hour) is free.
 *
 * Cache is automatically busted on deployments that run migrations, because
 * `php artisan migrate` should be followed by `php artisan cache:clear`.
 */
final class SchemaCache
{
    /** @var array<string, bool> In-memory map for the current request (zero extra round-trips). */
    private static array $memory = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $key = "schema_col:{$table}.{$column}";

        if (isset(self::$memory[$key])) {
            return self::$memory[$key];
        }

        $result = Cache::remember($key, 3600, fn () => Schema::hasColumn($table, $column));

        return self::$memory[$key] = $result;
    }

    public static function hasTable(string $table): bool
    {
        $key = "schema_tbl:{$table}";

        if (isset(self::$memory[$key])) {
            return self::$memory[$key];
        }

        $result = Cache::remember($key, 3600, fn () => Schema::hasTable($table));

        return self::$memory[$key] = $result;
    }
}
