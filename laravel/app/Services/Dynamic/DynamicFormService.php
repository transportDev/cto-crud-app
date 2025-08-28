<?php

namespace App\Services\Dynamic;

use App\Models\CtoTableMeta;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
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

                    // Resolve template/columns/search from metadata and fallbacks
                    $meta = null;
                    $displayTemplate = null;
                    $searchCol = null;
                    $labelCol = null;
                    try {
                        $meta = CtoTableMeta::query()->where('table_name', $refTable)->first();
                        if ($meta) {
                            $displayTemplate = is_array($meta->display_template) ? $meta->display_template : null;
                            $searchCol = $meta->search_column ?: null;
                            $labelCol = $meta->label_column ?: null;
                        }
                    } catch (\Throwable $e) {
                    }

                    $compositeCols = $this->schema->labelColumns($refTable);
                    $displayCol = $labelCol && Schema::hasColumn($refTable, $labelCol)
                        ? $labelCol
                        : ($this->schema->guessLabelColumn($refTable) ?: $this->schema->primaryKey($refTable));
                    $searchColumn = $searchCol && Schema::hasColumn($refTable, $searchCol)
                        ? $searchCol
                        : $this->schema->bestSearchColumn($refTable);

                    // Determine if the FK column is integer or string type
                    $isIntegerType = in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true);

                    // Build Filament Select with search and preload
                    $component = Select::make($name)
                        ->label($this->humanizeFkLabel($name))
                        ->searchable()
                        ->preload()
                        ->options(function () use ($refTable, $refPk, $displayCol, $compositeCols, $isIntegerType) {
                            $limit = (int) config('cto.fk_search_limit', 50);
                            $cols = array_unique(array_merge([$refPk], $compositeCols ?: [$displayCol]));
                            $q = DB::table($refTable)->select($cols);
                            if (Schema::hasColumn($refTable, $displayCol)) {
                                $q->orderBy($displayCol);
                            }
                            $rows = $q->limit($limit)->get();
                            $out = [];
                            foreach ($rows as $r) {
                                $rowArr = (array) $r;
                                // Ensure the key matches the expected type
                                $key = $rowArr[$refPk];
                                if ($isIntegerType && is_numeric($key)) {
                                    $key = (int) $key;
                                }
                                $out[$key] = app(DynamicSchemaService::class)->composeLabel($refTable, $rowArr);
                            }
                            return $out;
                        })
                        ->getSearchResultsUsing(function (string $search) use ($refTable, $refPk, $displayCol, $searchColumn, $compositeCols, $isIntegerType) {
                            $limit = (int) config('cto.fk_search_limit', 50);
                            $cols = array_unique(array_merge([$refPk], $compositeCols ?: [$displayCol]));
                            $q = DB::table($refTable)->select($cols);
                            if ($search !== '' && Schema::hasColumn($refTable, $searchColumn)) {
                                $q->where($searchColumn, 'like', "%{$search}%");
                            }
                            $rows = $q->limit($limit)->get();
                            $out = [];
                            foreach ($rows as $r) {
                                $rowArr = (array) $r;
                                // Ensure the key matches the expected type
                                $key = $rowArr[$refPk];
                                if ($isIntegerType && is_numeric($key)) {
                                    $key = (int) $key;
                                }
                                $out[$key] = app(DynamicSchemaService::class)->composeLabel($refTable, $rowArr);
                            }
                            return $out;
                        })
                        ->getOptionLabelUsing(function ($value) use ($refTable, $refPk, $isIntegerType) {
                            if ($value === null || $value === '') return null;
                            // Ensure we query with the correct type
                            if ($isIntegerType && is_numeric($value)) {
                                $value = (int) $value;
                            }
                            $row = DB::table($refTable)->where($refPk, $value)->first();
                            return $row ? app(DynamicSchemaService::class)->composeLabel($refTable, (array)$row) : (string) $value;
                        })
                        // Ensure the state is properly formatted when loaded
                        ->afterStateHydrated(function (Select $component, $state) use ($isIntegerType) {
                            if ($state !== null && $state !== '') {
                                if ($isIntegerType && is_numeric($state)) {
                                    $component->state((int) $state);
                                } else {
                                    $component->state((string) $state);
                                }
                            }
                        })
                        // Ensure proper type casting before saving
                        ->beforeStateDehydrated(function (Select $component, $state) use ($isIntegerType) {
                            if ($state !== null && $state !== '') {
                                if ($isIntegerType && is_numeric($state)) {
                                    $component->state((int) $state);
                                } else {
                                    $component->state((string) $state);
                                }
                            }
                        });

                    // Inline create support (admin-only)
                    $component->createOptionForm(function () use ($refTable) {
                        $pk = $this->schema->primaryKey($refTable);
                        $colsMeta = $this->schema->columns($refTable);
                        $schema = [];
                        foreach ($colsMeta as $colName => $colMeta) {
                            if ($colName === $pk) continue; // skip PK
                            if ($this->schema->isForeignKeyColumn($refTable, $colName)) continue; // skip FK to avoid recursion
                            if (in_array($colName, ['created_at', 'updated_at', 'deleted_at'], true)) continue; // skip system columns

                            $ctype = strtolower((string)($colMeta['type'] ?? 'string'));
                            $nullable = (bool)($colMeta['nullable'] ?? false);
                            $length = $colMeta['length'] ?? null;

                            $field = null;
                            if (in_array($ctype, ['string', 'varchar', 'char', 'text', 'mediumtext', 'longtext'])) {
                                // Textual
                                if (in_array($ctype, ['text', 'mediumtext', 'longtext'])) {
                                    $field = Forms\Components\Textarea::make($colName)->rows(3);
                                } else {
                                    $field = Forms\Components\TextInput::make($colName)->maxLength($length ?: 65535);
                                }
                            } elseif (in_array($ctype, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'])) {
                                $field = Forms\Components\TextInput::make($colName)->numeric();
                            } elseif (in_array($ctype, ['date'])) {
                                $field = Forms\Components\DatePicker::make($colName)->native(false);
                            } elseif (in_array($ctype, ['datetime', 'datetimetz', 'timestamp', 'time'])) {
                                $field = Forms\Components\DateTimePicker::make($colName)->native(false);
                            } elseif (in_array($ctype, ['boolean'])) {
                                $field = Forms\Components\Toggle::make($colName);
                            } elseif (in_array($ctype, ['enum', 'set'])) {
                                $opts = $this->schema->enumOptions($refTable, $colName);
                                $field = Forms\Components\Select::make($colName)
                                    ->options(array_combine($opts, $opts))
                                    ->multiple($ctype === 'set')
                                    ->searchable();
                            } else {
                                $field = Forms\Components\TextInput::make($colName);
                            }

                            $field->label(Str::headline($colName));
                            if (!$nullable) $field->required();
                            $schema[] = $field;
                        }

                        // Ensure at least one field when metadata is minimal
                        if (empty($schema)) {
                            $labelCol = $this->schema->guessLabelColumn($refTable);
                            if ($labelCol && $labelCol !== $pk) {
                                $schema[] = Forms\Components\TextInput::make($labelCol)
                                    ->label(Str::headline($labelCol))
                                    ->required();
                            }
                        }

                        return $schema;
                    });

                    // Admin-only visibility for inline create button
                    $component->createOptionAction(function (FormAction $action) {
                        $action->visible(function () {
                            $u = \Illuminate\Support\Facades\Auth::user();
                            if ($u instanceof \App\Models\User && method_exists($u, 'hasRole')) {
                                return $u->hasRole('admin');
                            }
                            return false;
                        });
                        return $action;
                    });

                    // Auto-select the newly created record
                    $component->createOptionUsing(function (array $data) use ($refTable, $refPk) {
                        // Insert and return PK
                        $isAuto = app(DynamicSchemaService::class)->isPrimaryAutoIncrement($refTable);
                        if ($isAuto) {
                            $id = DB::table($refTable)->insertGetId($data);
                            return $id;
                        }
                        DB::table($refTable)->insert($data);
                        return $data[$refPk] ?? null;
                    });

                    // Helper text for user
                    $component->helperText("Pilih atau cari dari tabel {$refTable}. Admin dapat menambah data baru.");
                }
            }

            // Prefer humanized FK label for *_id columns, otherwise default to column name
            if ($this->looksLikeForeignKey($table, $name)) {
                // label already set above for FK select
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

            // Apply type-based validation
            if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) {
                $colRules[] = 'integer';
            } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
                $colRules[] = 'numeric';
            } elseif (in_array($type, ['date', 'time', 'datetime', 'datetimetz', 'timestamp'], true)) {
                $colRules[] = 'date';
            } elseif (in_array($type, ['json'], true)) {
                $colRules[] = 'array';
            } else {
                // Default to string for varchar, char, text, etc.
                $colRules[] = 'string';
                if ($length && is_int($length)) {
                    $colRules[] = 'max:' . $length;
                }
            }

            // If it's a foreign key, add exists validation
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
