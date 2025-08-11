<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-auth', function () {
    $user = App\Models\User::where('email', 'admin@gmail.com')->first();
    Auth::login($user);
    return Auth::check() ? 'Logged in: ' . Auth::user()->name : 'Not logged in';
});