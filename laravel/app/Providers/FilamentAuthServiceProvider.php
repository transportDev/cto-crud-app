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
        // Register missing Filament auth routes
        Route::middleware(['web'])
            ->prefix('admin')
            ->name('filament.admin.')
            ->group(function () {
                // Add the missing POST login route
                Route::post('/login', function () {
                    return Filament::getCurrentPanel()
                        ->getLoginPage()
                        ->mount();
                })->name('auth.login.post');
            });
    }
}