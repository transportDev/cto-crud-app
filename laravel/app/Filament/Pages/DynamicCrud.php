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
use Illuminate\Support\Str;

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

    // Header selector form
    public ?array $config = [];
    public ?string $selectedTable = null;

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
                : 'Gunakan pemilih di atas untuk memilih tabel. Anda juga dapat membuat tabel baru melalui Table Builder.')
            ->striped()
            ->deferLoading()
            ->paginated([10, 25, 50, 100]);
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

        // Prefer to show up to 8 columns in the table by default
        $displayColumns = \array_slice($columns, 0, 8, preserve_keys: true);

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
                    ->limit(60)
                    ->tooltip(fn($state) => \is_string($state) ? $state : json_encode($state))
                    ->toggleable();
            } else {
                $col = TextColumn::make($name)
                    ->label($label)
                    ->searchable(in_array($type, ['string', 'text', 'char', 'uuid', 'ulid'], true))
                    ->toggleable()
                    ->sortable();
            }

            if ($this->isPrimaryKey($name)) {
                $col->label($label . ' (PK)')->weight('bold');
            }

            $filamentColumns[] = $col;
        }

        return $filamentColumns;
    }

    protected function buildHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add record')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn() => filled($this->selectedTable))
                ->form(fn() => $this->inferFormSchema(isEdit: false))
                ->using(function (array $data): Model {
                    $model = new DynamicModel();
                    $model->setRuntimeTable($this->selectedTable);

                    $this->applySafeDefaults($data, isEdit: false);

                    $record = $model->create($data);

                    $this->audit('record.created', [
                        'table' => $this->selectedTable,
                        'data' => $data,
                        'id' => $record->{$this->primaryKeyName()},
                    ]);

                    return $record;
                }),
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn() => filled($this->selectedTable))
                ->action('exportCsv'),
        ];
    }

    protected function buildRowActions(): array
    {
        return [
            ViewAction::make()
                ->form(fn($record) => $this->inferFormSchema(isEdit: false, forView: true))
                ->modalHeading('View record')
                ->label('View'),
            EditAction::make()
                ->label('Edit')
                ->form(fn($record) => $this->inferFormSchema(isEdit: true))
                ->using(function (Model $record, array $data) {
                    $this->applySafeDefaults($data, isEdit: true);

                    $record->fill($data);
                    $record->save();

                    $this->audit('record.updated', [
                        'table' => $this->selectedTable,
                        'id' => $record->{$this->primaryKeyName()},
                        'changes' => $data,
                    ]);

                    return $record;
                }),
            DeleteAction::make()
                ->label('Delete')
                ->requiresConfirmation()
                ->visible(fn() => !$this->isSystemTable($this->selectedTable))
                ->before(function (Model $record) {
                    // Optional: Check FK constraints proactively
                    // Rely on DB constraints to block if violation occurs.
                })
                ->after(function (Model $record) {
                    $this->audit('record.deleted', [
                        'table' => $this->selectedTable,
                        'id' => $record->{$this->primaryKeyName()},
                    ]);
                }),
        ];
    }

    protected function buildBulkActions(): array
    {
        return [
            BulkAction::make('bulk_delete')
                ->label('Delete selected')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
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
                ->deselectRecordsAfterCompletion(),
        ];
    }

    protected function buildFilters(): array
    {
        $filters = [];

        if ($this->hasDeletedAtColumn()) {
            $filters[] = Tables\Filters\TernaryFilter::make('trashed')
                ->label('Trashed')
                ->trueLabel('Only trashed')
                ->falseLabel('Exclude trashed')
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

        foreach ($columns as $name => $meta) {
            if ($this->isSystemColumn($name)) {
                continue;
            }

            $type = $meta['type'] ?? 'string';
            $nullable = (bool) ($meta['nullable'] ?? false);
            $length = $meta['length'] ?? null;

            // Primary keys are not editable once created if unsafe
            if ($this->isPrimaryKey($name) && $isEdit) {
                $component = Forms\Components\TextInput::make($name)
                    ->disabled()
                    ->dehydrated(false)
                    ->label(Str::headline($name));
                $schema[] = $component;
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
                Forms\Components\KeyValue::make($name)->addable()->deletable()->reorderable()->keyLabel('key')->valueLabel('value'),
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

            // Foreign key inference (_id postfix)
            if ($this->looksLikeForeignKey($name)) {
                $refTable = Str::of($name)->beforeLast('_id')->snake()->plural()->toString();
                if (Schema::hasTable($refTable)) {
                    $labelColumn = $this->guessLabelColumn($refTable);
                    $component = Forms\Components\Select::make($name)
                        ->searchable()
                        ->getSearchResultsUsing(
                            fn(string $search) =>
                            DB::table($refTable)
                                ->where($labelColumn, 'like', "%{$search}%")
                                ->limit(50)
                                ->pluck($labelColumn, 'id')
                        )
                        ->getOptionLabelUsing(
                            fn($value): ?string =>
                            DB::table($refTable)
                                ->where('id', $value)
                                ->value($labelColumn)
                        )
                        ->helperText("References {$refTable}.{$labelColumn}");
                }
            }

            $component->label(Str::headline($name));

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

            $schema[] = $component;
        }

        return $schema;
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

        // Laravel's native schema builder doesn't have a universal, reliable way to get
        // the primary key name across all DB drivers without Doctrine. We'll use a
        // reliable heuristic that covers 99% of cases.
        if (Schema::hasColumn($this->selectedTable, 'id')) {
            return 'id';
        }

        $guess = Str::of($this->selectedTable)->singular()->snake()->append('_id')->toString();
        if (Schema::hasColumn($this->selectedTable, $guess)) {
            return $guess;
        }

        // Fallback to the first column if no better guess.
        $columns = Schema::getColumnListing($this->selectedTable);
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

    public function exportCsv()
    {
        $table = $this->selectedTable;
        if (!$table) {
            Notification::make()->danger()->title('No table selected')->send();
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
