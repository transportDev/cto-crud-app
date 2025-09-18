<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataUsulanOrder extends Model
{
    protected $table = 'data_usulan_order';
    protected $primaryKey = 'no';
    public $timestamps = false; // table has no created_at/updated_at
    protected $guarded = ['no'];

    public function comments()
    {
        return $this->hasMany(KomenUsulanOrder::class, 'order_id', 'no');
    }
}
