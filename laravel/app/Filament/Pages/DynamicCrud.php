<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class DynamicCrud extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'Development';
    protected static ?string $navigationLabel = 'Dynamic CRUD';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.dynamic-crud';
    protected static ?string $title = 'Dynamic CRUD';
}
