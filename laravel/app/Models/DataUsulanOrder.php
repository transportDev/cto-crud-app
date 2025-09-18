<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataUsulanOrder extends Model
{
    use HasFactory;

    protected $table = 'data_usulan_order';
    protected $primaryKey = 'no';
    public $timestamps = false;

    protected $fillable = [
        'tanggal_input',
        'requestor',
        'regional',
        'nop',
        'siteid_ne',
        'siteid_fe',
        'transport_type',
        'pl_status',
        'transport_category',
        'pl_value',
        'link_capacity',
        'link_util',
        'link_owner',
        'propose_solution',
        'remark',
        'jarak_odp',
        'cek_nim_order',
        'status_order',
    ];

    public function comments()
    {
        return $this->hasMany(KomenUsulanOrder::class, 'order_id', 'no');
    }
}
