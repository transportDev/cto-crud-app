<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DataUsulanOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            ->orderBy('no', 'asc');

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

    public function list(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $ttl = (int) config('cto.dashboard_usulan_order_list_cache_ttl', 1800);
        $cacheKey = 'usulan_order:list:' . md5($q);

        $payload = Cache::remember($cacheKey, $ttl, function () use ($q) {
            $query = DataUsulanOrder::query()
                ->with(['comments' => function ($q2) {
                    $q2->orderBy('id', 'asc');
                }])
                ->orderBy('no', 'asc');

            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('siteid_ne', 'like', "%$q%")
                        ->orWhere('siteid_fe', 'like', "%$q%")
                        ->orWhere('requestor', 'like', "%$q%")
                        ->orWhere('nop', 'like', "%$q%")
                        ->orWhere('status_order', 'like', "%$q%");
                });
            }

            $rows = $query->get();
            return [
                'ok' => true,
                'count' => $rows->count(),
                'rows' => $rows,
            ];
        });

        return response()->json($payload);
    }
}
