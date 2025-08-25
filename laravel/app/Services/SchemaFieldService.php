<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SchemaFieldService
{
    /**
     * Analyze a proposed new field for an existing table and return:
     * - migration_php: Laravel migration snippet
     * - estimated_sql: MySQL 8 SQL approximation
     * - warnings: array of strings
     * - impact: 'safe' | 'risky'
     */
    public function analyze(string $table, array $col): array
    {
        $col = $col + [
            'type' => 'string',
            'length' => null,
            'precision' => null,
            'scale' => null,
            'nullable' => true,
            'default' => null,
            'default_bool' => null,
            'unique' => false,
            'index' => null, // null | 'index' | 'fulltext'
        ];

        $rowCount = 0;
        try {
            $rowCount = (int) DB::table($table)->count();
        } catch (\Throwable $e) {
            // ignore counting errors, assume 0 to avoid over-warning in unknown envs
        }

        $warnings = [];

        // NOT NULL without default and existing rows -> risky
        if (!$col['nullable'] && ($col['default'] === null && $col['default_bool'] === null) && $rowCount > 0) {
            $warnings[] = "New NOT NULL column without a default, table has {$rowCount} existing rows: writes will fail until backfilled.";
        }

        // UNIQUE on new column with non-null default and many rows -> risky
        if (!empty($col['unique']) && !$col['nullable'] && $col['default'] !== null && $rowCount > 1) {
            $warnings[] = 'UNIQUE with a non-null default can violate uniqueness on existing rows.';
        }

        // Foreign key basic warning (if type is foreignId)
        if (($col['type'] ?? null) === 'foreignId') {
            $warnings[] = 'Adding FK constraints can lock large tables; consider adding the column first, backfilling, then adding the constraint.';
        }

        [$migration, $sql] = $this->buildPreviews($table, $col);

        return [
            'migration_php' => $migration,
            'estimated_sql' => $sql,
            'warnings' => $warnings,
            'impact' => empty($warnings) ? 'safe' : 'risky',
        ];
    }

    protected function buildPreviews(string $table, array $c): array
    {
        $name = $c['name'];
        $type = $c['type'];

        $phpLine = $this->blueprintLine($c);

        $php = "Schema::table('{$table}', function (Blueprint $table) {\n    {$phpLine};\n";
        if (!empty($c['unique'])) {
            $php .= "    \$table->unique('{$name}');\n";
        } elseif (!empty($c['index']) && $c['index'] === 'index') {
            $php .= "    \$table->index('{$name}');\n";
        } elseif (!empty($c['index']) && $c['index'] === 'fulltext') {
            $php .= "    \$table->fullText('{$name}');\n";
        }
        $php .= '});';

        $sql = $this->estimatedSql($table, $c);

        return [$php, $sql];
    }

    protected function blueprintLine(array $c): string
    {
        $name = $c['name'];
        $type = $c['type'];
        $length = $c['length'] ?? null;
        $precision = $c['precision'] ?? null;
        $scale = $c['scale'] ?? null;
        $nullable = (bool) ($c['nullable'] ?? false);
        $default = $c['default'] ?? null;
        $defaultBool = $c['default_bool'] ?? null;

        $base = match ($type) {
            'string' => "\$table->string('{$name}'" . ($length ? ", {$length}" : '') . ")",
            'integer' => "\$table->integer('{$name}')",
            'bigInteger' => "\$table->bigInteger('{$name}')",
            'decimal' => "\$table->decimal('{$name}', " . ($precision ?: 10) . ", " . ($scale ?: 0) . ")",
            'boolean' => "\$table->boolean('{$name}')",
            'date' => "\$table->date('{$name}')",
            'datetime' => "\$table->dateTime('{$name}')",
            'json' => "\$table->json('{$name}')",
            'foreignId' => "\$table->foreignId('{$name}')",
            default => "\$table->string('{$name}')",
        };

        $chain = [];
        if ($nullable) {
            $chain[] = 'nullable()';
        }
        if ($default !== null && $default !== '') {
            $val = is_numeric($default) ? $default : "'" . str_replace("'", "\\'", (string) $default) . "'";
            $chain[] = "default({$val})";
        } elseif ($type === 'boolean' && $defaultBool !== null) {
            $chain[] = 'default(' . ($defaultBool ? 'true' : 'false') . ')';
        }

        return $base . (!empty($chain) ? '->' . implode('->', $chain) : '');
    }

    protected function estimatedSql(string $table, array $c): string
    {
        $name = $c['name'];
        $type = $c['type'];
        $length = $c['length'] ?? null;
        $precision = $c['precision'] ?? null;
        $scale = $c['scale'] ?? null;
        $nullable = (bool) ($c['nullable'] ?? false);
        $default = $c['default'] ?? null;
        $defaultBool = $c['default_bool'] ?? null;

        $colType = match ($type) {
            'string' => 'VARCHAR(' . ($length ?: 255) . ')',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'decimal' => 'DECIMAL(' . ($precision ?: 10) . ',' . ($scale ?: 0) . ')',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'json' => 'JSON',
            'foreignId' => 'BIGINT',
            default => 'VARCHAR(255)',
        };

        $nullSql = $nullable ? 'NULL' : 'NOT NULL';
        if ($type === 'boolean' && $defaultBool !== null) {
            $defSql = ' DEFAULT ' . ($defaultBool ? '1' : '0');
        } elseif ($default !== null && $default !== '') {
            $defSql = ' DEFAULT ' . (is_numeric($default) ? $default : "'" . addslashes((string)$default) . "'");
        } else {
            $defSql = '';
        }

        $lines = [
            "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$colType} {$nullSql}{$defSql};",
        ];

        if (!empty($c['unique'])) {
            $lines[] = "CREATE UNIQUE INDEX `{$table}_{$name}_unique` ON `{$table}` (`{$name}`);";
        } elseif (!empty($c['index']) && $c['index'] === 'index') {
            $lines[] = "CREATE INDEX `{$table}_{$name}_index` ON `{$table}` (`{$name}`);";
        } elseif (!empty($c['index']) && $c['index'] === 'fulltext') {
            $lines[] = "CREATE FULLTEXT INDEX `{$table}_{$name}_fulltext` ON `{$table}` (`{$name}`);";
        }

        return implode("\n", $lines);
    }
}
