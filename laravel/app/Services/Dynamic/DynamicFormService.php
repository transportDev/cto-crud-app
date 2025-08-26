<?php

namespace App\Services\Dynamic;

use App\Models\CtoTableMeta;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

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

            // FK handling when column looks like *_id and is not PK
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
                    // Resolve label strategy
                    $meta = null;
                    $displayTemplate = null;
                    $searchCol = null;
                    $labelCol = null;
                    $labelCols = [];
                    try {
                        $meta = CtoTableMeta::query()->where('table_name', $refTable)->first();
                        if ($meta) {
                            $displayTemplate = is_array($meta->display_template) ? $meta->display_template : null;
                            $labelCols = isset($displayTemplate['columns']) && is_array($displayTemplate['columns']) ? $displayTemplate['columns'] : [];
                            $searchCol = $meta->search_column ?: null;
                            $labelCol = $meta->label_column ?: null;
                        }
                    } catch (\Throwable $e) { /* ignore */
                    }
                    // If creating, embed inputs instead of showing raw FK
                    if (!$isEdit) {
                        $cols = !empty($labelCols) ? $labelCols : array_filter([$labelCol ?: $this->schema->guessLabelColumn($refTable)]);
                        $groupFields = [];
                        foreach ($cols as $c) {
                            if (!Schema::hasColumn($refTable, $c)) continue;
                            $groupFields[] = Forms\Components\TextInput::make('fk_new__' . $name . '__' . $refTable . '__' . $c)
                                ->label(Str::headline($c))
                                ->required(!$nullable && !$forView)
                                ->disabled($forView)
                                ->dehydrated(true);
                        }
                        if (!empty($groupFields)) {
                            // Use FK column name as context label to differentiate multiple FKs to the same table
                            $components[] = Forms\Components\Fieldset::make($this->humanizeFkLabel($name))
                                ->schema($groupFields)
                                ->columns(min(2, count($groupFields)));
                            continue; // skip default FK component
                        }
                    }
                    // Default: searchable select with inline create
                    // Determine display column fallback (label_column -> guessLabelColumn -> pk)
                    $displayCol = $labelCol && Schema::hasColumn($refTable, $labelCol) ? $labelCol : ($this->schema->guessLabelColumn($refTable) ?: $this->schema->primaryKey($refTable));
                    // Determine search column
                    $search = $searchCol && Schema::hasColumn($refTable, $searchCol) ? $searchCol : $this->schema->bestSearchColumn($refTable);
                    $component = Forms\Components\Select::make($name)
                        ->searchable()
                        ->getSearchResultsUsing(function (string $searchTerm) use ($refTable, $search, $displayCol, $refPk, $displayTemplate, $labelCols) {
                            $limit = (int)config('cto.fk_search_limit', 50);
                            if (!empty($labelCols)) {
                                $cols = array_unique(array_merge([$refPk], (array)$labelCols));
                                $query = DB::table($refTable)->select($cols);
                                if (Schema::hasColumn($refTable, $search)) {
                                    $query->where($search, 'like', "%{$searchTerm}%");
                                }
                                $rows = $query->limit($limit)->get();
                                $out = [];
                                foreach ($rows as $r) {
                                    $rowArr = (array)$r;
                                    $rendered = null;
                                    if ($displayTemplate && !empty($displayTemplate['template'])) {
                                        $rendered = app(DynamicSchemaService::class)->renderTemplateLabel($displayTemplate, $rowArr);
                                    }
                                    if ($rendered === null || $rendered === '') {
                                        $vals = [];
                                        foreach ($labelCols as $c) {
                                            $vals[] = (string)($rowArr[$c] ?? '');
                                        }
                                        $rendered = trim(implode(' - ', $vals));
                                    }
                                    if ($rendered === '') {
                                        $rendered = ($rowArr[$displayCol] ?? $rowArr[$refPk] ?? null);
                                    }
                                    if ($rendered !== null) {
                                        $out[$rowArr[$refPk]] = $rendered;
                                    }
                                }
                                return $out;
                            }
                            $q = DB::table($refTable);
                            if (Schema::hasColumn($refTable, $search)) {
                                $q->where($search, 'like', "%{$searchTerm}%");
                            }
                            if (Schema::hasColumn($refTable, $displayCol)) {
                                $q->orderBy($displayCol);
                                return $q->limit($limit)->pluck($displayCol, $refPk);
                            }
                            return $q->limit($limit)->pluck($refPk, $refPk);
                        })
                        ->getOptionLabelUsing(function ($value) use ($refTable, $displayCol, $search, $refPk, $displayTemplate, $labelCols) {
                            if ($value === null || $value === '') return null;
                            if (!empty($labelCols)) {
                                $cols = array_unique(array_merge([$refPk], (array)$labelCols));
                                $row = DB::table($refTable)->select($cols)->where($refPk, $value)->first();
                                if ($row) {
                                    $rowArr = (array)$row;
                                    $labelStr = null;
                                    if ($displayTemplate && !empty($displayTemplate['template'])) {
                                        $labelStr = app(DynamicSchemaService::class)->renderTemplateLabel($displayTemplate, $rowArr);
                                    }
                                    if ($labelStr === null || $labelStr === '') {
                                        $vals = [];
                                        foreach ($labelCols as $c) {
                                            $vals[] = (string)($rowArr[$c] ?? '');
                                        }
                                        $labelStr = trim(implode(' - ', $vals));
                                    }
                                    if ($labelStr !== '') return $labelStr;
                                }
                            }
                            if (Schema::hasColumn($refTable, $displayCol)) {
                                return DB::table($refTable)->where($refPk, $value)->value($displayCol);
                            }
                            return (string) $value;
                        })
                        ->helperText("Sumber: {$refTable}." . (Schema::hasColumn($refTable, $displayCol) ? $displayCol : $refPk) . " — Ketik untuk mencari, pilih salah satu.");
                }
            }

            // Prefer humanized FK label for *_id columns, otherwise default to column name
            if ($this->looksLikeForeignKey($table, $name)) {
                $component->label($this->humanizeFkLabel($name));
            } else {
                $component->label(Str::headline($name));
            }

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
                    // If creating and using embedded FK fields (label columns), do not require/exists on FK itself
                    if (!$isEdit) {
                        try {
                            $metaCfg = CtoTableMeta::query()->where('table_name', $refTable)->first();
                            $display = is_array($metaCfg->display_template ?? null) ? $metaCfg->display_template : null;
                            $labelCols = isset($display['columns']) && is_array($display['columns']) ? $display['columns'] : [];
                            if (!empty($labelCols)) {
                                // Relax FK rule, it'll be set after validation from embedded fields
                                $colRules = ['nullable'];
                                // Add simple rules for each embedded field
                                foreach ($labelCols as $lc) {
                                    $rules['fk_new__' . $name . '__' . $refTable . '__' . $lc] = ['required'];
                                }
                            } else {
                                if (Schema::hasColumn($refTable, $refPk)) {
                                    $colRules[] = 'exists:' . $refTable . ',' . $refPk;
                                }
                            }
                        } catch (\Throwable $e) {
                            if (Schema::hasColumn($refTable, $refPk)) {
                                $colRules[] = 'exists:' . $refTable . ',' . $refPk;
                            }
                        }
                    } else {
                        if (Schema::hasColumn($refTable, $refPk)) {
                            $colRules[] = 'exists:' . $refTable . ',' . $refPk;
                        }
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

    /**
     * Turn an FK column like `regional_fe_id` into a friendly label like `Regional FE`.
     * - Strips trailing _id
     * - Converts snake_case to Headline
     * - Uppercases common abbreviations (FE, IP, URL, ID)
     */
    protected function humanizeFkLabel(string $fkColumn): string
    {
        $base = Str::of($fkColumn)->beforeLast('_id')->replace('_', ' ')->headline()->toString();
        // Uppercase common abbreviations when they appear as separate tokens
        $abbrs = ['Fe' => 'FE', 'Ip' => 'IP', 'Url' => 'URL', 'Id' => 'ID', 'Sku' => 'SKU', 'Imei' => 'IMEI'];
        foreach ($abbrs as $needle => $upper) {
            $base = preg_replace('/\b' . preg_quote($needle, '/') . '\b/u', $upper, $base);
        }
        return trim($base);
    }
}
