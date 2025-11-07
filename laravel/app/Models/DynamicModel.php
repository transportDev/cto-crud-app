<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class DynamicModel extends Model
{
    protected $table;
    protected $guarded = [];
    public $timestamps = false;

    private const CACHE_DURATION = 3600;

    public function setRuntimeTable(string $table): static
    {
        $this->setTable($table);


        $tableInfo = $this->getTableInfo($table);


        $this->primaryKey = $tableInfo['primary_key'];
        $this->incrementing = $tableInfo['incrementing'];
        $this->keyType = $tableInfo['key_type'];
        $this->timestamps = $tableInfo['has_timestamps'];

        return $this;
    }


    public function newInstance($attributes = [], $exists = false)
    {
        $model = parent::newInstance($attributes, $exists);


        if ($this->table) {
            $model->setTable($this->table);
            $model->primaryKey = $this->primaryKey;
            $model->incrementing = $this->incrementing;
            $model->keyType = $this->keyType;
            $model->timestamps = $this->timestamps;
        }

        return $model;
    }


    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = parent::newFromBuilder($attributes, $connection);


        if ($this->table && !$model->primaryKey) {
            $tableInfo = $this->getTableInfo($this->table);
            $model->primaryKey = $tableInfo['primary_key'];
            $model->incrementing = $tableInfo['incrementing'];
            $model->keyType = $tableInfo['key_type'];
            $model->timestamps = $tableInfo['has_timestamps'];
        }

        return $model;
    }


    protected function getTableInfo(string $table): array
    {
        $cacheKey = 'table_info_' . config('database.default') . '_' . $table;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($table) {
            $primaryKey = $this->detectPrimaryKeyFromDatabase($table);

            return [
                'primary_key' => $primaryKey,
                'incrementing' => $this->detectIfIncrementing($table, $primaryKey),
                'key_type' => $this->detectKeyType($table, $primaryKey),
                'has_timestamps' => $this->detectTimestamps($table),
            ];
        });
    }


    protected function detectPrimaryKeyFromDatabase(string $table): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        try {
            if (in_array($connection, ['mysql', 'mariadb'])) {
                $result = DB::select("
                    SELECT COLUMN_NAME 
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = ? 
                    AND CONSTRAINT_NAME = 'PRIMARY'
                    ORDER BY ORDINAL_POSITION
                    LIMIT 1
                ", [$database, $table]);

                if (!empty($result)) {
                    $columnName = $result[0]->COLUMN_NAME ?? $result[0]->column_name ?? null;
                    if ($columnName) {
                        return $columnName;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to detect primary key for table {$table}: " . $e->getMessage());
        }


        return $this->detectPrimaryKeyByConvention($table);
    }

    protected function detectPrimaryKeyByConvention(string $table): string
    {

        $patterns = [
            'id',
            $table . '_id',
            Str::singular($table) . '_id',
            Str::snake(Str::singular($table)) . '_id',
            'record_id',
        ];

        foreach ($patterns as $pattern) {
            if (Schema::hasColumn($table, $pattern)) {
                return $pattern;
            }
        }


        $columns = Schema::getColumnListing($table);
        return $columns[0] ?? 'id';
    }

    protected function detectIfIncrementing(string $table, string $primaryKey): bool
    {
        try {
            $connection = config('database.default');

            if (in_array($connection, ['mysql', 'mariadb'])) {
                $result = DB::select("
                    SELECT COLUMN_NAME, DATA_TYPE, EXTRA 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = ? 
                    AND COLUMN_NAME = ?
                ", [config("database.connections.{$connection}.database"), $table, $primaryKey]);

                if (!empty($result)) {
                    $column = $result[0];
                    $extra = strtolower($column->EXTRA ?? $column->extra ?? '');
                    return str_contains($extra, 'auto_increment');
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to detect if primary key is incrementing for {$table}: " . $e->getMessage());
        }


        return !in_array($this->detectKeyType($table, $primaryKey), ['string', 'uuid']);
    }

    protected function detectKeyType(string $table, string $primaryKey): string
    {
        try {
            $connection = config('database.default');

            if (in_array($connection, ['mysql', 'mariadb'])) {
                $result = DB::select("
                    SELECT DATA_TYPE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = ? 
                    AND COLUMN_NAME = ?
                ", [config("database.connections.{$connection}.database"), $table, $primaryKey]);

                if (!empty($result)) {
                    $dataType = strtolower($result[0]->DATA_TYPE ?? $result[0]->data_type ?? '');

                    if (in_array($dataType, ['varchar', 'char', 'text', 'uuid', 'guid'])) {
                        return 'string';
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to detect key type for {$table}: " . $e->getMessage());
        }

        return 'int';
    }

    protected function detectTimestamps(string $table): bool
    {
        return Schema::hasColumn($table, 'created_at')
            && Schema::hasColumn($table, 'updated_at');
    }

    public static function clearTableInfoCache(?string $table = null): void
    {
        if ($table) {
            Cache::forget('table_info_' . config('database.default') . '_' . $table);
            Cache::forget('pk_' . config('database.default') . '_' . $table);
        }
    }
}
