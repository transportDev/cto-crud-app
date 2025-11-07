<?php

namespace App\Services\Dynamic;

use App\Models\CtoTableMeta;
use App\Services\TableBuilderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Service untuk mengelola akses metadata skema database secara aman dan ter-cache.
 *
 * Service ini menyediakan fungsionalitas untuk:
 * - Whitelisting tabel menggunakan TableBuilderService::listUserTables()
 * - Caching metadata kolom, foreign key, primary key, dan index
 * - Helper untuk deteksi PK/auto-increment dan soft delete
 * - Guess label column untuk dropdown FK dengan prioritas yang jelas
 * - Compose human-readable label dari row data
 * - Validasi keberadaan index pada kolom
 * - Invalidasi cache metadata tabel
 * - Populasi metadata tabel secara otomatis
 *
 * Extension points:
 * - Override cache TTL melalui config('cto.schema_cache_ttl', 300)
 * - Ganti sumber whitelist dengan mengganti binding TableBuilderService
 *
 * @package App\Services\Dynamic
 */
class DynamicSchemaService
{
    protected TableBuilderService $tableBuilder;

    /**
     * Membuat instance service dengan dependency injection.
     *
     * @param TableBuilderService $tableBuilder Service untuk menangani operasi table builder
     */
    public function __construct(TableBuilderService $tableBuilder)
    {
        $this->tableBuilder = $tableBuilder;
    }

    /**
     * Mendapatkan durasi cache TTL dari konfigurasi.
     *
     * @return int Durasi cache dalam detik (default: 300)
     */
    public function cacheTtl(): int
    {
        return (int) config('cto.schema_cache_ttl', 300);
    }

    /**
     * Mengembalikan whitelist tabel yang aman dan dapat dikelola user.
     *
     * Whitelist di-cache untuk menghindari query database berulang.
     *
     * @return array<int, string> Array nama tabel yang di-whitelist
     */
    public function whitelist(): array
    {
        return Cache::remember('cto:tables:whitelist', $this->cacheTtl(), function () {
            return $this->tableBuilder->listUserTables();
        });
    }

    /**
     * Memastikan tabel ada dalam whitelist dan mengembalikan nama yang sudah disanitasi.
     *
     * Method ini akan:
     * - Mengubah nama tabel ke lowercase snake_case
     * - Memvalidasi terhadap whitelist
     * - Mengembalikan null jika tidak valid
     *
     * @param string|null $table Nama tabel yang akan divalidasi
     * 
     * @return string|null Nama tabel yang sudah disanitasi, atau null jika tidak valid
     */
    public function sanitizeTable(?string $table): ?string
    {
        if (!$table) return null;
        $table = Str::of($table)->lower()->snake()->toString();
        return in_array($table, $this->whitelist(), true) ? $table : null;
    }

    /**
     * Mendapatkan metadata kolom tabel dalam format terstruktur.
     *
     * Mengembalikan array dengan struktur:
     * [nama_kolom => [type, nullable, length, default, options]]
     *
     * Data di-cache untuk performa optimal.
     *
     * @param string $table Nama tabel
     * 
     * @return array<string, array{type: string, nullable: bool, length: int|null, default: mixed, options: array}> 
     *         Metadata kolom
     */
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
     * Mendapatkan mapping foreign key dari tabel.
     *
     * Mengembalikan struktur:
     * [kolom_fk => ['referenced_table' => ..., 'referenced_column' => ...]]
     *
     * Hanya mendukung MySQL/MariaDB. Data di-cache untuk performa.
     *
     * @param string $table Nama tabel
     * 
     * @return array<string, array{referenced_table: string|null, referenced_column: string}> 
     *         Map foreign key
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

    /**
     * Mendapatkan nama kolom primary key dari metadata tabel.
     *
     * Method ini akan:
     * - Query INFORMATION_SCHEMA untuk PRIMARY KEY constraint
     * - Fallback ke heuristik jika tidak ditemukan:
     *   1. Cek kolom 'id'
     *   2. Cek kolom '{tabel_singular}_id'
     *   3. Gunakan kolom pertama sebagai last resort
     *
     * Data di-cache untuk performa optimal.
     *
     * @param string $table Nama tabel
     * 
     * @return string Nama kolom primary key (default: 'id')
     */
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

    /**
     * Memeriksa apakah primary key tabel adalah auto-increment.
     *
     * Method ini akan:
     * - Query INFORMATION_SCHEMA untuk flag auto_increment (MySQL/MariaDB)
     * - Fallback ke heuristik: kolom 'id' dengan tipe integer
     *
     * Data di-cache untuk performa optimal.
     *
     * @param string $table Nama tabel
     * 
     * @return bool True jika primary key auto-increment
     */
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

    /**
     * Memeriksa apakah tabel memiliki kolom deleted_at untuk soft delete.
     *
     * @param string $table Nama tabel
     * 
     * @return bool True jika tabel memiliki kolom deleted_at
     */
    public function hasDeletedAt(string $table): bool
    {
        $table = $this->sanitizeTable($table);
        return $table ? Schema::hasColumn($table, 'deleted_at') : false;
    }

    /**
     * Menebak kolom label terbaik untuk dropdown FK dengan prioritas berlapis.
     *
     * Prioritas pencarian:
     * 0. Override admin/user dari cto_table_meta.label_column
     * 1. Kolom dari cto_table_meta.display_template.columns
     * 2. Kolom umum yang human-readable: name, title, label, email, code, etc.
     * 3. Kolom pertama bertipe text-like (varchar, text, dll)
     * 4. Primary key sebagai last resort
     *
     * Data di-cache untuk performa optimal.
     *
     * @param string $table Nama tabel
     * 
     * @return string Nama kolom label terbaik (default: 'id')
     */
    public function guessLabelColumn(string $table): string
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return 'id';
        $key = 'cto:schema:label_col:' . DB::getDatabaseName() . ':' . $table;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table) {
            try {
                $meta = CtoTableMeta::query()->where('table_name', $table)->first();
                if ($meta && $meta->label_column && Schema::hasColumn($table, $meta->label_column)) {
                    return $meta->label_column;
                }
            } catch (\Throwable $e) {
            }
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
            }
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

            $colsMeta = $this->columns($table);
            foreach ($colsMeta as $name => $meta) {
                $type = strtolower((string)($meta['type'] ?? ''));
                if (in_array($type, ['string', 'varchar', 'char', 'text', 'mediumtext', 'longtext'], true)) {
                    return $name;
                }
            }

            $pk = $this->primaryKey($table);
            if ($pk && Schema::hasColumn($table, $pk)) return $pk;
            return Schema::hasColumn($table, 'id') ? 'id' : (Schema::getColumnListing($table)[0] ?? 'id');
        });
    }

    /**
     * Memilih kolom text-like yang ter-index untuk search dan meningkatkan performa.
     *
     * Prioritas:
     * 1. Override admin/user dari cto_table_meta.search_column
     * 2. Label column jika ter-index
     * 3. Kolom umum yang ter-index (name, title, email, dll)
     * 4. Label column (meskipun tidak ter-index)
     * 5. Primary key sebagai last resort
     *
     * @param string $table Nama tabel
     * 
     * @return string Nama kolom search terbaik (default: 'id')
     */
    public function bestSearchColumn(string $table): string
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return 'id';
        try {
            $meta = CtoTableMeta::query()->where('table_name', $table)->first();
            if ($meta && $meta->search_column && Schema::hasColumn($table, $meta->search_column)) {
                return $meta->search_column;
            }
        } catch (\Throwable $e) {
        }
        $label = $this->guessLabelColumn($table);
        if (Schema::hasColumn($table, $label) && $this->isIndexed($table, $label)) return $label;
        foreach (['name', 'title', 'label', 'email', 'region_name', 'site_name', 'code'] as $c) {
            if (Schema::hasColumn($table, $c) && $this->isIndexed($table, $c)) return $c;
        }
        if (Schema::hasColumn($table, $label)) return $label;
        $pk = $this->primaryKey($table);
        return Schema::hasColumn($table, $pk) ? $pk : (Schema::getColumnListing($table)[0] ?? 'id');
    }

    /**
     * Mengembalikan list kolom label untuk menyusun label yang human-readable.
     *
     * Prioritas:
     * 1. cto_table_meta.display_template.columns
     * 2. Special-case tables (misal: regional_lookup)
     * 3. Kandidat obvious: *_name, name, title, code
     * 4. Fallback ke detected label column
     *
     * @param string $table Nama tabel
     * 
     * @return array<int, string> Array nama kolom untuk label composite
     */
    public function labelColumns(string $table): array
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return [];

        try {
            $meta = CtoTableMeta::query()->where('table_name', $table)->first();
            if ($meta && is_array($meta->display_template) && !empty($meta->display_template['columns'])) {
                $cols = (array) $meta->display_template['columns'];
                return array_values(array_filter($cols, fn($c) => $c && Schema::hasColumn($table, $c)));
            }
        } catch (\Throwable $e) {
        }

        if ($table === 'regional_lookup') {
            $candidates = ['regional_name', 'tsel_reg', 'tlk_reg', 'island'];
            return array_values(array_filter($candidates, fn($c) => Schema::hasColumn($table, $c)));
        }

        $listing = Schema::getColumnListing($table);
        $nameLike = array_values(array_filter($listing, fn($c) => str_ends_with($c, '_name')));
        if (!empty($nameLike)) return $nameLike;
        foreach (['name', 'title', 'code', 'label'] as $c) {
            if (Schema::hasColumn($table, $c)) return [$c];
        }

        $fallback = $this->guessLabelColumn($table);
        return $fallback ? [$fallback] : [];
    }

    /**
     * Menyusun label yang human-friendly untuk row dari tabel tertentu.
     *
     * Method ini akan:
     * - Menggunakan template eksplisit dari metadata jika tersedia
     * - Menangani special-case table (misal: regional_lookup)
     * - Menggabungkan labelColumns dengan separator " - "
     * - Fallback ke label column atau primary key
     *
     * @param string $table Nama tabel
     * @param array<string, mixed> $row Data row sebagai associative array (kolom => nilai)
     * 
     * @return string Label yang sudah diformat
     */
    public function composeLabel(string $table, array $row): string
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return '';

        try {
            $meta = CtoTableMeta::query()->where('table_name', $table)->first();
            if ($meta && is_array($meta->display_template) && !empty($meta->display_template['template'])) {
                $rendered = $this->renderTemplateLabel($meta->display_template, $row);
                if ($rendered !== null && $rendered !== '') return $rendered;
            }
        } catch (\Throwable $e) {
        }

        if ($table === 'regional_lookup') {
            $parts = [];
            foreach (['regional_name', 'tsel_reg', 'tlk_reg', 'island'] as $c) {
                $parts[] = (string)($row[$c] ?? '');
            }
            $s = trim(implode(' - ', array_filter($parts, fn($v) => $v !== '')));
            if ($s !== '') return $s;
        }

        $cols = $this->labelColumns($table);
        if (!empty($cols)) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = (string)($row[$c] ?? '');
            }
            $s = trim(implode(' - ', array_filter($vals, fn($v) => $v !== '')));
            if ($s !== '') return $s;
        }

        $labelCol = $this->guessLabelColumn($table);
        if ($labelCol && isset($row[$labelCol])) return (string) $row[$labelCol];
        $pk = $this->primaryKey($table);
        return isset($row[$pk]) ? (string)$row[$pk] : '';
    }

    /**
     * Mendapatkan list kolom VARCHAR/CHAR/TEXT dari tabel (excluding PK).
     *
     * Berguna untuk form inline create atau field text-based lainnya.
     *
     * @param string $table Nama tabel
     * 
     * @return array<int, string> Array nama kolom bertipe text
     */
    public function varcharTextColumns(string $table): array
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return [];
        $pk = $this->primaryKey($table);
        $colsMeta = $this->columns($table);
        $out = [];
        foreach ($colsMeta as $name => $meta) {
            if ($name === $pk) continue;
            $type = strtolower((string)($meta['type'] ?? ''));
            if (in_array($type, ['string', 'varchar', 'char', 'text', 'mediumtext', 'longtext'], true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Mengembalikan opsi enum/set untuk kolom tertentu (MySQL/MariaDB only).
     *
     * Method ini akan:
     * - Query INFORMATION_SCHEMA untuk COLUMN_TYPE
     * - Parse enum('a','b','c') atau set('a','b') menjadi ['a','b','c']
     * - Menangani escaped quotes dalam nilai
     *
     * Data di-cache untuk performa optimal.
     *
     * @param string $table Nama tabel
     * @param string $column Nama kolom
     * 
     * @return array<int, string> Array opsi enum/set, atau empty array jika tidak applicable
     */
    public function enumOptions(string $table, string $column): array
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return [];
        $key = 'cto:schema:enum:' . DB::getDatabaseName() . ':' . $table . ':' . $column;
        return Cache::remember($key, $this->cacheTtl(), function () use ($table, $column) {
            $driver = DB::connection()->getDriverName();
            if (!in_array($driver, ['mysql', 'mariadb'], true)) return [];
            $db = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$db, $table, $column]
            );
            $type = (string) ($row->COLUMN_TYPE ?? '');
            if (!str_starts_with($type, 'enum(') && !str_starts_with($type, 'set(')) {
                return [];
            }
            $m = [];
            if (!preg_match("/^(enum|set)\((.*)\)$/i", $type, $m)) return [];
            $inner = $m[2] ?? '';
            $vals = [];
            $current = '';
            $inQuote = false;
            $len = strlen($inner);
            for ($i = 0; $i < $len; $i++) {
                $ch = $inner[$i];
                if ($ch === "'") {
                    if ($inQuote && ($i + 1 < $len) && $inner[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                    } else {
                        $inQuote = !$inQuote;
                    }
                } elseif ($ch === ',' && !$inQuote) {
                    $vals[] = $current;
                    $current = '';
                } else {
                    $current .= $ch;
                }
            }
            if ($current !== '') $vals[] = $current;
            $vals = array_map(fn($v) => trim($v, "' \t\n\r\0\x0B"), $vals);
            return array_values(array_filter($vals, fn($v) => $v !== ''));
        });
    }

    /**
     * Memeriksa apakah kolom tertentu pada tabel adalah foreign key column.
     *
     * @param string $table Nama tabel
     * @param string $column Nama kolom yang akan diperiksa
     * 
     * @return bool True jika kolom adalah foreign key
     */
    public function isForeignKeyColumn(string $table, string $column): bool
    {
        $table = $this->sanitizeTable($table);
        if (!$table) return false;
        $fks = $this->foreignKeys($table);
        return array_key_exists($column, $fks);
    }

    /**
     * Merender label dari display template menggunakan data row yang diberikan.
     *
     * Method ini akan:
     * - Mengganti placeholder {{kolom}} dengan nilai dari row
     * - Menghapus placeholder yang tidak dikenal
     * - Mengubah null menjadi string kosong
     *
     * Format template: "{{name}} - {{code}}" dengan data row akan menjadi "John - ABC"
     *
     * @param array<string, mixed>|null $template Template dengan key 'template' dan 'columns'
     * @param array<string, mixed> $row Data row sebagai associative array
     * 
     * @return string|null Label yang sudah dirender, atau null jika template tidak valid
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

    /**
     * Memeriksa apakah kolom ter-index (MySQL/MariaDB only).
     *
     * Data di-cache untuk performa optimal.
     *
     * @param string $table Nama tabel
     * @param string $column Nama kolom
     * 
     * @return bool True jika kolom memiliki index
     */
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

    /**
     * Menginvalidasi cache metadata skema untuk tabel tertentu.
     *
     * Method ini akan menghapus semua cache terkait:
     * - Metadata kolom
     * - Foreign keys
     * - Primary key
     * - Label column
     * - Auto-increment flag
     *
     * @param string $tableName Nama tabel yang cache-nya akan di-invalidate
     * 
     * @return void
     */
    public function invalidateTableCache(string $tableName): void
    {
        $table = $this->sanitizeTable($tableName);
        if (!$table) return;
        $db = DB::getDatabaseName();
        Cache::forget('cto:schema:cols:' . $db . ':' . $table);
        Cache::forget('cto:schema:fks:' . $db . ':' . $table);
        Cache::forget('cto:schema:pk:' . $db . ':' . $table);
        Cache::forget('cto:schema:label_col:' . $db . ':' . $table);
        try {
            $pk = $this->primaryKey($table);
            Cache::forget('cto:schema:pk:auto_inc:' . $db . ':' . $table . ':' . $pk);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Mendeteksi dan menyimpan primary_key_column dan label_column ke cto_table_meta.
     *
     * Method ini bersifat idempotent:
     * - Melakukan upsert pada row metadata
     * - Mempertahankan nilai yang sudah di-set user (tidak overwrite)
     * - Mendeteksi primary key via helper yang sudah ada
     * - Memilih default label menggunakan heuristik yang sama
     * - Menginvalidasi cache yang relevan setelah update
     *
     * @param string $tableName Nama tabel yang akan dipopulasi metadatanya
     * 
     * @return void
     */
    public function populateMetaForTable(string $tableName): void
    {
        $table = $this->sanitizeTable($tableName);
        if (!$table || !Schema::hasTable($table)) return;

        $pk = $this->primaryKey($table);

        $label = $this->guessLabelColumn($table);

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

            Cache::forget('cto:schema:label_col:' . DB::getDatabaseName() . ':' . $table);
        } catch (\Throwable $e) {
        }
    }
}
