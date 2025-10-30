<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\TableBuilderService;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Halaman Table Builder
 *
 * Halaman ini memungkinkan administrator untuk membuat tabel database secara visual
 * dengan wizard step-by-step. Fitur yang tersedia:
 * - Definisi tabel dengan nama dan opsi (timestamps, soft deletes)
 * - Definisi kolom dengan berbagai tipe data
 * - Preview struktur tabel
 * - Generate dan eksekusi migration
 *
 * @package App\Filament\Pages
 * @author CTO Panel
 * @since 1.0.0
 */
class TableBuilder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';
    protected static string $view = 'filament.pages.table-builder';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = null;
    protected static ?int $navigationSort = 1;
    protected static ?string $title = null;


    public ?array $data = [];


    public ?string $preview = null;


    public ?array $tablePreview = null;


    public bool $previewLoading = false;


    public bool $autoRefresh = true;

    public int $currentStep = 0;

    protected $listeners = [
        'previewTable' => 'previewTable',
        'handleRefreshPreview' => 'handleRefreshPreview',
        'wizardStepChanged' => 'handleWizardStepChanged'
    ];

    /**
     * Mendapatkan label navigasi dari file translasi
     *
     * @return string Label navigasi
     */
    public static function getNavigationLabel(): string
    {
        return __('table-builder.navigation_label');
    }

    /**
     * Mendapatkan judul halaman dari file translasi
     *
     * @return string Judul halaman
     */
    public function getTitle(): string
    {
        return __('table-builder.title');
    }

    /**
     * Inisialisasi halaman dan setup form awal
     *
     * @return void
     */
    public function mount(): void
    {
        if (!self::canAccess()) {
            abort(403);
        }

        app()->setLocale('id');

        $this->form->fill([
            'timestamps' => true,
            'soft_deletes' => false,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
            ],
        ]);
    }

    /**
     * Memeriksa apakah pengguna dapat mengakses halaman ini
     *
     * @return bool True jika pengguna memiliki role admin
     */
    public static function canAccess(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        $u = Auth::user();
        return $u instanceof \App\Models\User && method_exists($u, 'hasRole') && $u->hasRole('admin');
    }

    /**
     * Menentukan apakah halaman ini harus ditampilkan di navigasi
     *
     * @return bool True jika pengguna dapat mengakses
     */
    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /**
     * Mendefinisikan skema form wizard untuk membuat tabel
     *
     * Form wizard terdiri dari 3 step:
     * 1. Informasi Tabel: Nama tabel dan opsi dasar
     * 2. Definisi Kolom: Detil setiap kolom dengan tipe dan atribut
     * 3. Preview & Konfirmasi: Tampilan preview sebelum eksekusi
     *
     * @param Form $form Instance form Filament
     * @return Form Form yang sudah dikonfigurasi
     */
    public function form(Form $form): Form
    {
        $integerTypes = ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger'];
        $numericTypes = ['decimal', 'float', 'double'];
        $stringTypes = ['string', 'char'];
        $dateTypes = ['date', 'time', 'datetime', 'timestamp'];

        return $form
            ->schema([
                Wizard::make([
                    Wizard\Step::make(__('table-builder.steps.table_info'))
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Forms\Components\TextInput::make('table')
                                ->label(__('table-builder.table_name'))
                                ->required()
                                ->live(debounce: 500)
                                ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                ->validationMessages([
                                    'required' => 'Nama tabel wajib diisi',
                                    'regex' => __('table-builder.table_name_validation'),
                                ])
                                ->rule(function () {
                                    return function (string $attribute, $value, \Closure $fail) {
                                        if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                            $fail(__('table-builder.table_name_invalid'));
                                        }
                                        if ($value && Schema::hasTable($value)) {
                                            $fail(__('table-builder.table_name_exists'));
                                        }
                                    };
                                })
                                ->helperText(__('table-builder.table_name_helper')),
                            Grid::make(3)->schema([
                                Forms\Components\Toggle::make('timestamps')
                                    ->label(__('table-builder.timestamps'))
                                    ->helperText(__('table-builder.timestamps_helper'))
                                    ->default(true),
                                Forms\Components\Toggle::make('soft_deletes')
                                    ->label(__('table-builder.soft_deletes'))
                                    ->helperText(__('table-builder.soft_deletes_helper'))
                                    ->default(false),
                                Forms\Components\TextInput::make('comment')
                                    ->label(__('table-builder.table_comment'))
                                    ->columnSpan(1),
                            ]),
                        ]),
                    Wizard\Step::make(__('table-builder.steps.columns'))
                        ->icon('heroicon-o-table-cells')
                        ->schema([
                            Forms\Components\Repeater::make('columns')
                                ->label('')
                                ->minItems(1)
                                ->reorderable(true)
                                ->collapsible()
                                ->collapsed()
                                ->addActionLabel(__('table-builder.add_column'))
                                ->itemLabel(fn(array $state): ?string => ($state['name'] ?? __('table-builder.steps.columns')) . ' : ' . ($state['type'] ?? '...'))
                                ->schema([
                                    Section::make()->schema([
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('name')
                                                ->label(__('table-builder.column_name'))
                                                ->required()
                                                ->live(debounce: 500)
                                                ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                                ->validationMessages([
                                                    'regex' => __('table-builder.column_name_validation'),
                                                ])
                                                ->rule(function () {
                                                    return function (string $attribute, $value, \Closure $fail) {
                                                        if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                                            $fail(__('table-builder.column_name_invalid'));
                                                        }
                                                    };
                                                })
                                                ->columnSpan(3),
                                            Forms\Components\Select::make('type')
                                                ->label(__('table-builder.column_type'))
                                                ->required()
                                                ->searchable()
                                                ->live()
                                                ->options([
                                                    'string' => 'varchar',
                                                    'char' => 'char',
                                                    'text' => 'text',
                                                    'mediumText' => 'mediumText',
                                                    'longText' => 'longText',
                                                    'integer' => 'integer',
                                                    'tinyInteger' => 'tinyInteger',
                                                    'smallInteger' => 'smallInteger',
                                                    'mediumInteger' => 'mediumInteger',
                                                    'bigInteger' => 'bigInteger',
                                                    'decimal' => 'decimal',
                                                    'float' => 'float',
                                                    'double' => 'double',
                                                    'boolean' => 'boolean',
                                                    'date' => 'date',
                                                    'time' => 'time',
                                                    'datetime' => 'datetime',
                                                    'timestamp' => 'timestamp',
                                                    'uuid' => 'uuid',
                                                    'ulid' => 'ulid',
                                                    'json' => 'json',
                                                    'enum' => 'enum',
                                                    'set' => 'set',
                                                    'foreignId' => 'foreignId',
                                                ])
                                                ->default('string')
                                                ->columnSpan(3),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('length')->numeric()->minValue(1)->visible(fn($get) => in_array($get('type'), $stringTypes, true))->helperText(__('table-builder.length_helper'))->columnSpan(2),
                                            Forms\Components\TextInput::make('precision')->numeric()->minValue(1)->visible(fn($get) => in_array($get('type'), $numericTypes, true))->helperText(__('table-builder.precision_helper'))->columnSpan(2),
                                            Forms\Components\TextInput::make('scale')->numeric()->minValue(0)->visible(fn($get) => in_array($get('type'), $numericTypes, true))->helperText(__('table-builder.scale_helper'))->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Toggle::make('unsigned')->label(__('table-builder.unsigned'))->visible(fn($get) => in_array($get('type'), $integerTypes, true))->inline(false),
                                            Forms\Components\Toggle::make('auto_increment')->label(__('table-builder.auto_increment'))->visible(fn($get) => in_array($get('type'), ['integer', 'bigInteger'], true))->inline(false),
                                            Forms\Components\Toggle::make('primary')->label(__('table-builder.primary_key'))->visible(fn($get) => in_array($get('type'), array_merge($integerTypes, ['uuid', 'ulid']), true))->inline(false),
                                            Forms\Components\Toggle::make('nullable')->label(__('table-builder.nullable'))->inline(false),
                                            Forms\Components\Toggle::make('unique')->label(__('table-builder.unique'))->inline(false),
                                            Forms\Components\Select::make('index')->label(__('table-builder.index'))->options([
                                                null => __('table-builder.index_options.none'),
                                                'index' => __('table-builder.index_options.index'),
                                                'fulltext' => __('table-builder.index_options.fulltext')
                                            ])->default(null),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Select::make('default')
                                                ->label(__('table-builder.default_value'))
                                                ->visible(fn($get) => in_array($get('type'), ['date', 'datetime', 'timestamp'], true))
                                                ->options(function ($get) {
                                                    $type = $get('type');
                                                    $baseOptions = ['' => __('table-builder.default_options.none')];

                                                    if ($type === 'date') {
                                                        return array_merge($baseOptions, [
                                                            'CURDATE()' => 'CURDATE() - Current Date',
                                                            'CURRENT_DATE' => 'CURRENT_DATE - Current Date',
                                                        ]);
                                                    } elseif (in_array($type, ['datetime', 'timestamp'])) {
                                                        return array_merge($baseOptions, [
                                                            'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
                                                            'NOW()' => 'NOW() - Current Date & Time',
                                                        ]);
                                                    }
                                                    return $baseOptions;
                                                })
                                                ->allowHtml()
                                                ->searchable()
                                                ->columnSpan(4)
                                                ->placeholder(__('table-builder.default_placeholder_select'))
                                                ->helperText(__('table-builder.default_helper_datetime')),

                                            Forms\Components\Select::make('default')
                                                ->label(__('table-builder.default_value'))
                                                ->visible(fn($get) => in_array($get('type'), ['uuid', 'ulid'], true))
                                                ->options([
                                                    '' => __('table-builder.default_options.none'),
                                                    'UUID()' => 'UUID() - Generate UUID',
                                                    'ULID()' => 'ULID() - Generate ULID',
                                                ])
                                                ->allowHtml()
                                                ->searchable()
                                                ->columnSpan(4)
                                                ->placeholder(__('table-builder.default_placeholder_select'))
                                                ->helperText(__('table-builder.default_helper_uuid')),

                                            Forms\Components\TextInput::make('default')
                                                ->label(__('table-builder.default_value'))
                                                ->visible(fn($get) => !in_array($get('type'), ['boolean', 'date', 'datetime', 'timestamp', 'uuid', 'ulid'], true))
                                                ->columnSpan(4)
                                                ->placeholder(__('table-builder.default_placeholder_text'))
                                                ->helperText(__('table-builder.default_helper_text')),

                                            Forms\Components\Toggle::make('default_bool')
                                                ->label(__('table-builder.default_boolean'))
                                                ->visible(fn($get) => $get('type') === 'boolean')
                                                ->inline(false)
                                                ->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TagsInput::make('enum_options')->label(__('table-builder.enum_options'))->placeholder(__('table-builder.enum_options_placeholder'))->visible(fn($get) => in_array($get('type'), ['enum', 'set'], true))->helperText(__('table-builder.enum_options_helper'))->columnSpan(6),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Select::make('references_table')->label(__('table-builder.references_table'))->searchable()->options(fn() => app(TableBuilderService::class)->listUserTables())->visible(fn($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\TextInput::make('references_column')->label(__('table-builder.references_column'))->default('id')->visible(fn($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\Select::make('on_update')->label(__('table-builder.on_update'))->options([
                                                null => __('table-builder.foreign_actions.no_action'),
                                                'cascade' => __('table-builder.foreign_actions.cascade'),
                                                'restrict' => __('table-builder.foreign_actions.restrict'),
                                                'set_null' => __('table-builder.foreign_actions.set_null')
                                            ])->visible(fn($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\Select::make('on_delete')->label(__('table-builder.on_delete'))->options([
                                                null => __('table-builder.foreign_actions.no_action'),
                                                'cascade' => __('table-builder.foreign_actions.cascade'),
                                                'restrict' => __('table-builder.foreign_actions.restrict'),
                                                'set_null' => __('table-builder.foreign_actions.set_null')
                                            ])->visible(fn($get) => $get('type') === 'foreignId')->columnSpan(3),
                                        ]),
                                        Forms\Components\TextInput::make('comment')->label(__('table-builder.comment'))->maxLength(255),
                                    ])->columns(1),
                                ])
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $names = [];
                                    foreach ($state as $i => $col) {
                                        if (isset($col['name']) && in_array($col['name'], $names, true)) {
                                            $state[$i]['name'] = $col['name'] . '_' . ($i + 1);
                                        } elseif (isset($col['name'])) {
                                            $names[] = $col['name'];
                                        }
                                    }
                                    $set('columns', $state);
                                }),
                        ]),

                    Wizard\Step::make(__('table-builder.steps.preview_confirm'))
                        ->icon('heroicon-o-eye')
                        ->schema([
                            Forms\Components\Tabs::make('preview_tabs')
                                ->tabs([
                                    Forms\Components\Tabs\Tab::make(__('table-builder.preview_title'))
                                        ->schema([
                                            Forms\Components\View::make('filament.pages.partials.table-preview')
                                                ->viewData([
                                                    'tablePreview' => $this->tablePreview,
                                                    'loading' => $this->previewLoading,
                                                ])
                                                ->columnSpanFull(),
                                        ]),

                                ])
                                ->columnSpanFull(),
                        ]),
                ])
                    ->id('table-builder-wizard')
                    ->persistStepInQueryString()
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    /**
     * Handler untuk perubahan step wizard
     *
     * Method ini melakukan validasi sebelum berpindah step dan
     * mengatur auto-preview ketika mencapai step preview
     *
     * @param int $step Nomor step yang akan dituju
     * @return void
     */
    public function handleWizardStepChanged($step): void
    {
        if ($this->currentStep === 0 && $step > 0) {
            $data = $this->form->getState();

            if (empty($data['table'] ?? null)) {
                Notification::make()
                    ->title('Validasi Gagal')
                    ->body('Nama tabel wajib diisi')
                    ->danger()
                    ->send();

                $this->dispatch('wizard-step-changed', ['step' => 0]);
                return;
            }
        }

        $this->currentStep = $step;

        if ($step === 3) {
            $this->dispatch('browser-event', [
                'name' => 'set-preview-step',
                'data' => ['isPreviewStep' => true]
            ]);

            $this->dispatch('delayed-preview');
        } else {
            $this->dispatch('browser-event', [
                'name' => 'set-preview-step',
                'data' => ['isPreviewStep' => false]
            ]);
        }
    }

    /**
     * Generate preview tabel dan migration SQL
     *
     * Method ini membuat:
     * 1. Preview visual struktur tabel
     * 2. Preview kode migration SQL
     *
     * @param bool $silent True untuk tidak menampilkan notifikasi
     * @return void
     */
    public function previewTable(bool $silent = true): void
    {
        if ($this->previewLoading) {
            return;
        }

        $this->previewLoading = true;

        try {
            $service = app(TableBuilderService::class);

            $state = $this->form->getRawState();

            if (blank($state['table']) || empty($state['columns'])) {
                $this->tablePreview = null;
                $this->data['preview'] = null;
                return;
            }

            $state = array_filter($state, fn($value) => $value !== null && $value !== '');

            $names = array_filter(array_map(fn($c) => $c['name'] ?? '', $state['columns'] ?? []));
            if (count($names) !== count(array_unique($names))) {
                if (!$silent) {
                    Notification::make()
                        ->danger()
                        ->title(__('table-builder.notifications.duplicate_columns'))
                        ->send();
                }
                return;
            }

            try {
                $result = $service->preview($state);
                $this->data['preview'] = $result['preview'] ?? 'No preview generated';
            } catch (\Throwable $e) {
                Log::error('Service Preview Error', ['error' => $e->getMessage()]);
                $this->data['preview'] = 'Error generating migration preview: ' . $e->getMessage();
            }

            $this->tablePreview = $this->generateTablePreview($state);

            if (!$silent) {
                Notification::make()
                    ->title(__('table-builder.notifications.preview_generated'))
                    ->body(__('table-builder.notifications.preview_generated_body'))
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::error('Preview Table Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'state' => $this->form->getRawState(),
            ]);

            if (!$silent) {
                Notification::make()
                    ->danger()
                    ->title('Preview Error')
                    ->body('Failed to generate preview: ' . $e->getMessage())
                    ->send();
            }

            $this->tablePreview = [
                'error' => true,
                'message' => 'Preview generation failed',
                'helper' => $e->getMessage(),
            ];
            $this->data['preview'] = 'Error: ' . $e->getMessage();
        } finally {
            $this->previewLoading = false;
            $this->dispatch('preview-completed');
        }
    }

    /**
     * Membuat tabel baru di database berdasarkan form yang diisi
     *
     * Method ini melakukan:
     * 1. Validasi form
     * 2. Cek duplikasi nama kolom
     * 3. Cek eksistensi tabel
     * 4. Eksekusi pembuatan tabel
     * 5. Reset wizard setelah sukses
     *
     * @param TableBuilderService $service Service untuk membuat tabel
     * @return void
     */
    public function createTable(TableBuilderService $service): void
    {
        if ($this->previewLoading) {
            return;
        }

        $this->previewLoading = true;

        try {
            $this->form->validate();
            $state = $this->form->getState();

            $names = array_map(fn($c) => $c['name'] ?? '', $state['columns'] ?? []);
            $names = array_filter($names);

            if (count($names) !== count(array_unique($names))) {
                Notification::make()
                    ->danger()
                    ->title(__('table-builder.notifications.duplicate_columns'))
                    ->send();
                return;
            }

            if (Schema::hasTable($state['table'])) {
                Notification::make()
                    ->danger()
                    ->title(__('table-builder.notifications.table_exists'))
                    ->body(__('table-builder.notifications.table_exists_body', ['table' => $state['table']]))
                    ->send();
                return;
            }

            Log::info('Creating table', [
                'table' => $state['table'],
                'columns_count' => count($state['columns'] ?? [])
            ]);

            $result = $service->create($state);

            Log::info('Table created successfully', [
                'table' => $state['table'],
                'result' => $result
            ]);

            Notification::make()
                ->title(__('table-builder.notifications.table_created'))
                ->body(__('table-builder.notifications.table_created_body', ['table' => $state['table']]))
                ->success()
                ->duration(5000)
                ->send();

            $this->resetWizard();
        } catch (\Exception $e) {
            Log::error('Table Creation Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'table' => $state['table'] ?? 'unknown'
            ]);

            Notification::make()
                ->danger()
                ->title(__('table-builder.notifications.table_creation_failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();
        } finally {
            $this->previewLoading = false;
        }
    }

    /**
     * Mereset wizard ke step awal dan membersihkan semua data
     *
     * @return void
     */
    public function resetWizard(): void
    {
        try {
            $this->data = [];
            $this->preview = null;
            $this->tablePreview = null;
            $this->previewLoading = false;
            $this->currentStep = 0;

            $this->form->fill([
                'timestamps' => true,
                'soft_deletes' => false,
                'columns' => [
                    [
                        'name' => 'id',
                        'type' => 'bigInteger',
                        'auto_increment' => true,
                        'primary' => true,
                        'unsigned' => true
                    ],
                ],
            ]);

            $this->dispatch('reset-wizard-to-step-one');
            $this->dispatch('form-reset-complete');

            Log::info('Wizard form reset successfully');
        } catch (\Exception $e) {
            Log::error('Error resetting wizard', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate preview visual tabel untuk ditampilkan di UI
     *
     * Method ini membuat data preview yang berisi:
     * - Header kolom dengan tipe dan metadata
     * - 5 baris sample data
     * - Kolom timestamp dan soft delete jika diaktifkan
     *
     * @param array<string, mixed> $state Data form state
     * @return array<string, mixed> Data preview tabel
     */
    protected function generateTablePreview(array $state): array
    {
        $columns = $state['columns'] ?? [];

        Log::info('Table Preview Debug', [
            'columns_count' => count($columns),
            'columns_data' => $columns,
            'full_state' => $state
        ]);

        if (empty($columns)) {
            return [
                'error' => true,
                'message' => __('table-builder.no_columns'),
                'helper' => __('table-builder.no_columns_helper'),
            ];
        }

        $headers = [];
        $sampleRows = [];

        foreach ($columns as $index => $column) {
            if (empty($column['name']) || empty($column['type'])) {
                Log::warning('Column missing required fields', [
                    'index' => $index,
                    'column' => $column
                ]);
                continue;
            }

            $metadata = [];

            if (!empty($column['primary'])) {
                $metadata[] = __('table-builder.metadata.primary_key');
            }
            if (!empty($column['nullable'])) {
                $metadata[] = __('table-builder.metadata.nullable');
            }
            if (!empty($column['unique'])) {
                $metadata[] = __('table-builder.metadata.unique');
            }
            if (!empty($column['auto_increment'])) {
                $metadata[] = __('table-builder.metadata.auto_increment');
            }
            if (!empty($column['unsigned'])) {
                $metadata[] = __('table-builder.metadata.unsigned');
            }
            if (isset($column['default']) && $column['default'] !== null && $column['default'] !== '') {
                $metadata[] = __('table-builder.metadata.default') . ': ' . $column['default'];
            } elseif (isset($column['default_bool']) && $column['default_bool'] !== null) {
                $defaultValue = $column['default_bool'] ? __('table-builder.sample_data.boolean_true') : __('table-builder.sample_data.boolean_false');
                $metadata[] = __('table-builder.metadata.default') . ': ' . $defaultValue;
            }

            $headers[] = [
                'name' => $column['name'],
                'type' => $column['type'],
                'metadata' => $metadata,
            ];
        }

        if (empty($headers)) {
            return [
                'error' => true,
                'message' => __('table-builder.no_valid_columns'),
                'helper' => __('table-builder.no_valid_columns_helper'),
            ];
        }

        for ($i = 1; $i <= 5; $i++) {
            $row = [];
            foreach ($columns as $column) {
                if (empty($column['name']) || empty($column['type'])) {
                    continue;
                }
                $row[] = $this->generateSampleValue($column, $i);
            }
            $sampleRows[] = $row;
        }

        if (!empty($state['timestamps'])) {
            $headers[] = [
                'name' => 'created_at',
                'type' => 'timestamp',
                'metadata' => [],
            ];
            $headers[] = [
                'name' => 'updated_at',
                'type' => 'timestamp',
                'metadata' => [],
            ];

            for ($i = 0; $i < 5; $i++) {
                $timestamp = now()->subDays($i)->locale('id')->format('d M Y H.i');
                $sampleRows[$i][] = $timestamp;
                $sampleRows[$i][] = $timestamp;
            }
        }

        if (!empty($state['soft_deletes'])) {
            $headers[] = [
                'name' => 'deleted_at',
                'type' => 'timestamp',
                'metadata' => [__('table-builder.metadata.nullable')],
            ];

            for ($i = 0; $i < 5; $i++) {
                $sampleRows[$i][] = null;
            }
        }

        Log::info('Table Preview Generated Successfully', [
            'headers_count' => count($headers),
            'rows_count' => count($sampleRows)
        ]);

        return [
            'headers' => $headers,
            'rows' => $sampleRows,
            'table_name' => $state['table'] ?? '',
        ];
    }

    /**
     * Generate sample value untuk preview berdasarkan tipe kolom
     *
     * @param array<string, mixed> $column Definisi kolom
     * @param int $index Index baris untuk variasi data
     * @return string Sample value untuk ditampilkan
     */
    protected function generateSampleValue(array $column, int $index): string
    {
        $type = $column['type'];

        if (isset($column['default']) && $column['default'] !== null && $column['default'] !== '') {
            return $column['default'];
        }

        if ($type === 'boolean' && isset($column['default_bool']) && $column['default_bool'] !== null) {
            return $column['default_bool'] ? __('table-builder.sample_data.boolean_true') : __('table-builder.sample_data.boolean_false');
        }

        return match ($type) {
            'string', 'char', 'text', 'mediumText', 'longText' => __('table-builder.sample_data.text') . ' ' . $index,
            'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger' => (string) $index,
            'decimal', 'float', 'double' => number_format($index * 1.5, 2, ',', '.'),
            'boolean' => $index % 2 === 0 ? __('table-builder.sample_data.boolean_true') : __('table-builder.sample_data.boolean_false'),
            'date' => now()->subDays($index)->locale('id')->format('d M Y'),
            'time' => now()->addHours($index)->format('H.i'),
            'datetime', 'timestamp' => now()->subDays($index)->locale('id')->format('d M Y H.i'),
            'uuid' => '123e4567-e89b-12d3-a456-42661417400' . $index,
            'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV' . $index,
            'json' => '{"key": "value' . $index . '"}',
            'enum', 'set' => !empty($column['enum_options']) ? $column['enum_options'][0] ?? 'option1' : 'option' . $index,
            'foreignId' => (string) $index,
            default => __('table-builder.sample_data.text') . ' ' . $index,
        };
    }

    /**
     * Handler untuk perubahan data kolom
     *
     * Auto-refresh preview jika sedang di step preview
     *
     * @return void
     */
    public function updatedDataColumns(): void
    {
        if ($this->currentStep === 3) {
            $this->previewTable();
        }
    }

    /**
     * Memeriksa apakah sedang berada di step preview
     *
     * @return bool True jika sedang di step preview
     */
    protected function isOnPreviewStep(): bool
    {
        return $this->currentStep === 3;
    }

    /**
     * Handler untuk perubahan data form
     *
     * Auto-refresh preview jika perubahan terjadi di kolom dan sedang di step preview
     *
     * @param string $property Nama property yang berubah
     * @param mixed $value Nilai baru
     * @return void
     */
    public function updatedData($property, $value): void
    {
        if (Str::startsWith($property, 'columns') && $this->isOnPreviewStep()) {
            $this->dispatch('refresh-preview');
        }
    }

    /**
     * Handler untuk event refresh preview
     *
     * @return void
     */
    public function handleRefreshPreview(): void
    {
        $this->previewTable();
    }

    /**
     * Method debugging untuk troubleshooting preview
     *
     * Mencatat detail state dan hasil preview ke log
     *
     * @return void
     */
    public function debugPreview(): void
    {
        Log::info('Debug Preview Called');

        try {
            $service = app(TableBuilderService::class);
            Log::info('Service resolved successfully', ['service' => get_class($service)]);

            $state = $this->form->getRawState();
            Log::info('Form state retrieved', [
                'table' => $state['table'] ?? 'MISSING',
                'columns_count' => count($state['columns'] ?? []),
                'full_state' => $state
            ]);

            $result = $service->preview($state);
            Log::info('Service preview result', ['result' => $result]);

            Notification::make()
                ->title('Debug completed')
                ->body('Check logs for details')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Log::error('Debug Preview Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Notification::make()
                ->danger()
                ->title('Debug Error')
                ->body($e->getMessage())
                ->send();
        }
    }
}
