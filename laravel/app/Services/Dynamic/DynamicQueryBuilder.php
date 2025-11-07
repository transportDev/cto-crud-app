<?php

namespace App\Services\Dynamic;

use App\Models\DynamicModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service untuk membangun query runtime yang aman untuk Dynamic CRUD.
 *
 * Service ini menyediakan fungsionalitas untuk:
 * - Membangun query berdasarkan kolom terpilih dan metadata foreign key
 * - Menggunakan DynamicSchemaService untuk whitelisting dan metadata
 * - Menerapkan LEFT JOIN dengan tabel beralias untuk kolom display FK
 * - Menerapkan filter soft-deletes secara default ketika kolom exists
 * - Membatasi result set melalui paginasi
 * - Menghindari select * dengan hanya mengizinkan base table.* dan kolom FK eksplisit
 * - Memvalidasi semua join untuk mencegah join spoofing
 *
 * @package App\Services\Dynamic
 */
class DynamicQueryBuilder
{
    /**
     * Membuat instance service dengan dependency injection.
     *
     * @param DynamicSchemaService $schema Service untuk menangani operasi skema database
     */
    public function __construct(
        protected DynamicSchemaService $schema
    ) {}

    /**
     * Membangun Eloquent Builder untuk tabel dan kunci yang dipilih.
     *
     * Method ini akan:
     * - Memvalidasi dan sanitasi nama tabel
     * - Membuat base select dengan semua kolom tabel
     * - Memproses foreign key yang dipilih
     * - Menerapkan LEFT JOIN untuk setiap FK dengan validasi ketat
     * - Memvalidasi FK exists pada tabel base
     * - Memvalidasi referenced table cocok dengan metadata
     * - Memvalidasi referenced column exists pada referenced table
     * - Menerapkan filter soft-deletes jika kolom deleted_at ada
     * - Menggunakan alias untuk join dan kolom untuk menghindari konflik
     *
     * Format selectedKeys:
     * - 'self:col' untuk kolom tabel sendiri
     * - 'fk:fk_col:ref_table:ref_col' untuk kolom dari tabel foreign key
     *
     * Contoh:
     * - 'self:name'
     * - 'fk:user_id:users:name'
     *
     * @param string|null $table Nama tabel yang akan di-query
     * @param array<int, string> $selectedKeys Array kunci kolom yang dipilih
     * 
     * @return Builder Eloquent Builder yang telah dikonfigurasi dengan join dan filter
     */
    public function build(?string $table, array $selectedKeys): Builder
    {
        $safeTable = $this->schema->sanitizeTable($table);
        $model = new DynamicModel();
        $model->setRuntimeTable($safeTable ?: 'users');
        $builder = $model->newQuery();

        if (!$safeTable) {
            return $builder->whereRaw('1 = 0');
        }

        $selects = [$safeTable . '.*'];

        $fkMap = $this->schema->foreignKeys($safeTable);
        $joined = [];
        foreach ($selectedKeys as $key) {
            if (!str_starts_with($key, 'fk:')) continue;
            [, $fkCol, $refTable, $refCol] = explode(':', $key, 4);
            if (!isset($fkMap[$fkCol])) continue;
            $refTable = $this->schema->sanitizeTable($refTable);
            if (!$refTable) continue;
            if (($fkMap[$fkCol]['referenced_table'] ?? null) !== $refTable) continue;
            $refCols = array_keys($this->schema->columns($refTable));
            if (!in_array($refCol, $refCols, true)) continue;

            $refPk = $fkMap[$fkCol]['referenced_column'] ?? 'id';
            $joinAlias = $this->joinAlias($fkCol, $refTable);
            if (!isset($joined[$joinAlias])) {
                $builder->leftJoin($refTable . ' as ' . $joinAlias, $joinAlias . '.' . $refPk, '=', $safeTable . '.' . $fkCol);
                $joined[$joinAlias] = true;
            }
            $aliasCol = $this->columnAlias($fkCol, $refTable, $refCol);
            $selects[] = DB::raw("{$joinAlias}.`{$refCol}` as `{$aliasCol}`");
        }

        $builder->select($selects);

        if ($this->schema->hasDeletedAt($safeTable)) {
            $builder->whereNull($safeTable . '.deleted_at');
        }

        return $builder;
    }

    /**
     * Membuat alias untuk tabel yang di-join.
     *
     * Alias dibuat dengan format: {refTable}__{fkCol}
     * Ini memastikan tidak ada konflik nama ketika tabel yang sama
     * di-join beberapa kali melalui foreign key yang berbeda.
     *
     * Contoh: users__created_by_id, users__updated_by_id
     *
     * @param string $fkCol Nama kolom foreign key pada tabel base
     * @param string $refTable Nama tabel yang direferensikan
     * 
     * @return string Alias untuk join table
     */
    public function joinAlias(string $fkCol, string $refTable): string
    {
        return $refTable . '__' . $fkCol;
    }

    /**
     * Membuat alias untuk kolom foreign key dalam result set.
     *
     * Alias dibuat dengan format: fk_{fkCol}__{refTable}__{refCol}
     * Ini memastikan nama kolom unik dalam result set dan mudah diidentifikasi
     * sebagai kolom dari relasi foreign key.
     *
     * Contoh: fk_user_id__users__name, fk_category_id__categories__title
     *
     * @param string $fkCol Nama kolom foreign key pada tabel base
     * @param string $refTable Nama tabel yang direferensikan
     * @param string $refCol Nama kolom pada tabel yang direferensikan
     * 
     * @return string Alias untuk kolom dalam result set
     */
    public function columnAlias(string $fkCol, string $refTable, string $refCol): string
    {
        return 'fk_' . $fkCol . '__' . $refTable . '__' . $refCol;
    }
}
