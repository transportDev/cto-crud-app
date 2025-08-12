<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DynamicModel extends Model
{
    /**
     * We will set the table at runtime using setTable().
     */
    protected $table;

    /**
     * No guarded attributes; we'll rely on validation at the form level.
     */
    protected $guarded = [];

    /**
     * Disable timestamps by default; will be enabled per-table if detected.
     */
    public $timestamps = false;

    /**
     * Bind model to a specific table at runtime.
     */
    public function setRuntimeTable(string $table): static
    {
        $this->setTable($table);

        return $this;
    }
}