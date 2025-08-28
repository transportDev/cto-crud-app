<?php

namespace App\Services\Dynamic;

use App\Models\DynamicModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DynamicQueryBuilder
 *
 * Builds safe runtime queries for Dynamic CRUD based on selected columns and FK metadata.
 * - Uses DynamicSchemaService for whitelisting and metadata
 * - Applies LEFT JOINs with aliased tables for selected FK display columns
 * - Enforces soft-deletes filter by default when column exists
 * - Limits result set via paginated usage; avoids select * by allowing base table.* only and explicit FK columns
 */
class DynamicQueryBuilder
{
    public function __construct(
        protected DynamicSchemaService $schema
    ) {}

    /**
     * Build an Eloquent Builder for the provided table and selectedKeys.
     * selectedKeys: [ 'self:col', 'fk:fk_col:ref_table:ref_col', ... ]
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

        // Base select
        $selects = [$safeTable . '.*'];

        // FK projections
        $fkMap = $this->schema->foreignKeys($safeTable);
        $joined = [];
        foreach ($selectedKeys as $key) {
            if (!str_starts_with($key, 'fk:')) continue;
            [, $fkCol, $refTable, $refCol] = explode(':', $key, 4);
            // Validate FK exists on base table
            if (!isset($fkMap[$fkCol])) continue;
            $refTable = $this->schema->sanitizeTable($refTable);
            if (!$refTable) continue;
            // Validate referenced table matches metadata to prevent join spoofing
            if (($fkMap[$fkCol]['referenced_table'] ?? null) !== $refTable) continue;
            // Validate referenced column exists on referenced table
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

    public function joinAlias(string $fkCol, string $refTable): string
    {
        return $refTable . '__' . $fkCol;
    }

    public function columnAlias(string $fkCol, string $refTable, string $refCol): string
    {
        return 'fk_' . $fkCol . '__' . $refTable . '__' . $refCol;
    }
}
