<?php

use Filament\Pages\Auth\Login;
use Illuminate\Support\Facades\Route;

Route::post('/login', [Login::class, 'authenticate'])->name('auth.login');