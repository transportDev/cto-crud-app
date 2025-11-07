<?php

namespace App\Services;

use App\Services\Dynamic\DynamicSchemaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

/**
 * Service untuk membuat tabel database baru secara dinamis.
 *
 * Menyediakan fungsionalitas untuk:
 * - Validasi nama tabel dan definisi kolom
 * - Preview blueprint migrasi sebelum eksekusi
 * - Pembuatan tabel dengan constraint dan index
 * - Pembersihan cache metadata setelah perubahan skema
 * - Penyimpanan metadata tabel ke model DynamicTable
 *
 * @package App\Services
 * @author CTO CRUD System
 */
class TableBuilderService
{
    /**
     * Membuat preview blueprint migrasi tanpa mengeksekusi perubahan skema.
     *
     * Melakukan normalisasi dan validasi definisi tabel, kemudian membuat
     * representasi string dari kode migrasi Laravel yang akan dihasilkan.
     * Berguna untuk review sebelum pembuatan tabel aktual.
     *
     * @param array $definition Definisi tabel dengan keys: table_name, columns
     * @return array Array dengan keys: table_name, preview_lines, normalized
     * @throws ValidationException Jika validasi nama tabel atau kolom gagal
     */
    public function preview(array $input): array
    {
        $definition = $this->normalizeDefinition($input);

        $lines = [];
        $lines[] = "Schema::create('{$definition['table']}', function (Blueprint $" . "table) {";

        foreach ($definition['columns'] as $col) {
            $lines[] = '    ' . $this->buildBlueprintLine($col) . ';';
        }

        if (!empty($definition['timestamps'])) {
            $lines[] = '    $' . "table->timestamps();";
        }
        if (!empty($definition['soft_deletes'])) {
            $lines[] = '    $' . "table->softDeletes();";
        }

        // Indexes that are column-level unique/index are already reflected in column lines.
        $lines[] = '});';

        $preview = implode(PHP_EOL, $lines);

        Log::info('TableBuilder preview', [
            'definition' => $definition,
            'preview' => $preview,
        ]);

        return [
            'definition' => $definition,
            'preview' => $preview,
        ];
    }

    /**
     * Membuat tabel baru di database dengan validasi lengkap.
     *
     * Melakukan validasi komprehensif terhadap definisi tabel, membuat tabel
     * menggunakan Schema Builder Laravel, membersihkan cache metadata, dan
     * menyimpan informasi tabel ke model DynamicTable untuk tracking.
     *
     * @param array $definition Definisi tabel dengan keys: table, columns, timestamps, soft_deletes
     * @return void
     * @throws ValidationException Jika validasi gagal
     * @throws \Exception Jika terjadi error saat pembuatan tabel
     */
    public function create(array $definition): void
    {
        $definition = $this->normalizeDefinition($definition);

        $this->validateTableName($definition['table']);

        $this->validateColumns($definition['columns']);

        if (Schema::hasTable($definition['table'])) {
            throw ValidationException::withMessages([
                'table' => 'A table with this name already exists.',
            ]);
        }

        Schema::create($definition['table'], function (Blueprint $table) use ($definition) {
            foreach ($definition['columns'] as $col) {
                $this->applyColumn($table, $col);
            }

            if (!empty($definition['timestamps'])) {
                $table->timestamps();
            }

            if (!empty($definition['soft_deletes'])) {
                $table->softDeletes();
            }
        });

        try {
            app(DynamicSchemaService::class)->invalidateTableCache($definition['table']);
        } catch (\Throwable $e) {
        }

        Cache::forget('cto:tables:whitelist');

        try {
            app(DynamicSchemaService::class)->populateMetaForTable($definition['table']);
        } catch (\Throwable $e) {
        }

        if (Schema::hasTable('dynamic_tables')) {
            DB::table('dynamic_tables')->insert([
                'table' => $definition['table'],
                'meta' => json_encode($definition),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('admin_audit_logs')) {
            DB::table('admin_audit_logs')->insert([
                'user_id' => Auth::id(),
                'action' => 'table.created',
                'context' => json_encode([
                    'table' => $definition['table'],
                    'columns' => $definition['columns'],
                    'timestamps' => $definition['timestamps'] ?? false,
                    'soft_deletes' => $definition['soft_deletes'] ?? false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Memvalidasi format nama tabel atau kolom.
     *
     * Nama harus dimulai dengan huruf kecil, diikuti oleh huruf kecil,
     * angka, atau underscore.
     *
     * @param string $name Nama yang akan divalidasi
     * @return bool True jika nama valid
     */
    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
    }

    /**
     * Memeriksa apakah nama adalah reserved keyword SQL.
     *
     * @param string $name Nama yang akan diperiksa
     * @return bool True jika nama adalah reserved keyword
     */
    public static function isReserved(string $name): bool
    {
        $reserved = [
            'select',
            'insert',
            'update',
            'delete',
            'table',
            'from',
            'where',
            'and',
            'or',
            'join',
            'group',
            'order',
            'by',
            'create',
            'alter',
            'drop',
            'index',
            'constraint',
            'key',
            'primary',
            'unique',
            'int',
            'varchar',
            'text',
        ];
        return in_array(strtolower($name), $reserved, true);
    }

    /**
     * Menormalisasi input user menjadi array definisi tabel yang konsisten.
     *
     * Mengonversi input form mentah menjadi struktur data standar dengan
     * semua field yang diperlukan, termasuk handling tipe data khusus
     * seperti enum/set dan foreign key constraints.
     *
     * @param array $input Input mentah dari user
     * @return array Array definisi yang dinormalisasi dengan keys: table, columns, timestamps, soft_deletes
     */
    protected function normalizeDefinition(array $input): array
    {
        $columns = collect($input['columns'] ?? [])
            ->map(function ($col) {
                return [
                    'name' => $col['name'],
                    'type' => $col['type'],
                    'length' => $col['length'] ?? null,
                    'precision' => $col['precision'] ?? null,
                    'scale' => $col['scale'] ?? null,
                    'nullable' => (bool)($col['nullable'] ?? false),
                    'default' => $col['default'] ?? null,
                    'default_bool' => array_key_exists('default_bool', $col) ? (bool)$col['default_bool'] : null,
                    'unique' => (bool)($col['unique'] ?? false),
                    'index' => $col['index'] ?? null,
                    'unsigned' => (bool)($col['unsigned'] ?? false),
                    'auto_increment' => (bool)($col['auto_increment'] ?? false),
                    'primary' => (bool)($col['primary'] ?? false),
                    'comment' => $col['comment'] ?? null,
                    // Enum/Set
                    'enum_options' => isset($col['enum_options']) ? array_values(array_filter((array)$col['enum_options'])) : null,
                    // Foreign key
                    'references_table' => $col['references_table'] ?? null,
                    'references_column' => $col['references_column'] ?? 'id',
                    'on_update' => $col['on_update'] ?? null, // cascade|restrict|no_action|set_null
                    'on_delete' => $col['on_delete'] ?? null, // cascade|restrict|no_action|set_null
                ];
            })->all();

        return [
            'table' => $input['table'],
            'timestamps' => (bool)($input['timestamps'] ?? false),
            'soft_deletes' => (bool)($input['soft_deletes'] ?? false),
            'columns' => $columns,
        ];
    }

    /**
     * Memvalidasi nama tabel untuk memastikan format valid dan bukan reserved.
     *
     * Memeriksa format nama sesuai regex, menolak reserved keywords SQL,
     * dan memblokir nama tabel sistem penting.
     *
     * @param string $name Nama tabel yang akan divalidasi
     * @return void
     * @throws ValidationException Jika nama tidak valid atau reserved
     */
    protected function validateTableName(string $name): void
    {
        Validator::validate(
            ['table' => $name],
            ['table' => ['required', 'regex:/^[a-z][a-z0-9_]*$/']]
        );

        if (!self::isValidName($name) || self::isReserved($name)) {
            throw ValidationException::withMessages([
                'table' => 'Invalid or reserved table name.',
            ]);
        }

        $blocked = ['migrations', 'password_resets', 'personal_access_tokens', 'failed_jobs', 'admin_audit_logs', 'dynamic_tables'];
        if (in_array($name, $blocked, true)) {
            throw ValidationException::withMessages([
                'table' => 'This table name is reserved for system use.',
            ]);
        }
    }

    /**
     * Memvalidasi array definisi kolom.
     *
     * Memeriksa nama kolom unik, format nama valid, dan bukan reserved keyword.
     *
     * @param array $columns Array definisi kolom
     * @return void
     * @throws ValidationException Jika ada duplikasi nama atau nama tidak valid
     */
    protected function validateColumns(array $columns): void
    {
        $names = array_map(fn($c) => $c['name'] ?? '', $columns);
        if (count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages([
                'columns' => 'Duplicate column names detected.',
            ]);
        }

        foreach ($columns as $col) {
            if (!self::isValidName($col['name']) || self::isReserved($col['name'])) {
                throw ValidationException::withMessages([
                    "columns.{$col['name']}" => "Invalid or reserved column name '{$col['name']}'.",
                ]);
            }

            if (in_array($col['type'], ['enum', 'set'], true)) {
                if (empty($col['enum_options']) || !is_array($col['enum_options'])) {
                    throw ValidationException::withMessages([
                        "columns.{$col['name']}" => "Enum/Set requires at least one option.",
                    ]);
                }
            }

            if ($col['type'] === 'foreignId') {
                if (empty($col['references_table'])) {
                    throw ValidationException::withMessages([
                        "columns.{$col['name']}" => "Foreign key must reference a table.",
                    ]);
                }
            }
        }
    }

    /**
     * Menerapkan definisi kolom ke Blueprint tabel.
     *
     * Membuat kolom database berdasarkan tipe, menerapkan modifier
     * (nullable, default, comment), membuat index, dan menambahkan
     * constraint foreign key jika diperlukan.
     *
     * @param Blueprint $table Instance Blueprint untuk tabel
     * @param array $col Definisi kolom dengan keys: name, type, length, dll
     * @return void
     */
    protected function applyColumn(Blueprint $table, array $col): void
    {
        $type = $col['type'];
        $name = $col['name'];

        $column = match ($type) {
            'string' => $table->string($name, $col['length'] ?: 255),
            'char' => $table->char($name, $col['length'] ?: 255),
            'text' => $table->text($name),
            'mediumText' => $table->mediumText($name),
            'longText' => $table->longText($name),

            'integer' => $table->integer($name, autoIncrement: $col['auto_increment'] ?? false, unsigned: $col['unsigned'] ?? false),
            'tinyInteger' => $table->tinyInteger($name, unsigned: $col['unsigned'] ?? false),
            'smallInteger' => $table->smallInteger($name, unsigned: $col['unsigned'] ?? false),
            'mediumInteger' => $table->mediumInteger($name, unsigned: $col['unsigned'] ?? false),
            'bigInteger' => $col['auto_increment'] ? $table->bigIncrements($name) : $table->bigInteger($name, unsigned: $col['unsigned'] ?? false),

            'decimal' => $table->decimal($name, $col['precision'] ?: 10, $col['scale'] ?: 0),
            'float' => $table->float($name, $col['precision'] ?: null, $col['scale'] ?: null),
            'double' => $table->double($name, $col['precision'] ?: null, $col['scale'] ?: null),

            'boolean' => $table->boolean($name),

            'date' => $table->date($name),
            'time' => $table->time($name),
            'datetime' => $table->dateTime($name),
            'timestamp' => $table->timestamp($name),

            'uuid' => $table->uuid($name),
            'ulid' => $table->ulid($name),

            'json' => $table->json($name),

            'enum' => $table->enum($name, $col['enum_options'] ?? []),
            'set' => $table->set($name, $col['enum_options'] ?? []),

            'foreignId' => $table->foreignId($name),
            default => $table->string($name),
        };

        if (!empty($col['nullable'])) {
            $column->nullable();
        }

        if (array_key_exists('default', $col) && $col['default'] !== null && $col['default'] !== '') {
            $defaultValue = $col['default'];

            $currentTimestampFunctions = ['CURRENT_TIMESTAMP', 'NOW()'];
            if (in_array($type, ['timestamp', 'datetime']) && in_array(strtoupper($defaultValue), array_map('strtoupper', $currentTimestampFunctions))) {
                $column->useCurrent();
            } elseif ($type === 'date') {
                $dateFunctions = ['CURDATE()', 'CURRENT_DATE'];
                if (in_array(strtoupper($defaultValue), array_map('strtoupper', $dateFunctions))) {
                    $column->default(DB::raw($defaultValue));
                } else {
                    $column->default($defaultValue);
                }
            } else {
                $sqlFunctions = [
                    'CURRENT_TIME',
                    'UUID()',
                    'ULID()',
                    'CURTIME()'
                ];

                if (in_array(strtoupper($defaultValue), array_map('strtoupper', $sqlFunctions))) {
                    $column->default(DB::raw($defaultValue));
                } else {
                    $column->default($defaultValue);
                }
            }
        } elseif ($type === 'boolean' && $col['default_bool'] !== null) {
            $column->default($col['default_bool']);
        }

        if (!empty($col['comment'])) {
            $column->comment($col['comment']);
        }

        if (!empty($col['unique'])) {
            $table->unique($name);
        } elseif (!empty($col['index']) && $col['index'] === 'index') {
            $table->index($name);
        } elseif (!empty($col['index']) && $col['index'] === 'fulltext') {
            $table->fullText($name);
        }

        $impliesPrimary = ($type === 'bigInteger' && !empty($col['auto_increment']));
        if (!empty($col['primary']) && !$impliesPrimary) {
            $table->primary($name);
        }

        if ($type === 'foreignId' && !empty($col['references_table'])) {
            $foreign = $table->foreign($name)->references($col['references_column'] ?? 'id')->on($col['references_table']);
            if (($col['on_update'] ?? null) === 'cascade') {
                $foreign->onUpdate('cascade');
            } elseif (($col['on_update'] ?? null) === 'restrict') {
                $foreign->onUpdate('restrict');
            } elseif (($col['on_update'] ?? null) === 'set_null') {
                $foreign->onUpdate('set null');
            }

            if (($col['on_delete'] ?? null) === 'cascade') {
                $foreign->onDelete('cascade');
            } elseif (($col['on_delete'] ?? null) === 'restrict') {
                $foreign->onDelete('restrict');
            } elseif (($col['on_delete'] ?? null) === 'set_null') {
                $foreign->onDelete('set null');
            }
        }
    }

    /**
     * Membangun representasi string satu baris Blueprint untuk preview.
     *
     * Menghasilkan string kode PHP yang menunjukkan bagaimana kolom akan
     * dibuat dalam migrasi Laravel, termasuk modifier dan constraint.
     *
     * @param array $col Definisi kolom
     * @return string String kode PHP untuk preview
     */
    protected function buildBlueprintLine(array $col): string
    {
        $name = $col['name'];
        $type = $col['type'];

        $args = '';
        $chain = [];

        $map = [
            'string' => fn() => "\$table->string('{$name}'" . ($col['length'] ? ", {$col['length']}" : '') . ")",
            'char' => fn() => "\$table->char('{$name}'" . ($col['length'] ? ", {$col['length']}" : '') . ")",
            'text' => fn() => "\$table->text('{$name}')",
            'mediumText' => fn() => "\$table->mediumText('{$name}')",
            'longText' => fn() => "\$table->longText('{$name}')",

            'integer' => fn() => "\$table->integer('{$name}')",
            'tinyInteger' => fn() => "\$table->tinyInteger('{$name}')",
            'smallInteger' => fn() => "\$table->smallInteger('{$name}')",
            'mediumInteger' => fn() => "\$table->mediumInteger('{$name}')",
            'bigInteger' => fn() => ($col['auto_increment'] ? "\$table->bigIncrements('{$name}')" : "\$table->bigInteger('{$name}')"),

            'decimal' => fn() => "\$table->decimal('{$name}', " . ($col['precision'] ?: 10) . ', ' . ($col['scale'] ?: 0) . ")",
            'float' => fn() => "\$table->float('{$name}'" . ($col['precision'] ? ", {$col['precision']}, " . ($col['scale'] ?: 0) : '') . ")",
            'double' => fn() => "\$table->double('{$name}'" . ($col['precision'] ? ", {$col['precision']}, " . ($col['scale'] ?: 0) : '') . ")",

            'boolean' => fn() => "\$table->boolean('{$name}')",

            'date' => fn() => "\$table->date('{$name}')",
            'time' => fn() => "\$table->time('{$name}')",
            'datetime' => fn() => "\$table->dateTime('{$name}')",
            'timestamp' => fn() => "\$table->timestamp('{$name}')",

            'uuid' => fn() => "\$table->uuid('{$name}')",
            'ulid' => fn() => "\$table->ulid('{$name}')",

            'json' => fn() => "\$table->json('{$name}')",

            'enum' => fn() => "\$table->enum('{$name}', [" . collect($col['enum_options'] ?? [])->map(fn($v) => "'" . str_replace("'", "\\'", $v) . "'")->implode(', ') . "])",
            'set' => fn() => "\$table->set('{$name}', [" . collect($col['enum_options'] ?? [])->map(fn($v) => "'" . str_replace("'", "\\'", $v) . "'")->implode(', ') . "])",

            'foreignId' => fn() => "\$table->foreignId('{$name}')",
        ];

        $base = $map[$type] ?? fn() => "\$table->string('{$name}')";

        // Modifiers
        if (!empty($col['nullable'])) {
            $chain[] = 'nullable()';
        }

        if (array_key_exists('default', $col) && $col['default'] !== null && $col['default'] !== '') {
            $defaultValue = $col['default'];

            // Special handling for timestamp/datetime columns with CURRENT_TIMESTAMP or NOW()
            $currentTimestampFunctions = ['CURRENT_TIMESTAMP', 'NOW()'];
            if (in_array($type, ['timestamp', 'datetime']) && in_array(strtoupper($defaultValue), array_map('strtoupper', $currentTimestampFunctions))) {
                $chain[] = 'useCurrent()';
            } elseif ($type === 'date') {
                $dateFunctions = ['CURDATE()', 'CURRENT_DATE'];
                if (in_array(strtoupper($defaultValue), array_map('strtoupper', $dateFunctions))) {
                    $chain[] = "default(DB::raw('{$defaultValue}'))";
                } else {
                    $val = is_numeric($defaultValue) ? $defaultValue : "'" . str_replace("'", "\\'", (string)$defaultValue) . "'";
                    $chain[] = "default({$val})";
                }
            } else {
                $sqlFunctions = [
                    'CURRENT_TIME',
                    'UUID()',
                    'ULID()',
                    'CURTIME()'
                ];

                if (in_array(strtoupper($defaultValue), array_map('strtoupper', $sqlFunctions))) {
                    $chain[] = "default(DB::raw('{$defaultValue}'))";
                } else {
                    $val = is_numeric($defaultValue) ? $defaultValue : "'" . str_replace("'", "\\'", (string)$defaultValue) . "'";
                    $chain[] = "default({$val})";
                }
            }
        } elseif ($type === 'boolean' && $col['default_bool'] !== null) {
            $chain[] = "default(" . ($col['default_bool'] ? 'true' : 'false') . ")";
        }

        if (!empty($col['comment'])) {
            $chain[] = "comment('" . str_replace("'", "\\'", $col['comment']) . "')";
        }

        $line = $base();

        if (!empty($col['unique'])) {
            $chain[] = "unique()";
        }

        if (!empty($col['primary'])) {
            $chain[] = "primary()";
        }

        if ($type === 'foreignId' && !empty($col['references_table'])) {
            $fk = "->constrained('{$col['references_table']}'";
            if (!empty($col['references_column']) && $col['references_column'] !== 'id') {
                $fk .= ", '{$col['references_column']}'";
            }
            $fk .= ')';

            if (($col['on_update'] ?? null) === 'cascade') {
                $fk .= "->onUpdate('cascade')";
            } elseif (($col['on_update'] ?? null) === 'restrict') {
                $fk .= "->onUpdate('restrict')";
            } elseif (($col['on_update'] ?? null) === 'set_null') {
                $fk .= "->onUpdate('set null')";
            }

            if (($col['on_delete'] ?? null) === 'cascade') {
                $fk .= "->onDelete('cascade')";
            } elseif (($col['on_delete'] ?? null) === 'restrict') {
                $fk .= "->onDelete('restrict')";
            } elseif (($col['on_delete'] ?? null) === 'set_null') {
                $fk .= "->onDelete('set null')";
            }

            $chain[] = ltrim($fk, '->');
        }

        if (!empty($chain)) {
            $line .= '->' . implode('->', $chain);
        }

        if (!empty($col['index']) && $col['index'] === 'index') {
            $line .= "; // also: \$table->index('{$name}')";
        } elseif (!empty($col['index']) && $col['index'] === 'fulltext') {
            $line .= "; // also: \$table->fullText('{$name}')";
        }

        return $line;
    }

    /**
     * Mengembalikan daftar nama tabel user tanpa tabel sistem.
     *
     * Mengambil semua tabel dari database menggunakan Schema facade,
     * kemudian memfilter tabel-tabel sistem internal.
     *
     * @return array Array nama tabel user
     */
    public function listUserTables(): array
    {
        $names = Schema::getTableListing();

        $excluded = [
            'migrations',
            'failed_jobs',
            'password_reset_tokens',
            'password_resets',
            'personal_access_tokens',
            'cache',
            'jobs',
            'job_batches',
            'admin_audit_logs',
            'dynamic_tables',
            'permissions',
            'roles',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'schema_changes',
        ];

        return array_values(array_filter($names, fn($t) => !in_array($t, $excluded, true)));
    }
}
