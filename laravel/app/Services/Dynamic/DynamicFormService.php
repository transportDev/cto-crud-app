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
                        $meta = CtoTableMeta::where('table_name', $refTable)->first();
                        $displayTemplate = $meta?->display_template;
                        $searchCol = $meta?->search_column;
                        $labelCol = $meta?->label_column ?? $this->schema->guessLabelColumn($refTable);
                        if ($displayTemplate && !empty($displayTemplate['columns'])) {
                            $labelCols = $displayTemplate['columns'];
                        }
                    } catch (\Throwable $e) { /* ignore */
                    }

                    // Embedded form for creating new FK record
                    if (!empty($labelCols)) {
                        $embeddedInputs = [];
                        foreach ($labelCols as $c) {
                            $cMeta = $this->schema->columns($refTable)[$c] ?? null;
                            if (!$cMeta) continue;
                            
                            $inputKey = 'fk_new__' . $name . '__' . $refTable . '__' . $c;

                            // Each field from the related table becomes a searchable, creatable select
                            $input = Forms\Components\Select::make($inputKey)
                                ->label(Str::headline($c))
                                ->options(function() use ($refTable, $c) {
                                    // Provide existing distinct values for this column as options
                                    return DB::table($refTable)->distinct()->pluck($c, $c);
                                })
                                ->searchable()
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('value')->label('New Value')->required(),
                                ])
                                ->createOptionUsing(fn (array $data) => $data['value']);

                            $embeddedInputs[] = $input;
                        }
                        $components[] = Forms\Components\Fieldset::make($this->humanizeFkLabel($name))
                            ->schema($embeddedInputs)
                            ->columns(count($embeddedInputs) > 1 ? 2 : 1);
                        continue; // Skip default component creation at the end
                    }
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
