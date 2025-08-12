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

class TableBuilder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';
    protected static string $view = 'filament.pages.table-builder';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'TAMBAH TABLE';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Table Builder';

    public ?array $data = [];
    public ?string $preview = null;


    public function mount(): void
    {
        if (!self::canAccess()) {
            abort(403);
        }
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
                    Wizard\Step::make('Info Tabel')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Forms\Components\TextInput::make('table')
                                ->label('Nama tabel')
                                ->required()
                                ->live(debounce: 500)
                                ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                ->validationMessages([
                                    'regex' => 'Gunakan format snake_case dan mulai dengan huruf.',
                                ])
                                ->rule(function () {
                                    return function (string $attribute, $value, \Closure $fail) {
                                        if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                            $fail('Nama tabel tidak valid atau sudah digunakan sistem.');
                                        }
                                        if ($value && Schema::hasTable($value)) {
                                            $fail('Tabel dengan nama ini sudah ada.');
                                        }
                                    };
                                })
                                ->helperText('Contoh nama yang benar: customer_orders'),
                            Grid::make(3)->schema([
                                Forms\Components\Toggle::make('timestamps')
                                    ->label('Kolom waktu (timestamps)')
                                    ->helperText('Otomatis menambah kolom created_at dan updated_at')
                                    ->default(true),
                                Forms\Components\Toggle::make('soft_deletes')
                                    ->label('Soft delete')
                                    ->helperText('Otomatis menambah kolom deleted_at untuk penghapusan sementara')
                                    ->default(false),
                                Forms\Components\TextInput::make('comment')
                                    ->label('Catatan tabel')
                                    ->columnSpan(1),
                            ]),
                        ]),
                    Wizard\Step::make('Kolom')
                        ->icon('heroicon-o-table-cells')
                        ->schema([
                            // REFACTORED REPEATER COMPONENT
                            Forms\Components\Repeater::make('columns')
                                ->label(false) // Label is now implicit
                                ->minItems(1)
                                ->reorderable(true)
                                ->collapsible() // <-- ADDED: Makes items collapsible
                                ->collapsed()   // <-- ADDED: New items are collapsed by default
                                ->addActionLabel('Add to columns') // <-- ADDED: Custom button text
                                ->itemLabel(fn (array $state): ?string => ($state['name'] ?? 'New Column') . ' : ' . ($state['type'] ?? '...')) // <-- ADDED: Dynamic title
                                ->schema([
                                    // The inner schema remains the same, wrapped in a Section for better visuals
                                    Section::make()->schema([
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('name')
                                                ->label('Nama kolom')
                                                ->required()
                                                ->live(debounce: 500)
                                                ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                                ->validationMessages([
                                                    'regex' => 'Gunakan format snake_case dan mulai dengan huruf.',
                                                ])
                                                ->rule(function () {
                                                    return function (string $attribute, $value, \Closure $fail) {
                                                        if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                                            $fail('Nama kolom tidak valid atau sudah digunakan sistem.');
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
                                        // ... The rest of your existing column fields go here without change
                                        // I've included them for completeness
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('length')->numeric()->minValue(1)->visible(fn ($get) => in_array($get('type'), $stringTypes, true))->helperText('Panjang maksimal karakter')->columnSpan(2),
                                            Forms\Components\TextInput::make('precision')->numeric()->minValue(1)->visible(fn ($get) => in_array($get('type'), $numericTypes, true))->helperText('Jumlah digit untuk tipe angka')->columnSpan(2),
                                            Forms\Components\TextInput::make('scale')->numeric()->minValue(0)->visible(fn ($get) => in_array($get('type'), $numericTypes, true))->helperText('Digit di belakang koma')->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Toggle::make('unsigned')->label('Unsigned')->visible(fn ($get) => in_array($get('type'), $integerTypes, true))->inline(false),
                                            Forms\Components\Toggle::make('auto_increment')->label('Auto increment')->visible(fn ($get) => in_array($get('type'), ['integer','bigInteger'], true))->inline(false),
                                            Forms\Components\Toggle::make('primary')->label('Primary key')->visible(fn ($get) => in_array($get('type'), array_merge($integerTypes, ['uuid','ulid']), true))->inline(false),
                                            Forms\Components\Toggle::make('nullable')->label('Nullable')->inline(false),
                                            Forms\Components\Toggle::make('unique')->label('Unique')->inline(false),
                                            Forms\Components\Select::make('index')->label('Index')->options([null => 'none', 'index' => 'index', 'fulltext' => 'fulltext'])->default(null),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TextInput::make('default')->label('Default (string/number/date)')->visible(fn ($get) => !in_array($get('type'), ['boolean'], true))->columnSpan(4),
                                            Forms\Components\Toggle::make('default_bool')->label('Default (boolean)')->visible(fn ($get) => $get('type') === 'boolean')->inline(false)->columnSpan(2),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\TagsInput::make('enum_options')->label('Opsi')->placeholder('Ketik opsi lalu tekan Enter')->visible(fn ($get) => in_array($get('type'), ['enum','set'], true))->helperText('Daftar nilai yang diizinkan untuk enum/set')->columnSpan(6),
                                        ]),
                                        Grid::make(6)->schema([
                                            Forms\Components\Select::make('references_table')->label('Tabel referensi')->searchable()->options(fn () => app(TableBuilderService::class)->listUserTables())->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\TextInput::make('references_column')->label('Kolom referensi')->default('id')->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\Select::make('on_update')->label('Aksi saat update')->options([null => 'tidak ada aksi', 'cascade' => 'cascade', 'restrict' => 'restrict', 'set_null' => 'set null'])->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                            Forms\Components\Select::make('on_delete')->label('Aksi saat hapus')->options([null => 'tidak ada aksi', 'cascade' => 'cascade', 'restrict' => 'restrict', 'set_null' => 'set null'])->visible(fn ($get) => $get('type') === 'foreignId')->columnSpan(3),
                                        ]),
                                        Forms\Components\TextInput::make('comment')->label('Catatan')->maxLength(255),
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
                    Wizard\Step::make('Indeks & Aturan')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Forms\Components\View::make('filament.pages.partials.indexes-hint')
                                ->columnSpanFull(),
                        ]),
                    Wizard\Step::make('Pratinjau & Konfirmasi')
                        ->icon('heroicon-o-eye')
                        ->schema([
                            Forms\Components\Textarea::make('preview')
                                ->label('Pratinjau migrasi')
                                ->rows(12)
                                ->readOnly()
                                ->helperText('Periksa hasil migrasi sebelum menyimpan perubahan.')
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
        $names = array_map(fn ($c) => $c['name'] ?? '', $state['columns'] ?? []);
        if (count($names) !== count(array_unique($names))) {
            Notification::make()->danger()->title('Duplicate column names detected')->send();
            return;
        }
        $result = $service->preview($state);
        $this->data['preview'] = $result['preview'] ?? null;
        Notification::make()->title('Preview generated')->body('Review the migration preview in the Preview & Confirm step.')->success()->send();
    }

    public function createTable(TableBuilderService $service): void
    {
        $state = $this->form->getState();
        $names = array_map(fn ($c) => $c['name'] ?? '', $state['columns'] ?? []);
        if (count($names) !== count(array_unique($names))) {
            Notification::make()->danger()->title('Duplicate column names detected')->send();
            return;
        }
        $service->create($state);
        Notification::make()->title('Table created')->body("Table '{$state['table']}' has been created successfully.")->success()->send();
        $this->data['preview'] = null;
    }

    // REMOVED: This method is no longer needed as the Blade view provides the action buttons.
    /*
    protected function getFormActions(): array
    {
        // ...
    }
    */
}