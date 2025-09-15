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
                        ->when($selectedSiteId, function ($q) use ($selectedSiteId) { $q->where('site_id', $selectedSiteId); })
                        ->whereBetween('finish_timestamp', [$since, $latest])
                        ->groupBy('ts')
                        ->orderBy('ts')
                        ->get();
                });

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
        $table = 'etl_cell_4g_ran_huawei_kpi_hourly_agg';
        $weeks = (int) $request->input('weeks', 5);
        $threshold = (float) $request->input('threshold', 0.85);
        $sampling = (bool) $request->input('sampling', false);
        $limit = (int) $request->input('limit', 0);
        $sampleModulo = $sampling ? 10 : null;
        $cacheTtl = 1800; // 30 minutes

        $samplingSql = $sampling ? " AND site_id % 10 = 0" : "";
        // Added cache version suffix (v2) to ensure new field max_highest_persentase is populated
        $cacheKey = "capacity_v2_{$weeks}_{$threshold}_" . ($sampling ? "sampled" : "full");

        try {
            $rows = Cache::remember($cacheKey, $cacheTtl, function () use ($table, $weeks, $threshold, $samplingSql) {
                // -------------------------------
                // MAIN CAPACITY QUERY (mysql2)
                // -------------------------------
                $sql = "
                SELECT
                    dp.site_id,
                    AVG(dp.highest_persentase) AS avg_highest_persentase,
                    MAX(dp.highest_persentase) AS max_highest_persentase,
                    COUNT(*) AS day_count
                FROM (
                    SELECT
                        site_id,
                        CAST(finish_timestamp AS DATE) AS day,
                        MAX(s1_usage_dl_average_mbps / NULLIF(s1_usage_dl_maximum_mbps, 0)) AS highest_persentase
                    FROM {$table}
                    WHERE finish_timestamp >= CURRENT_DATE - INTERVAL ? WEEK {$samplingSql}
                    GROUP BY site_id, CAST(finish_timestamp AS DATE)
                ) AS dp
                GROUP BY dp.site_id
                HAVING AVG(dp.highest_persentase) >= ?
                ORDER BY avg_highest_persentase DESC, dp.site_id
            ";

                $rows = DB::connection('mysql2')->select($sql, [$weeks, $threshold]);
                $rows = json_decode(json_encode($rows), true);

                // Backward compatibility: if cached / legacy rows missing max_highest_persentase, compute it in bulk
                if (!empty($rows) && !array_key_exists('max_highest_persentase', $rows[0])) {
                    $siteIds = array_values(array_unique(array_column($rows, 'site_id')));
                    if (!empty($siteIds)) {
                        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
                        $sqlMax = "SELECT site_id, MAX(day_max) AS max_highest_persentase FROM (
    SELECT site_id, CAST(finish_timestamp AS DATE) AS day,
           MAX(s1_usage_dl_average_mbps / NULLIF(s1_usage_dl_maximum_mbps,0)) AS day_max
    FROM {$table}
    WHERE finish_timestamp >= CURRENT_DATE - INTERVAL ? WEEK" . ($samplingSql ? str_replace(' AND', ' AND', $samplingSql) : '') . "
    AND site_id IN ($placeholders)
    GROUP BY site_id, CAST(finish_timestamp AS DATE)
) t GROUP BY site_id";
                        $bindingsMax = array_merge([$weeks], $siteIds);
                        $maxRows = DB::connection('mysql2')->select($sqlMax, $bindingsMax);
                        $maxMap = [];
                        foreach ($maxRows as $mr) {
                            $maxMap[$mr->site_id] = $mr->max_highest_persentase;
                        }
                        foreach ($rows as &$row) {
                            $row['max_highest_persentase'] = $maxMap[$row['site_id']] ?? null;
                        }
                        unset($row);
                    }
                }

                if (empty($rows)) {
                    return $rows;
                }

                // -------------------------------
                // COMBINED PACKET LOSS + METADATA (mysql3, db_cto)
                // -------------------------------
                $siteIds = array_values(array_unique(array_column($rows, 'site_id')));
                $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
                $region = config('cto.packet_loss_region', 'BALI NUSRA');

                $sqlCombined = "
                SELECT 
                    s.site_id,
                    pl.packet_loss,
                    j.jarak,
                    d.no_order,
                    m.`Category Alpro` AS alpro_category,
                    m.`Type Alpro` AS alpro_type
                FROM (SELECT ? AS site_id " . str_repeat(" UNION ALL SELECT ?", count($siteIds) - 1) . ") AS s
                LEFT JOIN (
                    SELECT 
                        site_id,
                        AVG(avg_pl) AS packet_loss
                    FROM (
                        SELECT
                            site_id,
                            reg_name,
                            `week`,
                            avg_pl
                        FROM db_cto.official_mhi_weekly_summary_thi_per_site
                        WHERE reg_name = ?
                          AND `week` >= (
                                SELECT MAX(`week`) - ? 
                                FROM db_cto.official_mhi_weekly_summary_thi_per_site
                                WHERE reg_name = ?
                            )
                    ) w
                    WHERE site_id IN ({$placeholders})
                    GROUP BY site_id
                ) pl ON pl.site_id = s.site_id
                LEFT JOIN db_cto.jarak_site_radio j 
                    ON j.site_id = s.site_id
                LEFT JOIN db_cto.data_site_order_latest d 
                    ON d.site_id = s.site_id
                LEFT JOIN db_cto.masterdata m 
                    ON TRIM(m.`Site ID NE`) = TRIM(s.site_id)
            ";

                $bindings = array_merge($siteIds, [$region, $weeks, $region], $siteIds);
                $combinedRows = DB::connection('mysql3')->select($sqlCombined, $bindings);

                $combinedMap = [];
                foreach ($combinedRows as $cr) {
                    $combinedMap[$cr->site_id] = [
                        'packet_loss'    => $cr->packet_loss ?? 0,
                        'jarak'          => $cr->jarak ?? null,
                        'no_order'       => $cr->no_order ?? null,
                        'alpro_category' => $cr->alpro_category ?? null,
                        'alpro_type'     => $cr->alpro_type ?? null,
                    ];
                }

                foreach ($rows as &$row) {
                    $siteId = $row['site_id'];
                    $row = array_merge($row, $combinedMap[$siteId] ?? [
                        'packet_loss'    => 0,
                        'jarak'          => null,
                        'no_order'       => null,
                        'alpro_category' => null,
                        'alpro_type'     => null,
                    ]);
                    // Ensure key present (in case of earlier cache misses)
                    if (!array_key_exists('max_highest_persentase', $row)) {
                        $row['max_highest_persentase'] = null;
                    }
                }
                unset($row);

                return $rows;
            });

            // -------------------------------
            // FINAL JSON RESPONSE
            // -------------------------------
            return response()->json([
                'ok'           => true,
                'weeks'        => $weeks,
                'threshold'    => $threshold,
                'limit'        => $limit,
                'sampleModulo' => $sampleModulo,
                'count'        => count($rows),
                'rows'         => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
