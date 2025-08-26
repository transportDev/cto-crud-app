<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CtoTableMeta extends Model
{
    use HasFactory;

    protected $table = 'cto_table_meta';

    protected $fillable = [
        'table_name',
        'primary_key_column',
        'label_column',
        'search_column',
        'display_template',
    ];

    protected $casts = [
        'display_template' => 'array',
    ];
}
