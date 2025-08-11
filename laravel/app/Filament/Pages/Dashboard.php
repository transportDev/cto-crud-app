<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $route = '/'; // this makes it the root page of your admin panel
    protected static ?string $title = 'Dashboard';
    protected static ?string $navigationIcon = 'heroicon-o-home'; // optional icon
    protected static ?int $navigationSort = 1; // optional sort order in sidebar

    protected static ?string $navigationLabel = 'Dashboard'; // optional sidebar label

    protected static string $view = 'filament.pages.dashboard'; // create this blade view

    // You can add methods, widgets, etc. here as needed.
}
