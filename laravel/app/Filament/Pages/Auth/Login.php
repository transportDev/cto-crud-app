<?php

namespace App\Filament\Pages\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.login';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ])
            ->statePath('data');
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Email atau Nama Pengguna')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->prefixIcon('heroicon-o-user')
            ->extraInputAttributes([
                'tabindex' => 1,
                'aria-label' => 'Email atau nama pengguna',
                'inputmode' => 'text',
                'class' => 'login-input font-mono',
            ])
            ->placeholder('Masukkan email atau nama pengguna');
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            ->password()
            ->required()
            ->prefixIcon('heroicon-o-lock-closed')
            ->revealable()
            ->autocomplete('current-password')
            ->extraInputAttributes([
                'tabindex' => 2,
                'aria-label' => 'Password',
                'class' => 'login-input font-mono',
            ]);
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);

        $login = trim((string) ($credentials['login'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        $remember = (bool) ($data['remember'] ?? false);

        foreach ($this->buildCredentialAttempts($login, $password) as $attempt) {
            if (! Filament::auth()->attempt($attempt, $remember)) {
                continue;
            }

            $user = Filament::auth()->user();

            if (
                ($user instanceof FilamentUser)
                && (! $user->canAccessPanel(Filament::getCurrentPanel()))
            ) {
                Filament::auth()->logout();
                continue;
            }

            session()->regenerate();

            return app(LoginResponse::class);
        }

        $this->throwFailureValidationException();
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'login' => $data['login'] ?? '',
            'password' => $data['password'] ?? '',
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildCredentialAttempts(string $login, string $password): array
    {
        if ($login === '') {
            return [];
        }

        $normalized = Str::lower($login);
        $isEmail = filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false;

        $usernameAttempt = [
            'username' => $normalized,
            'password' => $password,
        ];

        $emailAttempt = [
            'email' => $normalized,
            'password' => $password,
        ];

        return $isEmail
            ? [$emailAttempt, $usernameAttempt]
            : [$usernameAttempt, $emailAttempt];
    }
}
