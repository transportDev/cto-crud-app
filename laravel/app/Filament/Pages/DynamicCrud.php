<?php

namespace App\Filament\Pages;

use App\Models\DynamicModel;
use App\Services\TableBuilderService;
use App\Services\Dynamic\DynamicSchemaService;
use App\Services\Dynamic\DynamicQueryBuilder;
use App\Services\Dynamic\DynamicFormService;
use App\Services\Dynamic\DynamicExportService;
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
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

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
    /**
     * Per-table selected fields for listing view.
     * Keys:
     *  - self:{column}
     *  - fk:{fk_column}:{ref_table}:{ref_column}
     */
    public array $tableFieldSelections = [];

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

    /**
     * Keep constructor injection minimal; mount resolves services.
     */
    public function mount(TableBuilderService $svc): void
    {
        if (!self::canAccess()) {
            abort(403);
        }

        // Whitelisted tables only
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
                        // Use table whitelist as options
                        $tables = app(TableBuilderService::class)->listUserTables();
                        return array_combine($tables, $tables);
                    })
                    ->live()
                    ->afterStateUpdated(function (?string $state) {
                        $safe = app(DynamicSchemaService::class)->sanitizeTable($state);
                        $this->selectedTable = $safe;
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
            // Hide the toggle-columns dropdown trigger button
            ->toggleColumnsTriggerAction(fn(Action $action) => $action->hidden())
            ->emptyStateHeading($this->selectedTable ? 'Tidak ada data ditemukan' : 'Pilih tabel untuk memulai')
            ->emptyStateDescription($this->selectedTable
                ? 'Belum ada data pada tabel ini. Tambahkan data baru untuk mulai mengelola.'
                : 'Gunakan pemilih di atas untuk memilih tabel. Anda juga dapat membuat tabel baru melalui Pembuat Tabel.')
            ->striped()
            ->deferLoading()
            ->paginated([10, 25, 50, 100]);
    }

    protected function getRuntimeQuery(): Builder
    {
        $qb = app(DynamicQueryBuilder::class);
        return $qb->build($this->selectedTable, $this->selectedFieldsForTable());
    }

    protected function inferTableColumns(): array
    {
        if (!$this->selectedTable) {
            return [];
        }

        $columns = app(DynamicSchemaService::class)->columns($this->selectedTable);
        $filamentColumns = [];

        $selectedKeys = $this->selectedFieldsForTable();
        if (empty($selectedKeys)) {
            $displayColumns = \array_slice($columns, 0, 20, true);
            foreach ($displayColumns as $name => $meta) {
                $selectedKeys[] = 'self:' . $name;
            }
        }

        foreach ($selectedKeys as $key) {
            if (str_starts_with($key, 'self:')) {
                $name = substr($key, 5);
                $meta = $columns[$name] ?? ['type' => 'string'];
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
                        ->dateTime();
                } elseif (in_array($type, ['enum', 'set'], true)) {
                    $col = BadgeColumn::make($name)
                        ->formatStateUsing(fn($state) => (string) $state)
                        ->colors([
                            'primary' => fn($state) => filled($state),
                        ])
                        ->sortable();
                } elseif (in_array($type, ['json'], true)) {
                    $col = TextColumn::make($name)
                        ->limit(60)
                        ->tooltip(fn($state) => \is_string($state) ? $state : json_encode($state));
                } else {
                    $isTextLike = in_array($type, ['string', 'char', 'uuid', 'ulid'], true);
                    $searchable = $isTextLike && app(DynamicSchemaService::class)->isIndexed($this->selectedTable, $name);
                    $col = TextColumn::make($name)
                        ->label($label)
                        ->searchable($searchable)
                        ->sortable();
                }

                if ($this->isPrimaryKey($name)) {
                    $col->label($label . ' (PK)')->weight('bold');
                }

                $filamentColumns[] = $col;
            } elseif (str_starts_with($key, 'fk:')) {
                [, $fkCol, $refTable, $refCol] = explode(':', $key, 4);
                $alias = app(DynamicQueryBuilder::class)->columnAlias($fkCol, $refTable, $refCol);
                $label = Str::headline($refTable . ' ' . $refCol);
                $filamentColumns[] = TextColumn::make($alias)
                    ->label($label);
            }
        }

        return $filamentColumns;
    }

    protected function buildHeaderActions(): array
    {
        return [
            Action::make('select_fields')
                ->label('Pilih Kolom')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn() => filled($this->selectedTable))
                ->modalHeading('Pilih Kolom Ditampilkan')
                ->modalSubmitActionLabel('Terapkan')
                ->form(function () {
                    $schema = [];
                    // Self table fields
                    $selfOptions = [];
                    foreach (array_keys($this->getColumnMeta()) as $col) {
                        $selfOptions['self:' . $col] = $col;
                    }
                    $schema[] = Forms\Components\Section::make('Kolom Tabel Ini')
                        ->schema([
                            Forms\Components\CheckboxList::make('selected_self_fields')
                                ->options($selfOptions)
                                ->columns(2)
                                ->bulkToggleable()
                                ->default(array_values(array_filter($this->selectedFieldsForTable(), fn($k) => str_starts_with($k, 'self:')))),
                        ]);

                    // Related fields grouped by FK
                    $fkMap = app(DynamicSchemaService::class)->foreignKeys($this->selectedTable);
                    foreach ($fkMap as $fkCol => $ref) {
                        $refTable = $ref['referenced_table'];
                        if (!$refTable) {
                            continue;
                        }
                        $refCols = Schema::getColumns($refTable);
                        $opts = [];
                        foreach ($refCols as $c) {
                            $cName = $c['name'];
                            if (in_array($cName, ['created_at', 'updated_at', 'deleted_at'], true)) {
                                continue;
                            }
                            $opts["fk:{$fkCol}:{$refTable}:{$cName}"] = $cName;
                        }
                        $schema[] = Forms\Components\Section::make("{$refTable} (via {$fkCol})")
                            ->description("Pilih kolom dari {$refTable}")
                            ->schema([
                                Forms\Components\CheckboxList::make('selected_' . $fkCol)
                                    ->options($opts)
                                    ->columns(2)
                                    ->bulkToggleable()
                                    ->default(array_values(array_filter($this->selectedFieldsForTable(), fn($k) => str_starts_with($k, "fk:{$fkCol}:{$refTable}:")))),
                            ]);
                    }

                    return $schema;
                })
                ->action(function (array $data) {
                    $selected = [];
                    foreach ($data as $vals) {
                        if (!is_array($vals)) {
                            continue;
                        }
                        foreach ($vals as $v) {
                            $selected[] = $v;
                        }
                    }
                    $this->tableFieldSelections[$this->selectedTable] = array_values(array_unique($selected));
                    $this->resetTable();
                }),
            CreateAction::make()
                ->label('Tambah data')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn() => filled($this->selectedTable))
                ->modalHeading(fn() => 'Tambah Data - ' . ($this->selectedTable ? Str::headline($this->selectedTable) : ''))
                ->modalSubmitActionLabel('Simpan')
                ->modalCancelActionLabel('Batal')
                ->form(fn() => $this->inferFormSchema(false))
                ->using(function (array $data): Model {
                    try {
                        // Validate against schema (cached)
                        $rules = $this->buildValidationRules(false);
                        Validator::make($data, $rules)->validate();

                        // Resolve any embedded FK fields (from relation label columns) into actual FK IDs
                        $this->resolveEmbeddedForeigns($data);

                        $model = new DynamicModel();
                        $model->setRuntimeTable($this->selectedTable);

                        $this->applySafeDefaults($data, false);

                        $record = $model->create($data);
                    } catch (ValidationException $ve) {
                        $msg = $this->firstValidationMessage($ve);
                        Notification::make()->danger()->title('Input tidak valid')->body($msg)->send();
                        throw $ve; // Let Filament highlight fields as well (keeps modal open)
                    } catch (QueryException $qe) {
                        [$t, $b] = $this->friendlyDbError($qe);
                        Notification::make()->danger()->title($t)->body($b)->send();
                        throw new Halt(); // keep modal open, no success toast
                    }

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
        ];
    }

    protected function buildRowActions(): array
    {
        return [
            EditAction::make()
                ->label('Ubah')
                ->modalHeading(fn() => 'Edit Record - ' . ($this->selectedTable ? Str::headline($this->selectedTable) : ''))
                ->modalSubmitActionLabel('Simpan')
                ->modalCancelActionLabel('Batal')
                ->form(fn($record) => $this->inferFormSchema(true))
                ->using(function (Model $record, array $data) {
                    try {
                        // Validate against schema (cached)
                        $rules = $this->buildValidationRules(true);
                        Validator::make($data, $rules)->validate();

                        // No embedded creation on edit; ignore any stray embedded keys
                        $this->stripEmbeddedForeigns($data);

                        $this->applySafeDefaults($data, true);

                        $record->fill($data);
                        $record->save();
                    } catch (ValidationException $ve) {
                        $msg = $this->firstValidationMessage($ve);
                        Notification::make()->danger()->title('Input tidak valid')->body($msg)->send();
                        throw $ve; // keeps modal open and highlights fields
                    } catch (QueryException $qe) {
                        [$t, $b] = $this->friendlyDbError($qe);
                        Notification::make()->danger()->title($t)->body($b)->send();
                        throw new Halt();
                    }

                    $this->audit('record.updated', [
                        'table' => $this->selectedTable,
                        'id' => $record->{$this->primaryKeyName()},
                        'changes' => $data,
                    ]);

                    return $record;
                })
                ->successNotificationTitle('Data berhasil diperbarui'),
            DeleteAction::make()
                ->label('Hapus')
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Hapus')
                ->modalDescription('Apakah Anda yakin ingin menghapus data ini? Tindakan ini tidak dapat dibatalkan.')
                ->modalSubmitActionLabel('Hapus')
                ->modalCancelActionLabel('Batal')
                ->visible(fn() => !$this->isSystemTable($this->selectedTable))
                ->using(function (Model $record) {
                    // Schema-aware guard: block delete when restricted by FK constraints
                    $blocked = $this->hasRestrictingIncomingReferences($record->{$this->primaryKeyName()});
                    if ($blocked) {
                        Notification::make()
                            ->danger()
                            ->title('Tidak dapat menghapus')
                            ->body('Record ini direferensikan oleh tabel lain (ON DELETE RESTRICT/NO ACTION).')
                            ->send();
                        return $record; // do not delete
                    }

                    try {
                        $record->delete();
                    } catch (QueryException $e) {
                        Notification::make()->danger()->title('Gagal menghapus')->body('Terkendala relasi database.')->send();
                    }

                    return $record;
                })
                ->after(function (Model $record) {
                    $this->audit('record.deleted', [
                        'table' => $this->selectedTable,
                        'id' => $record->{$this->primaryKeyName()},
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
                        $deletable = [];
                        $blocked = [];
                        foreach ($ids as $id) {
                            if ($this->hasRestrictingIncomingReferences($id)) {
                                $blocked[] = $id;
                            } else {
                                $deletable[] = $id;
                            }
                        }

                        if (!empty($deletable)) {
                            $model->newQuery()->whereIn($this->primaryKeyName(), $deletable)->delete();
                            $this->audit('record.bulk_deleted', [
                                'table' => $this->selectedTable,
                                'ids' => $deletable,
                            ]);
                        }

                        if (!empty($blocked)) {
                            Notification::make()->warning()->title('Sebagian tidak terhapus')->body('Beberapa record direferensikan dan diblokir oleh FK.')->send();
                        }
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
                    fn(Builder $query) => $query->whereNotNull($this->qualified('deleted_at')),
                    fn(Builder $query) => $query->whereNull($this->qualified('deleted_at')),
                    fn(Builder $query) => $query
                );
        }

        return $filters;
    }

    protected function inferFormSchema(bool $isEdit, bool $forView = false): array
    {
        if (!$this->selectedTable) return [];
        return app(DynamicFormService::class)->buildForm($this->selectedTable, $isEdit, $forView);
    }

    protected function selectedFieldsForTable(): array
    {
        if (!$this->selectedTable) {
            return [];
        }
        return $this->tableFieldSelections[$this->selectedTable] ?? [];
    }

    protected function aliasForJoin(string $fkCol, string $refTable): string
    {
        return $refTable . '__' . $fkCol;
    }

    protected function aliasForJoinColumn(string $fkCol, string $refTable, string $refCol): string
    {
        return 'fk_' . $fkCol . '__' . $refTable . '__' . $refCol;
    }

    protected function getForeignKeyMap(string $table): array
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        $map = [];
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $db = DB::getDatabaseName();
            $rows = DB::select(
                'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
                 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                 WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
                [$db, $table]
            );
            foreach ($rows as $r) {
                $col = $r->COLUMN_NAME;
                $map[$col] = [
                    'referenced_table' => $r->REFERENCED_TABLE_NAME,
                    'referenced_column' => $r->REFERENCED_COLUMN_NAME,
                ];
            }
        }

        return $map;
    }

    protected function isPrimaryAutoIncrement(): bool
    {
        $pk = $this->primaryKeyName();
        $columns = $this->getColumnMeta();
        $type = $columns[$pk]['type'] ?? null;

        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $db = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$db, $this->selectedTable, $pk]
            );
            if ($row && isset($row->EXTRA) && stripos($row->EXTRA, 'auto_increment') !== false) {
                return true;
            }
        }

        // Heuristic fallback
        return $pk === 'id' && in_array($type, ['integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true);
    }

    protected function firstValidationMessage(ValidationException $ve): string
    {
        try {
            $errors = $ve->errors();
            foreach ($errors as $field => $messages) {
                if (!empty($messages)) {
                    return (string) $messages[0];
                }
            }
        } catch (\Throwable $e) {
            // Fallback
        }
        return 'Input tidak valid.';
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
    }

    /**
     * Convert embedded FK label fields into real FK IDs on create. Keys look like
     * fk_new__{fk_col}__{ref_table}__{label_col}. We attempt to find an existing
     * row by all label columns; if not found, we insert a new row and set the FK.
     */
    protected function resolveEmbeddedForeigns(array &$data): void
    {
        $fks = app(DynamicSchemaService::class)->foreignKeys($this->selectedTable);
        foreach ($fks as $fkCol => $ref) {
            $refTable = $ref['referenced_table'] ?? null;
            $refPk = $ref['referenced_column'] ?? 'id';
            if (!$refTable) continue;

            // Collect embedded fields
            $prefix = 'fk_new__' . $fkCol . '__' . $refTable . '__';
            $labelFields = [];
            foreach ($data as $k => $v) {
                if (str_starts_with($k, $prefix)) {
                    $labelCol = substr($k, strlen($prefix));
                    $labelFields[$labelCol] = $v;
                }
            }
            if (empty($labelFields)) continue;

            // Try to locate existing row by exact match on provided label columns
            $q = DB::table($refTable);
            foreach ($labelFields as $col => $val) {
                if (Schema::hasColumn($refTable, $col)) {
                    $q->where($col, $val);
                }
            }
            $existing = $q->select($refPk)->first();
            if ($existing && isset($existing->{$refPk})) {
                $data[$fkCol] = $existing->{$refPk};
            } else {
                // Insert and set FK
                $isAuto = app(DynamicSchemaService::class)->isPrimaryAutoIncrement($refTable);
                if (!$isAuto && !isset($labelFields[$refPk]) && Schema::hasColumn($refTable, $refPk)) {
                    // Cannot infer PK; skip creating
                    continue;
                }
                $payload = $labelFields;
                if ($isAuto) {
                    unset($payload[$refPk]);
                    $newId = DB::table($refTable)->insertGetId($payload);
                    $data[$fkCol] = $newId;
                } else {
                    DB::table($refTable)->insert($payload);
                    $data[$fkCol] = $payload[$refPk];
                }
            }

            // Cleanup embedded fields from payload
            foreach (array_keys($labelFields) as $c) {
                unset($data[$prefix . $c]);
            }
        }
    }

    protected function stripEmbeddedForeigns(array &$data): void
    {
        foreach (array_keys($data) as $k) {
            if (str_starts_with($k, 'fk_new__')) unset($data[$k]);
        }
    }

    /**
     * Build Laravel validation rules derived from the current table schema.
     * - required for non-nullable, unless auto-increment PK on create
     * - numeric rules for integer/decimal types
     * - date rules for date/time types
     * - string length max for varchar/char
     * - exists rule for foreign keys
     */
    protected function buildValidationRules(bool $isEdit): array
    {
        if (!$this->selectedTable) {
            return [];
        }
        return app(DynamicFormService::class)->buildRules($this->selectedTable, $isEdit);
    }

    protected function isPrimaryKey(string $column): bool
    {
        return $column === $this->primaryKeyName();
    }

    protected function primaryKeyName(): string
    {
        // Heuristic: id or {table}_id
        if (!$this->selectedTable) {
            return 'id';
        }

        // Prefer metadata via DynamicSchemaService
        return app(DynamicSchemaService::class)->primaryKey($this->selectedTable);
    }

    protected function looksLikeForeignKey(string $column): bool
    {
        return Str::endsWith($column, '_id') && $column !== $this->primaryKeyName();
    }

    protected function isSystemColumn(string $name): bool
    {
        return in_array($name, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    /**
     * Map database errors to friendly, actionable messages.
     * Returns [title, body].
     */
    protected function friendlyDbError(QueryException $e): array
    {
        $sqlState = $e->errorInfo[0] ?? null; // e.g. '23000'
        $driverCode = (int) ($e->errorInfo[1] ?? 0); // MySQL error number
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        // Data too long for column -> truncate/length guidance
        if ($driverCode === 1406 || Str::contains($message, ['Data too long for column', 'value too long'])) {
            // Try to extract column name
            preg_match("/for column '([^']+)'/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Teks terlalu panjang';
            $body = $col
                ? "Nilai untuk kolom '{$col}' melebihi batas panjang. Kurangi jumlah karakter sesuai panjang kolom."
                : 'Beberapa isian melebihi batas panjang. Kurangi jumlah karakter sesuai panjang kolom.';
            return [$title, $body];
        }

        // Incorrect integer value / wrong type
        if (Str::contains($message, ['Incorrect integer value', 'Incorrect double value', 'Incorrect decimal value'])) {
            preg_match("/for column '([^']+)'/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Format angka tidak valid';
            $body = $col
                ? "Isian '{$col}' harus berupa angka yang valid."
                : 'Beberapa isian angka tidak valid. Gunakan hanya angka.';
            return [$title, $body];
        }

        // Foreign key constraint fails
        if ($driverCode === 1452 || Str::contains($message, 'foreign key constraint fails')) {
            preg_match("/CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\)/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Referensi tidak ditemukan';
            $body = $col
                ? "Nilai pada '{$col}' tidak cocok dengan data referensi. Pilih nilai yang tersedia di daftar."
                : 'Beberapa nilai referensi tidak ditemukan. Pilih nilai yang tersedia di daftar.';
            return [$title, $body];
        }

        // Duplicate entry for unique index
        if ($driverCode === 1062 || Str::contains($message, 'Duplicate entry')) {
            preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/i", $message, $m);
            $val = $m[1] ?? null;
            $key = $m[2] ?? null;
            $title = 'Nilai sudah dipakai';
            $body = $val && $key
                ? "Nilai '{$val}' sudah digunakan (unik: {$key}). Gunakan nilai lain."
                : 'Ada nilai yang harus unik sudah digunakan. Gunakan nilai lain.';
            return [$title, $body];
        }

        // Cannot be null
        if ($driverCode === 1048 || Str::contains($message, 'cannot be null')) {
            preg_match("/Column '([^']+)' cannot be null/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Isian wajib diisi';
            $body = $col
                ? "Kolom '{$col}' wajib diisi."
                : 'Ada kolom yang wajib diisi.';
            return [$title, $body];
        }

        // Default fallback
        return ['Gagal menyimpan', 'Periksa input Anda dan coba lagi.'];
    }

    protected function hasDeletedAtColumn(): bool
    {
        return $this->selectedTable && app(DynamicSchemaService::class)->hasDeletedAt($this->selectedTable);
    }

    protected function qualified(string $column): string
    {
        return $this->selectedTable . '.' . $column;
    }

    protected function guessLabelColumn(string $table): string
    {
        return app(DynamicSchemaService::class)->guessLabelColumn($table);
    }

    protected function getColumnMeta(): array
    {
        return $this->selectedTable ? app(DynamicSchemaService::class)->columns($this->selectedTable) : [];
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

    /**
     * Check if the given PK value has incoming FK references with DELETE_RULE RESTRICT/NO ACTION.
     */
    protected function hasRestrictingIncomingReferences($pkValue): bool
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return false; // fallback to DB error behavior
        }

        $db = DB::getDatabaseName();
        $pk = $this->primaryKeyName();

        $rows = DB::select(
            'SELECT k.TABLE_NAME as ref_table, k.COLUMN_NAME as ref_column, r.DELETE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.REFERENCED_TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = ?',
            [$db, $this->selectedTable, $pk]
        );

        foreach ($rows as $r) {
            $rule = strtoupper((string)($r->DELETE_RULE ?? ''));
            if (in_array($rule, ['RESTRICT', 'NO ACTION', 'NO_ACTION'], true)) {
                $count = DB::table($r->ref_table)->where($r->ref_column, $pkValue)->limit(1)->count();
                if ($count > 0) {
                    return true;
                }
            }
        }

        return false;
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

    public function exportCsv()
    {
        $table = $this->selectedTable;
        if (!$table) {
            Notification::make()->danger()->title('Tidak ada tabel yang dipilih')->send();
            return;
        }
        return app(DynamicExportService::class)->streamCsv($table);
    }
}
