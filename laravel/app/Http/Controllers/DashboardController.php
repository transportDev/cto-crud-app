<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $error = null;
        $s1dlLabels = $s1dlValues = [];
        $sites = [];
        $selectedSiteId = $request->query('site_id');

        try {
            $conn = DB::connection('mysql2');
            $table = 'etl_cell_4g_ran_huawei_kpi_hourly_agg';

            // Determine latest timestamp and 7d window
            $latestTs = $conn->table($table)->max('finish_timestamp');
            if ($latestTs) {
                $latest = Carbon::parse($latestTs);
                $since = $latest->copy()->subDays(7);

                // Cache keys bound to the last hour window and the selected site
                $windowKey = 'r7d:' . $latest->copy()->startOfHour()->format('YmdH');
                $siteKey = $selectedSiteId ? 'site:' . $selectedSiteId : 'all';

                // S1 DL Average (Mbps) time series over last 24h, optionally filtered by site
                $seriesTtl = (int) config('cto.dashboard_series_cache_ttl', 180);
                $rowsDl = Cache::remember("dash:s1dl:$windowKey:$siteKey", $seriesTtl, function () use ($conn, $table, $since, $latest, $selectedSiteId) {
                    return $conn->table($table)
                        ->selectRaw('DATE_FORMAT(finish_timestamp, "%Y-%m-%d %H:00") as ts, AVG(s1_usage_dl_average_mbps) as avg_mbps')
                        ->when($selectedSiteId, function ($q) use ($selectedSiteId) {
                            $q->where('site_id', $selectedSiteId);
                        })
                        ->whereBetween('finish_timestamp', [$since, $latest])
                        ->groupBy('ts')
                        ->orderBy('ts')
                        ->get();
                });

                $s1dlLabels = $rowsDl->pluck('ts')->all();
                $s1dlValues = $rowsDl->pluck('avg_mbps')->map(fn($v) => round((float)$v, 2))->all();

                // Distinct site list (from last 24h window)
                $sitesTtl = (int) config('cto.dashboard_sites_cache_ttl', 600);
                $sites = Cache::remember("dash:sites:$windowKey", $sitesTtl, function () use ($conn, $table, $since, $latest) {
                    return $conn->table($table)
                        ->select('site_id')
                        ->whereBetween('finish_timestamp', [$since, $latest])
                        ->whereNotNull('site_id')
                        ->distinct()
                        ->orderBy('site_id')
                        ->limit(500)
                        ->pluck('site_id')
                        ->all();
                });
            } else {
                $error = 'No data found in db2 table.';
            }
        } catch (\Throwable $e) {
            $error = 'DB2 error: ' . $e->getMessage();
        }

        $payload = [
            'error' => $error,
            's1dlLabels' => $s1dlLabels,
            's1dlValues' => $s1dlValues,
            'sites' => $sites,
            'selectedSiteId' => $selectedSiteId,
            'latestTs' => isset($latest) ? $latest->copy()->startOfHour()->format('Y-m-d H:00') : null,
            'earliestTs' => isset($since) ? $since->copy()->startOfHour()->format('Y-m-d H:00') : null,
        ];

        // Support fetching data as JSON for AJAX updates
        if ($request->wantsJson() || $request->boolean('json')) {
            return response()->json($payload);
        }

        return view('dashboard', $payload);
    }
}
