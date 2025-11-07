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
 * Service untuk membangun komponen form Filament dan aturan validasi Laravel secara dinamis.
 *
 * Service ini menyediakan fungsionalitas untuk:
 * - Membuat komponen form berdasarkan metadata skema database
 * - Menghormati immutability primary key dan auto-increment pada mode create
 * - Menyediakan select foreign key yang aman dengan async search
 * - Menggunakan label column yang dapat dikonfigurasi dan query yang terbatas
 * - Mendukung inline create untuk relasi foreign key (khusus admin)
 * - Menghasilkan aturan validasi Laravel yang sesuai dengan tipe data
 *
 * @package App\Services\Dynamic
 */
class DynamicFormService
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
     * Membangun array komponen form Filament berdasarkan skema tabel.
     *
     * Method ini akan:
     * - Mengiterasi semua kolom dalam tabel
     * - Melewati kolom timestamp sistem (created_at, updated_at, deleted_at)
     * - Menyembunyikan auto-increment PK pada mode create
     * - Menonaktifkan PK pada mode edit
     * - Membuat komponen form yang sesuai dengan tipe data
     * - Menangani foreign key dengan Select searchable dan preload
     * - Mendukung inline create untuk FK (khusus admin)
     * - Menerapkan validasi dan constraint berdasarkan metadata
     *
     * @param string $table Nama tabel yang akan dibangun formnya
     * @param bool $isEdit True jika form untuk edit, false untuk create
     * @param bool $forView True jika form untuk view-only (semua field disabled)
     * 
     * @return array<int, \Filament\Forms\Components\Component> Array komponen form Filament
     */
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
                continue;
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

            if ($this->looksLikeForeignKey($table, $name)) {
                $fkMap = $this->schema->foreignKeys($table);
                $refTable = $fkMap[$name]['referenced_table'] ?? Str::of($name)->beforeLast('_id')->snake()->plural()->toString();
                $refTable = $this->schema->sanitizeTable($refTable);
                $refPk = $fkMap[$name]['referenced_column'] ?? 'id';
                if ($refTable && Schema::hasTable($refTable)) {
                    if (!Schema::hasColumn($refTable, $refPk)) {
                        $refPk = $this->schema->primaryKey($refTable);
                    }

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

                    $isIntegerType = in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true);

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
                            if ($isIntegerType && is_numeric($value)) {
                                $value = (int) $value;
                            }
                            $row = DB::table($refTable)->where($refPk, $value)->first();
                            return $row ? app(DynamicSchemaService::class)->composeLabel($refTable, (array)$row) : (string) $value;
                        })
                        ->afterStateHydrated(function (Select $component, $state) use ($isIntegerType) {
                            if ($state !== null && $state !== '') {
                                if ($isIntegerType && is_numeric($state)) {
                                    $component->state((int) $state);
                                } else {
                                    $component->state((string) $state);
                                }
                            }
                        })
                        ->beforeStateDehydrated(function (Select $component, $state) use ($isIntegerType) {
                            if ($state !== null && $state !== '') {
                                if ($isIntegerType && is_numeric($state)) {
                                    $component->state((int) $state);
                                } else {
                                    $component->state((string) $state);
                                }
                            }
                        });

                    $component->createOptionForm(function () use ($refTable) {
                        $pk = $this->schema->primaryKey($refTable);
                        $colsMeta = $this->schema->columns($refTable);
                        $schema = [];
                        foreach ($colsMeta as $colName => $colMeta) {
                            if ($colName === $pk) continue;
                            if ($this->schema->isForeignKeyColumn($refTable, $colName)) continue;
                            if (in_array($colName, ['created_at', 'updated_at', 'deleted_at'], true)) continue;

                            $ctype = strtolower((string)($colMeta['type'] ?? 'string'));
                            $nullable = (bool)($colMeta['nullable'] ?? false);
                            $length = $colMeta['length'] ?? null;

                            $field = null;
                            if (in_array($ctype, ['string', 'varchar', 'char', 'text', 'mediumtext', 'longtext'])) {
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

                    $component->createOptionUsing(function (array $data) use ($refTable, $refPk) {
                        $isAuto = app(DynamicSchemaService::class)->isPrimaryAutoIncrement($refTable);
                        if ($isAuto) {
                            $id = DB::table($refTable)->insertGetId($data);
                            return $id;
                        }
                        DB::table($refTable)->insert($data);
                        return $data[$refPk] ?? null;
                    });

                    $component->helperText("Pilih atau cari dari tabel {$refTable}. Admin dapat menambah data baru.");
                }
            }

            if ($this->looksLikeForeignKey($table, $name)) {
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

    /**
     * Membangun array aturan validasi Laravel berdasarkan skema tabel.
     *
     * Method ini akan:
     * - Mengiterasi semua kolom dalam tabel
     * - Melewati kolom timestamp sistem
     * - Melewati primary key pada mode edit
     * - Menerapkan aturan berdasarkan tipe data (integer, numeric, date, array, string)
     * - Menambahkan constraint panjang maksimal untuk tipe string
     * - Menambahkan validasi exists untuk foreign key
     * - Menangani nullable/required sesuai metadata kolom
     *
     * @param string $table Nama tabel yang akan dibangun aturan validasinya
     * @param bool $isEdit True jika untuk mode edit, false untuk create
     * 
     * @return array<string, array<int, string>> Array aturan validasi Laravel
     */
    public function buildRules(string $table, bool $isEdit): array
    {
        $cols = $this->schema->columns($table);
        $pk = $this->schema->primaryKey($table);
        $fks = $this->schema->foreignKeys($table);
        $rules = [];

        foreach ($cols as $name => $meta) {
            if (in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)) continue;
            if ($isEdit && $name === $pk) continue;
            if (!$isEdit && $name === $pk && $this->schema->isPrimaryAutoIncrement($table)) continue;

            $type = $meta['type'] ?? 'string';
            $nullable = (bool)($meta['nullable'] ?? false);
            $length = $meta['length'] ?? null;

            $colRules = [];
            $colRules[] = $nullable ? 'nullable' : 'required';

            if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) {
                $colRules[] = 'integer';
            } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
                $colRules[] = 'numeric';
            } elseif (in_array($type, ['date', 'time', 'datetime', 'datetimetz', 'timestamp'], true)) {
                $colRules[] = 'date';
            } elseif (in_array($type, ['json'], true)) {
                $colRules[] = 'array';
            } else {
                $colRules[] = 'string';
                if ($length && is_int($length)) {
                    $colRules[] = 'max:' . $length;
                }
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

    /**
     * Memeriksa apakah kolom terlihat seperti foreign key.
     *
     * Kolom dianggap sebagai foreign key jika:
     * - Berakhiran dengan '_id'
     * - Bukan merupakan primary key dari tabel
     *
     * @param string $table Nama tabel
     * @param string $column Nama kolom yang akan diperiksa
     * 
     * @return bool True jika kolom terlihat seperti foreign key
     */
    protected function looksLikeForeignKey(string $table, string $column): bool
    {
        return Str::endsWith($column, '_id') && $column !== $this->schema->primaryKey($table);
    }

    /**
     * Mengubah nama kolom foreign key menjadi label yang lebih ramah pengguna.
     *
     * Proses transformasi:
     * - Menghapus suffix '_id'
     * - Mengubah snake_case menjadi Headline Case
     * - Mengubah singkatan umum menjadi uppercase (FE, IP, URL, ID, SKU, IMEI)
     *
     * Contoh: 'regional_fe_id' => 'Regional FE'
     *
     * @param string $fkColumn Nama kolom foreign key
     * 
     * @return string Label yang sudah diformat
     */
    protected function humanizeFkLabel(string $fkColumn): string
    {
        $base = Str::of($fkColumn)->beforeLast('_id')->replace('_', ' ')->headline()->toString();
        $abbrs = ['Fe' => 'FE', 'Ip' => 'IP', 'Url' => 'URL', 'Id' => 'ID', 'Sku' => 'SKU', 'Imei' => 'IMEI'];
        foreach ($abbrs as $needle => $upper) {
            $base = preg_replace('/\b' . preg_quote($needle, '/') . '\b/u', $upper, $base);
        }
        return trim($base);
    }
}
