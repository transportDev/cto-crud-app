<?php

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


    public static function getNavigationLabel(): string
    {
        return __('table-builder.navigation_label');
    }

    public function getTitle(): string
    {
        return __('table-builder.title');
    }

    public function mount(): void
    {
        if (!self::canAccess()) {
            abort(403);
        }
        
        // Set locale to Indonesian for this page
        app()->setLocale('id');
        
        $this->form->fill([
            'timestamps' => true,
            'soft_deletes' => false,
            // Add an initial empty column to guide the user
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
            ],
        ]);
    }


    public static function canAccess(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        $u = Auth::user();
        return $u instanceof \App\Models\User && method_exists($u, 'hasRole') && $u->hasRole('admin');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

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
                            // REFACTORED REPEATER COMPONENT
                            Forms\Components\Repeater::make('columns')
                                ->label(false) // Label is now implicit
                                ->minItems(1)
                                ->reorderable(true)
                                ->collapsible() // <-- ADDED: Makes items collapsible
                                ->collapsed()   // <-- ADDED: New items are collapsed by default
                                ->addActionLabel(__('table-builder.add_column')) // <-- ADDED: Custom button text
                                ->itemLabel(fn (array $state): ?string => ($state['name'] ?? __('table-builder.steps.columns')) . ' : ' . ($state['type'] ?? '...')) // <-- ADDED: Dynamic title
                                ->schema([
                                    // The inner schema remains the same, wrapped in a Section for better visuals
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
                                                    // strings
                                                    'string' => 'string', 'char' => 'char', 'text' => 'text', 'mediumText' => 'mediumText', 'longText' => 'longText',
                                                    // integers
                                                    'integer' => 'integer', 'tinyInteger' => 'tinyInteger', 'smallInteger' => 'smallInteger', 'mediumInteger' => 'mediumInteger', 'bigInteger' => 'bigInteger',
                                                    // numeric
                                                    'decimal' => 'decimal', 'float' => 'float', 'double' => 'double',
                                                    // boolean
                                                    'boolean' => 'boolean',
                                                    // date/time
                                                    'date' => 'date', 'time' => 'time', 'datetime' => 'datetime', 'timestamp' => 'timestamp',
                                                    // ids
                                                    'uuid' => 'uuid', 'ulid' => 'ulid',
                                                    // json
                                                    'json' => 'json',
                                                    // enum/set
                                                    'enum' => 'enum', 'set' => 'set',
                                                    // foreign
                                                    'foreignId' => 'foreignId',
                                                ])
                                                ->default('string')
                                                ->columnSpan(3),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('length')->numeric()->minValue(1)->visible(fn ($get) => in_array($get('type'), $stringTypes, true))->helperText(__('table-builder.length_helper'))->columnSpan(2),
                                            Forms\Components\TextInput::make('precision')->numeric()->minValue(1)->visible(fn ($get) => in_array($get('type'), $numericTypes, true))->helperText(__('table-builder.precision_helper'))->columnSpan(2),
                                            Forms\Components\TextInput::make('scale')->numeric()->minValue(0)->visible(fn ($get) => in_array($get('type'), $numericTypes, true))->helperText(__('table-builder.scale_helper'))->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Toggle::make('unsigned')->label(__('table-builder.unsigned'))->visible(fn ($get) => in_array($get('type'), $integerTypes, true))->inline(false),
                                            Forms\Components\Toggle::make('auto_increment')->label(__('table-builder.auto_increment'))->visible(fn ($get) => in_array($get('type'), ['integer','bigInteger'], true))->inline(false),
                                            Forms\Components\Toggle::make('primary')->label(__('table-builder.primary_key'))->visible(fn ($get) => in_array($get('type'), array_merge($integerTypes, ['uuid','ulid']), true))->inline(false),
                                            Forms\Components\Toggle::make('nullable')->label(__('table-builder.nullable'))->inline(false),
                                            Forms\Components\Toggle::make('unique')->label(__('table-builder.unique'))->inline(false),
                                            Forms\Components\Select::make('index')->label(__('table-builder.index'))->options([
                                                null => __('table-builder.index_options.none'),
                                                'index' => __('table-builder.index_options.index'),
                                                'fulltext' => __('table-builder.index_options.fulltext')
                                            ])->default(null),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('default')->label(__('table-builder.default_value'))->visible(fn ($get) => !in_array($get('type'), ['boolean'], true))->columnSpan(4),
                                            Forms\Components\Toggle::make('default_bool')->label(__('table-builder.default_boolean'))->visible(fn ($get) => $get('type') === 'boolean')->inline(false)->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TagsInput::make('enum_options')->label(__('table-builder.enum_options'))->placeholder(__('table-builder.enum_options_placeholder'))->visible(fn ($get) => in_array($get('type'), ['enum','set'], true))->helperText(__('table-builder.enum_options_helper'))->columnSpan(6),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Select::make('references_table')->label(__('table-builder.references_table'))->searchable()->options(fn () => app(TableBuilderService::class)->listUserTables())->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\TextInput::make('references_column')->label(__('table-builder.references_column'))->default('id')->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\Select::make('on_update')->label(__('table-builder.on_update'))->options([
                                                null => __('table-builder.foreign_actions.no_action'),
                                                'cascade' => __('table-builder.foreign_actions.cascade'),
                                                'restrict' => __('table-builder.foreign_actions.restrict'),
                                                'set_null' => __('table-builder.foreign_actions.set_null')
                                            ])->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\Select::make('on_delete')->label(__('table-builder.on_delete'))->options([
                                                null => __('table-builder.foreign_actions.no_action'),
                                                'cascade' => __('table-builder.foreign_actions.cascade'),
                                                'restrict' => __('table-builder.foreign_actions.restrict'),
                                                'set_null' => __('table-builder.foreign_actions.set_null')
                                            ])->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                        ]),
                                        Forms\Components\TextInput::make('comment')->label(__('table-builder.comment'))->maxLength(255),
                                    ])->columns(1),
                                ])
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $names = [];
                                    foreach ($state as $i => $col) {
                                        if (isset($col['name']) && in_array($col['name'], $names, true)) {
                                            $state[$i]['name'] = $col['name'] . '_' . ($i + 1);
                                        } elseif(isset($col['name'])) {
                                            $names[] = $col['name'];
                                        }
                                    }
                                    $set('columns', $state);
                                }),
                        ]),
                    Wizard\Step::make(__('table-builder.steps.indexes_rules'))
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Forms\Components\View::make('filament.pages.partials.indexes-hint')
                                ->columnSpanFull(),
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
                                                    'loading' => false,
                                                ])
                                                ->columnSpanFull(),
                                        ]),
                                    Forms\Components\Tabs\Tab::make(__('table-builder.migration_code'))
                                        ->schema([
                                            Forms\Components\Textarea::make('preview')
                                                ->label(__('table-builder.migration_code'))
                                                ->rows(12)
                                                ->readOnly()
                                                ->helperText(__('table-builder.preview_helper'))
                                                ->dehydrated(false),
                                        ]),
                                ])
                                ->columnSpanFull(),
                        ]),
                ])
                    ->skippable()
                    ->persistStepInQueryString()
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function previewTable(TableBuilderService $service): void
{
    try {
        $state = $this->form->getState();
        
        // Debug: Log the state we're working with
        Log::info('Preview Table State', [
            'state' => $state,
            'columns' => $state['columns'] ?? 'NO_COLUMNS'
        ]);
        
        // Check for duplicate column names
        $names = array_map(fn ($c) => $c['name'] ?? '', $state['columns'] ?? []);
        $names = array_filter($names); // Remove empty names
        
        if (count($names) !== count(array_unique($names))) {
            Notification::make()
                ->danger()
                ->title(__('table-builder.notifications.duplicate_columns'))
                ->send();
            return;
        }
        
        // Generate migration code preview
        $result = $service->preview($state);
        $this->data['preview'] = $result['preview'] ?? null;
        
        // Generate visual table preview
        $this->tablePreview = $this->generateTablePreview($state);
        
        // Check if preview generation failed
        if (isset($this->tablePreview['error']) && $this->tablePreview['error']) {
            Notification::make()
                ->warning()
                ->title(__('table-builder.notifications.preview_warning'))
                ->body($this->tablePreview['message'] ?? 'Unknown error')
                ->send();
        } else {
            Notification::make()
                ->title(__('table-builder.notifications.preview_generated'))
                ->body(__('table-builder.notifications.preview_generated_body'))
                ->success()
                ->send();
        }
        
    } catch (\Exception $e) {
        Log::error('Preview Table Error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        Notification::make()
            ->danger()
            ->title('Preview Error')
            ->body('An error occurred while generating the preview: ' . $e->getMessage())
            ->send();
            
        // Set error state
        $this->tablePreview = [
            'error' => true,
            'message' => 'Preview generation failed',
            'helper' => 'Check the logs for more details.'
        ];
    }
}

    public function createTable(TableBuilderService $service): void
    {
        $state = $this->form->getState();
        $names = array_map(fn ($c) => $c['name'] ?? '', $state['columns'] ?? []);
        if (count($names) !== count(array_unique($names))) {
            Notification::make()->danger()->title(__('table-builder.notifications.duplicate_columns'))->send();
            return;
        }
        $service->create($state);
        Notification::make()
            ->title(__('table-builder.notifications.table_created'))
            ->body(__('table-builder.notifications.table_created_body', ['table' => $state['table']]))
            ->success()
            ->send();
        $this->data['preview'] = null;
        $this->tablePreview = null;
    }

    protected function generateTablePreview(array $state): array
{
    $columns = $state['columns'] ?? [];
    
    // Debug: Add logging to see what we're getting
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

    // Generate headers with metadata
    foreach ($columns as $index => $column) {
        // Ensure we have required fields
        if (empty($column['name']) || empty($column['type'])) {
            Log::warning('Column missing required fields', [
                'index' => $index,
                'column' => $column
            ]);
            continue; // Skip malformed columns
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

    // Check if we have any valid headers
    if (empty($headers)) {
        return [
            'error' => true,
            'message' => __('table-builder.no_valid_columns'),
            'helper' => __('table-builder.no_valid_columns_helper'),
        ];
    }

    // Generate 5 sample rows
    for ($i = 1; $i <= 5; $i++) {
        $row = [];
        foreach ($columns as $column) {
            // Skip malformed columns
            if (empty($column['name']) || empty($column['type'])) {
                continue;
            }
            $row[] = $this->generateSampleValue($column, $i);
        }
        $sampleRows[] = $row;
    }

    // Add timestamp columns if enabled
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

    // Add soft delete column if enabled
    if (!empty($state['soft_deletes'])) {
        $headers[] = [
            'name' => 'deleted_at',
            'type' => 'timestamp',
            'metadata' => [__('table-builder.metadata.nullable')],
        ];
        
        for ($i = 0; $i < 5; $i++) {
            $sampleRows[$i][] = null; // All sample rows are not deleted
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

    protected function generateSampleValue(array $column, int $index): string
    {
        $type = $column['type'];
        
        // Handle default values first
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

    // REMOVED: This method is no longer needed as the Blade view provides the action buttons.
    /*
    protected function getFormActions(): array
    {
        // ...
    }
    */
}