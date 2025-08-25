<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\SchemaWizardService;
use App\Services\TableBuilderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DBSchema;

class SchemaWizard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Schema Wizard';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.schema-wizard';

    public ?array $data = [];
    public ?array $analysis = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();
        return $u instanceof User && $u->hasRole('admin');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function form(Form $form): Form
    {
        $integerTypes = ['integer', 'bigInteger'];
        $numericTypes = ['decimal'];
        $stringTypes = ['string'];

        return $form->schema([
            Forms\Components\Wizard::make([
                Forms\Components\Wizard\Step::make('Select Table')
                    ->schema([
                        Forms\Components\Select::make('table')
                            ->label('Table')
                            ->options(fn() => collect(app(TableBuilderService::class)->listUserTables())->mapWithKeys(fn($t) => [$t => $t])->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->helperText('Pick an item from the list.')
                            ->rule(function () {
                                return function (string $attribute, $value, $fail) {
                                    if (empty($value)) {
                                        return;
                                    }
                                    if (! DBSchema::hasTable($value)) {
                                        $fail('This table does not exist.');
                                    }
                                };
                            }),
                    ]),
                Forms\Components\Wizard\Step::make('Add New Field/Relation')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('Changes')
                            ->addActionLabel('Add field / relation')
                            ->minItems(1)
                            ->reorderable(true)
                            ->schema([
                                Forms\Components\Section::make('Change')
                                    ->collapsible()
                                    ->schema([
                                        Forms\Components\Select::make('kind')->options(['field' => 'Field', 'relation' => 'Relation'])->required()->default('field')->live(),
                                        Forms\Components\TextInput::make('name')->required()->label('Name'),
                                        Forms\Components\Grid::make(12)->schema([
                                            Forms\Components\Select::make('type')->label('Type')->options([
                                                'string' => 'string',
                                                'integer' => 'integer',
                                                'bigInteger' => 'bigInteger',
                                                'decimal' => 'decimal',
                                                'boolean' => 'boolean',
                                                'date' => 'date',
                                                'datetime' => 'datetime',
                                                'json' => 'json',
                                                'foreignId' => 'foreignId'
                                            ])->visible(fn($get) => $get('kind') === 'field')->required(fn($get) => $get('kind') === 'field')->columnSpan(3)->live(),
                                            Forms\Components\TextInput::make('length')->numeric()->visible(fn($get) => $get('kind') === 'field' && in_array($get('type'), $stringTypes, true))->columnSpan(2),
                                            Forms\Components\TextInput::make('precision')->numeric()->visible(fn($get) => $get('kind') === 'field' && in_array($get('type'), $numericTypes, true))->columnSpan(2),
                                            Forms\Components\TextInput::make('scale')->numeric()->visible(fn($get) => $get('kind') === 'field' && in_array($get('type'), $numericTypes, true))->columnSpan(2),
                                            Forms\Components\Toggle::make('nullable')->default(true)->columnSpan(2),
                                            Forms\Components\TextInput::make('default')->label('Default')->visible(fn($get) => $get('kind') === 'field' && $get('type') !== 'boolean')->columnSpan(3),
                                            Forms\Components\Toggle::make('default_bool')->visible(fn($get) => $get('kind') === 'field' && $get('type') === 'boolean')->columnSpan(2),
                                            Forms\Components\Toggle::make('unique')->visible(fn($get) => $get('kind') === 'field')->columnSpan(2),
                                            Forms\Components\Select::make('index')->options([null => 'none', 'index' => 'index', 'fulltext' => 'fulltext'])->visible(fn($get) => $get('kind') === 'field')->columnSpan(3),
                                        ]),
                                        Forms\Components\Grid::make(12)->schema([
                                            Forms\Components\Select::make('references_table')->label('References Table')->options(fn() => collect(app(TableBuilderService::class)->listUserTables())->mapWithKeys(fn($t) => [$t => $t])->all())->visible(fn($get) => $get('kind') === 'relation')->searchable()->columnSpan(4),
                                            Forms\Components\TextInput::make('references_column')->label('References Column')->default('id')->visible(fn($get) => $get('kind') === 'relation')->columnSpan(3),
                                            Forms\Components\Select::make('on_update')->label('On Update')->options([null => 'no_action', 'cascade' => 'cascade', 'restrict' => 'restrict', 'set null' => 'set null'])->visible(fn($get) => $get('kind') === 'relation')->columnSpan(2),
                                            Forms\Components\Select::make('on_delete')->label('On Delete')->options([null => 'no_action', 'cascade' => 'cascade', 'restrict' => 'restrict', 'set null' => 'set null'])->visible(fn($get) => $get('kind') === 'relation')->columnSpan(2),
                                        ]),
                                    ])
                            ])
                            ->columns(1)
                            ->collapsed(false),
                    ]),
                Forms\Components\Wizard\Step::make('Review & Confirm')
                    ->schema([
                        Forms\Components\View::make('filament.pages.partials.wizard-preview')->viewData([
                            'analysis' => $this->analysis,
                        ])->columnSpanFull(),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('analyze')->label('Refresh Preview')->action('refreshAnalysis')->color('secondary'),
                            Forms\Components\Actions\Action::make('execute')->label('Apply Changes Directly')->requiresConfirmation()->modalHeading('Apply schema changes directly?')->modalDescription('This will modify the database immediately without creating migration files. Changes will be applied in a transaction and rolled back if any fail.')->color('primary')->action('applyChanges'),
                            Forms\Components\Actions\Action::make('generate')->label('Generate Migration File')->requiresConfirmation()->modalHeading('Generate migration file?')->modalDescription('This creates a migration file that you can review and run manually with "php artisan migrate".')->color('secondary')->action('generateMigration'),
                        ])->columnSpanFull(),
                    ]),
            ])->persistStepInQueryString(),
        ])->statePath('data');
    }

    public function refreshAnalysis(SchemaWizardService $svc): void
    {
        $state = $this->form->getState();
        if (empty($state['table']) || empty($state['items'])) {
            $this->analysis = null;
            return;
        }
        $this->analysis = $svc->analyze($state['table'], $state['items']);
    }

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    public function applyChanges(SchemaWizardService $svc): void
    {
        $state = $this->form->getState();
        if (empty($state['table']) || empty($state['items'])) {
            Notification::make()->title('Nothing to apply')->danger()->send();
            return;
        }

        $result = $svc->applyDirectChanges($state['table'], $state['items']);

        if ($result['success']) {
            Notification::make()
                ->title('Schema updated successfully')
                ->body(implode('. ', $result['applied']))
                ->success()
                ->send();
            
            // Clear the form after successful application
            $this->form->fill(['table' => $state['table'], 'items' => []]);
            $this->analysis = null;
        } else {
            Notification::make()
                ->title('Schema update failed')
                ->body(implode('. ', $result['errors']))
                ->danger()
                ->send();
        }
    }

    public function generateMigration(SchemaWizardService $svc): void
    {
        $state = $this->form->getState();
        if (empty($state['table']) || empty($state['items'])) {
            Notification::make()->title('Nothing to generate')->danger()->send();
            return;
        }
        
        $file = $svc->generateMigration($state['table'], $state['items']);
        Notification::make()
            ->title('Migration file created')
            ->body("Created: {$file}. Run 'php artisan migrate' to apply.")
            ->success()
            ->send();
    }
}
