<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KomenUsulanOrder extends Model
{
    use HasFactory;

    protected $table = 'komen_usulan_order';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'requestor',
        'comment',
        'siteid_ne',
    ];

    public function order()
    {
        return $this->belongsTo(DataUsulanOrder::class, 'order_id', 'no');
    }
}
