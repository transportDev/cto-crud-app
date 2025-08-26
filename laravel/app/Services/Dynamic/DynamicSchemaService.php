<?php

namespace App\Services\Dynamic;

use App\Models\CtoTableMeta;
use App\Services\TableBuilderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DynamicSchemaService
 *
 * Centralizes safe, cached access to schema metadata and constraints.
 * - Whitelists tables using TableBuilderService::listUserTables()
 * - Caches column metadata, foreign keys, primary key, indexes
 * - Provides helpers for PK/auto-increment detection, soft delete detection
 *
 * Extension points:
 * - Override cache TTL via config('cto.schema_cache_ttl', 300)
 * - Swap whitelist source if needed by replacing TableBuilderService binding
 */
class DynamicSchemaService
{
    protected TableBuilderService $tableBuilder;

    public function __construct(TableBuilderService $tableBuilder)
    {
        $this->tableBuilder = $tableBuilder;
    }

    public function cacheTtl(): int
    {
        return (int) config('cto.schema_cache_ttl', 300);
    }

    /**
     * Return a whitelist of safe, user-manageable tables.
     */
    public function whitelist(): array
    {
        return Cache::remember('cto:tables:whitelist', $this->cacheTtl(), function () {
            return $this->tableBuilder->listUserTables();
        });
    }

    /** Ensure the table is whitelisted; returns sanitized name or null. */
    public function sanitizeTable(?string $table): ?string
    {
        if (!$table) return null;
        $table = Str::of($table)->lower()->snake()->toString();
        return in_array($table, $this->whitelist(), true) ? $table : null;
    }

    /** Columns metadata as [name => [type, nullable, length, default, options]]. */
    public function columns(string $table): array
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return [];

        $key = 'cto:schema:cols:' . DB::getDatabaseName() . ':' . $table;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table) {
            $meta = [];
            foreach (Schema::getColumns($table) as $column) {
                $name = $column['name'];
                $meta[$name] = [
                    'type' => $column['type'],
                    'nullable' => (bool)($column['nullable'] ?? false),
                    'length' => $column['size'] ?? null,
                    'default' => $column['default'] ?? null,
                    'options' => [],
                ];
            }
            return $meta;
        });
    }

    /**
     * Foreign key map: [fk_col => ['referenced_table' => ..., 'referenced_column' => ...]]
     */
    public function foreignKeys(string $table): array
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return [];

        $key = 'cto:schema:fks:' . DB::getDatabaseName() . ':' . $table;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table) {
            $map = [];
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $db = DB::getDatabaseName();
                $rows = DB::select(
                    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
                     FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                     WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
                    [$db, $table]
                );
                foreach ($rows as $r) {
                    $map[$r->COLUMN_NAME] = [
                        'referenced_table' => $this->sanitizeTable($r->REFERENCED_TABLE_NAME),
                        'referenced_column' => $r->REFERENCED_COLUMN_NAME,
                    ];
                }
            }
            return $map;
        });
    }

    /** Primary key column name from metadata; safe fallback to heuristic. */
    public function primaryKey(string $table): string
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return 'id';

        $key = 'cto:schema:pk:' . DB::getDatabaseName() . ':' . $table;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table) {
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $db = DB::getDatabaseName();
                $row = DB::selectOne(
                    "SELECT k.COLUMN_NAME
                     FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS t
                     JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                       ON k.CONSTRAINT_NAME = t.CONSTRAINT_NAME
                      AND k.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA
                      AND k.TABLE_NAME = t.TABLE_NAME
                     WHERE t.CONSTRAINT_TYPE = 'PRIMARY KEY'
                       AND t.TABLE_SCHEMA = ? AND t.TABLE_NAME = ?
                     LIMIT 1",
                    [$db, $table]
                );
                if ($row && isset($row->COLUMN_NAME)) {
                    return (string)$row->COLUMN_NAME;
                }
            }

            // Heuristics
            if (Schema::hasColumn($table, 'id')) {
                return 'id';
            }
            $guess = Str::of($table)->singular()->snake()->append('_id')->toString();
            if (Schema::hasColumn($table, $guess)) {
                return $guess;
            }
            $list = Schema::getColumnListing($table);
            return $list[0] ?? 'id';
        });
    }

    public function isPrimaryAutoIncrement(string $table): bool
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return false;
        $pk = $this->primaryKey($table);
        $key = 'cto:schema:pk:auto_inc:' . DB::getDatabaseName() . ':' . $table . ':' . $pk;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table, $pk) {
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $db = DB::getDatabaseName();
                $row = DB::selectOne(
                    'SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$db, $table, $pk]
                );
                if ($row && isset($row->EXTRA) && stripos((string)$row->EXTRA, 'auto_increment') !== false) {
                    return true;
                }
            }
            $columns = $this->columns($table);
            $type = $columns[$pk]['type'] ?? null;
            return $pk === 'id' && in_array($type, ['integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true);
        });
    }

    public function hasDeletedAt(string $table): bool
    {
        $table = $this->sanitizeTable($table);
        return $table ? Schema::hasColumn($table, 'deleted_at') : false;
    }

    /**
     * Guess label column for FK dropdowns (cached).
     */
    public function guessLabelColumn(string $table): string
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return 'id';
        $key = 'cto:schema:label_col:' . DB::getDatabaseName() . ':' . $table;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table) {
            // 0) Admin/user override from metadata
            try {
                $meta = CtoTableMeta::query()->where('table_name', $table)->first();
                if ($meta && $meta->label_column && Schema::hasColumn($table, $meta->label_column)) {
                    return $meta->label_column;
                }
            } catch (\Throwable $e) {
                // Safe no-op if table doesn't exist yet or on early bootstrap
            }
            // 1) Prefer display_template columns if defined, fall back to label_column
            try {
                $meta2 = CtoTableMeta::query()->where('table_name', $table)->first();
                if ($meta2 && is_array($meta2->display_template) && !empty($meta2->display_template['columns'])) {
                    foreach ((array)$meta2->display_template['columns'] as $c) {
                        if ($c && Schema::hasColumn($table, $c)) return (string)$c;
                    }
                }
                if ($meta2 && $meta2->label_column && Schema::hasColumn($table, $meta2->label_column)) {
                    return $meta2->label_column;
                }
            } catch (\Throwable $e) {
                // ignore
            }
            // 2) Prefer common human-readable columns
            $preferred = [
                'name',
                'title',
                'label',
                'email',
                'code',
                'description',
                'region_name',
                'regional_name',
                'site_name',
                'vendor_name',
                'mitra_name',
                'transport_type',
                'type_alpro',
            ];
            foreach ($preferred as $col) {
                if (Schema::hasColumn($table, $col)) return $col;
            }

            // 3) Otherwise, pick the first text-like column
            $colsMeta = $this->columns($table);
            foreach ($colsMeta as $name => $meta) {
                $type = strtolower((string)($meta['type'] ?? ''));
                if (in_array($type, ['string', 'varchar', 'char', 'text', 'mediumtext', 'longtext'], true)) {
                    return $name;
                }
            }

            // 4) Fallback to primary key if nothing else fits, but only 'id' if it exists
            $pk = $this->primaryKey($table);
            if ($pk && Schema::hasColumn($table, $pk)) return $pk;
            return Schema::hasColumn($table, 'id') ? 'id' : (Schema::getColumnListing($table)[0] ?? 'id');
        });
    }

    /**
     * Prefer an indexed text-like column for search to improve performance.
     */
    public function bestSearchColumn(string $table): string
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return 'id';
        // Admin/user override from metadata (prefer indexed if possible)
        try {
            $meta = CtoTableMeta::query()->where('table_name', $table)->first();
            if ($meta && $meta->search_column && Schema::hasColumn($table, $meta->search_column)) {
                return $meta->search_column;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $label = $this->guessLabelColumn($table);
        if (Schema::hasColumn($table, $label) && $this->isIndexed($table, $label)) return $label;
        // Try common indexed fallbacks
        foreach (['name', 'title', 'label', 'email', 'region_name', 'site_name', 'code'] as $c) {
            if (Schema::hasColumn($table, $c) && $this->isIndexed($table, $c)) return $c;
        }
        // As a safety, ensure the returned column exists
        if (Schema::hasColumn($table, $label)) return $label;
        $pk = $this->primaryKey($table);
        return Schema::hasColumn($table, $pk) ? $pk : (Schema::getColumnListing($table)[0] ?? 'id');
    }

    /**
     * Render a label from a display template using the provided row array.
     * Unknown placeholders are removed. Nulls become empty strings.
     */
    public function renderTemplateLabel(?array $template, array $row): ?string
    {
        if (!$template || empty($template['template'])) return null;
        $s = (string) $template['template'];
        foreach ($row as $k => $v) {
            $s = str_replace('{{' . $k . '}}', (string)($v ?? ''), $s);
            $s = str_replace('{{ ' . $k . ' }}', (string)($v ?? ''), $s);
        }
        $s = preg_replace('/{{[^}]+}}/', '', $s);
        return trim((string)$s);
    }

    /** Quick index existence check (MySQL/MariaDB only), cached. */
    public function isIndexed(string $table, string $column): bool
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return false;
        $key = 'cto:schema:indexed:' . DB::getDatabaseName() . ':' . $table . ':' . $column;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table, $column) {
            $driver = DB::connection()->getDriverName();
            if (!in_array($driver, ['mysql', 'mariadb'], true)) return false;
            $db = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$db, $table, $column]
            );
            return (bool)$row;
        });
    }

    /** Invalidate cached schema metadata for a table. */
    public function invalidateTableCache(string $tableName): void
    {
        $table = $this->sanitizeTable($tableName);
        if (!$table) return;
        $db = DB::getDatabaseName();
        Cache::forget('cto:schema:cols:' . $db . ':' . $table);
        Cache::forget('cto:schema:fks:' . $db . ':' . $table);
        Cache::forget('cto:schema:pk:' . $db . ':' . $table);
        Cache::forget('cto:schema:label_col:' . $db . ':' . $table);
        // Best-effort: also drop current pk auto_inc flag if resolvable
        try {
            $pk = $this->primaryKey($table);
            Cache::forget('cto:schema:pk:auto_inc:' . $db . ':' . $table . ':' . $pk);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Detect and store primary_key_column and label_column for a table into cto_table_meta.
     * Idempotent: upserts the row and preserves explicit user overrides.
     */
    public function populateMetaForTable(string $tableName): void
    {
        $table = $this->sanitizeTable($tableName);
        if (!$table || !Schema::hasTable($table)) return;

        // Detect primary key via existing helper (cached and information_schema-backed)
        $pk = $this->primaryKey($table);

        // Choose a default label via the same heuristics used at runtime
        $label = $this->guessLabelColumn($table);

        // Upsert into meta, but do not overwrite user-set values if already present
        try {
            $existing = CtoTableMeta::query()->where('table_name', $table)->first();
            if ($existing) {
                $updates = [];
                if (!$existing->primary_key_column && $pk && Schema::hasColumn($table, $pk)) {
                    $updates['primary_key_column'] = $pk;
                }
                if (!$existing->label_column && $label && Schema::hasColumn($table, $label)) {
                    $updates['label_column'] = $label;
                }
                if (!empty($updates)) {
                    $existing->fill($updates)->save();
                }
            } else {
                CtoTableMeta::query()->updateOrCreate(
                    ['table_name' => $table],
                    [
                        'primary_key_column' => Schema::hasColumn($table, $pk) ? $pk : null,
                        'label_column' => Schema::hasColumn($table, $label) ? $label : null,
                    ]
                );
            }

            // Invalidate caches relevant to this table so new meta is picked up quickly
            Cache::forget('cto:schema:label_col:' . DB::getDatabaseName() . ':' . $table);
        } catch (\Throwable $e) {
            // Swallow errors to avoid breaking table creation flows; logs can be added if needed
        }
    }
}
