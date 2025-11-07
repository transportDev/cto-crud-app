<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Filament\Facades\Filament;

class FilamentAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {

        Route::middleware(['web'])
            ->prefix('admin')
            ->name('filament.admin.')
            ->group(function () {

                Route::post('/login', function () {
                    return Filament::getCurrentPanel()
                        ->getLoginPage()
                        ->mount();
                })->name('auth.login.post');
            });
    }
}
