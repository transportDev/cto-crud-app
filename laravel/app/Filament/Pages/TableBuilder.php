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

class TableBuilder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';
    protected static string $view = 'filament.pages.table-builder';
    protected static ?string $navigationGroup = 'Development';
    protected static ?string $navigationLabel = 'Table Builder';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Table Builder';

    public ?array $data = [];
    public ?string $preview = null;


    public function mount(): void
    {
        // Enforce admin-only access even if the route is guessed or linked directly.
        if (!self::canAccess()) {
            abort(403);
        }

        $this->form->fill([
            'timestamps' => true,
            'soft_deletes' => false,
        ]);
    }


    public static function canAccess(): bool
    {
        if (!\Illuminate\Support\Facades\Auth::check()) {
            return false;
        }
        $u = \Illuminate\Support\Facades\Auth::user();
        return $u instanceof \App\Models\User && \method_exists($u, 'hasRole') && $u->hasRole('admin');
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
                    Wizard\Step::make('Table Info')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Forms\Components\TextInput::make('table')
                                ->label('Table name')
                                ->required()
                                ->live(debounce: 500)
                                ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                ->validationMessages([
                                    'regex' => 'Use snake_case starting with a letter.',
                                ])
                                ->rule(function () {
                                    return function (string $attribute, $value, \Closure $fail) {
                                        if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                            $fail('Invalid or reserved table name.');
                                        }
                                        if ($value && Schema::hasTable($value)) {
                                            $fail('A table with this name already exists.');
                                        }
                                    };
                                })
                                ->helperText('Snake_case, unique, not reserved, e.g. customer_orders'),
                            Grid::make(3)->schema([
                                Forms\Components\Toggle::make('timestamps')
                                    ->label('Timestamps')
                                    ->helperText('Adds created_at and updated_at')
                                    ->default(true),
                                Forms\Components\Toggle::make('soft_deletes')
                                    ->label('Soft deletes')
                                    ->helperText('Adds deleted_at')
                                    ->default(false),
                                Forms\Components\TextInput::make('comment')
                                    ->label('Table comment')
                                    ->columnSpan(1),
                            ]),
                        ]),
                    Wizard\Step::make('Columns')
                        ->icon('heroicon-o-table-cells')
                        ->schema([
                            Forms\Components\Repeater::make('columns')
                                ->label('Columns')
                                ->minItems(1)
                                ->reorderable(true)
                                ->grid(2)
                                ->schema([
                                    Section::make()->schema([
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('name')
                                                ->label('Column name')
                                                ->required()
                                                ->live(debounce: 500)
                                                ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                                ->validationMessages([
                                                    'regex' => 'Use snake_case starting with a letter.',
                                                ])
                                                ->rule(function () {
                                                    return function (string $attribute, $value, \Closure $fail) {
                                                        if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                                            $fail('Invalid or reserved column name.');
                                                        }
                                                    };
                                                })
                                                ->columnSpan(3),
                                            Forms\Components\Select::make('type')
                                                ->label('Type')
                                                ->required()
                                                ->searchable()
                                                ->live()
                                                ->options([
                                                    // strings
                                                    'string' => 'string',
                                                    'char' => 'char',
                                                    'text' => 'text',
                                                    'mediumText' => 'mediumText',
                                                    'longText' => 'longText',
                                                    // integers
                                                    'integer' => 'integer',
                                                    'tinyInteger' => 'tinyInteger',
                                                    'smallInteger' => 'smallInteger',
                                                    'mediumInteger' => 'mediumInteger',
                                                    'bigInteger' => 'bigInteger',
                                                    // numeric
                                                    'decimal' => 'decimal',
                                                    'float' => 'float',
                                                    'double' => 'double',
                                                    // boolean
                                                    'boolean' => 'boolean',
                                                    // date/time
                                                    'date' => 'date',
                                                    'time' => 'time',
                                                    'datetime' => 'datetime',
                                                    'timestamp' => 'timestamp',
                                                    // ids
                                                    'uuid' => 'uuid',
                                                    'ulid' => 'ulid',
                                                    // json
                                                    'json' => 'json',
                                                    // enum/set
                                                    'enum' => 'enum',
                                                    'set' => 'set',
                                                    // foreign
                                                    'foreignId' => 'foreignId',
                                                ])
                                                ->default('string')
                                                ->columnSpan(3),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('length')
                                                ->numeric()
                                                ->minValue(1)
                                                ->visible(fn ($get) => in_array($get('type'), $stringTypes, true))
                                                ->helperText('Max length for string/char')
                                                ->columnSpan(2),
                                            Forms\Components\TextInput::make('precision')
                                                ->numeric()
                                                ->minValue(1)
                                                ->visible(fn ($get) => in_array($get('type'), $numericTypes, true))
                                                ->helperText('Total digits (numeric types)')
                                                ->columnSpan(2),
                                            Forms\Components\TextInput::make('scale')
                                                ->numeric()
                                                ->minValue(0)
                                                ->visible(fn ($get) => in_array($get('type'), $numericTypes, true))
                                                ->helperText('Digits after decimal (numeric types)')
                                                ->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Toggle::make('unsigned')
                                                ->label('Unsigned')
                                                ->visible(fn ($get) => in_array($get('type'), $integerTypes, true))
                                                ->inline(false),
                                            Forms\Components\Toggle::make('auto_increment')
                                                ->label('Auto increment')
                                                ->visible(fn ($get) => in_array($get('type'), ['integer','bigInteger'], true))
                                                ->inline(false),
                                            Forms\Components\Toggle::make('primary')
                                                ->label('Primary key')
                                                ->visible(fn ($get) => in_array($get('type'), array_merge($integerTypes, ['uuid','ulid']), true))
                                                ->inline(false),
                                            Forms\Components\Toggle::make('nullable')
                                                ->label('Nullable')
                                                ->inline(false),
                                            Forms\Components\Toggle::make('unique')
                                                ->label('Unique')
                                                ->inline(false),
                                            Forms\Components\Select::make('index')
                                                ->label('Index')
                                                ->options([
                                                    null => 'none',
                                                    'index' => 'index',
                                                    'fulltext' => 'fulltext',
                                                ])
                                                ->default(null),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('default')
                                                ->label('Default (string/number/date)')
                                                ->visible(fn ($get) => !in_array($get('type'), ['boolean'], true))
                                                ->columnSpan(4),
                                            Forms\Components\Toggle::make('default_bool')
                                                ->label('Default (boolean)')
                                                ->visible(fn ($get) => $get('type') === 'boolean')
                                                ->inline(false)
                                                ->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TagsInput::make('enum_options')
                                                ->label('Options')
                                                ->placeholder('Type an option and press Enter')
                                                ->visible(fn ($get) => in_array($get('type'), ['enum','set'], true))
                                                ->helperText('Define allowed values for enum/set')
                                                ->columnSpan(6),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Select::make('references_table')
                                                ->label('References table')
                                                ->searchable()
                                                ->options(fn () => app(TableBuilderService::class)->listUserTables())
                                                ->visible(fn ($get) => $get('type') === 'foreignId')
                                                ->columnSpan(3),
                                            Forms\Components\TextInput::make('references_column')
                                                ->label('References column')
                                                ->default('id')
                                                ->visible(fn ($get) => $get('type') === 'foreignId')
                                                ->columnSpan(3),
                                            Forms\Components\Select::make('on_update')
                                                ->label('On update')
                                                ->options([
                                                    null => 'no action',
                                                    'cascade' => 'cascade',
                                                    'restrict' => 'restrict',
                                                    'set_null' => 'set null',
                                                ])
                                                ->visible(fn ($get) => $get('type') === 'foreignId')
                                                ->columnSpan(3),
                                            Forms\Components\Select::make('on_delete')
                                                ->label('On delete')
                                                ->options([
                                                    null => 'no action',
                                                    'cascade' => 'cascade',
                                                    'restrict' => 'restrict',
                                                    'set_null' => 'set null',
                                                ])
                                                ->visible(fn ($get) => $get('type') === 'foreignId')
                                                ->columnSpan(3),
                                        ]),
                                        Forms\Components\TextInput::make('comment')
                                            ->label('Comment')
                                            ->maxLength(255),
                                    ])->columns(1),
                                ])
                                ->afterStateUpdated(function ($state, callable $set) {
                                    // Deduplicate column names
                                    $names = [];
                                    foreach ($state as $i => $col) {
                                        if (isset($col['name'])) {
                                            if (in_array($col['name'], $names, true)) {
                                                $state[$i]['name'] = $col['name'] . '_' . ($i + 1);
                                            } else {
                                                $names[] = $col['name'];
                                            }
                                        }
                                    }
                                    $set('columns', $state);
                                }),
                        ]),
                    Wizard\Step::make('Indexes & Constraints')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Forms\Components\View::make('filament.pages.partials.indexes-hint')
                                ->columnSpanFull(),
                        ]),
                    Wizard\Step::make('Preview & Confirm')
                        ->icon('heroicon-o-eye')
                        ->schema([
                            Forms\Components\Textarea::make('preview')
                                ->label('Migration preview')
                                ->rows(12)
                                ->readOnly()
                                ->helperText('Review the generated migration blueprint before applying changes.')
                                ->dehydrated(false),
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
        $state = $this->form->getState();

        // Server-side duplicate name validation
        $names = array_map(fn ($c) => $c['name'] ?? '', $state['columns'] ?? []);
        if (count($names) !== count(array_unique($names))) {
            Notification::make()->danger()->title('Duplicate column names detected')->send();
            return;
        }

        $result = $service->preview($state);
        $this->data['preview'] = $result['preview'] ?? null;

        Notification::make()
            ->title('Preview generated')
            ->body('Review the migration preview in the Preview & Confirm step.')
            ->success()
            ->send();
    }

    public function createTable(TableBuilderService $service): void
    {
        $state = $this->form->getState();

        // Server-side duplicate name validation
        $names = array_map(fn ($c) => $c['name'] ?? '', $state['columns'] ?? []);
        if (count($names) !== count(array_unique($names))) {
            Notification::make()->danger()->title('Duplicate column names detected')->send();
            return;
        }

        $service->create($state);

        Notification::make()
            ->title('Table created')
            ->body("Table '{$state['table']}' has been created successfully.")
            ->success()
            ->send();

        // Ensure the new table appears immediately in dynamic CRUD selector etc.
        $this->data['preview'] = null;
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Forms\Components\Actions::make([
                \Filament\Forms\Components\Actions\Action::make('preview')
                    ->label('Preview')
                    ->color('info')
                    ->action('previewTable'),
                \Filament\Forms\Components\Actions\Action::make('create')
                    ->label('Create Table')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Apply schema changes?')
                    ->modalDescription('This will create the table and apply schema changes to the database. This operation may be irreversible without a manual rollback.')
                    ->action('createTable'),
            ]),
        ];
    }
}
