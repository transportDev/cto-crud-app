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
use Illuminate\Support\Facades\DB;

class SchemaWizard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Wizard Skema';
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
                Forms\Components\Wizard\Step::make('Pilih Tabel')
                    ->schema([
                        Forms\Components\Select::make('table')
                            ->label('Tabel')
                            ->options(fn() => collect(app(TableBuilderService::class)->listUserTables())->mapWithKeys(fn($t) => [$t => $t])->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->helperText('Pilih tabel dari daftar yang ingin Anda ubah skemanya.')
                            ->rule(function () {
                                return function (string $attribute, $value, $fail) {
                                    if (empty($value)) {
                                        return;
                                    }
                                    if (! DBSchema::hasTable($value)) {
                                        $fail('Tabel tidak ditemukan.');
                                    }
                                };
                            }),
                    ]),
                Forms\Components\Wizard\Step::make('Tambah Field/Relasi')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('Perubahan')
                            ->addActionLabel('Tambah field / relasi')
                            ->minItems(1)
                            ->reorderable(true)
                            ->schema([
                                Forms\Components\Section::make('Change')
                                    ->collapsible()
                                    ->schema([
                                        Forms\Components\Select::make('kind')->options(['field' => 'Field', 'relation' => 'Relasi'])->required()->default('field')->live(),
                                        Forms\Components\TextInput::make('name')->required()->label('Nama Kolom'),
                                        Forms\Components\Grid::make(12)->schema([
                                            Forms\Components\Select::make('type')->label('Tipe')->options([
                                                'string' => 'string',
                                                'integer' => 'integer',
                                                'bigInteger' => 'bigInteger',
                                                'decimal' => 'decimal',
                                                'boolean' => 'boolean',
                                                'date' => 'date',
                                                'datetime' => 'datetime',
                                                'json' => 'json',
                                                'foreignId' => 'foreignId'
                                            ])->visible(fn($get) => $get('kind') === 'field')->required(fn($get) => $get('kind') === 'field')->columnSpan(3)->live()->helperText('Pilih tipe data untuk kolom baru.'),
                                            Forms\Components\TextInput::make('length')->label('Panjang')->numeric()->visible(fn($get) => $get('kind') === 'field' && in_array($get('type'), $stringTypes, true))->columnSpan(2)->helperText('Opsional. Panjang maksimal untuk tipe string (contoh: 255).'),
                                            Forms\Components\TextInput::make('precision')->label('Presisi')->numeric()->visible(fn($get) => $get('kind') === 'field' && in_array($get('type'), $numericTypes, true))->columnSpan(2)->helperText('Jumlah digit total untuk decimal.'),
                                            Forms\Components\TextInput::make('scale')->label('Skala')->numeric()->visible(fn($get) => $get('kind') === 'field' && in_array($get('type'), $numericTypes, true))->columnSpan(2)->helperText('Jumlah digit di belakang koma untuk decimal.'),
                                            Forms\Components\Toggle::make('nullable')->label('Boleh Kosong')->default(true)->columnSpan(2)->helperText('Jika aktif, kolom boleh berisi nilai kosong (NULL).'),
                                            Forms\Components\TextInput::make('default')->label('Nilai Bawaan')->visible(fn($get) => $get('kind') === 'field' && $get('type') !== 'boolean')->columnSpan(3)->helperText('Opsional. Nilai awal otomatis jika tidak diisi.'),
                                            Forms\Components\Toggle::make('default_bool')->label('Nilai Bawaan (Boolean)')->visible(fn($get) => $get('kind') === 'field' && $get('type') === 'boolean')->columnSpan(2),
                                            Forms\Components\Toggle::make('unique')->label('Unik')->visible(fn($get) => $get('kind') === 'field')->columnSpan(2)->helperText('Jika aktif, tidak boleh ada duplikasi nilai.'),
                                            Forms\Components\Select::make('index')->label('Indeks')
                                                ->options([null => 'Tidak ada', 'index' => 'Index biasa', 'fulltext' => 'Fulltext'])
                                                ->visible(fn($get) => $get('kind') === 'field')
                                                ->columnSpan(3)
                                                ->helperText('Indeks membantu pencarian lebih cepat. Fulltext untuk pencarian teks paragraf.'),
                                        ]),
                                        Forms\Components\Grid::make(12)->schema([
                                            Forms\Components\Select::make('references_table')
                                                ->label('Tabel Referensi')
                                                ->options(fn() => collect(app(TableBuilderService::class)->listUserTables())->mapWithKeys(fn($t) => [$t => $t])->all())
                                                ->visible(fn($get) => $get('kind') === 'relation')
                                                ->searchable()
                                                ->live()
                                                ->afterStateUpdated(function ($state, callable $set) {
                                                    // Reset dependent fields when reference table changes
                                                    $set('label_columns', []);
                                                    $set('search_column', null);
                                                })
                                                ->columnSpan(4)
                                                ->helperText('Data dropdown akan diambil dari tabel ini.'),
                                            Forms\Components\TextInput::make('references_column')
                                                ->label('Kolom Referensi')
                                                ->default('id')
                                                ->visible(fn($get) => $get('kind') === 'relation')
                                                ->columnSpan(3)
                                                ->helperText('Kolom kunci pada tabel referensi (biasanya "id").'),
                                            Forms\Components\Select::make('on_update')
                                                ->label('Aksi Saat Update')
                                                ->options([null => 'Tidak ada', 'cascade' => 'Cascade', 'restrict' => 'Restrict', 'set null' => 'Set Null'])
                                                ->visible(fn($get) => $get('kind') === 'relation')
                                                ->columnSpan(2)
                                                ->helperText('Tentukan perilaku saat data di tabel referensi diubah: Cascade = ikut berubah, Restrict = cegah perubahan, Set Null = isi NULL.'),
                                            Forms\Components\Select::make('on_delete')
                                                ->label('Aksi Saat Hapus')
                                                ->options([null => 'Tidak ada', 'cascade' => 'Cascade', 'restrict' => 'Restrict', 'set null' => 'Set Null'])
                                                ->visible(fn($get) => $get('kind') === 'relation')
                                                ->columnSpan(2)
                                                ->helperText('Tentukan perilaku saat data di tabel referensi dihapus: Cascade = ikut terhapus, Restrict = cegah penghapusan, Set Null = isi NULL.'),
                                            Forms\Components\Select::make('label_columns')
                                                ->label('Kolom Label')
                                                ->multiple()
                                                ->searchable()
                                                ->visible(fn($get) => $get('kind') === 'relation' && !empty($get('references_table')))
                                                ->options(function (callable $get) {
                                                    $tbl = $get('references_table');
                                                    if (!$tbl || !DBSchema::hasTable($tbl)) return [];
                                                    return collect(DBSchema::getColumnListing($tbl))->mapWithKeys(fn($c) => [$c => $c])->all();
                                                })
                                                ->helperText("Pilih satu atau lebih kolom untuk ditampilkan sebagai teks pilihan dropdown. Urutan pilihan menentukan urutan teks. Contoh: pilih 'vendor_code' lalu 'vendor_name' → tampil 'V001 - Vendor A'.")
                                                ->live()
                                                ->columnSpan(5),
                                            Forms\Components\Select::make('search_column')
                                                ->label('Kolom Pencarian')
                                                ->helperText('Kolom yang dipakai untuk mem-filter saat Anda mengetik di dropdown. Jika kosong, sistem memakai kolom label pertama.')
                                                ->visible(fn($get) => $get('kind') === 'relation' && !empty($get('references_table')))
                                                ->options(function (callable $get) {
                                                    $tbl = $get('references_table');
                                                    if (!$tbl || !DBSchema::hasTable($tbl)) return [];
                                                    return collect(DBSchema::getColumnListing($tbl))->mapWithKeys(fn($c) => [$c => $c])->all();
                                                })
                                                ->searchable()
                                                ->columnSpan(3),
                                            Forms\Components\Placeholder::make('label_preview')
                                                ->label('Pratinjau')
                                                ->visible(fn($get) => $get('kind') === 'relation' && !empty($get('references_table')))
                                                ->content(function (callable $get) {
                                                    $tbl = $get('references_table');
                                                    if (!$tbl || !DBSchema::hasTable($tbl)) return 'No preview';
                                                    $cols = (array)($get('label_columns') ?? []);
                                                    if (empty($cols)) return 'Pilih Kolom Label terlebih dahulu';
                                                    $select = array_slice($cols, 0, 8);
                                                    try {
                                                        $rows = DB::table($tbl)->select($select)->limit(3)->get();
                                                    } catch (\Throwable $e) {
                                                        return 'Pratinjau tidak tersedia';
                                                    }
                                                    $render = function ($row) use ($cols) {
                                                        $vals = [];
                                                        foreach ($cols as $c) {
                                                            $vals[] = (string)($row[$c] ?? '');
                                                        }
                                                        return implode(' - ', $vals);
                                                    };
                                                    if ($rows->isEmpty()) return 'Tidak ada data untuk pratinjau';
                                                    return $rows->map(fn($r) => '• ' . $render((array)$r))->implode("\n");
                                                })
                                                ->columnSpan(12),
                                        ]),
                                    ])
                            ])
                            ->columns(1)
                            ->collapsed(false),
                    ]),
                Forms\Components\Wizard\Step::make('Tinjau & Konfirmasi')
                    ->schema([
                        Forms\Components\View::make('filament.pages.partials.wizard-preview')->viewData([
                            'analysis' => $this->analysis,
                        ])->columnSpanFull(),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('analyze')->label('Segarkan Pratinjau')->action('refreshAnalysis')->color('secondary'),
                            Forms\Components\Actions\Action::make('execute')->label('Terapkan Perubahan Langsung')->requiresConfirmation()->modalHeading('Terapkan perubahan skema sekarang?')->modalDescription('Perubahan akan langsung diterapkan ke database tanpa membuat file migrasi. Gunakan opsi ini untuk pembaruan cepat di lingkungan non-produksi.')->color('primary')->action('applyChanges'),
                            Forms\Components\Actions\Action::make('generate')->label('Buat File Migrasi')->requiresConfirmation()->modalHeading('Buat file migrasi?')->modalDescription('Sistem akan membuat file migrasi agar Anda bisa meninjau dan menjalankannya dengan perintah "php artisan migrate".')->color('secondary')->action('generateMigration'),
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
            Notification::make()->title('Tidak ada perubahan untuk diterapkan')->danger()->send();
            return;
        }

        $result = $svc->applyDirectChanges($state['table'], $state['items']);

        if ($result['success']) {
            Notification::make()
                ->title('Skema berhasil diperbarui')
                ->body(implode(". ", $result['applied']))
                ->success()
                ->send();

            // Clear the form after successful application
            $this->form->fill(['table' => $state['table'], 'items' => []]);
            $this->analysis = null;
        } else {
            Notification::make()
                ->title('Gagal memperbarui skema')
                ->body(implode(". ", $result['errors']))
                ->danger()
                ->send();
        }
    }

    public function generateMigration(SchemaWizardService $svc): void
    {
        $state = $this->form->getState();
        if (empty($state['table']) || empty($state['items'])) {
            Notification::make()->title('Tidak ada yang dapat dibuat')->danger()->send();
            return;
        }

        $file = $svc->generateMigration($state['table'], $state['items']);
        Notification::make()
            ->title('File migrasi berhasil dibuat')
            ->body("Dibuat: {$file}. Jalankan 'php artisan migrate' untuk menerapkan.")
            ->success()
            ->send();
    }
}
