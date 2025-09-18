<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\DataUsulanOrder;
use App\Models\KomenUsulanOrder;

class OrderController extends Controller
{
    /**
     * Store a new order (Data Usulan Order) record.
     */
    public function store(Request $request): JsonResponse
    {
        // Authorization: require a specific permission if defined
        if ($request->user() && method_exists($request->user(), 'can')) {
            if (!$request->user()->can('create orders')) {
                return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
            }
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

        // tanggal_input uses DB default CURRENT_DATE, do not set unless provided intentionally
        unset($data['tanggal_input']);

        $commentText = $data['comment'] ?? null;
        unset($data['comment']);

        $row = DataUsulanOrder::create($data);

        if ($commentText !== null && trim($commentText) !== '') {
            KomenUsulanOrder::create([
                'order_id' => $row->no,
                'requestor' => $row->requestor, // or auth user name/email if preferred
                'comment' => $commentText,
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => $row,
        ]);
    }

    /**
     * Prefill data for order form (read-only, mysql3).
     * GET /api/order-prefill?site_id=...
     */
    public function prefill(Request $request): JsonResponse
    {
        $siteId = trim((string)$request->query('site_id', ''));
        if ($siteId === '') {
            return response()->json(['ok' => false, 'error' => 'site_id required'], 422);
        }
        // Basic auth guard (still behind login). Optional permission check if needed later.
        if (!$request->user()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        try {
            $conn = DB::connection('mysql3');

            // dapot: Branch by Siteid
            $branch = null;
            $rowDapot = $conn->selectOne("SELECT Branch FROM dapot WHERE Siteid = ? LIMIT 1", [$siteId]);
            if ($rowDapot) {
                $branch = $rowDapot->Branch ?? null;
            }

            // official_mhi_weekly_summary_thi_per_site: category_avg21 and avg_pl (for pl_value categorization)
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

            // data_site_order: no_order, status_order
            $cekNim = $statusOrder = null;
            $rowOrder = $conn->selectOne("SELECT no_order, status_order FROM data_site_order WHERE site_id = ? LIMIT 1", [$siteId]);
            if ($rowOrder) {
                $cekNim = $rowOrder->no_order ?? null;
                $statusOrder = $rowOrder->status_order ?? null;
            }

            // bwsetting: BW SETTING, LINK OWNER (remarks ok) picking highest BW SETTING numerically
            $linkCapacity = $linkOwner = null;
            $rowsBw = $conn->select("SELECT `BW SETTING` AS bw, `LINK OWNER` AS owner FROM bwsetting WHERE `SITE ID NE` = ? AND `REMARKS`='ok'", [$siteId]);
            if ($rowsBw) {
                // Choose row with max numeric BW (extract number from string)
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

            return response()->json([
                'ok' => true,
                'site_id' => $siteId,
                'data' => [
                    'nop' => $branch, // Branch -> nop
                    'pl_status' => $plStatus, // category_avg21
                    'pl_value' => $plValue, // derived from avg_pl
                    'link_capacity' => $linkCapacity, // BW SETTING
                    'link_owner' => $linkOwner, // LINK OWNER
                    'cek_nim_order' => $cekNim, // no_order
                    'status_order' => $statusOrder, // status_order
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * List existing comments (read-only) for the latest order of a site.
     * GET /api/order-comments?site_id=...
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

        // Find the most recent order (by primary key) for this site
        $order = DataUsulanOrder::where('siteid_ne', $siteId)
            ->orderByDesc('no')
            ->first(['no']);

        if (!$order) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        $comments = KomenUsulanOrder::where('order_id', $order->no)
            ->orderBy('id')
            ->get(['requestor', 'comment']);

        return response()->json([
            'ok' => true,
            'order_id' => $order->no,
            'data' => $comments,
        ]);
    }
}
