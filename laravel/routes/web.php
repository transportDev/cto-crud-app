<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Dashboard\UsulanOrderDashboardController;

Route::get('/', function () {
    return view('welcome');
});
// Redirect any attempt to access Filament's default login to shared /login
Route::redirect('/admin/login', '/login')->name('admin.login.redirect');

// Shared authentication routes
Route::middleware('web')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// Dashboard and its APIs require authentication and appropriate permission
Route::middleware(['auth', 'permission:view dashboard'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/usulan-order', [UsulanOrderDashboardController::class, 'index'])->name('dashboard.usulan-order');
    Route::get('/api/capacity', [DashboardController::class, 'capacity'])->name('dashboard.capacity');
    Route::get('/api/traffic', [DashboardController::class, 'traffic'])->name('dashboard.traffic');
    Route::get('/api/capacity-trend', [DashboardController::class, 'capacityTrend'])->name('dashboard.capacityTrend');
    Route::post('/api/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/api/order-prefill', [OrderController::class, 'prefill'])->name('orders.prefill');
    Route::get('/api/order-comments', [OrderController::class, 'comments'])->name('orders.comments');
});
