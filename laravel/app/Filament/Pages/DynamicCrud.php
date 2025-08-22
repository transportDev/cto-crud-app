<?php

namespace App\Filament\Pages;

use App\Models\DynamicModel;
use App\Services\TableBuilderService;
use Filament\Forms;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class DynamicCrud extends Page implements HasTable, HasForms
{
    use InteractsWithTable;
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'CTO CRUD';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.dynamic-crud';
    protected static ?string $title = 'CTO CRUD';
    protected static ?string $slug = 'crud';

    // Header selector form
    public ?array $config = [];
    public ?string $selectedTable = null;
    public bool $showAllColumns = false;

    public static function canAccess(): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $u = Auth::user();

        // Be defensive for static analysers and custom guards
        return $u instanceof \App\Models\User
            && \method_exists($u, 'hasRole')
            && $u->hasRole('admin');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(TableBuilderService $svc): void
    {
        if (!self::canAccess()) {
            abort(403);
        }

        $this->showAllColumns = (bool) session('crud_show_all_columns', false);

        $tables = $svc->listUserTables();
        if (!$this->selectedTable && !empty($tables)) {
            $this->selectedTable = $tables[0];
        }

        $this->form->fill(['table' => $this->selectedTable]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FormSelect::make('table')
                    ->label('Pilih tabel')
                    ->searchable()
                    ->options(function () {
                        $tables = app(TableBuilderService::class)->listUserTables();
                        return array_combine($tables, $tables);
                    })
                    ->live()
                    ->afterStateUpdated(function (?string $state) {
                        $this->selectedTable = $state;
                        // Force table to refresh its query/columns
                        $this->resetTable();
                    })
                    ->helperText('Pilih tabel database yang ingin dikelola. Tabel baru akan muncul secara otomatis.'),
            ])
            ->statePath('config');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getRuntimeQuery())
            ->columns($this->inferTableColumns())
            ->filters($this->buildFilters())
            ->headerActions($this->buildHeaderActions())
            ->actions($this->buildRowActions())
            ->bulkActions($this->buildBulkActions())
            ->emptyStateHeading($this->selectedTable ? 'Tidak ada data ditemukan' : 'Pilih tabel untuk memulai')
            ->emptyStateDescription($this->selectedTable
                ? 'Belum ada data pada tabel ini. Tambahkan data baru untuk mulai mengelola.'
                : 'Gunakan pemilih di atas untuk memilih tabel. Anda juga dapat membuat tabel baru melalui Pembuat Tabel.')
            ->striped()
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultSort($this->primaryKeyName(), 'asc');
    }

    protected function getRuntimeQuery(): Builder
    {
        $model = new DynamicModel();

        if (!$this->selectedTable) {
            $model->setRuntimeTable('users');
            return $model->newQuery()->whereRaw('1 = 0');
        }

        $model->setRuntimeTable($this->selectedTable);
        $builder = $model->newQuery();

        // Default: hide soft-deleted if present
        if ($this->hasDeletedAtColumn()) {
            $builder->whereNull($this->qualified('deleted_at'));
        }

        return $builder;
    }

    protected function inferTableColumns(): array
    {
        if (!$this->selectedTable) {
            return [];
        }

        $columns = $this->getColumnMeta();
        $filamentColumns = [];
    $allColumnNames = array_keys($columns);

        // Prefer to show up to 8 columns in the table by default
        $displayColumns = $this->showAllColumns ? $columns : \array_slice($columns, 0, 12, preserve_keys: true);

        foreach ($displayColumns as $name => $meta) {
            $label = Str::headline($name);

            $type = $meta['type'] ?? 'string';
            $col = null;

        if ($type === 'boolean') {
                $col = IconColumn::make($name)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger');
            } elseif (in_array($type, ['date', 'datetime', 'datetimetz', 'timestamp', 'time'], true)) {
                $col = TextColumn::make($name)
                    ->dateTime()
            ->toggleable()
                    ->sortable();
            } elseif (in_array($type, ['enum', 'set'], true)) {
                $col = BadgeColumn::make($name)
                    ->formatStateUsing(fn($state) => (string) $state)
                    ->colors([
                        'primary' => fn($state) => filled($state),
                    ])
                    ->sortable()
                    ->toggleable();
            } elseif (in_array($type, ['json'], true)) {
                $col = TextColumn::make($name)
                    ->limit(80)
                    ->tooltip(fn($state) => \is_string($state) ? $state : json_encode($state))
                    ->wrap()
                    ->toggleable();
            } else {
                $col = TextColumn::make($name)
                    ->label($label)
                    ->searchable(in_array($type, ['string', 'text', 'char', 'uuid', 'ulid'], true))
                    ->lineClamp(2)
                    ->tooltip(fn($state) => (string) $state)
                    ->copyable()
            ->toggleable()
                    ->sortable();
            }

            if ($this->isPrimaryKey($name)) {
                $col->label($label . ' (PK)')->weight('bold');
            }

            // Enhance FK columns to display human-readable labels instead of raw IDs
            $isRedundantFk = false;
            if ($this->looksLikeForeignKey($name)) {
                $baseName = (string) Str::of($name)->beforeLast('_id');
                if (in_array($baseName, $allColumnNames, true)) {
                    $isRedundantFk = true; // human-readable sibling exists
                }
                $refTable = $this->mapForeignKeyTable($name) ?? \Illuminate\Support\Str::of($name)->beforeLast('_id')->snake()->plural()->toString();
                if (Schema::hasTable($refTable)) {
                    $labelColumn = $this->guessLabelColumnFor($refTable) ?? $this->guessLabelColumn($refTable);
                    $pk = $this->detectPrimaryKeyFromDatabase($refTable) ?: 'id';
                    $col = TextColumn::make($name)
                        ->label($label)
                        ->formatStateUsing(function ($state) use ($refTable, $labelColumn, $pk) {
                            if ($state === null) return null;
                            if ($refTable === 'equipment_type_lookup') {
                                return DB::table($refTable)
                                    ->where($pk, $state)
                                    ->selectRaw("CONCAT(COALESCE(type_alpro,''),' - ',COALESCE(category_alpro,'')) AS __label")
                                    ->value('__label') ?? $state;
                            }
                            return DB::table($refTable)->where($pk, $state)->value($labelColumn ?: $pk) ?? $state;
                        })
                        ->sortable();
                }
            }

            // Hide redundant *_id columns by default if a sibling text column exists
            if ($isRedundantFk && method_exists($col, 'toggleable')) {
                $col->toggleable(isToggledHiddenByDefault: true);
            }

            $filamentColumns[] = $col;
        }

        return $filamentColumns;
    }

    protected function buildHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah data')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn() => filled($this->selectedTable))
                ->modalHeading(fn() => 'Tambah Data - ' . ($this->selectedTable ? Str::headline($this->selectedTable) : ''))
                ->modalSubmitActionLabel('Simpan')
                ->modalCancelActionLabel('Batal')
                ->form(fn() => $this->inferFormSchema(isEdit: false))
                ->using(function (array $data): Model {
                    $model = new DynamicModel();
                    $model->setRuntimeTable($this->selectedTable);

                    $this->applySafeDefaults($data, isEdit: false);
                    $this->applyDerivedLookups($data);

                    $record = $model->create($data);

                    $this->audit('record.created', [
                        'table' => $this->selectedTable,
                        'data' => $data,
                        'id' => $record->{$this->primaryKeyName()},
                    ]);

                    return $record;
                })
                ->successNotificationTitle('Data berhasil ditambahkan'),
            Action::make('export_csv')
                ->label('Ekspor CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn() => filled($this->selectedTable))
                ->action('exportCsv'),
            Action::make('toggleColumns')
                ->label(fn() => $this->showAllColumns ? 'Tampilkan lebih sedikit kolom' : 'Tampilkan semua kolom')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn() => filled($this->selectedTable))
                ->action(function () {
                    $this->showAllColumns = ! $this->showAllColumns;
                    session(['crud_show_all_columns' => $this->showAllColumns]);
                    $this->resetTable(); // re-render columns
                }),
        ];
    }

    protected function buildRowActions(): array
    {
        return [
            ViewAction::make()
                ->form(fn($record) => $this->inferFormSchema(isEdit: false, forView: true))
                ->modalHeading(fn() => 'Lihat Data - ' . ($this->selectedTable ? Str::headline($this->selectedTable) : ''))
                ->modalSubmitActionLabel('Tutup')
                ->label('Lihat'),

            EditAction::make()
                ->label('Ubah')
                ->modalHeading(fn() => 'Edit Record - ' . ($this->selectedTable ? Str::headline($this->selectedTable) : ''))
                ->modalSubmitActionLabel('Simpan')
                ->modalCancelActionLabel('Batal')
                ->form(fn($record) => $this->inferFormSchema(isEdit: true))
                ->mutateRecordDataUsing(function (array $data): array {
                    // Remove any system fields that shouldn't be updated
                    $this->applySafeDefaults($data, isEdit: true);
                    return $data;
                })
                ->using(function (Model $record, array $data) {
                    // Ensure the model knows its table and primary key
                    if ($record instanceof DynamicModel) {
                        $record->setRuntimeTable($this->selectedTable);
                    }

                    // Get the actual primary key name and value
                    $primaryKeyName = $this->primaryKeyName();
                    $primaryKeyValue = $record->getAttribute($primaryKeyName);

                    if (!$primaryKeyValue) {
                        throw new \Exception("Cannot update record without primary key value");
                    }

                    // Clean the data
                    $this->applySafeDefaults($data, isEdit: true);
                    $this->applyDerivedLookups($data);

                    // Option 1: Direct database update (more reliable for dynamic tables)
                    DB::table($this->selectedTable)
                        ->where($primaryKeyName, $primaryKeyValue)
                        ->update($data);

                    // Refresh the model
                    $freshRecord = DB::table($this->selectedTable)
                        ->where($primaryKeyName, $primaryKeyValue)
                        ->first();

                    if ($freshRecord) {
                        $record->setRawAttributes((array) $freshRecord, true);
                    }

                    $this->audit('record.updated', [
                        'table' => $this->selectedTable,
                        'id' => $primaryKeyValue,
                        'changes' => $data,
                    ]);

                    return $record;
                })
                ->successNotificationTitle('Data berhasil diperbarui'),

            DeleteAction::make()
                ->label('Hapus')
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Hapus')
                ->modalDescription('Apakah Anda yakin ingin menghapus data ini?')
                ->modalSubmitActionLabel('Hapus')
                ->modalCancelActionLabel('Batal')
                ->visible(fn() => !$this->isSystemTable($this->selectedTable))
                ->action(function (Model $record) {
                    // Ensure the model knows its table
                    if ($record instanceof DynamicModel) {
                        $record->setRuntimeTable($this->selectedTable);
                    }

                    $primaryKeyName = $this->primaryKeyName();
                    $primaryKeyValue = $record->getAttribute($primaryKeyName);

                    if (!$primaryKeyValue) {
                        throw new \Exception("Cannot delete record without primary key value");
                    }

                    // Use direct query for deletion
                    DB::table($this->selectedTable)
                        ->where($primaryKeyName, $primaryKeyValue)
                        ->delete();

                    $this->audit('record.deleted', [
                        'table' => $this->selectedTable,
                        'id' => $primaryKeyValue,
                    ]);
                })
                ->successNotificationTitle('Data berhasil dihapus'),
        ];
    }

    protected function buildBulkActions(): array
    {
        return [
            BulkAction::make('bulk_delete')
                ->label('Hapus yang dipilih')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Hapus Massal')
                ->modalDescription('Apakah Anda yakin ingin menghapus data yang dipilih? Tindakan ini tidak dapat dibatalkan.')
                ->modalSubmitActionLabel('Hapus')
                ->modalCancelActionLabel('Batal')
                ->action(function (Tables\Actions\BulkAction $action) {
                    $model = new DynamicModel();
                    $model->setRuntimeTable($this->selectedTable);

                    $ids = $action->getRecords()->pluck($this->primaryKeyName())->all();
                    if (!empty($ids)) {
                        $model->newQuery()->whereIn($this->primaryKeyName(), $ids)->delete();

                        $this->audit('record.bulk_deleted', [
                            'table' => $this->selectedTable,
                            'ids' => $ids,
                        ]);
                    }
                })
                ->successNotificationTitle('Data terpilih berhasil dihapus')
                ->deselectRecordsAfterCompletion(),
        ];
    }

    protected function buildFilters(): array
    {
        $filters = [];

        if ($this->hasDeletedAtColumn()) {
            $filters[] = Tables\Filters\TernaryFilter::make('trashed')
                ->label('Sampah')
                ->trueLabel('Hanya yang dihapus')
                ->falseLabel('Kecualikan yang dihapus')
                ->nullable()
                ->queries(
                    true: fn(Builder $query) => $query->whereNotNull($this->qualified('deleted_at')),
                    false: fn(Builder $query) => $query->whereNull($this->qualified('deleted_at')),
                    blank: fn(Builder $query) => $query
                );
        }

        return $filters;
    }

    protected function inferFormSchema(bool $isEdit, bool $forView = false): array
    {
        $schema = [];
        $columns = $this->getColumnMeta();
        $componentsByName = [];

        foreach ($columns as $name => $meta) {
            if ($this->isSystemColumn($name)) {
                continue;
            }

            $type = $meta['type'] ?? 'string';
            $nullable = (bool) ($meta['nullable'] ?? false);
            $length = $meta['length'] ?? null;

            // Primary keys: hide on create, show read-only on edit
            if ($this->isPrimaryKey($name)) {
                if ($isEdit) {
                    $component = Forms\Components\TextInput::make($name)
                        ->disabled()
                        ->dehydrated(false)
                        ->label(Str::headline($name));
                    $schema[] = $component;
                }
                // Skip adding PK field on create forms entirely
                continue;
            }

            // Type mapping
            $component = match (true) {
                in_array($type, ['text', 'mediumText', 'longText'], true) =>
                Forms\Components\Textarea::make($name)->rows(4),
                in_array($type, ['integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true) =>
                Forms\Components\TextInput::make($name)->numeric(),
                in_array($type, ['decimal', 'float', 'double'], true) =>
                Forms\Components\TextInput::make($name)->numeric(),
                $type === 'boolean' =>
                Forms\Components\Toggle::make($name),
                in_array($type, ['date', 'time'], true) =>
                Forms\Components\DateTimePicker::make($name)->withoutTime()->native(false),
                in_array($type, ['datetime', 'datetimetz', 'timestamp'], true) =>
                Forms\Components\DateTimePicker::make($name)->native(false),
                $type === 'json' =>
                Forms\Components\KeyValue::make($name)->addable()->deletable()->reorderable()->keyLabel('kunci')->valueLabel('nilai'),
                in_array($type, ['enum', 'set'], true) =>
                Forms\Components\Select::make($name)
                    ->options(function () use ($name, $meta) {
                        $opts = $meta['options'] ?? [];
                        return array_combine($opts, $opts);
                    })
                    ->multiple($type === 'set')
                    ->searchable(),
                default =>
                Forms\Components\TextInput::make($name)->maxLength($length ?: 65535),
            };

            // Foreign key inference (_id postfix) with smart mapping & filters
            if ($this->looksLikeForeignKey($name)) {
                $refTable = $this->mapForeignKeyTable($name) ?? Str::of($name)->beforeLast('_id')->snake()->plural()->toString();

                if (Schema::hasTable($refTable)) {
                    $labelColumn = $this->guessLabelColumnFor($refTable) ?? $this->guessLabelColumn($refTable);

                    // Optional where filters based on domain (vendor/category types)
                    $whereFilters = $this->getForeignSelectFilters($name, $refTable);

                    $component = Forms\Components\Select::make($name)
                        ->searchable()
                        ->preload()
                        ->getSearchResultsUsing(function (string $search) use ($refTable, $labelColumn, $whereFilters) {
                            $q = DB::table($refTable)->limit(50);
                            $pk = $this->detectPrimaryKeyFromDatabase($refTable) ?: 'id';
                            foreach ($whereFilters as $filter) {
                                $q = $filter($q);
                            }
                            if ($refTable === 'equipment_type_lookup') {
                                $q->select([$pk, DB::raw("CONCAT(COALESCE(type_alpro,''),' - ',COALESCE(category_alpro,'')) AS __label")]);
                                if ($search !== '') {
                                    $q->where(function ($w) use ($search) {
                                        $w->where('type_alpro', 'like', "%{$search}%")
                                            ->orWhere('category_alpro', 'like', "%{$search}%");
                                    });
                                }
                                return $q->pluck('__label', $pk);
                            }
                            if ($labelColumn !== 'id' && $labelColumn) {
                                $q->where($labelColumn, 'like', "%{$search}%");
                            }
                            return $q->pluck($labelColumn ?: $pk, $pk);
                        })
                        ->getOptionLabelUsing(function ($value) use ($refTable, $labelColumn) {
                            if ($value === null) return null;
                            $pk = $this->detectPrimaryKeyFromDatabase($refTable) ?: 'id';
                            if ($refTable === 'equipment_type_lookup') {
                                return DB::table($refTable)
                                    ->where($pk, $value)
                                    ->selectRaw("CONCAT(COALESCE(type_alpro,''),' - ',COALESCE(category_alpro,'')) AS __label")
                                    ->value('__label');
                            }
                            return DB::table($refTable)->where($pk, $value)->value($labelColumn ?: $pk);
                        })
                        ->helperText("Referensi {$refTable}.{$labelColumn}");

                    // Inline create for known lookup tables
                    if (in_array($refTable, ['vendor_lookup', 'category_lookup', 'transport_type_lookup', 'regional_lookup'], true)) {
                        $component->createOptionForm($this->buildCreateOptionForm($name, $refTable))
                            ->createOptionUsing(function (array $data) use ($name, $refTable) {
                                // Normalize payload and ensure DB compatibility
                                $payload = $this->normalizeCreateOptionPayload($name, $refTable, $data);
                                $payload = $this->ensureTimestampsIfPresent($refTable, $payload);
                                $payload = $this->sanitizeInsertPayload($refTable, $payload);
                                return DB::table($refTable)->insertGetId($payload);
                            });
                    }
                }
            }

            // Friendly labels for specific FK fields
            if ($this->selectedTable === 'data_osn' && $name === 'regional_id') {
                $component->label('Regional');
            } elseif ($this->selectedTable === 'data_osn' && $name === 'regional_fe_id') {
                $component->label('Regional Fe');
            } else {
                $component->label(Str::headline($name));
            }

            // Validation
            $rules = [];
            if (!$nullable && !$forView) {
                $component->required();
            }
            if ($length && \is_int($length) && !$forView && $component instanceof Forms\Components\TextInput) {
                $component->maxLength($length);
            }
            if (in_array($type, ['integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true) && !$forView) {
                $rules[] = 'numeric';
            }
            if (in_array($type, ['date', 'time', 'datetime', 'datetimetz', 'timestamp'], true) && !$forView) {
                $rules[] = 'date';
            }
            if ($rules) {
                $component->rule(implode('|', $rules));
            }

            // Disable when viewing
            if ($forView) {
                $component->disabled()->dehydrated(false);
            }

            // store by name to allow grouping later
            $componentsByName[$name] = $component;
        }

        // Apply domain-specific tweaks (conditional visibility/requirements)
        $this->applyDomainFieldTweaks($componentsByName);

        // If we have known tables, group fields into sections for better UX
        $schema = $this->maybeGroupIntoSections($componentsByName);

        // Auto-sync text columns for known FK fields on save (before dehydration)
        if (!$forView) {
            $this->attachAutosyncDehydrationHooks($schema);
        }

        return $schema;
    }

    /**
     * Populate companion text columns from selected foreign keys for known tables.
     */
    protected function applyDerivedLookups(array &$data): void
    {
        if (!$this->selectedTable) return;

        if ($this->selectedTable === 'data_osn') {
            // regional_id -> regional (name)
            if (array_key_exists('regional_id', $data)) {
                $pk = $this->detectPrimaryKeyFromDatabase('regional_lookup') ?: 'regional_id';
                $data['regional'] = $data['regional_id']
                    ? (DB::table('regional_lookup')->where($pk, $data['regional_id'])->value('regional_name') ?? null)
                    : null;
            }
            // regional_fe_id -> regional_fe (name)
            if (array_key_exists('regional_fe_id', $data)) {
                $pk = $this->detectPrimaryKeyFromDatabase('regional_lookup') ?: 'regional_id';
                $data['regional_fe'] = $data['regional_fe_id']
                    ? (DB::table('regional_lookup')->where($pk, $data['regional_fe_id'])->value('regional_name') ?? null)
                    : null;
            }
        }
    }

    /**
     * Attach dehydration hooks to copy human-readable labels into companion text columns
     * when only FK selects are displayed.
     */
    protected function attachAutosyncDehydrationHooks(array &$schema): void
    {
        if ($this->selectedTable === 'data_osn') {
            // Map regional_id -> regional, regional_fe_id -> regional_fe
            $sync = function ($get, $set, string $fkField, string $textField) {
                $id = $get($fkField);
                if ($id) {
                    $pk = $this->detectPrimaryKeyFromDatabase('regional_lookup') ?: 'regional_id';
                    $name = DB::table('regional_lookup')->where($pk, $id)->value('regional_name');
                    if ($name) $set($textField, $name);
                } else {
                    $set($textField, null);
                }
            };

            foreach ($schema as $section) {
                if (method_exists($section, 'getChildComponents')) {
                    foreach ($section->getChildComponents() as $component) {
                        $field = method_exists($component, 'getName') ? $component->getName() : null;
                        if (in_array($field, ['regional_id', 'regional_fe_id'], true)) {
                            $component->dehydrated(true)->afterStateUpdated(function ($state, callable $set, callable $get) use ($field, $sync) {
                                $text = $field === 'regional_id' ? 'regional' : 'regional_fe';
                                $sync($get, $set, $field, $text);
                            });
                        }
                    }
                }
            }
        }
    }

    protected function applySafeDefaults(array &$data, bool $isEdit): void
    {
        // Remove system columns and immutable primary when editing
        foreach (array_keys($data) as $key) {
            if ($this->isSystemColumn($key) || ($isEdit && $this->isPrimaryKey($key))) {
                unset($data[$key]);
            }
        }

        // Autofill created_at and updated_at if the table has those columns and it's a create action
        if (!$isEdit && $this->selectedTable) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($this->selectedTable, 'created_at')) {
                $data['created_at'] = now();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn($this->selectedTable, 'updated_at')) {
                $data['updated_at'] = now();
            }
        }

        // Convert empty strings to null for nullable columns to respect DB nullability
        if ($this->selectedTable) {
            $meta = $this->getColumnMeta();
            foreach ($data as $k => $v) {
                if ($v === '' && ($meta[$k]['nullable'] ?? false)) {
                    $data[$k] = null;
                }
            }
        }
    }

    protected function isPrimaryKey(string $column): bool
    {
        return $column === $this->primaryKeyName();
    }

    protected function primaryKeyName(): string
    {
        if (!$this->selectedTable) {
            return 'id';
        }

        // Use caching to avoid repeated database queries
        $cacheKey = 'pk_' . config('database.default') . '_' . $this->selectedTable;

        return Cache::remember($cacheKey, 3600, function () {
            return $this->detectPrimaryKeyFromDatabase($this->selectedTable);
        });
    }
    /**
     * Detect primary key from database information schema
     * Reuse the same logic as DynamicModel
     */
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
                    return $result[0]->COLUMN_NAME ?? $result[0]->column_name ?? 'id';
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to detect primary key for table {$table}: " . $e->getMessage());
        }

        // Fallback to convention
        if (Schema::hasColumn($table, 'id')) {
            return 'id';
        }

        $guess = Str::of($table)->singular()->snake()->append('_id')->toString();
        if (Schema::hasColumn($table, $guess)) {
            return $guess;
        }

        $columns = Schema::getColumnListing($table);
        return $columns[0] ?? 'id';
    }

    protected function looksLikeForeignKey(string $column): bool
    {
        return Str::endsWith($column, '_id') && $column !== $this->primaryKeyName();
    }

    protected function isSystemColumn(string $name): bool
    {
        return in_array($name, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    protected function hasDeletedAtColumn(): bool
    {
        return $this->selectedTable && Schema::hasColumn($this->selectedTable, 'deleted_at');
    }

    protected function qualified(string $column): string
    {
        return $this->selectedTable . '.' . $column;
    }

    protected function guessLabelColumn(string $table): string
    {
        foreach (['name', 'title', 'label', 'email'] as $col) {
            if (Schema::hasColumn($table, $col)) {
                return $col;
            }
        }
        return 'id';
    }

    /**
     * Domain-aware label column guessing for known lookup tables.
     */
    protected function guessLabelColumnFor(string $table): ?string
    {
        $map = [
            'vendor_lookup' => 'vendor_name',
            'category_lookup' => 'category_name',
            // schema uses `transport_type` (not transport_type_name)
            'transport_type_lookup' => 'transport_type',
            'regional_lookup' => 'regional_name',
            // equipment types use a composite label; return null to trigger composite handling
            'equipment_type_lookup' => null,
        ];
        $col = $map[$table] ?? null;
        return ($col === null) ? null : ((Schema::hasColumn($table, $col)) ? $col : null);
    }

    /**
     * Map a foreign key column name to an actual reference table, when conventional pluralization fails.
     */
    protected function mapForeignKeyTable(string $column): ?string
    {
        $map = [
            // vendor_*_id columns all map to vendor_lookup
            'vendor_trm_id' => 'vendor_lookup',
            'vendor_ipmw_id' => 'vendor_lookup',
            'vendor_transmisi_id' => 'vendor_lookup',
            'vendor_owner_id' => 'vendor_lookup',
            // category_* columns map to category_lookup
            'type_alpro_id' => 'category_lookup',
            'category_alpro_id' => 'category_lookup',
            'category_tematik_id' => 'category_lookup',
            'category_sla_id' => 'category_lookup',
            'project_milestones_id' => 'category_lookup',
            // regionals
            'regional_id' => 'regional_lookup',
            'regional_fe_id' => 'regional_lookup',
            // transports
            'transport_type_id' => 'transport_type_lookup',
            // equipment type
            'equipment_type_id' => 'equipment_type_lookup',
        ];

        $ref = $map[$column] ?? null;
        return $ref && Schema::hasTable($ref) ? $ref : null;
    }

    /**
     * Return closures to apply where filters for FK selects (vendor/category type scoping).
     */
    protected function getForeignSelectFilters(string $column, string $refTable): array
    {
        $filters = [];

    if ($refTable === 'vendor_lookup' && Schema::hasColumn('vendor_lookup', 'vendor_type')) {
            $type = null;
            if (str_contains($column, 'trm')) $type = 'TRM';
            elseif (str_contains($column, 'ipmw')) $type = 'IPMW';
            elseif (str_contains($column, 'transmisi')) $type = 'TRANSMISI';
            elseif (str_contains($column, 'owner')) $type = 'OWNER';

            if ($type) {
                $filters[] = fn($q) => $q->where('vendor_type', $type);
            }
        }

        if ($refTable === 'category_lookup') {
            $ctype = null;
            if ($column === 'type_alpro_id') $ctype = 'ALPRO_TYPE';
            elseif ($column === 'category_alpro_id') $ctype = 'ALPRO_CATEGORY';
            elseif ($column === 'category_tematik_id') $ctype = 'TEMATIK';
            elseif ($column === 'category_sla_id') $ctype = 'SLA';
            elseif ($column === 'project_milestones_id') $ctype = 'PROJECT';

            if ($ctype) {
                $filters[] = fn($q) => $q->where('category_type', $ctype);
            }
        }

        return $filters;
    }

    /**
     * Build createOptionForm for lookup selects.
     */
    protected function buildCreateOptionForm(string $column, string $refTable): array
    {
        if ($refTable === 'vendor_lookup') {
            $defaultType = null;
            if (str_contains($column, 'trm')) $defaultType = 'TRM';
            elseif (str_contains($column, 'ipmw')) $defaultType = 'IPMW';
            elseif (str_contains($column, 'transmisi')) $defaultType = 'TRANSMISI';
            elseif (str_contains($column, 'owner')) $defaultType = 'OWNER';

            $fields = [
                Forms\Components\TextInput::make('vendor_name')->label('Vendor Name')->required(),
            ];
            // Only show vendor_type if the column exists in schema
            if (Schema::hasColumn('vendor_lookup', 'vendor_type')) {
                $fields[] = Forms\Components\Select::make('vendor_type')->label('Vendor Type')->options([
                    'IPMW' => 'IPMW',
                    'TRANSMISI' => 'TRANSMISI',
                    'TRM' => 'TRM',
                    'OWNER' => 'OWNER',
                ])->default($defaultType)->required();
            }
            return $fields;
        }

        if ($refTable === 'category_lookup') {
            $defaultType = match ($column) {
                'type_alpro_id' => 'ALPRO_TYPE',
                'category_alpro_id' => 'ALPRO_CATEGORY',
                'category_tematik_id' => 'TEMATIK',
                'category_sla_id' => 'SLA',
                'project_milestones_id' => 'PROJECT',
                default => null,
            };
            return [
                Forms\Components\TextInput::make('category_name')->label('Category Name')->required(),
                Forms\Components\Select::make('category_type')->label('Category Type')->options([
                    'ALPRO_TYPE' => 'ALPRO_TYPE',
                    'ALPRO_CATEGORY' => 'ALPRO_CATEGORY',
                    'TEMATIK' => 'TEMATIK',
                    'SLA' => 'SLA',
                    'PROJECT' => 'PROJECT',
                ])->default($defaultType)->required(),
            ];
        }

        if ($refTable === 'transport_type_lookup') {
            return [
                Forms\Components\TextInput::make('transport_type')->label('Transport Type')->required(),
            ];
        }

        if ($refTable === 'regional_lookup') {
            return [
                Forms\Components\TextInput::make('regional_name')->label('Regional')->required(),
            ];
        }

        return [];
    }

    /**
     * Normalize createOption payload to table columns.
     */
    protected function normalizeCreateOptionPayload(string $column, string $refTable, array $data): array
    {
        if ($refTable === 'vendor_lookup') {
            $payload = [
                'vendor_name' => $data['vendor_name'] ?? null,
            ];
            if (Schema::hasColumn('vendor_lookup', 'vendor_type')) {
                $payload['vendor_type'] = $data['vendor_type'] ?? null;
            }
            return $payload;
        }
        if ($refTable === 'category_lookup') {
            return [
                'category_name' => $data['category_name'] ?? null,
                'category_type' => $data['category_type'] ?? null,
            ];
        }
        if ($refTable === 'transport_type_lookup') {
            return [
                'transport_type' => $data['transport_type'] ?? null,
            ];
        }
        if ($refTable === 'regional_lookup') {
            return [
                'regional_name' => $data['regional_name'] ?? null,
            ];
        }
        return $data;
    }

    /**
     * Add timestamps to payload only if the target table has the columns.
     */
    protected function ensureTimestampsIfPresent(string $table, array $payload): array
    {
        try {
            if (Schema::hasColumn($table, 'created_at')) {
                $payload['created_at'] = $payload['created_at'] ?? now();
            }
            if (Schema::hasColumn($table, 'updated_at')) {
                $payload['updated_at'] = $payload['updated_at'] ?? now();
            }
        } catch (\Throwable $e) {
            // Best-effort; ignore if schema introspection fails
        }
        return $payload;
    }

    /**
     * Filter payload to only include columns that exist in the table.
     */
    protected function sanitizeInsertPayload(string $table, array $payload): array
    {
        try {
            $columns = Schema::getColumnListing($table);
            return Arr::only($payload, $columns);
        } catch (\Throwable $e) {
            // If we can't read columns, return as-is (DB will error if invalid)
            return $payload;
        }
    }

    /**
     * Group known-table components into sections for improved UX when possible.
     */
    protected function maybeGroupIntoSections(array $componentsByName): array
    {
        $name = $this->selectedTable;
        if (!$name) return array_values($componentsByName);

        $S = fn($title, array $fields) => \Filament\Forms\Components\Section::make($title)
            ->schema(array_values(array_intersect_key($componentsByName, array_flip($fields))))
            ->columns(2);

        switch ($name) {
            case 'masterdata':
                return array_filter([
                    $S('Site & Hop', ['site_id', 'hop_id', 'hop_name']),
                    // include both FK and text columns to avoid dropping fields
                    $S('Equipment', ['equipment_type_id', 'type_alpro_id', 'category_alpro_id', 'type_alpro', 'category_alpro', 'link_type']),
                    $S('Vendors', ['vendor_ipmw_id', 'vendor_transmisi_id', 'vendor_ipmw', 'vendor_transmisi']),
                    $S('Details', ['hop_site_name', 'ns', 'rtpo', 'kab', 'remark']),
                ]);
            case 'bwsetting':
                return array_filter([
                    $S('Site & Transport', ['site_id', 'transport_type_id', 'transport_type']),
                    $S('Vendor & Owner', ['vendor_trm_id', 'vendor_trm', 'link_owner']),
                    $S('Hop Details', ['hop_name', 'site_id_fe', 'po_order', 'config']),
                    $S('Capacity & Billing', ['link_capacity', 'bw_setting', 'bw_billing', 'remarks']),
                ]);
            case 'data_osn':
                return array_filter([
                    $S('Site & Direction', ['site_id', 'direction', 'ne', 'source_port_ne']),
                    $S('Far End', ['ne_fe', 'site_id_fe', 'sink_port_fe']),
                    // only show FK selects; text columns will be auto-filled from FK on save
                    $S('Regionals', ['regional_id', 'regional_fe_id']),
                    $S('Other', ['length_km', 'remark', 'remarks']),
                ]);
            case 'data_ip_nms':
                return array_filter([
                    $S('Site & IP', ['site_id', 'asset', 'idu_type', 'vlan', 'ip_address']),
                ]);
            case 'nimorder':
                return array_filter([
                    $S('Site & Order', ['site_id', 'nd_nim_no', 'order_batch', 'site_name']),
                    $S('Regions & Counts', ['tsel_reg', 'tlk_reg', 'island', 'count_bw', 'regional_id', 'mitra_id', 'mitra']),
                    // include text columns too to avoid missed inputs
                    $S('Categories & SLA', ['category_tematik_id', 'category_sla_id', 'project_milestones_id', 'category_tematik', 'category_sla', 'project_milestones', 'durasi_sla_day', 'sla_status']),
                    $S('Dates & Status', ['start_target_date', 'target_date', 'on_air_date', 'current_date', 'status_final', 'ordertype']),
                ]);
            default:
                return array_values($componentsByName);
        }
    }

    /**
     * Apply conditional visibility/requirements for known domain tables.
     */
    protected function applyDomainFieldTweaks(array &$componentsByName): void
    {
        $table = $this->selectedTable;
        if (!$table) return;

        // Helper to safely fetch component
        $cmp = function (string $name) use (&$componentsByName) {
            return $componentsByName[$name] ?? null;
        };

        if ($table === 'bwsetting') {
            // Show PO Order only when vendor_trm_id selected
            if ($c = $cmp('po_order')) {
                $c->visible(fn($get) => filled($get('vendor_trm_id')));
            }
            // Require bw_billing when bw_setting is provided
            if ($c = $cmp('bw_billing')) {
                $c->required(fn($get) => filled($get('bw_setting')));
            }
        }

        if ($table === 'masterdata') {
            // Require vendor by link_type
            if ($c = $cmp('vendor_ipmw_id')) {
                $c->required(fn($get) => strtolower((string)$get('link_type')) === 'mw');
            }
            if ($c = $cmp('vendor_transmisi_id')) {
                $c->required(fn($get) => strtolower((string)$get('link_type')) === 'fo');
            }
        }

        if ($table === 'data_osn') {
            // Always show Far End fields and Regionals to avoid missing values on create/edit
            // (was previously conditionally visible)
            // no-op: ensure components remain visible by default
        }
    }

    protected function getColumnMeta(): array
    {
        if (!$this->selectedTable) {
            return [];
        }

        $meta = [];
        // Use Laravel's native schema introspection. It's less detailed than Doctrine
        // for things like enums or defaults, but it's universal and dependency-free.
        $columns = Schema::getColumns($this->selectedTable);

        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $column['type']; // Note: this is the simplified Laravel type

            $meta[$name] = [
                'type' => $type,
                'nullable' => (bool)$column['nullable'],
                'length' => $column['size'] ?? null,
                'default' => $column['default'],
                'options' => [], // Native schema doesn't reliably provide enum options
            ];
        }

        return $meta;
    }

    protected function isSystemTable(?string $name): bool
    {
        if (!$name) return true;

        $excluded = [
            'migrations',
            'failed_jobs',
            'password_reset_tokens',
            'password_resets',
            'personal_access_tokens',
            'cache',
            'jobs',
            'job_batches',
            'permissions',
            'roles',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'admin_audit_logs',
            'dynamic_tables',
            'cache_locks',
            'sessions'
        ];

        return in_array($name, $excluded, true);
    }

    protected function audit(string $action, array $context = []): void
    {
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => Auth::id(),
                    'action' => $action,
                    'context' => json_encode($context),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Do not block the UI if audit logging fails
        }
    }

    public function getTableRecordKey(Model $record): string
    {
        // Use the correct primary key name
        $primaryKey = $this->primaryKeyName();

        // Try to get the value using the correct primary key
        $value = $record->getAttribute($primaryKey);

        // If that doesn't work, try the model's own primary key
        if ($value === null && $record->getKeyName()) {
            $value = $record->getKey();
        }

        if ($value !== null) {
            return (string) $value;
        }

        // Fallback logic for tables without proper primary keys
        $fallbackColumns = $this->getFallbackKeyColumns();
        $keyParts = [];

        foreach ($fallbackColumns as $column) {
            $columnValue = $record->getAttribute($column);
            if ($columnValue !== null) {
                $keyParts[] = $columnValue;
            }
        }

        if (empty($keyParts)) {
            // Last resort: try to use any unique identifier
            $allAttributes = $record->getAttributes();
            if (!empty($allAttributes)) {
                return md5(json_encode($allAttributes));
            }

            throw new \Exception("Cannot generate unique key for table '{$this->selectedTable}'.");
        }

        return implode('-', $keyParts);
    }

    /**
     * Get fallback columns to use when primary key is missing
     */
    protected function getFallbackKeyColumns(): array
    {
        if (!$this->selectedTable) {
            return [];
        }

        // Define fallback columns for each table without primary keys
        $fallbackMap = [
            'bwsetting' => ['bw_record_id'], // Use the auto-increment ID
            'data_ip_nms' => ['ip_record_id'],
            'data_osn' => ['osn_record_id'],
            'linkroute' => ['link_record_id'],
            'masterdata' => ['master_record_id'],
            'nimorder' => ['order_record_id'],
        ];

        return $fallbackMap[$this->selectedTable] ?? ['created_at', 'updated_at'];
    }

    public function exportCsv()
    {
        $table = $this->selectedTable;
        if (!$table) {
            Notification::make()->danger()->title('Tidak ada tabel yang dipilih')->send();
            return;
        }

        $columns = Schema::getColumnListing($table);

        return response()->streamDownload(function () use ($table, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            DB::table($table)
                ->orderBy($this->primaryKeyName())
                ->chunk(500, function ($rows) use ($out, $columns) {
                    foreach ($rows as $row) {
                        $data = [];
                        foreach ($columns as $col) {
                            $val = $row->{$col} ?? null;
                            if (is_array($val) || is_object($val)) {
                                $val = json_encode($val);
                            }
                            $data[] = $val;
                        }
                        fputcsv($out, $data);
                    }
                });

            fclose($out);
        }, $table . '-' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
