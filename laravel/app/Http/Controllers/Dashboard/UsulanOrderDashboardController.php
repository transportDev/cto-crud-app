<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DataUsulanOrder;
use Illuminate\Http\Request;

class UsulanOrderDashboardController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('perPage', 25);
        $perPage = ($perPage > 0 && $perPage <= 200) ? $perPage : 25;

        $query = DataUsulanOrder::query()
            ->with(['comments' => function ($q) {
                $q->orderBy('id', 'asc');
            }])
            ->orderByDesc('no');

        // Basic search by siteid or requestor
        if ($s = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($s) {
                $w->where('siteid_ne', 'like', "%$s%")
                    ->orWhere('siteid_fe', 'like', "%$s%")
                    ->orWhere('requestor', 'like', "%$s%")
                    ->orWhere('nop', 'like', "%$s%")
                    ->orWhere('status_order', 'like', "%$s%")
                ;
            });
        }

        $orders = $query->paginate($perPage)->withQueryString();

        return view('dashboard-usulan-order', [
            'orders' => $orders,
            'perPage' => $perPage,
            'search' => $request->query('q', ''),
        ]);
    }
}
