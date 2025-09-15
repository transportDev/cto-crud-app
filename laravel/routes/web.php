<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-auth', function () {
    $user = App\Models\User::where('email', 'admin@gmail.com')->first();
    Auth::login($user);
    return Auth::check() ? 'Logged in: ' . Auth::user()->name : 'Not logged in';
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/api/capacity', [DashboardController::class, 'capacity'])->name('dashboard.capacity');
Route::get('/api/traffic', [DashboardController::class, 'traffic'])->name('dashboard.traffic');
