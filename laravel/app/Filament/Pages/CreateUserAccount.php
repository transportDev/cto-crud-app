<?php

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

class CreateUserAccount extends Page implements HasForms
{
    use InteractsWithForms;

    protected ?array $cachedRoleOptions = null;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Buat Akun Pengguna';
    protected static ?string $title = 'Buat Akun Pengguna';
    protected static ?string $slug = 'buat-akun-pengguna';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.create-user-account';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('admin');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $defaults = $this->defaultFormData();

        $this->form->fill($defaults);
        $this->data = $defaults;
    }

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
                'required' => 'Silakan pilih peran pengguna.',
                'in' => 'Peran yang dipilih tidak valid.',
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
                                    ]),
                                TextInput::make('password')
                                    ->label('Kata Sandi')
                                    ->placeholder('Minimal 8 karakter')
                                    ->password()
                                    ->required()
                                    ->minLength(8)
                                    ->helperText('Wajib minimal 8 karakter untuk keamanan akun.'),
                            ]),
                        $roleField,
                    ])
                    ->icon('heroicon-o-user-circle'),
            ])
            ->statePath('data');
    }

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

    public function cancel(): mixed
    {
        return redirect()->route('filament.admin.pages.dashboard');
    }

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

    protected function getDefaultRoleOption(): ?string
    {
        $options = $this->roleOptions();

        return array_key_first($options) ?: null;
    }

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

    protected function extractFormData(array $validated): array
    {
        return $validated['data'] ?? $validated;
    }
}
