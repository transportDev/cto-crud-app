<?php

namespace App\Services;

use App\Models\CtoTableMeta;
use App\Services\Dynamic\DynamicSchemaService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchemaWizardService
{
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
            } else { // relation
                $col = [
                    'name' => $i['name'],
                    'type' => 'foreignId',
                    'nullable' => (bool)($i['nullable'] ?? true),
                ];
                $fieldRes = $fieldSvc->analyze($table, $col);
                $phpLines[] = '    ' . "\$table->foreignId('{$i['name']}')" . (($i['nullable'] ?? true) ? '->nullable()' : '') . ';' . "\n" .
                    '    ' . "\$table->foreign('{$i['name']}')->references('" . ($i['references_column'] ?? 'id') . "')->on('{$i['references_table']}')" .
                    ($i['on_update'] ? "->onUpdate('{$i['on_update']}')" : '') .
                    ($i['on_delete'] ? "->onDelete('{$i['on_delete']}')" : '') . ';';
                $sqlLines[] = "ALTER TABLE `{$table}` ADD COLUMN `{$i['name']}` BIGINT " . (($i['nullable'] ?? true) ? 'NULL' : 'NOT NULL') . ";\n" .
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$i['name']}_fk` FOREIGN KEY (`{$i['name']}`) REFERENCES `{$i['references_table']}` (`" . ($i['references_column'] ?? 'id') . "')" .
                    ($i['on_update'] ? " ON UPDATE {$i['on_update']}" : '') .
                    ($i['on_delete'] ? " ON DELETE {$i['on_delete']}" : '') . ';';
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

    public function generateMigration(string $table, array $items): string
    {
        $fs = new Filesystem();
        $dir = base_path('database/migrations');
        if (!$fs->exists($dir)) {
            $fs->makeDirectory($dir, 0755, true);
        }

        $ts = now()->format('Y_m_d_His');
        $name = "{$ts}_wizard_update_{$table}.php";
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        $body = $this->buildMigrationBody($table, $items);
        $fs->put($path, $body);
        return $name;
    }

    protected function buildMigrationBody(string $table, array $items): string
    {
        $linesUp = [];
        $linesDown = [];

        foreach ($items as $i) {
            if (($i['kind'] ?? 'field') === 'field') {
                $svc = app(SchemaFieldService::class);
                $linesUp[] = '            ' . $svcMethod = (new class($svc) {
                    public function __construct(private SchemaFieldService $s) {}
                    public function line(array $c)
                    {
                        return (new \ReflectionClass($this->s))->getMethod('blueprintLine')->invoke($this->s, $c);
                    }
                })->line($i) . ';';
                $linesDown[] = "            \$table->dropColumn('{$i['name']}');";
                if (!empty($i['unique'])) {
                    $linesUp[] = "            \$table->unique('{$i['name']}');";
                } elseif (!empty($i['index']) && $i['index'] === 'index') {
                    $linesUp[] = "            \$table->index('{$i['name']}');";
                } elseif (!empty($i['index']) && $i['index'] === 'fulltext') {
                    $linesUp[] = "            \$table->fullText('{$i['name']}');";
                }
            } else { // relation
                $nullable = (bool)($i['nullable'] ?? true);
                $linesUp[] = '            ' . "\$table->foreignId('{$i['name']}')" . ($nullable ? '->nullable()' : '') . ';';
                $fk = "            \$table->foreign('{$i['name']}')->references('" . ($i['references_column'] ?? 'id') . "')->on('{$i['references_table']}')";
                if (!empty($i['on_update'])) {
                    $fk .= "->onUpdate('{$i['on_update']}')";
                }
                if (!empty($i['on_delete'])) {
                    $fk .= "->onDelete('{$i['on_delete']}')";
                }
                $fk .= ';';
                $linesUp[] = $fk;
                $linesDown[] = "            \$table->dropForeign(['{$i['name']}']);\n            \$table->dropColumn('{$i['name']}');";
            }
        }

        $template = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('%TABLE%', function (Blueprint $table) {
%UP%
        });
    }

    public function down(): void
    {
        Schema::table('%TABLE%', function (Blueprint $table) {
%DOWN%
        });
    }
};
PHP;

        return str_replace(['%TABLE%', '%UP%', '%DOWN%'], [
            $table,
            implode("\n", $linesUp) ?: '            // no-op',
            implode("\n", $linesDown) ?: '            // no-op',
        ], $template);
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Apply schema changes directly to the database without creating migration files.
     * Better for admin tools - immediate feedback, no duplicate migration issues.
     */
    public function applyDirectChanges(string $table, array $items): array
    {
        $applied = [];
        $errors = [];

        // Note: MySQL performs implicit commits for many DDL operations (e.g. ALTER TABLE),
        // so wrapping these in a DB::transaction() can yield misleading errors such as
        // "There is no active transaction" even when the change succeeded. Instead we
        // apply changes sequentially with defensive idempotent checks and compensating
        // cleanup to avoid leaving partial state on failure.
        foreach ($items as $i) {
            try {
                if (($i['kind'] ?? 'field') === 'field') {
                    $this->addFieldDirectly($table, $i);
                    $applied[] = "Added/verified field '{$i['name']}' on table '{$table}'";
                } else { // relation
                    $this->addRelationDirectly($table, $i);
                    $applied[] = "Added/verified foreign key '{$i['name']}' on table '{$table}'";

                    // Persist label columns (preferred) and search column for the referenced table
                    $refTable = $i['references_table'] ?? null;
                    if ($refTable) {
                        try {
                            $payload = [];
                            // Prefer storing explicit columns; generate template only for backward-compat reads
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
                                // default search column to first label column
                                $payload['search_column'] = (string) ($payload['display_template']['columns'][0] ?? null);
                            }

                            if (!empty($payload)) {
                                CtoTableMeta::query()->updateOrCreate(
                                    ['table_name' => $refTable],
                                    $payload
                                );
                            }
                        } catch (\Throwable $_e) {
                            // Non-fatal: metadata persistence errors should not block schema apply
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Failed to apply '{$i['name']}': " . $e->getMessage();
            }
        }

        // Invalidate schema cache and repopulate meta so UI reflects changes immediately
        try {
            $schemaSvc = app(DynamicSchemaService::class);
            $schemaSvc->invalidateTableCache($table);
            $schemaSvc->populateMetaForTable($table);
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'success' => empty($errors),
            'applied' => $applied,
            'errors' => $errors,
        ];
    }

    protected function addFieldDirectly(string $table, array $field): void
    {
        // Skip if the column already exists (idempotent)
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, $field['name'])) {
            return; // already present
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

            // Build column based on type
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

            // Apply modifiers
            if ($nullable) {
                $column->nullable();
            }

            // Handle defaults
            if ($default !== null && $default !== '') {
                $column->default($default);
            } elseif ($type === 'boolean' && $defaultBool !== null) {
                $column->default($defaultBool);
            }

            // Apply indexes
            if (!empty($field['unique'])) {
                $table->unique($name);
            } elseif (!empty($field['index']) && $field['index'] === 'index') {
                $table->index($name);
            } elseif (!empty($field['index']) && $field['index'] === 'fulltext') {
                $table->fullText($name);
            }
        });
    }

    protected function addRelationDirectly(string $table, array $relation): void
    {
        $column = $relation['name'];
        $nullable = (bool)($relation['nullable'] ?? true);
        $refTable = $relation['references_table'];
        $refColumn = $relation['references_column'] ?? 'id';

        $createdColumn = false;

        // 1) Ensure column exists
        if (!\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
            \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($column, $nullable) {
                $col = $t->foreignId($column);
                if ($nullable) {
                    $col->nullable();
                }
            });
            $createdColumn = true;
        }

        // 2) Ensure the FK constraint exists
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
                // Best-effort: invalidate cache for both tables (source + referenced)
                try {
                    $schemaSvc = app(DynamicSchemaService::class);
                    $schemaSvc->invalidateTableCache($table);
                    $schemaSvc->invalidateTableCache($refTable);
                } catch (\Throwable $_) {
                }
            } catch (\Throwable $e) {
                // Compensating action: drop the newly created column if FK creation failed
                if ($createdColumn) {
                    try {
                        \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($column) {
                            // dropForeign if any was partially added (defensive)
                            try {
                                $t->dropForeign([$column]);
                            } catch (\Throwable $_) {
                            }
                            $t->dropColumn($column);
                        });
                    } catch (\Throwable $_) {
                        // ignore cleanup failure
                    }
                }
                throw $e;
            }
        }
    }

    /**
     * Check if a foreign key exists for the given table+column.
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
            // If the metadata query fails, be conservative and return false so we attempt to add it.
            return false;
        }
    }
}
