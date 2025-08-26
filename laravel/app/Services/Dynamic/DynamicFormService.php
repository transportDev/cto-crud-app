<?php

namespace App\Services\Dynamic;

use Filament\Forms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DynamicFormService
 *
 * Builds Filament form components and Laravel validation rules from schema metadata.
 * - Respects PK immutability and auto-increment on create
 * - Provides safe FK async selects using configurable label columns and bounded queries
 */
class DynamicFormService
{
    public function __construct(
        protected DynamicSchemaService $schema
    ) {}

    public function buildForm(string $table, bool $isEdit, bool $forView = false): array
    {
        $cols = $this->schema->columns($table);
        $pk = $this->schema->primaryKey($table);
        $components = [];

        foreach ($cols as $name => $meta) {
            if (in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)) continue;

            $type = $meta['type'] ?? 'string';
            $nullable = (bool)($meta['nullable'] ?? false);
            $length = $meta['length'] ?? null;

            if (!$isEdit && $name === $pk && $this->schema->isPrimaryAutoIncrement($table)) {
                continue; // hide auto-inc PK on create
            }

            if ($name === $pk && $isEdit) {
                $component = Forms\Components\TextInput::make($name)->disabled()->dehydrated(false)->label(Str::headline($name));
                $components[] = $component;
                continue;
            }

            $component = match (true) {
                in_array($type, ['text', 'mediumText', 'longText'], true) => Forms\Components\Textarea::make($name)->rows(4),
                in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true) => Forms\Components\TextInput::make($name)->numeric(),
                in_array($type, ['decimal', 'float', 'double'], true) => Forms\Components\TextInput::make($name)->numeric(),
                $type === 'boolean' => Forms\Components\Toggle::make($name),
                in_array($type, ['date', 'time'], true) => Forms\Components\DateTimePicker::make($name)->withoutTime()->native(false),
                in_array($type, ['datetime', 'datetimetz', 'timestamp'], true) => Forms\Components\DateTimePicker::make($name)->native(false),
                $type === 'json' => Forms\Components\KeyValue::make($name)->addable()->deletable()->reorderable()->keyLabel('kunci')->valueLabel('nilai'),
                in_array($type, ['enum', 'set'], true) => Forms\Components\Select::make($name)
                    ->options(function () use ($meta) {
                        $opts = $meta['options'] ?? [];
                        return array_combine($opts, $opts);
                    })
                    ->multiple($type === 'set')->searchable(),
                default => Forms\Components\TextInput::make($name)->maxLength($length ?: 65535),
            };

            // FK async select when column looks like *_id and is not PK
            if ($this->looksLikeForeignKey($table, $name)) {
                $fkMap = $this->schema->foreignKeys($table);
                $refTable = $fkMap[$name]['referenced_table'] ?? Str::of($name)->beforeLast('_id')->snake()->plural()->toString();
                $refTable = $this->schema->sanitizeTable($refTable);
                $refPk = $fkMap[$name]['referenced_column'] ?? 'id';
                if ($refTable && Schema::hasTable($refTable)) {
                    // Ensure we use a valid PK column on the referenced table
                    if (!Schema::hasColumn($refTable, $refPk)) {
                        $refPk = $this->schema->primaryKey($refTable);
                    }
                    $label = $this->schema->bestSearchColumn($refTable);
                    $component = Forms\Components\Select::make($name)
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search) use ($refTable, $label, $refPk) {
                            return DB::table($refTable)
                                ->where($label, 'like', "%{$search}%")
                                ->orderBy($label)
                                ->limit((int)config('cto.fk_search_limit', 50))
                                ->pluck($label, $refPk);
                        })
                        ->getOptionLabelUsing(function ($value) use ($refTable, $label, $refPk) {
                            if ($value === null || $value === '') return null;
                            return DB::table($refTable)->where($refPk, $value)->value($label);
                        })
                        ->helperText("Referensi {$refTable}.{$label}");
                }
            }

            $component->label(Str::headline($name));

            if (!$nullable && !$forView) $component->required();
            if ($length && is_int($length) && !$forView && $component instanceof Forms\Components\TextInput) $component->maxLength($length);
            if ($forView) $component->disabled()->dehydrated(false);

            $components[] = $component;
        }

        return $components;
    }

    public function buildRules(string $table, bool $isEdit): array
    {
        $cols = $this->schema->columns($table);
        $pk = $this->schema->primaryKey($table);
        $fks = $this->schema->foreignKeys($table);
        $rules = [];
        foreach ($cols as $name => $meta) {
            if (in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)) continue;
            // On edit, never validate PK (it's disabled & not dehydrated in the form)
            if ($isEdit && $name === $pk) continue;
            if (!$isEdit && $name === $pk && $this->schema->isPrimaryAutoIncrement($table)) continue;

            $type = $meta['type'] ?? 'string';
            $nullable = (bool)($meta['nullable'] ?? false);
            $length = $meta['length'] ?? null;

            $colRules = [];
            $colRules[] = $nullable ? 'nullable' : 'required';
            if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) $colRules[] = 'integer';
            elseif (in_array($type, ['decimal', 'float', 'double'], true)) $colRules[] = 'numeric';
            elseif (in_array($type, ['date', 'time', 'datetime', 'datetimetz', 'timestamp'], true)) $colRules[] = 'date';
            elseif (in_array($type, ['json'], true)) $colRules[] = 'array';
            else {
                $colRules[] = 'string';
                if ($length && is_int($length)) $colRules[] = 'max:' . $length;
            }

            if ($this->looksLikeForeignKey($table, $name)) {
                $refTable = $fks[$name]['referenced_table'] ?? Str::of($name)->beforeLast('_id')->snake()->plural()->toString();
                $refTable = $this->schema->sanitizeTable($refTable);
                $refPk = $fks[$name]['referenced_column'] ?? 'id';
                if ($refTable && Schema::hasTable($refTable)) {
                    if (!Schema::hasColumn($refTable, $refPk)) {
                        $refPk = $this->schema->primaryKey($refTable);
                    }
                    if (Schema::hasColumn($refTable, $refPk)) {
                        $colRules[] = 'exists:' . $refTable . ',' . $refPk;
                    }
                }
            }
            $rules[$name] = $colRules;
        }
        return $rules;
    }

    protected function looksLikeForeignKey(string $table, string $column): bool
    {
        return Str::endsWith($column, '_id') && $column !== $this->schema->primaryKey($table);
    }
}
