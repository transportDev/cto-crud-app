<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Service untuk menganalisis dan membangun preview perubahan skema database.
 *
 * Service ini menyediakan fungsionalitas untuk:
 * - Menganalisis field baru yang akan ditambahkan ke tabel existing
 * - Menghasilkan snippet migration Laravel
 * - Menghasilkan perkiraan SQL untuk MySQL 8
 * - Mendeteksi potensi masalah dan risiko (warnings)
 * - Menentukan impact level: 'safe' atau 'risky'
 * - Menangani berbagai tipe data dan constraint (unique, index, fulltext)
 *
 * @package App\Services
 */
class SchemaFieldService
{
    /**
     * Menganalisis field baru yang diusulkan untuk tabel existing.
     *
     * Method ini akan:
     * - Memvalidasi konfigurasi field (type, nullable, default, unique, index)
     * - Menghitung jumlah row existing untuk analisis risiko
     * - Mendeteksi potensi masalah:
     *   1. NOT NULL tanpa default pada tabel berisi data
     *   2. UNIQUE dengan default value pada tabel berisi multiple rows
     *   3. Foreign key pada tabel besar yang bisa lock table
     * - Menghasilkan migration PHP dan SQL preview
     * - Menentukan impact level berdasarkan warnings
     *
     * @param string $table Nama tabel yang akan ditambahkan field
     * @param array<string, mixed> $col Konfigurasi kolom dengan keys:
     *        - name (string): Nama kolom
     *        - type (string): Tipe data (string, integer, bigInteger, decimal, boolean, date, datetime, json, foreignId)
     *        - length (int|null): Panjang untuk tipe string
     *        - precision (int|null): Precision untuk decimal
     *        - scale (int|null): Scale untuk decimal
     *        - nullable (bool): Apakah kolom boleh NULL
     *        - default (mixed): Nilai default
     *        - default_bool (bool|null): Default untuk boolean
     *        - unique (bool): Apakah unique constraint
     *        - index (string|null): Tipe index ('index', 'fulltext', atau null)
     *
     * @return array{
     *     migration_php: string,
     *     estimated_sql: string,
     *     warnings: array<int, string>,
     *     impact: string
     * } Array berisi:
     *     - migration_php: Snippet migration Laravel
     *     - estimated_sql: Perkiraan SQL untuk MySQL 8
     *     - warnings: Array peringatan dalam Bahasa Indonesia
     *     - impact: 'safe' atau 'risky'
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
            'index' => null,
        ];

        $rowCount = 0;
        try {
            $rowCount = (int) DB::table($table)->count();
        } catch (\Throwable $e) {
        }

        $warnings = [];

        if (!$col['nullable'] && ($col['default'] === null && $col['default_bool'] === null) && $rowCount > 0) {
            $warnings[] = "Kolom '{$col['name']}' diwajibkan (NOT NULL) tapi tidak punya nilai default. Ini akan menyebabkan error saat menyimpan data baru jika kolom ini tidak diisi. Pertimbangkan untuk memberikan nilai default atau mengizinkan NULL.";
        }

        if (!empty($col['unique']) && !$col['nullable'] && $col['default'] !== null && $rowCount > 1) {
            $warnings[] = "Kolom '{$col['name']}' memiliki batasan unik (UNIQUE) dan nilai default. Jika ada lebih dari satu baris data, ini akan langsung menyebabkan error karena nilai default yang sama. Sebaiknya tambahkan kolom ini sebagai nullable, isi data unik untuk setiap baris, baru tambahkan batasan unik.";
        }

        if (($col['type'] ?? null) === 'foreignId') {
            $warnings[] = "Menambah foreign key pada tabel besar bisa mengunci tabel untuk sementara. Pertimbangkan menambah kolom terlebih dahulu, mengisi datanya, baru menambahkan constraint foreign key.";
        }

        [$migration, $sql] = $this->buildPreviews($table, $col);

        return [
            'migration_php' => $migration,
            'estimated_sql' => $sql,
            'warnings' => $warnings,
            'impact' => empty($warnings) ? 'safe' : 'risky',
        ];
    }

    /**
     * Membangun preview migration PHP dan SQL untuk field baru.
     *
     * Method ini akan:
     * - Menghasilkan snippet Laravel Blueprint
     * - Menambahkan constraint unique/index/fulltext jika ada
     * - Menghasilkan perkiraan SQL untuk MySQL 8
     *
     * @param string $table Nama tabel
     * @param array<string, mixed> $c Konfigurasi kolom
     *
     * @return array{0: string, 1: string} Tuple [migration_php, estimated_sql]
     */
    protected function buildPreviews(string $table, array $c): array
    {
        $name = $c['name'];
        $type = $c['type'];

        $phpLine = $this->blueprintLine($c);

        $php = "Schema::table('{$table}', function (Blueprint \$table) {\n    {$phpLine};\n";
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

    /**
     * Menghasilkan baris blueprint Laravel untuk definisi kolom.
     *
     * Method ini akan:
     * - Membuat method call Blueprint sesuai tipe data
     * - Menambahkan chain method untuk nullable, default
     * - Menangani escape string untuk default value
     * - Menangani default boolean secara khusus
     *
     * @param array<string, mixed> $c Konfigurasi kolom
     *
     * @return string Baris blueprint PHP (contoh: "$table->string('name', 255)->nullable()->default('test')")
     */
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

    /**
     * Menghasilkan perkiraan SQL untuk MySQL 8.
     *
     * Method ini akan:
     * - Mengkonversi tipe Laravel ke tipe MySQL
     * - Menghasilkan ALTER TABLE statement dengan ADD COLUMN
     * - Menambahkan NULL/NOT NULL constraint
     * - Menambahkan DEFAULT value jika ada
     * - Menghasilkan CREATE INDEX statement untuk unique/index/fulltext
     *
     * Note: Ini adalah perkiraan untuk MySQL 8, hasil aktual mungkin berbeda
     * tergantung dialect SQL dan versi database.
     *
     * @param string $table Nama tabel
     * @param array<string, mixed> $c Konfigurasi kolom
     *
     * @return string SQL statement untuk menambahkan kolom dan index
     */
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
