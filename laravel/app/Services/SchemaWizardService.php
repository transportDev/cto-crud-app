<?php

namespace App\Services;

use App\Models\CtoTableMeta;
use App\Services\Dynamic\DynamicSchemaService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Service untuk mengelola wizard penambahan field dan relasi ke tabel database.
 *
 * Service ini menyediakan fungsionalitas untuk:
 * - Menganalisis field dan relasi yang akan ditambahkan
 * - Menghasilkan preview migration PHP dan SQL
 * - Mendeteksi potensi masalah dan risiko
 * - Menerapkan perubahan skema langsung ke database (tanpa file migration)
 * - Menambahkan kolom dengan berbagai tipe data dan constraint
 * - Menambahkan foreign key constraint dengan berbagai action (CASCADE, SET NULL, dll)
 * - Menyimpan metadata untuk display template dan search column
 * - Melakukan invalidasi cache setelah perubahan skema
 * - Menangani idempotency untuk menghindari duplikasi
 * - Compensating action untuk rollback jika terjadi error
 *
 * @package App\Services
 */
class SchemaWizardService
{
    /**
     * Menormalisasi pilihan tipe relasi ke salah satu tipe yang didukung.
     *
     * Tipe yang didukung: bigInteger, integer, mediumInteger, smallInteger, tinyInteger
     * Default: bigInteger
     *
     * @param string|null $t Tipe relasi yang diminta
     *
     * @return string Tipe relasi yang valid (default: 'bigInteger')
     */
    protected function resolveRelationType(?string $t): string
    {
        $t = (string)($t ?? 'bigInteger');
        $allowed = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];
        return in_array($t, $allowed, true) ? $t : 'bigInteger';
    }

    /**
     * Membangun ekspresi PHP Blueprint untuk kolom FK unsigned integer.
     *
     * Contoh output: $table->unsignedBigInteger('user_id')->nullable()
     *
     * @param string $name Nama kolom
     * @param string $relationType Tipe relasi (bigInteger, integer, dll)
     * @param bool $nullable Apakah kolom boleh NULL
     *
     * @return string Ekspresi Blueprint PHP
     */
    protected function relationPhpColumn(string $name, string $relationType, bool $nullable): string
    {
        $method = match ($relationType) {
            'bigInteger' => 'unsignedBigInteger',
            'integer' => 'unsignedInteger',
            'mediumInteger' => 'unsignedMediumInteger',
            'smallInteger' => 'unsignedSmallInteger',
            'tinyInteger' => 'unsignedTinyInteger',
            default => 'unsignedBigInteger',
        };
        $expr = "\$table->{$method}('{$name}')";
        if ($nullable) {
            $expr .= '->nullable()';
        }
        return $expr;
    }

    /**
     * Mendapatkan tipe kolom MySQL untuk FK integer yang dipilih (selalu UNSIGNED).
     *
     * @param string $relationType Tipe relasi
     *
     * @return string Tipe kolom MySQL (contoh: 'BIGINT UNSIGNED')
     */
    protected function relationSqlColumnType(string $relationType): string
    {
        return match ($relationType) {
            'bigInteger' => 'BIGINT UNSIGNED',
            'integer' => 'INT UNSIGNED',
            'mediumInteger' => 'MEDIUMINT UNSIGNED',
            'smallInteger' => 'SMALLINT UNSIGNED',
            'tinyInteger' => 'TINYINT UNSIGNED',
            default => 'BIGINT UNSIGNED',
        };
    }

    /**
     * Menganalisis array field dan relasi yang akan ditambahkan ke tabel.
     *
     * Method ini akan:
     * - Mengiterasi semua item (field atau relasi)
     * - Menghasilkan preview PHP Blueprint dan SQL
     * - Mengumpulkan warnings dari setiap item
     * - Menentukan impact level (safe/risky)
     * - Menangani foreign key dengan action (onUpdate, onDelete)
     *
     * @param string $table Nama tabel target
     * @param array<int, array<string, mixed>> $items Array item yang berisi:
     *        - kind: 'field' atau 'relation'
     *        - name: nama kolom
     *        - type: tipe data (untuk field)
     *        - nullable: boolean
     *        - relation_type: tipe integer untuk FK (untuk relasi)
     *        - references_table: tabel yang direferensikan (untuk relasi)
     *        - references_column: kolom yang direferensikan (untuk relasi)
     *        - on_update: action untuk ON UPDATE (untuk relasi)
     *        - on_delete: action untuk ON DELETE (untuk relasi)
     *
     * @return array{
     *     migration_php: string,
     *     estimated_sql: string,
     *     warnings: array<int, string>,
     *     impact: string
     * } Array berisi migration preview dan warnings
     */
    public function analyze(string $table, array $items): array
    {
        $fieldSvc = app(SchemaFieldService::class);
        $warnings = [];
        $phpLines = [];
        $sqlLines = [];

        foreach ($items as $i) {
            $kind = $i['kind'] ?? 'field';
            if ($kind === 'field') {
                $res = $fieldSvc->analyze($table, $i);
                $phpLines[] = preg_replace('/^Schema::table\([^\n]+\n\s{4}/', '    ', $res['migration_php']);
                $sqlLines[] = $res['estimated_sql'];
                $warnings = array_merge($warnings, $res['warnings']);
            } else {
                $nullable = (bool)($i['nullable'] ?? true);
                $relationType = $this->resolveRelationType($i['relation_type'] ?? null);
                $unsignedPhp = $this->relationPhpColumn($i['name'], $relationType, $nullable);
                $phpLines[] = '    ' . $unsignedPhp . ';' . "\n" .
                    '    ' . "\$table->foreign('{$i['name']}')->references('" . ($i['references_column'] ?? 'id') . "')->on('{$i['references_table']}')" .
                    (!empty($i['on_update']) ? "->onUpdate('{$i['on_update']}')" : '') .
                    (!empty($i['on_delete']) ? "->onDelete('{$i['on_delete']}')" : '') . ';';
                $sqlCol = $this->relationSqlColumnType($relationType);
                $sqlLines[] = "ALTER TABLE `{$table}` ADD COLUMN `{$i['name']}` {$sqlCol} " . ($nullable ? 'NULL' : 'NOT NULL') . ";\n" .
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$i['name']}_fk` FOREIGN KEY (`{$i['name']}`) REFERENCES `{$i['references_table']}` (`" . ($i['references_column'] ?? 'id') . "`)" .
                    (!empty($i['on_update']) ? " ON UPDATE {$i['on_update']}" : '') .
                    (!empty($i['on_delete']) ? " ON DELETE {$i['on_delete']}" : '') . ';';
                $fieldRes = $fieldSvc->analyze($table, [
                    'name' => $i['name'],
                    'type' => 'foreignId',
                    'nullable' => $nullable,
                ]);
                $warnings = array_merge($warnings, $fieldRes['warnings'], [
                    'Foreign keys on large tables may lock the table; consider adding column first, backfill, then add constraint.',
                ]);
            }
        }

        $migrationPhp = "Schema::table('{$table}', function (Blueprint \$table) {\n" . implode("\n", $phpLines) . "\n});";
        $estimatedSql = implode("\n\n", array_filter($sqlLines));

        return [
            'migration_php' => $migrationPhp,
            'estimated_sql' => $estimatedSql,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'impact' => empty($warnings) ? 'safe' : 'risky',
        ];
    }

    /**
     * Menjalankan migrasi Laravel yang pending.
     *
     * @return void
     */
    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Menerapkan perubahan skema langsung ke database tanpa membuat file migration.
     *
     * Pendekatan ini lebih baik untuk admin tools karena:
     * - Feedback langsung ke user
     * - Menghindari duplikasi file migration
     * - Tidak memerlukan commit ke version control
     *
     * Method ini menangani:
     * - Penambahan field dengan berbagai tipe dan constraint
     * - Penambahan relasi dengan foreign key
     * - Penyimpanan metadata (display_template, search_column)
     * - Invalidasi cache setelah perubahan
     * - Idempotency check untuk menghindari duplikasi
     * - Compensating action untuk rollback partial state
     *
     * Note: MySQL melakukan implicit commit untuk banyak operasi DDL (ALTER TABLE),
     * jadi DB::transaction() tidak efektif dan bisa menghasilkan error misleading.
     * Sebagai gantinya, perubahan diterapkan secara sequential dengan defensive
     * idempotent checks dan compensating cleanup untuk menghindari partial state.
     *
     * @param string $table Nama tabel target
     * @param array<int, array<string, mixed>> $items Array item untuk ditambahkan
     *
     * @return array{
     *     success: bool,
     *     applied: array<int, string>,
     *     errors: array<int, string>
     * } Hasil penerapan perubahan
     */
    public function applyDirectChanges(string $table, array $items): array
    {
        $applied = [];
        $errors = [];

        foreach ($items as $i) {
            try {
                if (($i['kind'] ?? 'field') === 'field') {
                    $this->addFieldDirectly($table, $i);
                    $applied[] = "Added/verified field '{$i['name']}' on table '{$table}'";
                } else {
                    $this->addRelationDirectly($table, $i);
                    $applied[] = "Added/verified foreign key '{$i['name']}' on table '{$table}'";

                    $refTable = $i['references_table'] ?? null;
                    if ($refTable) {
                        try {
                            $payload = [];
                            $labelCols = $i['label_columns'] ?? [];
                            if (is_array($labelCols) && !empty($labelCols)) {
                                $payload['display_template'] = [
                                    'template' => implode(' - ', array_map(fn($c) => '{{' . $c . '}}', $labelCols)),
                                    'columns' => array_values($labelCols),
                                ];
                            }

                            if (!empty($i['search_column'])) {
                                $payload['search_column'] = (string) $i['search_column'];
                            } elseif (!empty($payload['display_template']['columns'])) {
                                $payload['search_column'] = (string) ($payload['display_template']['columns'][0] ?? null);
                            }

                            if (!empty($payload)) {
                                CtoTableMeta::query()->updateOrCreate(
                                    ['table_name' => $refTable],
                                    $payload
                                );
                            }
                        } catch (\Throwable $_e) {
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Failed to apply '{$i['name']}': " . $e->getMessage();
            }
        }

        try {
            $schemaSvc = app(DynamicSchemaService::class);
            $schemaSvc->invalidateTableCache($table);
            $schemaSvc->populateMetaForTable($table);
        } catch (\Throwable $e) {
        }

        return [
            'success' => empty($errors),
            'applied' => $applied,
            'errors' => $errors,
        ];
    }

    /**
     * Menambahkan field langsung ke tabel tanpa migration file.
     *
     * Method ini bersifat idempotent - akan skip jika kolom sudah ada.
     *
     * Menangani:
     * - Berbagai tipe data (string, integer, decimal, boolean, date, datetime, json, dll)
     * - Nullable constraint
     * - Default value (termasuk boolean)
     * - Index (unique, index, fulltext)
     *
     * @param string $table Nama tabel
     * @param array<string, mixed> $field Konfigurasi field
     *
     * @return void
     */
    protected function addFieldDirectly(string $table, array $field): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, $field['name'])) {
            return;
        }

        \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $table) use ($field) {
            $name = $field['name'];
            $type = $field['type'];
            $length = $field['length'] ?? null;
            $precision = $field['precision'] ?? null;
            $scale = $field['scale'] ?? null;
            $nullable = (bool)($field['nullable'] ?? false);
            $default = $field['default'] ?? null;
            $defaultBool = $field['default_bool'] ?? null;

            $column = match ($type) {
                'string' => $table->string($name, $length ?: 255),
                'integer' => $table->integer($name),
                'bigInteger' => $table->bigInteger($name),
                'decimal' => $table->decimal($name, $precision ?: 10, $scale ?: 0),
                'boolean' => $table->boolean($name),
                'date' => $table->date($name),
                'datetime' => $table->dateTime($name),
                'json' => $table->json($name),
                'foreignId' => $table->foreignId($name),
                default => $table->string($name),
            };

            if ($nullable) {
                $column->nullable();
            }

            if ($default !== null && $default !== '') {
                $column->default($default);
            } elseif ($type === 'boolean' && $defaultBool !== null) {
                $column->default($defaultBool);
            }

            if (!empty($field['unique'])) {
                $table->unique($name);
            } elseif (!empty($field['index']) && $field['index'] === 'index') {
                $table->index($name);
            } elseif (!empty($field['index']) && $field['index'] === 'fulltext') {
                $table->fullText($name);
            }
        });
    }

    /**
     * Menambahkan relasi (foreign key) langsung ke tabel tanpa migration file.
     *
     * Method ini menangani:
     * 1. Memastikan kolom FK exists (jika belum ada, buat kolom unsigned integer)
     * 2. Memastikan constraint FK exists (jika belum ada, buat constraint)
     * 3. Menangani action ON UPDATE dan ON DELETE
     * 4. Invalidasi cache untuk kedua tabel (source dan referenced)
     * 5. Compensating action: drop kolom jika pembuatan FK gagal
     *
     * Method ini bersifat idempotent dengan mengecek keberadaan kolom dan constraint.
     *
     * @param string $table Nama tabel source
     * @param array<string, mixed> $relation Konfigurasi relasi dengan keys:
     *        - name: nama kolom FK
     *        - nullable: boolean
     *        - references_table: tabel yang direferensikan
     *        - references_column: kolom yang direferensikan (default: 'id')
     *        - relation_type: tipe integer (bigInteger, integer, dll)
     *        - on_update: action untuk ON UPDATE (CASCADE, SET NULL, dll)
     *        - on_delete: action untuk ON DELETE
     *
     * @return void
     *
     * @throws \Throwable Jika gagal membuat FK constraint
     */
    protected function addRelationDirectly(string $table, array $relation): void
    {
        $column = $relation['name'];
        $nullable = (bool)($relation['nullable'] ?? true);
        $refTable = $relation['references_table'];
        $refColumn = $relation['references_column'] ?? 'id';
        $relationType = $this->resolveRelationType($relation['relation_type'] ?? null);

        $createdColumn = false;

        if (!\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
            \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($column, $nullable, $relationType) {
                $method = match ($relationType) {
                    'bigInteger' => 'unsignedBigInteger',
                    'integer' => 'unsignedInteger',
                    'mediumInteger' => 'unsignedMediumInteger',
                    'smallInteger' => 'unsignedSmallInteger',
                    'tinyInteger' => 'unsignedTinyInteger',
                    default => 'unsignedBigInteger',
                };
                $col = $t->{$method}($column);
                if ($nullable) {
                    $col->nullable();
                }
            });
            $createdColumn = true;
        }

        if (!$this->hasForeignKey($table, $column)) {
            try {
                \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($column, $refTable, $refColumn, $relation) {
                    $foreign = $t->foreign($column)
                        ->references($refColumn)
                        ->on($refTable);

                    if (!empty($relation['on_update'])) {
                        $foreign->onUpdate($relation['on_update']);
                    }
                    if (!empty($relation['on_delete'])) {
                        $foreign->onDelete($relation['on_delete']);
                    }
                });
                try {
                    $schemaSvc = app(DynamicSchemaService::class);
                    $schemaSvc->invalidateTableCache($table);
                    $schemaSvc->invalidateTableCache($refTable);
                } catch (\Throwable $_) {
                }
            } catch (\Throwable $e) {
                if ($createdColumn) {
                    try {
                        \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($column) {
                            try {
                                $t->dropForeign([$column]);
                            } catch (\Throwable $_) {
                            }
                            $t->dropColumn($column);
                        });
                    } catch (\Throwable $_) {
                    }
                }
                throw $e;
            }
        }
    }

    /**
     * Memeriksa apakah foreign key constraint exists untuk tabel dan kolom tertentu.
     *
     * Method ini query INFORMATION_SCHEMA.KEY_COLUMN_USAGE untuk mencari
     * foreign key yang existing.
     *
     * @param string $table Nama tabel
     * @param string $column Nama kolom
     *
     * @return bool True jika foreign key exists, false jika tidak atau query error
     */
    protected function hasForeignKey(string $table, string $column): bool
    {
        try {
            $database = DB::getDatabaseName();
            $result = DB::select(
                'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
                [$database, $table, $column]
            );
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
