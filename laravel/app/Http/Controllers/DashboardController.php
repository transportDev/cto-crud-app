<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * Controller Dashboard
 *
 * Controller ini mengelola endpoint-endpoint untuk dashboard monitoring yang menampilkan:
 * - Grafik traffic S1 DL (Download) average dalam periode 7 hari
 * - Daftar site dengan kapasitas S1 tinggi (> threshold)
 * - Ringkasan status order (belum order, done, on progress)
 * - Ringkasan order per NOP (Network Operation Partner)
 *
 * Semua endpoint menggunakan caching berlapis untuk optimasi performa:
 * - Cache untuk timestamp terbaru
 * - Cache untuk data series chart
 * - Cache untuk daftar site
 * - Cache untuk enrichment data (packet loss, metadata, comments)
 *
 * @package App\Http\Controllers
 * @author  CTO CRUD App Team
 * @version 1.0
 * @since   1.0.0
 */
class DashboardController extends Controller
{
    /**
     * Halaman utama dashboard
     *
     * Method ini menampilkan halaman dashboard dengan data:
     * - Grafik S1 DL average dalam periode 7 hari terakhir (hanya untuk JSON request)
     * - Daftar site tersedia (di-load dari client via /api/traffic)
     * - Filter berdasarkan site_id (optional)
     *
     * Untuk initial page load (SSR), hanya struktur HTML yang di-render tanpa
     * data berat. Client kemudian fetch data via AJAX untuk performa lebih baik.
     *
     * Cache strategy:
     * - Latest timestamp: 60 detik (configurable via cto.dashboard_latest_cache_ttl)
     * - Series data: 180 detik (configurable via cto.dashboard_series_cache_ttl)
     *
     * @param Request $request Request object dengan query params: json, site_id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse View atau JSON response
     */
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

            $latestTtl = (int) config('cto.dashboard_latest_cache_ttl', 60);
            $latestTs = Cache::remember('dash:latestTs', $latestTtl, function () use ($conn, $table) {
                return $conn->table($table)->max('finish_timestamp');
            });
            if ($latestTs) {
                $latest = Carbon::parse($latestTs);
                $since = $latest->copy()->subDays(7);

                $windowKey = 'r7d:' . $latest->copy()->startOfHour()->format('YmdH');
                $siteKey = $selectedSiteId ? 'site:' . $selectedSiteId : 'all';

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

        if ($forJson) {
            return response()->json($payload);
        }

        return view('dashboard', $payload);
    }

    /**
     * Endpoint JSON untuk data traffic chart
     *
     * Endpoint ringan yang di-load dari client-side untuk mengisi chart traffic.
     * Method ini mengembalikan:
     * - Series data S1 DL average (labels & values) untuk 7 hari terakhir
     * - Daftar site yang tersedia (max 500 sites, distinct dari data 7 hari)
     * - Timestamp range (latest & earliest)
     *
     * Cache strategy berlapis:
     * - Latest timestamp: 60 detik
     * - Series data: 180 detik
     * - Sites list: 6 jam (21600 detik)
     * - Payload komposit: 120 detik
     *
     * @param Request $request Request object dengan query param: site_id (optional)
     * @return \Illuminate\Http\JsonResponse JSON response dengan ok status dan data
     */
    public function traffic(Request $request)
    {
        $selectedSiteId = $request->query('site_id');
        $table = 'etl_cell_4g_ran_huawei_kpi_hourly_agg';
        $conn = DB::connection('mysql2');

        try {
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

            $payloadTtl = (int) config('cto.dashboard_traffic_cache_ttl', 120);
            $payload = Cache::remember("dash:traffic:v1:$windowKey:$siteKey", $payloadTtl, function () use ($conn, $table, $since, $latest, $selectedSiteId, $windowKey) {
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

    /**
     * Endpoint JSON untuk data capacity sites
     *
     * Method ini mengembalikan daftar site yang memiliki utilisasi S1 >= threshold
     * beserta enrichment data tambahan:
     * - Latest S1 utilization per site dari data_s1_util (mysql3)
     * - Packet loss average dari official_mhi_weekly_summary
     * - Jarak site dari jarak_site_radio
     * - Order info dari data_site_order
     * - Metadata Alpro dari masterdata
     * - Comments dari komen_usulan_order
     *
     * Process flow:
     * 1. Query latest S1 util per site yang >= threshold
     * 2. Enrich dengan packet loss, jarak, order, metadata, comments
     * 3. Merge main rows dengan enrichment data
     * 4. Return JSON dengan ok status
     *
     * Cache strategy:
     * - Main capacity data: 30 menit (configurable via cto.capacity_cache_ttl)
     * - Enrichment data: 30 menit per set of site IDs
     *
     * @param Request $request Request object dengan params: threshold (default 0.85), limit (optional)
     * @return \Illuminate\Http\JsonResponse JSON response dengan daftar site capacity tinggi
     */
    public function capacity(Request $request)
    {
        $threshold = (float) $request->input('threshold', 0.85);
        $limit = (int) $request->input('limit', 0);
        $cacheTtl = (int) config('cto.capacity_cache_ttl', 1800);

        $cacheKey = "capacity_latest_{$threshold}";

        try {
            $rows = Cache::remember($cacheKey, $cacheTtl, function () use ($threshold) {

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
                        d.tgl_on_air,
                        m.`Category Alpro` AS alpro_category,
                        m.`Type Alpro` AS alpro_type,
                        GROUP_CONCAT(
                            DISTINCT CONCAT_WS('|', 
                                kom.id,
                                kom.requestor, 
                                kom.comment,
                                duo.no
                            ) 
                            ORDER BY kom.id DESC 
                            SEPARATOR ';;'
                        ) AS comments_data
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
                    LEFT JOIN db_cto.data_usulan_order duo
                        ON TRIM(duo.siteid_ne) = TRIM(s.site_id)
                    LEFT JOIN db_cto.komen_usulan_order kom
                        ON kom.order_id = duo.no AND TRIM(kom.siteid_ne) = TRIM(s.site_id)
                    GROUP BY s.site_id, pl.packet_loss, j.jarak, d.no_order, 
                             d.status_order, d.progress, d.tgl_on_air, 
                             m.`Category Alpro`, m.`Type Alpro`
                ", array_merge($siteIds, [$region], $siteIds));

                    $map = [];
                    foreach ($combinedRows as $cr) {
                        $comments = [];
                        if (!empty($cr->comments_data)) {
                            $commentsRaw = explode(';;', $cr->comments_data);
                            foreach ($commentsRaw as $commentStr) {
                                $parts = explode('|', $commentStr);
                                if (count($parts) === 4) {
                                    $comments[] = [
                                        'id' => (int) $parts[0],
                                        'requestor' => $parts[1],
                                        'comment' => $parts[2],
                                        'order_id' => (int) $parts[3],
                                    ];
                                }
                            }
                        }

                        $map[$cr->site_id] = [
                            'packet_loss' => $cr->packet_loss ?? 0,
                            'jarak' => $cr->jarak ?? null,
                            'no_order' => $cr->no_order ?? null,
                            'status_order' => $cr->status_order ?? null,
                            'tgl_on_air' => $cr->tgl_on_air ?? null,
                            'progress' => $cr->progress ?? null,
                            'alpro_category' => $cr->alpro_category ?? null,
                            'alpro_type' => $cr->alpro_type ?? null,
                            'comments' => $comments,
                            'comments_count' => count($comments),
                        ];
                    }
                    return $map;
                });

                foreach ($mainRows as &$row) {
                    $siteId = $row['site_id'];
                    $row = array_merge($row, $combinedMap[$siteId] ?? []);
                }
                unset($row);

                return $mainRows;
            });

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

    /**
     * Endpoint JSON untuk ringkasan status order (pie chart)
     *
     * Method ini mengembalikan summary data order untuk pie chart dengan breakdown:
     * - Belum ada order: site yang belum memiliki order sama sekali
     * - Order Done: site dengan order yang sudah Close (progress = '5.Close')
     * - Order On Progress: site dengan order tapi belum Close
     *
     * Data dikembalikan untuk 2 snapshot date:
     * - Latest date (hari ini/terbaru)
     * - Previous date (kemarin/sebelumnya)
     *
     * Juga menghitung delta (perubahan) antara kedua tanggal tersebut.
     *
     * Cache strategy:
     * - Summary data: 1 jam (configurable via cto.dashboard_order_summary_cache_ttl)
     * - Cache key terikat pada latest_date dan prev_date
     *
     * @param Request $request Request object
     * @return \Illuminate\Http\JsonResponse JSON response dengan summary order
     */
    public function orderSummary(Request $request)
    {
        try {
            // Determine latest and previous snapshot dates once, then cache the per-date summary
            $datesRow = DB::connection('mysql3')->selectOne(<<<SQL
                SELECT
                    (SELECT DATE(date_id)
                     FROM db_cto.data_site_order
                     GROUP BY DATE(date_id)
                     ORDER BY DATE(date_id) DESC
                     LIMIT 1) AS latest_date,
                    (SELECT DATE(date_id)
                     FROM db_cto.data_site_order
                     GROUP BY DATE(date_id)
                     ORDER BY DATE(date_id) DESC
                     LIMIT 1 OFFSET 1) AS prev_date
            SQL);

            $latestDate = $datesRow->latest_date ?? null;
            $prevDate = $datesRow->prev_date ?? null;
            $cacheKey = sprintf(
                'order_summary:%s:%s',
                $latestDate ?? 'none',
                $prevDate ?? 'none'
            );

            $ttl = (int) config('cto.dashboard_order_summary_cache_ttl', 3600);

            $result = Cache::remember($cacheKey, $ttl, function () use ($latestDate, $prevDate) {
                $sql = <<<'SQL'
SELECT
    SUM(
        CASE
            WHEN duo.cek_nim_order IS NULL THEN 1
            ELSE 0
        END
    ) AS belum_ada_order_today,
    SUM(
        CASE
            WHEN duo.cek_nim_order IS NULL THEN 1
            ELSE 0
        END
    ) AS belum_ada_order_yesterday,
    COUNT(DISTINCT CASE
        WHEN duo.siteid_ne IS NOT NULL
         AND duo.cek_nim_order IS NOT NULL
         AND dso.progress IN ('5.Close')
         AND DATE(dso.date_id) = dates.latest_date
        THEN duo.siteid_ne
    END) AS order_done_today,
    COUNT(DISTINCT CASE
        WHEN duo.siteid_ne IS NOT NULL
         AND duo.cek_nim_order IS NOT NULL
    THEN duo.siteid_ne END)
    -
    COUNT(DISTINCT CASE
        WHEN duo.siteid_ne IS NOT NULL
         AND dso.progress IN ('5.Close')
         AND DATE(dso.date_id) = dates.latest_date
    THEN duo.siteid_ne END) AS order_on_progress_today,
    COUNT(DISTINCT CASE
        WHEN duo.siteid_ne IS NOT NULL
         AND duo.cek_nim_order IS NOT NULL
         AND dso.progress IN ('5.Close')
         AND DATE(dso.date_id) = dates.prev_date
        THEN duo.siteid_ne
    END) AS order_done_yesterday,
    COUNT(DISTINCT CASE
        WHEN duo.siteid_ne IS NOT NULL
         AND duo.cek_nim_order IS NOT NULL
    THEN duo.siteid_ne END)
    -
    COUNT(DISTINCT CASE
        WHEN duo.siteid_ne IS NOT NULL
         AND dso.progress IN ('5.Close')
         AND DATE(dso.date_id) = dates.prev_date
    THEN duo.siteid_ne END) AS order_on_progress_yesterday
FROM db_cto.data_usulan_order duo
LEFT JOIN db_cto.data_site_order dso
  ON duo.siteid_ne = dso.site_id
CROSS JOIN (
    SELECT ? AS latest_date, ? AS prev_date
) AS dates
SQL;

                $row = DB::connection('mysql3')->selectOne($sql, [$latestDate, $prevDate]);
                $row = $row ? (object) $row : (object) [];

                $belumToday = (int) ($row->belum_ada_order_today ?? 0);
                $belumYesterday = (int) ($row->belum_ada_order_yesterday ?? 0);
                $doneToday = (int) ($row->order_done_today ?? 0);
                $doneYesterday = (int) ($row->order_done_yesterday ?? 0);
                $onProgressToday = (int) ($row->order_on_progress_today ?? 0);
                $onProgressYesterday = (int) ($row->order_on_progress_yesterday ?? 0);

                $totalUsulan = (int) DB::connection('mysql3')
                    ->table('db_cto.data_usulan_order')
                    ->count();

                $totalToday = $belumToday + $doneToday + $onProgressToday;
                $totalYesterday = $belumYesterday + $doneYesterday + $onProgressYesterday;

                return [
                    'belum' => $belumToday,
                    'belumYesterday' => $belumYesterday,
                    'done' => $doneToday,
                    'doneYesterday' => $doneYesterday,
                    'onProgress' => $onProgressToday,
                    'onProgressYesterday' => $onProgressYesterday,
                    'totalUsulan' => $totalUsulan,
                    'totalToday' => $totalToday,
                    'totalYesterday' => $totalYesterday,
                    'delta' => [
                        'belum' => $belumYesterday - $belumToday,
                        'done' => $doneYesterday - $doneToday,
                        'onProgress' => $onProgressYesterday - $onProgressToday,
                        'total' => $totalYesterday - $totalToday,
                    ],
                    'dates' => [
                        'latest' => $latestDate,
                        'previous' => $prevDate,
                    ],
                ];
            });

            return response()->json(['ok' => true, 'summary' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Endpoint JSON untuk ringkasan order per NOP
     *
     * Method ini mengembalikan breakdown order berdasarkan NOP dengan 3 kategori:
     * - Belum ada Order: usulan yang belum memiliki order
     * - Order Done: order yang sudah selesai (progress = '5.Close')
     * - Order On Progress: order yang masih berjalan (belum Close)
     *
     * Data dikelompokkan per NOP dan diurutkan berdasarkan nama NOP.
     * NOP yang tidak diketahui akan ditampilkan sebagai 'Tidak diketahui'.
     *
     * Cache strategy:
     * - Summary per NOP: 1 jam (configurable via cto.dashboard_order_summary_cache_ttl)
     * - Cache key terikat pada max_date dari data_site_order
     *
     * @param Request $request Request object
     * @return \Illuminate\Http\JsonResponse JSON response dengan rows per NOP
     */
    public function orderSummaryByNop(Request $request)
    {
        try {
            $maxRow = DB::connection('mysql3')->selectOne("SELECT MAX(DATE(date_id)) AS max_date FROM db_cto.data_site_order");
            $maxDate = $maxRow->max_date ?? null;
            $cacheKeyDate = $maxDate ?? 'none';
            $ttl = (int) config('cto.dashboard_order_summary_cache_ttl', 3600);

            $rows = Cache::remember("order_summary_nop:{$cacheKeyDate}", $ttl, function () use ($maxDate) {
                $sql = "SELECT 
    COALESCE(duo.nop, 'Tidak diketahui') AS nop,
    SUM(
        CASE
            WHEN duo.cek_nim_order IS NULL THEN 1
            ELSE 0
        END
    ) AS `Belum ada Order`,
    SUM(
        CASE
            WHEN duo.siteid_ne IS NOT NULL 
             AND duo.cek_nim_order IS NOT NULL 
             AND dso.progress IN ('5.Close') 
             AND DATE(dso.date_id) = ?
            THEN 1
            ELSE 0
        END
    ) AS `Order Done`,
    COUNT(DISTINCT CASE 
            WHEN duo.siteid_ne IS NOT NULL 
             AND duo.cek_nim_order IS NOT NULL 
            THEN duo.siteid_ne 
        END)
    -
    COUNT(DISTINCT CASE
            WHEN duo.siteid_ne IS NOT NULL
             AND dso.progress IN ('5.Close')
             AND DATE(dso.date_id) = ?
            THEN duo.siteid_ne 
        END) AS `Order On Progress`
FROM db_cto.data_usulan_order duo
LEFT JOIN db_cto.data_site_order dso 
       ON duo.siteid_ne = dso.site_id
GROUP BY duo.nop
ORDER BY duo.nop";

                $bindings = [$maxDate, $maxDate];
                $rawRows = DB::connection('mysql3')->select($sql, $bindings);

                return array_map(function ($row) {
                    $arrayRow = (array) $row;
                    return [
                        'nop' => (string) ($arrayRow['nop'] ?? 'Tidak diketahui'),
                        'Belum ada Order' => (int) ($arrayRow['Belum ada Order'] ?? 0),
                        'Order Done' => (int) ($arrayRow['Order Done'] ?? 0),
                        'Order On Progress' => (int) ($arrayRow['Order On Progress'] ?? 0),
                    ];
                }, $rawRows);
            });

            return response()->json([
                'ok' => true,
                'rows' => $rows,
                'maxDate' => $maxDate,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
