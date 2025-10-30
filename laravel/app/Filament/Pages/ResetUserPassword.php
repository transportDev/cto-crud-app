<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Halaman Reset Password Pengguna
 *
 * Halaman ini memungkinkan administrator untuk mereset password pengguna lain
 * dalam sistem. Hanya pengguna dengan role 'admin' yang dapat mengakses halaman ini.
 *
 * @package App\Filament\Pages
 * @author CTO Panel
 * @since 1.0.0
 */
class ResetUserPassword extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Reset Password Pengguna';
    protected static ?string $title = '';
    protected static ?string $slug = 'reset-password-pengguna';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.reset-user-password';

    /**
     * Data form untuk reset password
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Memeriksa apakah pengguna saat ini dapat mengakses halaman ini
     *
     * @return bool True jika pengguna memiliki role admin
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('admin');
    }

    /**
     * Menentukan apakah halaman ini harus ditampilkan di navigasi
     *
     * @return bool True jika pengguna dapat mengakses halaman
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Inisialisasi halaman dan reset form ke nilai default
     *
     * @return void
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'user_id' => null,
            'new_password' => '',
            'new_password_confirmation' => '',
        ]);
    }

    /**
     * Mendefinisikan skema form untuk reset password
     *
     * Form ini terdiri dari dua section:
     * 1. Informasi Pengguna: Dropdown untuk memilih pengguna
     * 2. Password Baru: Input password dan konfirmasi password
     *
     * @param Form $form Instance form Filament
     * @return Form Form yang sudah dikonfigurasi
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informasi Pengguna')
                    ->description('Pilih pengguna yang akan direset password-nya')
                    ->schema([
                        Select::make('user_id')
                            ->label('Pilih Pengguna')
                            ->options(function () {
                                return User::query()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function (User $user) {
                                        $roles = $user->getRoleNames()->join(', ');
                                        $label = $user->name;

                                        if ($user->username) {
                                            $label .= " (@{$user->username})";
                                        }

                                        if ($user->email) {
                                            $label .= " - {$user->email}";
                                        }

                                        if ($roles) {
                                            $label .= " [{$roles}]";
                                        }

                                        return [$user->id => $label];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->placeholder('Pilih pengguna...')
                            ->helperText('Cari dan pilih pengguna berdasarkan nama, username, atau email')
                            ->live()
                            ->afterStateUpdated(function ($state) {
                                if ($state) {
                                    $this->resetErrorBag(['data.user_id']);
                                }
                            }),
                    ]),

                Section::make('Password Baru')
                    ->description('Masukkan password baru untuk pengguna')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('new_password')
                                    ->label('Password Baru')
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->maxLength(255)
                                    ->live()
                                    ->afterStateUpdated(function ($state) {
                                        if ($state) {
                                            $this->resetErrorBag(['data.new_password']);
                                        }
                                    }),

                                TextInput::make('new_password_confirmation')
                                    ->label('Konfirmasi Password Baru')
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->same('new_password')
                                    ->live()
                                    ->afterStateUpdated(function ($state) {
                                        if ($state) {
                                            $this->resetErrorBag(['data.new_password_confirmation']);
                                        }
                                    }),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Mereset form ke nilai default
     *
     * Method ini membersihkan semua input form dan error validation
     *
     * @return void
     */
    public function resetForm(): void
    {
        $this->form->fill([
            'user_id' => null,
            'new_password' => '',
            'new_password_confirmation' => '',
        ]);

        $this->resetErrorBag();
    }

    /**
     * Menampilkan modal konfirmasi reset password
     *
     * @return void
     */
    public function confirmPasswordReset(): void
    {
        $this->dispatch('open-modal', id: 'confirm-password-reset');
    }

    /**
     * Mereset password pengguna yang dipilih
     *
     * Method ini akan:
     * 1. Validasi data form
     * 2. Update password pengguna
     * 3. Mengirim notifikasi sukses/gagal
     * 4. Mencatat aktivitas ke log
     * 5. Menutup modal dan mereset form
     *
     * @return void
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function resetPassword(): void
    {
        $data = $this->form->getState();

        try {
            $user = User::findOrFail($data['user_id']);

            $user->password = Hash::make($data['new_password']);
            $user->save();

            Notification::make()
                ->title('Password Berhasil Direset')
                ->body("Password untuk pengguna \"{$user->name}\" telah berhasil direset.")
                ->success()
                ->duration(5000)
                ->send();

            Log::info('Password reset by admin', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'reset_by' => Auth::user()->name,
                'reset_by_id' => Auth::id(),
            ]);

            $this->dispatch('close-modal', id: 'confirm-password-reset');
            $this->resetForm();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Gagal Mereset Password')
                ->body('Terjadi kesalahan saat mereset password. Silakan coba lagi.')
                ->danger()
                ->duration(5000)
                ->send();

            Log::error('Password reset failed', [
                'error' => $e->getMessage(),
                'user_id' => $data['user_id'] ?? null,
                'reset_by' => Auth::id(),
            ]);
        }
    }
}
