<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\DataUsulanOrder;
use App\Models\KomenUsulanOrder;

/**
 * Controller Order Management
 *
 * Controller ini mengelola endpoint-endpoint untuk operasi order (Data Usulan Order):
 * - Pembuatan order baru dengan validasi komprehensif
 * - Prefill data form order dari berbagai sumber (dapot, summary, bwsetting, masterdata)
 * - Detail order terbaru per site dari view data_site_order_latest
 * - List comments untuk site tertentu
 *
 * @package App\Http\Controllers
 * @author  CTO CRUD App Team
 * @version 1.0
 * @since   1.0.0
 */
class OrderController extends Controller
{
    /**
     * Menyimpan record order baru (Data Usulan Order)
     *
     * Method ini melakukan:
     * 1. Validasi authorization (role admin/requestor + permission 'create orders')
     * 2. Validasi input data dengan rules lengkap
     * 3. Menyimpan data order ke table data_usulan_order
     * 4. Membuat comment pertama (jika ada) ke table komen_usulan_order
     * 5. Operasi dilakukan dalam transaction untuk konsistensi data
     *
     * Field tanggal_input menggunakan DB default (CURRENT_DATE) sehingga
     * tidak perlu di-set manual.
     *
     * POST /api/order
     *
     * @param Request $request Request object dengan data order dan optional comment
     * @return JsonResponse JSON response dengan ok status dan data order yang dibuat
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }
        if (
            method_exists($user, 'hasAnyRole')
            ? ! $user->hasAnyRole(['admin', 'requestor'])
            : (method_exists($user, 'hasRole') && ! $user->hasRole('admin'))
        ) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }
        if (method_exists($user, 'can') && !$user->can('create orders')) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }
        $data = $request->all();

        $rules = [
            'requestor' => 'required|string|max:100',
            'regional' => 'required|string|max:50',
            'nop' => 'nullable|string|max:50',
            'siteid_ne' => 'nullable|string|max:10',
            'siteid_fe' => 'nullable|string|max:50',
            'transport_type' => 'nullable|string|max:20',
            'pl_status' => 'nullable|string|max:20',
            'transport_category' => 'nullable|string|max:20',
            'pl_value' => 'nullable|string|max:20',
            'link_capacity' => 'nullable|integer',
            'link_util' => 'nullable|numeric',
            'link_owner' => 'nullable|string|max:20',
            'propose_solution' => 'nullable|string|max:100',
            'remark' => 'nullable|string|max:100',
            'jarak_odp' => 'nullable|numeric',
            'cek_nim_order' => 'nullable|string|max:50',
            'status_order' => 'nullable|string|max:50',
            'comment' => 'nullable|string|max:1000',
        ];

        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            return response()->json([
                'ok' => false,
                'errors' => $v->errors(),
            ], 422);
        }

        unset($data['tanggal_input']);

        $commentText = $data['comment'] ?? null;
        unset($data['comment']);

        $row = null;
        DB::connection('mysql3')->transaction(function () use ($data, $commentText, &$row) {
            $row = DataUsulanOrder::on('mysql3')->create($data);
            if ($commentText !== null && trim($commentText) !== '') {
                KomenUsulanOrder::on('mysql3')->create([
                    'order_id'  => $row->no,
                    'requestor' => $row->requestor,
                    'comment'   => $commentText,
                    'siteid_ne' => $row->siteid_ne ?? '',
                ]);
            }
        });

        return response()->json([
            'ok' => true,
            'data' => $row,
        ]);
    }

    /**
     * Prefill data form order dari berbagai sumber database
     *
     * Method ini mengambil dan menggabungkan data dari 5 tabel berbeda di mysql3 untuk
     * keperluan prefill form pembuatan order. Data dikombinasikan berdasarkan site_id.
     *
     * GET /api/order-prefill?site_id=XXX
     *
     * @param Request $request Request object dengan query param site_id (required)
     * @return JsonResponse JSON response dengan ok status dan aggregated data dari 5 tabel
     */
    public function prefill(Request $request): JsonResponse
    {
        $siteId = trim((string)$request->query('site_id', ''));
        if ($siteId === '') {
            return response()->json(['ok' => false, 'error' => 'site_id required'], 422);
        }
        if (!$request->user()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        try {
            $conn = DB::connection('mysql3');

            $branch = null;
            $rowDapot = $conn->selectOne("SELECT Branch FROM dapot WHERE Siteid = ? LIMIT 1", [$siteId]);
            if ($rowDapot) {
                $branch = $rowDapot->Branch ?? null;
            }

            $plStatus = null;
            $plValue = null;
            $rowSummary = $conn->selectOne(
                "SELECT category_avg21, avg_pl 
                                 FROM official_mhi_weekly_summary_thi_per_site 
                                 WHERE site_id = ? 
                                     AND week = (
                                             SELECT MAX(week) 
                                             FROM official_mhi_weekly_summary_thi_per_site 
                                             WHERE site_id = ?
                                     ) 
                                 LIMIT 1",
                [$siteId, $siteId]
            );
            if ($rowSummary) {
                $plStatus = $rowSummary->category_avg21 ?? null;
                $avgPl = $rowSummary->avg_pl ?? null;
                if ($avgPl !== null) {
                    $avgPlNum = (float) $avgPl;
                    if ($avgPlNum > 5) {
                        $plValue = "PL > 5%";
                    } elseif ($avgPlNum >= 1) {
                        $plValue = "PL 1% - 5%";
                    } else {
                        $plValue = "PL < 1%";
                    }
                }
            }

            $cekNim = $statusOrder = null;
            $rowOrder = $conn->selectOne("SELECT no_order, status_order FROM data_site_order WHERE site_id = ? LIMIT 1", [$siteId]);
            if ($rowOrder) {
                $cekNim = $rowOrder->no_order ?? null;
                $statusOrder = $rowOrder->status_order ?? null;
            }

            $linkCapacity = $linkOwner = null;
            $rowsBw = $conn->select("SELECT `BW SETTING` AS bw, `LINK OWNER` AS owner FROM bwsetting WHERE `SITE ID NE` = ? AND `REMARKS`='ok'", [$siteId]);
            if ($rowsBw) {
                $best = null;
                $bestVal = -1;
                foreach ($rowsBw as $r) {
                    $bwRaw = (string)($r->bw ?? '');
                    if (preg_match('/(\\d+(?:\\.\\d+)?)/', $bwRaw, $m)) {
                        $num = (float)$m[1];
                    } else {
                        $num = 0;
                    }
                    if ($num > $bestVal) {
                        $bestVal = $num;
                        $best = $r;
                    }
                }
                if ($best) {
                    $linkCapacity = $best->bw;
                    $linkOwner = $best->owner;
                }
            }

            $transportType = null;
            $transportCategory = null;
            $rowMaster = $conn->selectOne(
                "SELECT `Type Alpro` AS type_alpro, `Category Alpro` AS category_alpro FROM masterdata WHERE `Site ID NE` = ? LIMIT 1",
                [$siteId]
            );
            if ($rowMaster) {
                $transportType = $rowMaster->type_alpro ?? null;
                $transportCategory = $rowMaster->category_alpro ?? null;
            }

            return response()->json([
                'ok' => true,
                'site_id' => $siteId,
                'data' => [
                    'nop' => $branch,
                    'pl_status' => $plStatus,
                    'pl_value' => $plValue,
                    'link_capacity' => $linkCapacity,
                    'link_owner' => $linkOwner,
                    'cek_nim_order' => $cekNim,
                    'status_order' => $statusOrder,
                    'transport_type' => $transportType,
                    'transport_category' => $transportCategory,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mengambil detail order terbaru untuk sebuah site dari view
     *
     * Method ini melakukan query ke view data_site_order_latest di mysql3
     * untuk mendapatkan informasi order terlengkap dan terbaru per site.
     *
     * GET /api/order/detail/{site_id}
     *
     * @param Request $request Request object dengan user authentication
     * @param string $siteId Site ID yang akan diambil detail ordernya
     * @return JsonResponse JSON response dengan ok status dan object detail order (35 fields)
     */
    public function detail(Request $request, string $siteId): JsonResponse
    {
        $siteId = trim($siteId);
        if ($siteId === '') {
            return response()->json(['ok' => false, 'error' => 'site_id required'], 422);
        }
        if (!$request->user()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        try {
            $conn = DB::connection('mysql3');
            $detail = $conn->selectOne(
                'SELECT `no`, `site_id`, `site_name`, `tier`, `region`, `bw_order`, `program`, `sow`, `bill_type`, `lat`, `long`, `product_type`, `no_order`, `tgl_order`, `nama_program`, `progress`, `update_progress`, `dependency`, `pic`, `target_close`, `tgl_on_air`, `aging_order`, `pl_distribution`, `pl_status`, `pl_aging`, `flatten_status`, `simpul`, `site_class`, `nop`, `priority`, `last_update`, `feedback`, `date_co`, `witel`, `status_order`, `date_id`
                 FROM data_site_order_latest WHERE site_id = ? LIMIT 1',
                [$siteId]
            );

            if (!$detail) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Data tidak ditemukan',
                ], 404);
            }

            return response()->json([
                'ok' => true,
                'data' => (array) $detail,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mengambil daftar comments untuk sebuah site tertentu
     *
     * Method ini melakukan query ke table komen_usulan_order di mysql3
     * untuk mendapatkan semua comments/komentar yang terkait dengan sebuah site.
     *
     * GET /api/order-comments?site_id=XXX
     *
     * @param Request $request Request object dengan query param site_id (required)
     * @return JsonResponse JSON response dengan ok status dan array of comments
     */
    public function comments(Request $request): JsonResponse
    {
        $siteId = trim((string)$request->query('site_id', ''));
        if ($siteId === '') {
            return response()->json(['ok' => false, 'error' => 'site_id required'], 422);
        }
        if (!$request->user()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $comments = KomenUsulanOrder::on('mysql3')
            ->where('siteid_ne', $siteId)
            ->orderBy('id')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $comments,
        ]);
    }
}
