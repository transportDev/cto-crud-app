<?php

namespace App\Filament\Pages;

use App\Services\TableBuilderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;

class TableBuilder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';
    protected static string $view = 'filament.pages.table-builder';
    protected static ?string $navigationGroup = 'Development';
    protected static ?string $navigationLabel = 'Table Builder';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Table Builder';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('table')
                    ->label('Table name')
                    ->required()
                    ->rule('regex:/^[a-z][a-z0-9_]*$/')
                    ->rule(function () {
                        return function (string $attribute, $value, \Closure $fail) {
                            if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                $fail('Invalid or reserved table name.');
                            }
                            if (Schema::hasTable($value)) {
                                $fail('A table with this name already exists.');
                            }
                        };
                    }),
                Forms\Components\Toggle::make('timestamps')
                    ->label('Include timestamps')
                    ->default(true),
                Forms\Components\Repeater::make('columns')
                    ->label('Columns')
                    ->minItems(1)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->rule('regex:/^[a-z][a-z0-9_]*$/')
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    if (!\App\Services\TableBuilderService::isValidName($value) || \App\Services\TableBuilderService::isReserved($value)) {
                                        $fail('Invalid or reserved column name.');
                                    }
                                };
                            }),
                        Forms\Components\Select::make('type')
                            ->required()
                            ->options([
                                'string' => 'string',
                                'text' => 'text',
                                'integer' => 'integer',
                                'boolean' => 'boolean',
                                'date' => 'date',
                                'datetime' => 'datetime',
                                'decimal' => 'decimal',
                            ])
                            ->default('string'),
                        Forms\Components\TextInput::make('length')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('For string fields'),
                        Forms\Components\Fieldset::make('numeric precision')
                            ->schema([
                                Forms\Components\TextInput::make('precision')
                                    ->numeric()
                                    ->minValue(1),
                                Forms\Components\TextInput::make('scale')
                                    ->numeric()
                                    ->minValue(0),
                            ])
                            ->columns(2)
                            ->visible(fn ($get) => $get('type') === 'decimal'),
                        Forms\Components\Toggle::make('nullable')->inline(false),
                        Forms\Components\TextInput::make('default'),
                        Forms\Components\Toggle::make('unique')->inline(false),
                        Forms\Components\Select::make('index')
                            ->options([
                                null => 'none',
                                'index' => 'index',
                                'fulltext' => 'fulltext',
                            ])
                            ->default(null),
                    ])
                    ->columns(2)
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Deduplicate column names
                        $names = [];
                        foreach ($state as $i => $col) {
                            if (isset($col['name'])) {
                                if (in_array($col['name'], $names, true)) {
                                    $state[$i]['name'] = $col['name'] . '_' . ($i+1);
                                } else {
                                    $names[] = $col['name'];
                                }
                            }
                        }
                        $set('columns', $state);
                    }),
            ])
            ->statePath('data');
    }

    public function submit(TableBuilderService $service): void
    {
        $validated = $this->form->getState();

        // Additional server-side validation for duplicates
        $names = array_map(fn ($c) => $c['name'] ?? '', $validated['columns'] ?? []);
        if (count($names) !== count(array_unique($names))) {
            Notification::make()->danger()->title('Duplicate column names detected')->send();
            return;
        }

        $definition = $service->preview($validated);

        Notification::make()
            ->success()
            ->title('Preview logged')
            ->body('The table definition was validated and logged. No schema changes performed.')
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Forms\Components\Actions::make([
                \Filament\Forms\Components\Actions\Action::make('preview')
                    ->label('Preview')
                    ->color('info')
                    ->submit('submit'),
            ]),
        ];
    }
}
