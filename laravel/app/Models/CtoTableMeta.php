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

    /**
     * Convenience: return array of columns from display_template[columns].
     */
    public function displayColumns(): array
    {
        $tpl = $this->display_template;
        if (!is_array($tpl)) return [];
        $cols = $tpl['columns'] ?? [];
        return is_array($cols) ? array_values(array_filter($cols)) : [];
    }
}
