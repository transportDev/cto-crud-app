<?php

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

class ResetUserPassword extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $navigationGroup = 'Management';
    protected static ?string $navigationLabel = 'Reset Password Pengguna';
    protected static ?string $title = 'Reset Password Pengguna';
    protected static ?string $slug = 'reset-password-pengguna';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.reset-user-password';

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

        $this->form->fill([
            'user_id' => null,
            'new_password' => '',
            'new_password_confirmation' => '',
        ]);
    }

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

    public function resetForm(): void
    {
        $this->form->fill([
            'user_id' => null,
            'new_password' => '',
            'new_password_confirmation' => '',
        ]);

        $this->resetErrorBag();
    }

    public function confirmPasswordReset(): void
    {
        $this->dispatch('open-modal', id: 'confirm-password-reset');
    }

    public function resetPassword(): void
    {
        $data = $this->form->getState();

        try {
            $user = User::findOrFail($data['user_id']);

            // Update password
            $user->password = Hash::make($data['new_password']);
            $user->save();

            // Send success notification
            Notification::make()
                ->title('Password Berhasil Direset')
                ->body("Password untuk pengguna \"{$user->name}\" telah berhasil direset.")
                ->success()
                ->duration(5000)
                ->send();

            // Log the action
            Log::info('Password reset by admin', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'reset_by' => Auth::user()->name,
                'reset_by_id' => Auth::id(),
            ]);

            // Close modal and reset form after successful password reset
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
