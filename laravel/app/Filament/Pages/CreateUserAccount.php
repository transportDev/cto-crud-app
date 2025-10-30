<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Halaman Pembuatan Akun Pengguna
 *
 * Halaman Filament yang menyediakan antarmuka untuk membuat akun pengguna baru
 * dengan validasi komprehensif dan assignment role otomatis. Halaman ini hanya
 * dapat diakses oleh user dengan role 'admin'.
 *
 * Fitur utama:
 * - Form pembuatan user dengan validasi real-time
 * - Username normalisasi otomatis (lowercase)
 * - Email opsional dengan validasi uniqueness
 * - Password minimal 8 karakter
 * - Role assignment dengan dropdown dinamis
 * - Notifikasi sukses/error yang informatif
 * - Reset form otomatis setelah sukses
 * - Caching role options untuk performa
 *
 * @package App\Filament\Pages
 * @author  CTO CRUD App Team
 * @version 1.0
 * @since   1.0.0
 */
class CreateUserAccount extends Page implements HasForms
{
    use InteractsWithForms;

    protected ?array $cachedRoleOptions = null;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Buat Akun Pengguna';
    protected static ?string $title = '';
    protected static ?string $slug = 'buat-akun-pengguna';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.create-user-account';


    public ?array $data = [];

    /**
     * Mengecek apakah user yang sedang login memiliki akses ke halaman ini
     *
     * Hanya user dengan role 'admin' yang dapat mengakses halaman pembuatan
     * akun pengguna baru untuk menjaga keamanan dan integritas sistem.
     *
     * @return bool True jika user memiliki akses, false jika tidak
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('admin');
    }

    /**
     * Mengecek apakah halaman ini harus ditampilkan di navigasi
     *
     * @return bool True jika halaman harus muncul di menu navigasi
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Inisialisasi halaman saat pertama kali dimuat
     *
     * Method ini dipanggil oleh Livewire lifecycle dan digunakan untuk:
     * - Melakukan pengecekan akses (abort jika tidak authorized)
     * - Mengisi form dengan nilai default
     * - Menginisialisasi data state
     *
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Jika user tidak memiliki akses
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $defaults = $this->defaultFormData();

        $this->form->fill($defaults);
        $this->data = $defaults;
    }

    /**
     * Mendefinisikan form untuk pembuatan akun pengguna
     *
     * Form ini terdiri dari:
     * - Nama lengkap (required, max 255 karakter)
     * - Username (required, unique, lowercase, 3-50 karakter, alpha_dash)
     * - Email (optional, unique, valid email format)
     * - Password (required, min 8 karakter, revealable)
     * - Role (required jika ada role tersedia, dropdown dengan preload)
     *
     * Validasi dilakukan secara real-time dengan live update untuk username
     * dan role selection. Role field akan disabled jika belum ada role tersedia.
     *
     * @param Form $form Instance form dari Filament
     * @return Form Form yang telah dikonfigurasi
     */
    public function form(Form $form): Form
    {
        $roleOptions = $this->roleOptions();
        $defaultRole = $this->getDefaultRoleOption();

        $roleField = Select::make('role')
            ->label('Role Pengguna')
            ->options($roleOptions)
            ->default($defaultRole)
            ->live()
            ->afterStateUpdated(function ($state) {
                if (blank($state)) {
                    return;
                }

                $this->resetErrorBag(['data.role']);
            })
            ->helperText($roleOptions
                ? 'Tentukan hak akses pengguna melalui peran yang tersedia.'
                : 'Belum ada peran yang tersedia. Tambahkan peran terlebih dahulu melalui halaman manajemen peran.')
            ->placeholder($roleOptions ? 'Pilih peran' : 'Tidak ada peran tersedia')
            ->validationMessages([
                'required' => 'Role pengguna wajib dipilih.',
                'in' => 'Role yang dipilih tidak valid.',
            ]);

        if (empty($roleOptions)) {
            $roleField->disabled();
        } else {
            $roleField
                ->required()
                ->rules([Rule::in(array_keys($roleOptions))])
                ->preload()
                ->native(false);

            if (count($roleOptions) > 5) {
                $roleField->searchable();
            }
        }

        return $form
            ->schema([
                Section::make('Informasi Akun')
                    ->description('Isi detail pengguna baru yang akan mendapatkan akses aplikasi.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nama')
                                    ->placeholder('Masukkan nama lengkap')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('username')
                                    ->label('Username')
                                    ->placeholder('Masukkan username')
                                    ->required()
                                    ->maxLength(50)
                                    ->alphaDash()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn($state, callable $set) => $set('username', Str::lower((string) $state)))
                                    ->helperText('Gunakan huruf kecil, angka, tanda hubung (-), atau garis bawah (_).')
                                    ->rules([
                                        'required',
                                        'string',
                                        'min:3',
                                        'max:50',
                                        Rule::unique('users', 'username'),
                                    ])
                                    ->validationMessages([
                                        'required' => 'Username wajib diisi.',
                                        'string' => 'Username harus berupa teks.',
                                        'min' => 'Username minimal 3 karakter.',
                                        'max' => 'Username maksimal 50 karakter.',
                                        'alpha_dash' => 'Username hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
                                        'unique' => 'Username sudah digunakan. Silakan pilih username lain.',
                                    ]),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('email')
                                    ->label('Email (Opsional)')
                                    ->placeholder('Masukkan email (boleh dikosongkan)')
                                    ->nullable()
                                    ->maxLength(255)
                                    ->rules([
                                        'nullable',
                                        'email',
                                        'max:255',
                                        Rule::unique('users', 'email'),
                                    ])
                                    ->validationMessages([
                                        'email' => 'Format email tidak valid.',
                                        'max' => 'Email maksimal 255 karakter.',
                                        'unique' => 'Email sudah terdaftar. Silakan gunakan email lain.',
                                    ]),
                                TextInput::make('password')
                                    ->label('Kata Sandi')
                                    ->placeholder('Minimal 8 karakter')
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->minLength(8)
                                    ->helperText('Wajib minimal 8 karakter untuk keamanan akun.')
                                    ->validationMessages([
                                        'required' => 'Kata sandi wajib diisi.',
                                        'min' => 'Kata sandi minimal 8 karakter.',
                                    ]),
                            ]),
                        $roleField,
                    ])
                    ->icon('heroicon-o-user-circle'),
            ])
            ->statePath('data');
    }

    /**
     * Membuat user baru dengan data yang telah divalidasi
     *
     * Method ini melakukan:
     * 1. Validasi form menggunakan rules yang telah didefinisikan
     * 2. Ekstraksi data form dari state path
     * 3. Normalisasi username ke lowercase
     * 4. Membuat user baru dalam database transaction
     * 5. Hash password menggunakan bcrypt
     * 6. Assign role jika tersedia
     * 7. Kirim notifikasi sukses
     * 8. Reset form ke nilai default
     *
     * Jika terjadi error, akan menampilkan notifikasi danger dan log exception.
     *
     * @return void
     */
    public function createUser(): void
    {
        $validated = $this->form->validate();
        $formData = $this->extractFormData($validated);

        try {
            $roleName = $formData['role'] ?? null;
            $formData['username'] = Str::lower($formData['username'] ?? '');

            $this->resetErrorBag();
            $this->resetValidation();

            $user = DB::transaction(function () use ($formData, $roleName) {
                $email = blank($formData['email'] ?? null) ? null : $formData['email'];

                $user = User::create([
                    'name' => $formData['name'] ?? '',
                    'username' => $formData['username'],
                    'email' => $email,
                    'password' => Hash::make($formData['password']),
                ]);

                if (! empty($roleName)) {
                    $user->assignRole($roleName);
                }

                return $user;
            });

            Notification::make()
                ->success()
                ->title('Akun berhasil dibuat')
                ->body("Pengguna {$user->name} telah ditambahkan dan siap digunakan.")
                ->duration(5000)
                ->send();

            $defaults = $this->defaultFormData();

            $this->form->fill($defaults);
            $this->data = $defaults;
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Gagal membuat akun')
                ->body('Terjadi kesalahan saat menyimpan data. Silakan coba lagi.')
                ->persistent()
                ->send();
        }
    }

    /**
     * Membatalkan pembuatan user dan redirect ke dashboard
     *
     * Method ini dipanggil ketika user mengklik tombol batal pada form.
     *
     * @return mixed Redirect response ke halaman dashboard
     */
    public function cancel(): mixed
    {
        return redirect()->route('filament.admin.pages.dashboard');
    }

    /**
     * Mendapatkan daftar role yang tersedia
     *
     * Method ini mengambil semua role dari database dan menyimpannya dalam cache
     * untuk menghindari query berulang. Role diurutkan berdasarkan nama secara
     * ascending.
     *
     * @return array<string, string> Array role name sebagai key dan value
     */
    protected function roleOptions(): array
    {
        if ($this->cachedRoleOptions !== null) {
            return $this->cachedRoleOptions;
        }

        return $this->cachedRoleOptions = Role::query()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->toArray();
    }

    /**
     * Mendapatkan role default untuk form
     *
     * Mengambil role pertama dari daftar role yang tersedia sebagai nilai default.
     * Jika tidak ada role tersedia, akan mengembalikan null.
     *
     * @return string|null Nama role default atau null jika tidak ada role
     */
    protected function getDefaultRoleOption(): ?string
    {
        $options = $this->roleOptions();

        return array_key_first($options) ?: null;
    }

    /**
     * Mendapatkan data default untuk form
     *
     * Method ini mengembalikan struktur data default yang digunakan untuk
     * inisialisasi form dan reset setelah pembuatan user berhasil.
     *
     * @return array<string, mixed> Array data default form
     */
    protected function defaultFormData(): array
    {
        return [
            'name' => null,
            'username' => null,
            'email' => null,
            'password' => null,
            'role' => $this->getDefaultRoleOption(),
        ];
    }

    /**
     * Mengekstrak data form dari validated data
     *
     * Method helper untuk mengambil data dari 'data' state path.
     * Jika data sudah dalam format yang benar, akan dikembalikan langsung.
     *
     * @param array<string, mixed> $validated Data yang telah divalidasi
     * @return array<string, mixed> Data form yang telah diekstrak
     */
    protected function extractFormData(array $validated): array
    {
        return $validated['data'] ?? $validated;
    }
}
