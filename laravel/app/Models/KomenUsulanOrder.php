<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KomenUsulanOrder extends Model
{
    protected $table = 'komen_usulan_order';
    public $timestamps = false; // not specified in schema
    protected $guarded = ['id'];

    public function order()
    {
        return $this->belongsTo(DataUsulanOrder::class, 'order_id', 'no');
    }
}
