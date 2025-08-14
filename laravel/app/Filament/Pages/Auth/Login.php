<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;

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
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/login.form.email.label'))
            ->email()
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->prefixIcon('heroicon-o-user')
            ->extraInputAttributes([
                'tabindex' => 1,
                'aria-label' => 'Email address',
                'inputmode' => 'email',
                'class' => 'login-input font-mono',
            ]);
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

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => $data['email'],
            'password' => $data['password'],
        ];
    }
}