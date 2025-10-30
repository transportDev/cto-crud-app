<?php

declare(strict_types=1);

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

/**
 * Halaman Dynamic CRUD
 *
 * Halaman Filament yang menyediakan antarmuka CRUD (Create, Read, Update, Delete) dinamis
 * untuk mengelola tabel-tabel database secara langsung melalui panel admin. Mendukung
 * pemilihan tabel secara dinamis, validasi berbasis skema, penanganan foreign key,
 * operasi bulk, ekspor data, dan audit logging.
 *
 * Fitur utama:
 * - Pemilihan tabel dinamis dari whitelist yang tersedia
 * - Form builder otomatis berdasarkan struktur tabel
 * - Validasi skema dengan pesan error yang user-friendly
 * - Dukungan relasi foreign key dan embedded creation
 * - Kustomisasi kolom tampilan per tabel
 * - Operasi bulk delete dengan validasi constraint
 * - Ekspor data ke format CSV
 * - Audit logging untuk semua operasi CRUD
 * - Perlindungan soft delete dan system table
 *
 * @package App\Filament\Pages
 * @author  CTO CRUD App Team
 * @version 1.0
 * @since   1.0.0
 */
class DynamicCrud extends Page implements HasTable, HasForms
{
    use InteractsWithTable;
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'CTO CRUD';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.dynamic-crud';
    protected static ?string $title = 'CTO CRUD';
    protected static ?string $slug = '/';


    public ?array $config = [];

    public ?string $selectedTable = null;

    /**
     * Daftar kolom yang dipilih untuk ditampilkan per tabel
     *
     * Format key:
     * - self:{column} untuk kolom dari tabel sendiri
     * - fk:{fk_column}:{ref_table}:{ref_column} untuk kolom dari tabel relasi
     *
     * @var array<string, array<int, string>>
     */
    public array $tableFieldSelections = [];

    /**
     * Mengecek apakah user yang sedang login memiliki akses ke halaman ini
     *
     * Hanya user dengan role 'admin' yang dapat mengakses halaman Dynamic CRUD.
     * Method ini melakukan defensive checking untuk memastikan guard yang digunakan
     * adalah model User yang memiliki method hasRole.
     *
     * @return bool True jika user memiliki akses, false jika tidak
     */
    public static function canAccess(): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $u = Auth::user();

        return $u instanceof \App\Models\User
            && \method_exists($u, 'hasRole')
            && $u->hasRole('admin');
    }

    /**
     * Mengecek apakah halaman ini harus ditampilkan di navigasi
     *
     * @return bool True jika halaman harus muncul di menu navigasi
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /**
     * Inisialisasi halaman saat pertama kali dimuat
     *
     * Method ini dipanggil oleh Livewire lifecycle dan digunakan untuk:
     * - Melakukan pengecekan akses
     * - Mengambil daftar tabel yang tersedia dari whitelist
     * - Mengatur tabel default jika belum ada yang dipilih
     * - Menginisialisasi form dengan nilai default
     *
     * @param TableBuilderService $svc Service untuk mengambil daftar tabel
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Jika user tidak memiliki akses
     */
    public function mount(TableBuilderService $svc): void
    {
        if (!self::canAccess()) {
            abort(403);
        }

        $tables = $svc->listUserTables();
        if (!$this->selectedTable && !empty($tables)) {
            $this->selectedTable = $tables[0];
        }

        $this->form->fill(['table' => $this->selectedTable]);
    }

    /**
     * Mendefinisikan form untuk pemilihan tabel
     *
     * Form ini berisi dropdown untuk memilih tabel dari daftar whitelist yang tersedia.
     * Saat tabel dipilih, halaman akan di-refresh untuk menampilkan data dari tabel tersebut.
     *
     * @param Form $form Instance form dari Filament
     * @return Form Form yang telah dikonfigurasi
     */
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
                        $safe = app(DynamicSchemaService::class)->sanitizeTable($state);
                        $this->selectedTable = $safe;
                        $this->resetTable();
                    })
                    ->helperText('Pilih tabel database yang ingin dikelola. Tabel baru akan muncul secara otomatis.'),
            ])
            ->statePath('config');
    }

    /**
     * Mendefinisikan tabel untuk menampilkan data CRUD
     *
     * Tabel ini secara dinamis menampilkan data dari tabel yang dipilih dengan:
     * - Query builder dinamis dengan dukungan join untuk foreign key
     * - Kolom yang dapat dikustomisasi per tabel
     * - Filter untuk soft delete
     * - Actions untuk Create, Edit, Delete, Export
     * - Bulk actions untuk operasi massal
     *
     * @param Table $table Instance table dari Filament
     * @return Table Table yang telah dikonfigurasi
     */
    public function table(Table $table): Table
    {
        return $table
            ->query($this->getRuntimeQuery())
            ->columns($this->inferTableColumns())
            ->filters($this->buildFilters())
            ->headerActions($this->buildHeaderActions())
            ->actions($this->buildRowActions())
            ->bulkActions($this->buildBulkActions())
            ->toggleColumnsTriggerAction(fn(Action $action) => $action->hidden())
            ->emptyStateHeading($this->selectedTable ? 'Tidak ada data ditemukan' : 'Pilih tabel untuk memulai')
            ->emptyStateDescription($this->selectedTable
                ? 'Belum ada data pada tabel ini. Tambahkan data baru untuk mulai mengelola.'
                : 'Gunakan pemilih di atas untuk memilih tabel. Anda juga dapat membuat tabel baru melalui Pembuat Tabel.')
            ->striped()
            ->deferLoading()
            ->paginated([10, 25, 50, 100]);
    }

    /**
     * Membuat query runtime untuk tabel yang dipilih
     *
     * Query ini dibangun secara dinamis dengan dukungan join untuk foreign key
     * berdasarkan kolom yang dipilih untuk ditampilkan.
     *
     * @return Builder Query builder Eloquent untuk tabel yang dipilih
     */
    protected function getRuntimeQuery(): Builder
    {
        $qb = app(DynamicQueryBuilder::class);
        return $qb->build($this->selectedTable, $this->selectedFieldsForTable());
    }

    /**
     * Menghasilkan definisi kolom tabel secara dinamis
     *
     * Method ini membuat kolom-kolom Filament berdasarkan:
     * - Kolom yang dipilih user (atau default 20 kolom pertama)
     * - Tipe data kolom (boolean, date, json, enum, dll)
     * - Status kolom (primary key, indexed, dll)
     * - Relasi foreign key
     *
     * @return array<int, \Filament\Tables\Columns\Column> Array kolom Filament
     */
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

    /**
     * Membangun actions untuk header tabel
     *
     * Actions yang tersedia:
     * - Pilih Kolom: Memilih kolom mana yang ingin ditampilkan
     * - Tambah Data: Membuat record baru dengan validasi skema
     * - Ekspor CSV: Mengekspor data tabel ke format CSV
     *
     * @return array<int, Action> Array actions untuk header tabel
     */
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
                        $rules = $this->buildValidationRules(false);
                        Validator::make($data, $rules)->validate();

                        $this->resolveEmbeddedForeigns($data);

                        try {
                            $colsMeta = app(DynamicSchemaService::class)->columns($this->selectedTable);
                            foreach ($colsMeta as $colName => $meta) {
                                if (($meta['type'] ?? null) === 'json' && isset($data[$colName]) && is_array($data[$colName])) {
                                    $clean = [];
                                    foreach ($data[$colName] as $k => $v) {
                                        if ($k === null || $k === '' || $v === '' || $v === null) continue;
                                        $clean[$k] = $v;
                                    }
                                    $data[$colName] = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
                                }
                            }
                        } catch (\Throwable $e) {
                        }

                        $model = new DynamicModel();
                        $model->setRuntimeTable($this->selectedTable);

                        $this->applySafeDefaults($data, false);

                        $record = $model->create($data);
                    } catch (ValidationException $ve) {
                        $msg = $this->firstValidationMessage($ve);
                        Notification::make()->danger()->title('Input tidak valid')->body($msg)->send();
                        throw $ve;
                    } catch (QueryException $qe) {
                        [$t, $b] = $this->friendlyDbError($qe);
                        Notification::make()->danger()->title($t)->body($b)->send();
                        throw new Halt();
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

    /**
     * Membangun actions untuk setiap baris data
     *
     * Actions yang tersedia untuk setiap record:
     * - Edit: Mengubah data dengan validasi skema
     * - Delete: Menghapus data dengan pengecekan constraint
     *
     * @return array<int, Action> Array actions untuk baris tabel
     */
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
                        $rules = $this->buildValidationRules(true);
                        Validator::make($data, $rules)->validate();

                        $this->stripEmbeddedForeigns($data);

                        $this->applySafeDefaults($data, true);

                        try {
                            $colsMeta = app(DynamicSchemaService::class)->columns($this->selectedTable);
                            foreach ($colsMeta as $colName => $meta) {
                                if (($meta['type'] ?? null) === 'json' && array_key_exists($colName, $data)) {
                                    if (is_array($data[$colName])) {
                                        $clean = [];
                                        foreach ($data[$colName] as $k => $v) {
                                            if ($k === null || $k === '' || $v === '' || $v === null) continue;
                                            $clean[$k] = $v;
                                        }
                                        $data[$colName] = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
                                    } elseif ($data[$colName] === '') {
                                        $data[$colName] = null;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                        }

                        $record->fill($data);
                        $record->save();
                    } catch (ValidationException $ve) {
                        $msg = $this->firstValidationMessage($ve);
                        Notification::make()->danger()->title('Input tidak valid')->body($msg)->send();
                        throw $ve;
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
                    $blocked = $this->hasRestrictingIncomingReferences($record->{$this->primaryKeyName()});
                    if ($blocked) {
                        Notification::make()
                            ->danger()
                            ->title('Tidak dapat menghapus')
                            ->body('Record ini direferensikan oleh tabel lain (ON DELETE RESTRICT/NO ACTION).')
                            ->send();
                        return $record;
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

    /**
     * Membangun bulk actions untuk operasi massal
     *
     * Bulk actions yang tersedia:
     * - Delete: Menghapus multiple records sekaligus dengan validasi constraint
     *
     * @return array<int, BulkAction> Array bulk actions
     */
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

    /**
     * Membangun filters untuk tabel
     *
     * Filter yang tersedia:
     * - Soft Delete Filter: Jika tabel memiliki kolom deleted_at
     *
     * @return array<int, \Filament\Tables\Filters\BaseFilter> Array filters
     */
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

    /**
     * Menghasilkan form schema secara dinamis berdasarkan struktur tabel
     *
     * @param bool $isEdit True jika form untuk edit, false untuk create
     * @param bool $forView True jika form untuk view only
     * @return array<int, \Filament\Forms\Components\Component> Array komponen form
     */
    protected function inferFormSchema(bool $isEdit, bool $forView = false): array
    {
        if (!$this->selectedTable) return [];
        return app(DynamicFormService::class)->buildForm($this->selectedTable, $isEdit, $forView);
    }

    /**
     * Mendapatkan daftar kolom yang dipilih untuk ditampilkan
     *
     * @return array<int, string> Array key kolom yang dipilih
     */
    protected function selectedFieldsForTable(): array
    {
        if (!$this->selectedTable) {
            return [];
        }
        return $this->tableFieldSelections[$this->selectedTable] ?? [];
    }

    /**
     * Membuat alias untuk join table
     *
     * @param string $fkCol Nama kolom foreign key
     * @param string $refTable Nama tabel yang direferensikan
     * @return string Alias untuk join table
     */
    protected function aliasForJoin(string $fkCol, string $refTable): string
    {
        return $refTable . '__' . $fkCol;
    }

    /**
     * Membuat alias untuk kolom dari join table
     *
     * @param string $fkCol Nama kolom foreign key
     * @param string $refTable Nama tabel yang direferensikan
     * @param string $refCol Nama kolom di tabel yang direferensikan
     * @return string Alias untuk kolom join
     */
    protected function aliasForJoinColumn(string $fkCol, string $refTable, string $refCol): string
    {
        return 'fk_' . $fkCol . '__' . $refTable . '__' . $refCol;
    }

    /**
     * Mendapatkan mapping foreign key untuk tabel
     *
     * @param string $table Nama tabel
     * @return array<string, array{referenced_table: string, referenced_column: string}> Map foreign key
     */
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

    /**
     * Mengecek apakah primary key adalah auto increment
     *
     * @return bool True jika primary key auto increment
     */
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

        return $pk === 'id' && in_array($type, ['integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true);
    }

    /**
     * Mendapatkan pesan error pertama dari ValidationException
     *
     * @param ValidationException $ve Exception validasi
     * @return string Pesan error pertama
     */
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
        }
        return 'Input tidak valid.';
    }

    /**
     * Menerapkan default values yang aman untuk data
     *
     * Method ini:
     * - Menghapus system columns (created_at, updated_at, deleted_at)
     * - Menghapus primary key saat edit
     * - Menambahkan timestamps otomatis saat create
     *
     * @param array<string, mixed> &$data Data yang akan dimodifikasi (passed by reference)
     * @param bool $isEdit True jika operasi edit, false jika create
     * @return void
     */
    protected function applySafeDefaults(array &$data, bool $isEdit): void
    {
        foreach (array_keys($data) as $key) {
            if ($this->isSystemColumn($key) || ($isEdit && $this->isPrimaryKey($key))) {
                unset($data[$key]);
            }
        }

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
     * Menyelesaikan embedded foreign key fields saat create record
     *
     * Method ini mengkonversi field dengan format fk_new__{fk_col}__{ref_table}__{label_col}
     * menjadi nilai foreign key yang sebenarnya. Jika record dengan nilai label tersebut
     * sudah ada, akan digunakan ID-nya. Jika belum ada, akan dibuat record baru.
     *
     * @param array<string, mixed> &$data Data form yang akan dimodifikasi (passed by reference)
     * @return void
     */
    protected function resolveEmbeddedForeigns(array &$data): void
    {
        $fks = app(DynamicSchemaService::class)->foreignKeys($this->selectedTable);
        foreach ($fks as $fkCol => $ref) {
            $refTable = $ref['referenced_table'] ?? null;
            $refPk = $ref['referenced_column'] ?? 'id';
            if (!$refTable) continue;

            $prefix = 'fk_new__' . $fkCol . '__' . $refTable . '__';
            $labelFields = [];
            foreach ($data as $k => $v) {
                if (str_starts_with($k, $prefix)) {
                    $labelCol = substr($k, strlen($prefix));
                    $labelFields[$labelCol] = $v;
                }
            }
            if (empty($labelFields)) continue;

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
                $isAuto = app(DynamicSchemaService::class)->isPrimaryAutoIncrement($refTable);
                if (!$isAuto && !isset($labelFields[$refPk]) && Schema::hasColumn($refTable, $refPk)) {
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

            foreach (array_keys($labelFields) as $c) {
                unset($data[$prefix . $c]);
            }
        }
    }

    /**
     * Menghapus embedded foreign key fields dari data
     *
     * Digunakan saat edit untuk menghindari pemrosesan embedded fields
     * karena embedded creation hanya diperbolehkan saat create.
     *
     * @param array<string, mixed> &$data Data yang akan dibersihkan (passed by reference)
     * @return void
     */
    protected function stripEmbeddedForeigns(array &$data): void
    {
        foreach (array_keys($data) as $k) {
            if (str_starts_with($k, 'fk_new__')) unset($data[$k]);
        }
    }

    /**
     * Membangun rules validasi Laravel berdasarkan skema tabel
     *
     * Rules yang dihasilkan mencakup:
     * - required untuk kolom non-nullable (kecuali auto-increment PK saat create)
     * - numeric untuk tipe integer/decimal
     * - date rules untuk tipe date/time
     * - string max length untuk varchar/char
     * - exists rules untuk foreign keys
     *
     * @param bool $isEdit True jika validasi untuk edit, false untuk create
     * @return array<string, mixed> Array validation rules
     */
    protected function buildValidationRules(bool $isEdit): array
    {
        if (!$this->selectedTable) {
            return [];
        }
        return app(DynamicFormService::class)->buildRules($this->selectedTable, $isEdit);
    }

    /**
     * Mengecek apakah kolom adalah primary key
     *
     * @param string $column Nama kolom
     * @return bool True jika kolom adalah primary key
     */
    protected function isPrimaryKey(string $column): bool
    {
        return $column === $this->primaryKeyName();
    }

    /**
     * Mendapatkan nama primary key dari tabel
     *
     * Menggunakan DynamicSchemaService untuk mendapatkan informasi metadata PK.
     * Fallback ke 'id' jika tidak dapat ditentukan.
     *
     * @return string Nama kolom primary key
     */
    protected function primaryKeyName(): string
    {
        if (!$this->selectedTable) {
            return 'id';
        }

        return app(DynamicSchemaService::class)->primaryKey($this->selectedTable);
    }

    /**
     * Mengecek apakah kolom terlihat seperti foreign key
     *
     * Heuristik sederhana: kolom yang berakhiran '_id' dan bukan primary key.
     *
     * @param string $column Nama kolom
     * @return bool True jika kolom seperti foreign key
     */
    protected function looksLikeForeignKey(string $column): bool
    {
        return Str::endsWith($column, '_id') && $column !== $this->primaryKeyName();
    }

    /**
     * Mengecek apakah kolom adalah system column
     *
     * System columns adalah kolom yang dikelola otomatis oleh framework:
     * created_at, updated_at, deleted_at
     *
     * @param string $name Nama kolom
     * @return bool True jika kolom adalah system column
     */
    protected function isSystemColumn(string $name): bool
    {
        return in_array($name, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    /**
     * Mengkonversi database error menjadi pesan yang user-friendly
     *
     * Method ini mengidentifikasi berbagai jenis error database (MySQL):
     * - Data too long (1406): Teks terlalu panjang
     * - Incorrect value: Format angka tidak valid
     * - Foreign key constraint fails (1452): Referensi tidak ditemukan
     * - Duplicate entry (1062): Nilai sudah dipakai
     * - Cannot be null (1048): Isian wajib diisi
     *
     * Dan menghasilkan pesan dalam Bahasa Indonesia yang mudah dipahami.
     *
     * @param QueryException $e Exception dari query database
     * @return array{0: string, 1: string} Tuple [title, body] pesan error
     */
    protected function friendlyDbError(QueryException $e): array
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($driverCode === 1406 || Str::contains($message, ['Data too long for column', 'value too long'])) {
            preg_match("/for column '([^']+)'/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Teks terlalu panjang';
            $body = $col
                ? "Nilai untuk kolom '{$col}' melebihi batas panjang. Kurangi jumlah karakter sesuai panjang kolom."
                : 'Beberapa isian melebihi batas panjang. Kurangi jumlah karakter sesuai panjang kolom.';
            return [$title, $body];
        }

        if (Str::contains($message, ['Incorrect integer value', 'Incorrect double value', 'Incorrect decimal value'])) {
            preg_match("/for column '([^']+)'/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Format angka tidak valid';
            $body = $col
                ? "Isian '{$col}' harus berupa angka yang valid."
                : 'Beberapa isian angka tidak valid. Gunakan hanya angka.';
            return [$title, $body];
        }

        if ($driverCode === 1452 || Str::contains($message, 'foreign key constraint fails')) {
            preg_match("/CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\)/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Referensi tidak ditemukan';
            $body = $col
                ? "Nilai pada '{$col}' tidak cocok dengan data referensi. Pilih nilai yang tersedia di daftar."
                : 'Beberapa nilai referensi tidak ditemukan. Pilih nilai yang tersedia di daftar.';
            return [$title, $body];
        }

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

        if ($driverCode === 1048 || Str::contains($message, 'cannot be null')) {
            preg_match("/Column '([^']+)' cannot be null/i", $message, $m);
            $col = $m[1] ?? null;
            $title = 'Isian wajib diisi';
            $body = $col
                ? "Kolom '{$col}' wajib diisi."
                : 'Ada kolom yang wajib diisi.';
            return [$title, $body];
        }

        return ['Gagal menyimpan', 'Periksa input Anda dan coba lagi.'];
    }

    /**
     * Mengecek apakah tabel memiliki kolom deleted_at
     *
     * @return bool True jika tabel support soft delete
     */
    protected function hasDeletedAtColumn(): bool
    {
        return $this->selectedTable && app(DynamicSchemaService::class)->hasDeletedAt($this->selectedTable);
    }

    /**
     * Membuat qualified column name dengan nama tabel
     *
     * @param string $column Nama kolom
     * @return string Qualified column name (table.column)
     */
    protected function qualified(string $column): string
    {
        return $this->selectedTable . '.' . $column;
    }

    /**
     * Menebak kolom yang tepat untuk dijadikan label/display
     *
     * @param string $table Nama tabel
     * @return string Nama kolom yang cocok untuk label
     */
    protected function guessLabelColumn(string $table): string
    {
        return app(DynamicSchemaService::class)->guessLabelColumn($table);
    }

    /**
     * Mendapatkan metadata kolom untuk tabel yang sedang dipilih
     *
     * @return array<string, array{type: string, nullable: bool, default: mixed}> Map kolom ke metadata
     */
    protected function getColumnMeta(): array
    {
        return $this->selectedTable ? app(DynamicSchemaService::class)->columns($this->selectedTable) : [];
    }

    /**
     * Mengecek apakah tabel adalah system table
     *
     * System tables adalah tabel yang digunakan oleh framework dan tidak boleh
     * dimodifikasi melalui Dynamic CRUD, seperti migrations, permissions, dll.
     *
     * @param string|null $name Nama tabel
     * @return bool True jika tabel adalah system table
     */
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
     * Mengecek apakah record memiliki referensi incoming dengan DELETE RESTRICT/NO ACTION
     *
     * Method ini memeriksa apakah ada tabel lain yang mereferensikan primary key dari
     * record ini melalui foreign key dengan aturan ON DELETE RESTRICT atau NO ACTION.
     * Jika ada, record tidak boleh dihapus untuk menjaga integritas referensial.
     *
     * @param mixed $pkValue Nilai primary key yang akan dicek
     * @return bool True jika ada referensi yang memblokir penghapusan
     */
    protected function hasRestrictingIncomingReferences($pkValue): bool
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = 'mysql';
        }

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
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

    /**
     * Mencatat aktivitas user ke audit log
     *
     * Method ini mencatat semua operasi CRUD yang dilakukan user ke tabel admin_audit_logs
     * untuk keperluan tracking dan compliance. Jika tabel audit tidak ada atau terjadi
     * error, operasi tidak akan diblokir.
     *
     * @param string $action Nama aksi yang dilakukan (e.g., 'record.created', 'record.updated')
     * @param array<string, mixed> $context Data konteks tambahan untuk audit
     * @return void
     */
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
        }
    }

    /**
     * Mengekspor data tabel ke format CSV
     *
     * Method ini menggunakan DynamicExportService untuk menghasilkan file CSV
     * yang dapat di-download oleh user.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|null Response download CSV
     */
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
