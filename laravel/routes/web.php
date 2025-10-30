<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UsulanOrderDashboardController;

Route::redirect('/', '/dashboard')->name('home');

Route::redirect('/admin/login', '/login')->name('admin.login.redirect');


Route::middleware('web')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});


Route::middleware(['auth', 'permission:view dashboard'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/usulan-order', [UsulanOrderDashboardController::class, 'index'])->name('dashboard.usulan-order');
    Route::get('/api/capacity', [DashboardController::class, 'capacity'])->name('dashboard.capacity');
    Route::get('/api/traffic', [DashboardController::class, 'traffic'])->name('dashboard.traffic');
    Route::get('/api/capacity-trend', [DashboardController::class, 'capacityTrend'])->name('dashboard.capacityTrend');
    Route::get('/api/order-summary', [DashboardController::class, 'orderSummary'])
        ->name('dashboard.orderSummary');
    Route::get('/api/order-summary-nop', [DashboardController::class, 'orderSummaryByNop'])
        ->name('dashboard.orderSummaryNop');
    Route::get('/api/usulan-order', [UsulanOrderDashboardController::class, 'list'])
        ->name('usulanOrder.list');

    Route::post('/api/orders', [OrderController::class, 'store'])
        ->middleware('role:admin|requestor')
        ->name('orders.store');
    Route::get('/api/order-prefill', [OrderController::class, 'prefill'])->name('orders.prefill');
    Route::get('/api/order-comments', [OrderController::class, 'comments'])->name('orders.comments');
    Route::get('/api/order/detail/{site_id}', [OrderController::class, 'detail'])
        ->name('orders.detail');
});
