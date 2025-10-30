<?php

declare(strict_types=1);

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

/**
 * Halaman Schema Wizard
 *
 * Halaman ini memungkinkan administrator untuk memodifikasi skema tabel yang sudah ada
 * dengan wizard step-by-step. Fitur yang tersedia:
 * - Pilih tabel yang akan dimodifikasi
 * - Tambah field baru atau relasi foreign key
 * - Preview perubahan skema
 * - Terapkan perubahan langsung ke database
 *
 * @package App\Filament\Pages
 * @author CTO Panel
 * @since 1.0.0
 */
class SchemaWizard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Wizard Skema';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.schema-wizard';


    public ?array $data = [];


    public ?array $analysis = null;

    /**
     * Memeriksa apakah pengguna dapat mengakses halaman ini
     *
     * @return bool True jika pengguna memiliki role admin
     */
    public static function canAccess(): bool
    {
        $u = Auth::user();
        return $u instanceof User && $u->hasRole('admin');
    }

    /**
     * Menentukan apakah halaman ini harus ditampilkan di navigasi
     *
     * @return bool True jika pengguna dapat mengakses
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Mendefinisikan skema form wizard untuk modifikasi skema tabel
     *
     * Form wizard terdiri dari 3 step:
     * 1. Pilih Tabel: Memilih tabel yang akan dimodifikasi
     * 2. Tambah Field/Relasi: Definisi field baru atau relasi foreign key
     * 3. Tinjau & Konfirmasi: Review dan terapkan perubahan
     *
     * @param Form $form Instance form Filament
     * @return Form Form yang sudah dikonfigurasi
     */
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
                            ->live(onBlur: true)
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
                                                    $set('label_columns', []);
                                                    $set('search_column', null);
                                                })
                                                ->columnSpan(4)
                                                ->helperText('Data dropdown akan diambil dari tabel ini.'),
                                            Forms\Components\Select::make('relation_type')
                                                ->label('Tipe Data Relasi')
                                                ->options([
                                                    'bigInteger' => 'bigInteger (unsigned)',
                                                    'integer' => 'integer (unsigned)',
                                                    'mediumInteger' => 'mediumInteger (unsigned)',
                                                    'smallInteger' => 'smallInteger (unsigned)',
                                                    'tinyInteger' => 'tinyInteger (unsigned)',
                                                ])
                                                ->default('bigInteger')
                                                ->visible(fn($get) => $get('kind') === 'relation')
                                                ->columnSpan(4)
                                                ->helperText('Pilih tipe bilangan bulat untuk kolom FK (selalu unsigned).'),
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
                        Forms\Components\View::make('filament.pages.partials.wizard-summary')->viewData([
                            'items' => $this->data['items'] ?? [],
                        ])->columnSpanFull(),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('execute')->label('Terapkan Perubahan Langsung')->requiresConfirmation()->modalHeading('Terapkan perubahan skema sekarang?')->modalDescription('Perubahan akan langsung diterapkan ke database tanpa membuat file migrasi. Gunakan opsi ini untuk pembaruan cepat di lingkungan non-produksi.')->color('primary')->action('applyChanges'),
                        ])
                            ->columnSpanFull()
                            ->alignment(\Filament\Support\Enums\Alignment::End),
                    ]),
            ])
                ->live()
                ->afterStateUpdated(function ($livewire, $state) {
                    if ($state == 2) {
                        $livewire->refreshAnalysis();
                    }
                })
                ->persistStepInQueryString(),
        ])->statePath('data');
    }

    /**
     * Refresh analisis perubahan skema
     *
     * Method ini menganalisis perubahan yang akan diterapkan dan
     * menghasilkan preview SQL yang akan dieksekusi
     *
     * @return void
     */
    public function refreshAnalysis(): void
    {
        $state = $this->form->getState();
        if (empty($state['table']) || empty($state['items'])) {
            $this->analysis = null;
            return;
        }
        $svc = app(\App\Services\SchemaWizardService::class);
        $this->analysis = $svc->analyze($state['table'], $state['items']);
    }

    /**
     * Inisialisasi halaman dan setup form
     *
     * @return void
     */
    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    /**
     * Menerapkan perubahan skema langsung ke database
     *
     * Method ini akan:
     * 1. Validasi data form
     * 2. Eksekusi perubahan skema via service
     * 3. Menampilkan notifikasi sukses/gagal
     * 4. Reset form setelah sukses
     *
     * @param SchemaWizardService $svc Service untuk modifikasi skema
     * @return void
     */
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

    /**
     * Auto-refresh analisis ketika items berubah
     *
     * @return void
     */
    public function updatedDataItems(): void
    {
        $this->refreshAnalysis();
    }
}
