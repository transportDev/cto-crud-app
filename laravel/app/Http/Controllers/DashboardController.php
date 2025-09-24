<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $forJson = $request->wantsJson() || $request->boolean('json');
        $error = null;
        $s1dlLabels = $s1dlValues = [];
        // Skip computing sites list on initial SSR; client will fetch via /api/traffic
        $sites = [];
        $selectedSiteId = $request->query('site_id');

        try {
            $conn = DB::connection('mysql2');
            $table = 'etl_cell_4g_ran_huawei_kpi_hourly_agg';

            // Determine latest timestamp and 7d window
            // Cache latest timestamp briefly to avoid frequent MAX() scans on a large table
            $latestTtl = (int) config('cto.dashboard_latest_cache_ttl', 60);
            $latestTs = Cache::remember('dash:latestTs', $latestTtl, function () use ($conn, $table) {
                return $conn->table($table)->max('finish_timestamp');
            });
            if ($latestTs) {
                $latest = Carbon::parse($latestTs);
                $since = $latest->copy()->subDays(7);

                // Cache keys bound to the last hour window and the selected site
                $windowKey = 'r7d:' . $latest->copy()->startOfHour()->format('YmdH');
                $siteKey = $selectedSiteId ? 'site:' . $selectedSiteId : 'all';

                // S1 DL series: only compute for JSON/AJAX requests to speed up initial HTML render
                if ($forJson) {
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
                }
                // Sites list intentionally skipped on SSR (client will populate from /api/traffic)
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
        if ($forJson) {
            return response()->json($payload);
        }

        return view('dashboard', $payload);
    }

    // Lightweight JSON endpoint for S1 DL average series (client-side chart fetch)
    public function traffic(Request $request)
    {
        $selectedSiteId = $request->query('site_id');
        $table = 'etl_cell_4g_ran_huawei_kpi_hourly_agg';
        $conn = DB::connection('mysql2');

        try {
            // Cache latest timestamp briefly to avoid frequent MAX() scans
            $latestTtl = (int) config('cto.dashboard_latest_cache_ttl', 60);
            $latestTs = Cache::remember('dash:latestTs', $latestTtl, function () use ($conn, $table) {
                return $conn->table($table)->max('finish_timestamp');
            });

            if (!$latestTs) {
                return response()->json(['ok' => false, 'error' => 'No data found'], 404);
            }

            $latest = Carbon::parse($latestTs);
            $since = $latest->copy()->subDays(7);
            $windowKey = 'r7d:' . $latest->copy()->startOfHour()->format('YmdH');
            $siteKey = $selectedSiteId ? 'site:' . $selectedSiteId : 'all';

            // Top-level payload cache (composed from existing sub-caches)
            $payloadTtl = (int) config('cto.dashboard_traffic_cache_ttl', 120);
            $payload = Cache::remember("dash:traffic:v1:$windowKey:$siteKey", $payloadTtl, function () use ($conn, $table, $since, $latest, $selectedSiteId, $windowKey) {
                // Series cache (already efficient)
                $seriesTtl = (int) config('cto.dashboard_series_cache_ttl', 180);
                $rowsDl = Cache::remember("dash:s1dl:$windowKey:" . ($selectedSiteId ? 'site:' . $selectedSiteId : 'all'), $seriesTtl, function () use ($conn, $table, $since, $latest, $selectedSiteId) {
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

                // Sites list cache: extend default TTL to 6 hours (21600s)
                $sitesTtl = (int) config('cto.dashboard_sites_cache_ttl', 21600);
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

                return [
                    'ok' => true,
                    's1dlLabels' => $rowsDl->pluck('ts')->all(),
                    's1dlValues' => $rowsDl->pluck('avg_mbps')->map(fn($v) => round((float)$v, 2))->all(),
                    'sites' => $sites,
                    'selectedSiteId' => $selectedSiteId,
                    'latestTs' => $latest->copy()->startOfHour()->format('Y-m-d H:00'),
                    'earliestTs' => $since->copy()->startOfHour()->format('Y-m-d H:00'),
                ];
            });

            return response()->json($payload);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function capacity(Request $request)
    {
        $threshold = (float) $request->input('threshold', 0.85);
        $limit = (int) $request->input('limit', 0); // optional, can be used later
        $cacheTtl = (int) config('cto.capacity_cache_ttl', 1800); // 30 minutes

        $cacheKey = "capacity_latest_{$threshold}";

        try {
            $rows = Cache::remember($cacheKey, $cacheTtl, function () use ($threshold) {

                // -------------------------------
                // 1. Get latest S1 utilization per site (mysql3)
                // -------------------------------
                $sqlLatest = "
                SELECT t1.site_id, t1.s1_util, t1.timestamp
                FROM data_s1_util t1
                INNER JOIN (
                    SELECT site_id, MAX(timestamp) AS latest_ts
                    FROM data_s1_util
                    GROUP BY site_id
                ) t2
                ON t1.site_id = t2.site_id AND t1.timestamp = t2.latest_ts
                WHERE t1.s1_util >= ?
                ORDER BY t1.s1_util DESC, t1.site_id
            ";

                $mainRows = DB::connection('mysql3')->select($sqlLatest, [$threshold]);
                $mainRows = json_decode(json_encode($mainRows), true);

                if (empty($mainRows)) {
                    return [];
                }

                $siteIds = array_column($mainRows, 'site_id');
                $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
                $region = config('cto.packet_loss_region', 'BALI NUSRA');

                // -------------------------------
                // 2. Enrich with packet loss & metadata (mysql3)
                // -------------------------------
                $siteSetHash = md5(implode(',', $siteIds));
                $enrichTtl = (int) config('cto.dashboard_capacity_enrich_cache_ttl', 1800);

                $combinedMap = Cache::remember("capacity_enrich:{$siteSetHash}", $enrichTtl, function () use ($siteIds, $placeholders, $region) {

                    $combinedRows = DB::connection('mysql3')->select("
                    SELECT 
                        s.site_id,
                        pl.packet_loss,
                        j.jarak,
                        d.no_order,
                        d.status_order,
                        d.progress,
                        m.`Category Alpro` AS alpro_category,
                        m.`Type Alpro` AS alpro_type
                    FROM (SELECT ? AS site_id " . str_repeat(" UNION ALL SELECT ?", count($siteIds) - 1) . ") AS s
                    LEFT JOIN (
                        SELECT 
                            site_id,
                            AVG(avg_pl) AS packet_loss
                        FROM (
                            SELECT site_id, reg_name, `week`, avg_pl
                            FROM db_cto.official_mhi_weekly_summary_thi_per_site
                            WHERE reg_name = ?
                        ) w
                        WHERE site_id IN ({$placeholders})
                        GROUP BY site_id
                    ) pl ON pl.site_id = s.site_id
                    LEFT JOIN db_cto.jarak_site_radio j 
                        ON j.site_id = s.site_id
                    LEFT JOIN db_cto.data_site_order d 
                        ON d.site_id = s.site_id
                    LEFT JOIN db_cto.masterdata m 
                        ON TRIM(m.`Site ID NE`) = TRIM(s.site_id)
                ", array_merge($siteIds, [$region], $siteIds));

                    $map = [];
                    foreach ($combinedRows as $cr) {
                        $map[$cr->site_id] = [
                            'packet_loss' => $cr->packet_loss ?? 0,
                            'jarak' => $cr->jarak ?? null,
                            'no_order' => $cr->no_order ?? null,
                            'status_order' => $cr->status_order ?? null,
                            'progress' => $cr->progress ?? null,
                            'alpro_category' => $cr->alpro_category ?? null,
                            'alpro_type' => $cr->alpro_type ?? null,
                        ];
                    }
                    return $map;
                });

                // -------------------------------
                // 3. Merge main rows with enrichment
                // -------------------------------
                foreach ($mainRows as &$row) {
                    $siteId = $row['site_id'];
                    $row = array_merge($row, $combinedMap[$siteId] ?? []);
                }
                unset($row);

                return $mainRows;
            });

            // -------------------------------
            // 4. Return JSON
            // -------------------------------
            return response()->json([
                'ok' => true,
                'threshold' => $threshold,
                'limit' => $limit,
                'count' => count($rows),
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    // Order summary counts for pie chart
    public function orderSummary(Request $request)
    {
        try {
            // Determine latest date once and cache per-date summary
            $maxRow = DB::connection('mysql3')->selectOne("SELECT MAX(DATE(date_id)) AS max_date FROM db_cto.data_site_order");
            $maxDate = $maxRow->max_date ?? 'none';
            $ttl = (int) config('cto.dashboard_order_summary_cache_ttl', 3600);

            $result = Cache::remember("order_summary:{$maxDate}", $ttl, function () use ($maxDate) {
                $sql = "select 
    SUM(
        CASE
            WHEN duo.cek_nim_order IS null THEN 1
            ELSE 0
        END
    ) AS `Belum ada Order`
,
SUM(
        CASE
            WHEN duo.siteid_ne IS NOT null and duo.cek_nim_order IS NOT null AND dso.progress in ('5.Close') AND DATE(dso.date_id) = ?
            THEN 1
            ELSE 0
        END
    ) AS `Order Done` ,
    COUNT(DISTINCT CASE WHEN duo.siteid_ne IS NOT NULL and duo.cek_nim_order IS NOT null THEN duo.siteid_ne END)
    -
    COUNT(DISTINCT CASE
            WHEN duo.siteid_ne IS NOT NULL
             AND dso.progress IN ('5.Close')
             AND DATE(dso.date_id) = ?
            THEN duo.siteid_ne END) AS `Order On Progress`
from db_cto.data_usulan_order duo LEFT join db_cto.data_site_order dso on duo.siteid_ne  = dso.site_id";

                $row = DB::connection('mysql3')->selectOne($sql, [$maxDate, $maxDate]);
                return [
                    'belum' => (int) ($row->{"Belum ada Order"} ?? 0),
                    'done' => (int) ($row->{"Order Done"} ?? 0),
                    'onProgress' => (int) ($row->{"Order On Progress"} ?? 0),
                ];
            });

            return response()->json(['ok' => true, 'summary' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
