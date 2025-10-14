@extends('layouts.dashboard')

@php($canCreateOrders = auth()->check() && auth()->user()->can('create orders'))

@section('title', 'BI Dashboard')

@push('head')
<script defer src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
{{-- XLSX is now lazy-loaded on demand in resources/js/pages/dashboard.js --}}
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/favicon.ico">
<meta name="theme-color" content="#000000">
<meta name="can-create-orders" content="{{ $canCreateOrders ? '1' : '0' }}">
<script>
    (function() {
        const meta = document.querySelector('meta[name="can-create-orders"]');
        window.canCreateOrders = meta && meta.getAttribute('content') === '1';
    })();
</script>
<script>
    window.NOP_OPTIONS = Array.from(
        new Set([
            ...(Array.isArray(window.NOP_OPTIONS) ? window.NOP_OPTIONS : []),
            "DENPASAR",
            "KUPANG",
            "MATARAM",
            "FLORES",
        ])
    );
</script>
<script>
    window.PROPOSE_SOLUTION_OPTIONS = Array.from(
        new Set([
            ...(Array.isArray(window.PROPOSE_SOLUTION_OPTIONS) ?
                window.PROPOSE_SOLUTION_OPTIONS : []),
            "",
            "FO TLKM",
            "Radio IP",
            "No need (Done Upgrade Channel 56 MHz)",
        ])
    );
</script>
<script id="dash-data" type="application/json">
    {
        "s1dlLabels": @json($s1dlLabels ?? []),
        "s1dlValues": @json($s1dlValues ?? []),
        "sites": @json($sites ?? []),
        "selectedSiteId": @json($selectedSiteId ?? null),
        "error": @json($error ?? null),
        "earliestTs": @json($earliestTs ?? null),
        "latestTs": @json($latestTs ?? null)
    }
</script>
@endpush

@section('content')


<div id="errorBox" class="hidden mb-4 p-3 rounded-md" style="background:#2b1211;color:#fecaca;border:1px solid #7f1d1d"></div>


<div class="dashboard-grid">
    <div class="kpi-card dashboard-grid__item--kpi-total">
        <div class="kpi-card__label">Total Usulan Order</div>
        <div class="kpi-card__value" id="kpiTotalValue">0</div>
        <div class="kpi-card__delta is-hidden" id="kpiTotalDelta" data-trend="flat">
            <span class="kpi-card__delta-icon">—</span>
            <div class="kpi-card__delta-info">
                <span class="kpi-card__delta-value">No Changes</span>
                <span class="kpi-card__delta-label">dari kemarin</span>
            </div>
        </div>
        <div class="kpi-card__meta">Jumlah keseluruhan usulan order</div>
    </div>
    <div class="kpi-card dashboard-grid__item--kpi-progress">
        <div class="kpi-card__label">Order Dalam Proses</div>
        <div class="kpi-card__value" id="kpiProgressValue">0</div>
        <div class="kpi-card__delta is-hidden" id="kpiProgressDelta" data-trend="flat">
            <span class="kpi-card__delta-icon">—</span>
            <div class="kpi-card__delta-info">
                <span class="kpi-card__delta-value">No Changes</span>
                <span class="kpi-card__delta-label">dari kemarin</span>
            </div>
        </div>
        <div class="kpi-card__meta">Order yang sedang on progres</div>
    </div>
    <div class="kpi-card dashboard-grid__item--kpi-done">
        <div class="kpi-card__label">Order Selesai</div>
        <div class="kpi-card__value" id="kpiDoneValue">0</div>
        <div class="kpi-card__delta is-hidden" id="kpiDoneDelta" data-trend="flat">
            <span class="kpi-card__delta-icon">—</span>
            <div class="kpi-card__delta-info">
                <span class="kpi-card__delta-value">No Changes</span>
                <span class="kpi-card__delta-label">dari kemarin</span>
            </div>
        </div>
        <div class="kpi-card__meta">Order yang telah selesai</div>
    </div>

    {{-- Pie Chart Card (reusable) --}}
    <div class="dashboard-grid__item--donut dashboard-grid__chart">
        <x-chart-card id="orderSummaryChart" title="Ringkasan Order Kapasitas" height="100%" class="chart-card--compact">
            <x-slot name="header">
                <button id="refreshPie" type="button" class="btn-ghost" title="Segarkan">Segarkan</button>
            </x-slot>
        </x-chart-card>
    </div>

    {{-- Traffic Chart Card (reusable) --}}
    <div class="dashboard-grid__item--traffic dashboard-grid__chart">
        <x-chart-card id="trafficChart" title="Rata-rata Traffic" height="100%" class="chart-card--compact">
            <x-slot name="header">
                <div class="flex items-center gap-3">
                    <label for="siteSelect" class="text-sm text-gray-300">Site</label>
                    <select id="siteSelect" class="select-dark min-w-[220px]">
                        <option value="">All Sites</option>
                    </select>
                </div>
            </x-slot>
        </x-chart-card>
    </div>

    {{-- Bar Chart Card for Order by NOP --}}
    <div class="dashboard-grid__item--bar dashboard-grid__chart">
        <x-chart-card id="orderSummaryNopChart" title="Ringkasan Order per NOP" height="100%" class="chart-card--compact">
            <x-slot name="header">
                <button id="refreshNop" type="button" class="btn-ghost" title="Segarkan">Segarkan</button>
            </x-slot>
        </x-chart-card>
    </div>

    <div class="card relative dashboard-grid__item--table">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
            <div>
                <h2 class="text-xl font-semibold">Site Prioritas Tanpa Order</h2>
                <p class="text-xs text-gray-400">Daftar site yang melampaui threshold 85% selama 5 minggu terakhir dan belum memiliki order.</p>
            </div>
            @if($canCreateOrders)
            <!-- <button id="manualOrderButton" type="button" class="btn-red text-sm self-start sm:self-auto">Buat Order Manual</button> -->
            @endif
        </div>

        <div id="capContent" class="space-y-12">
            <!-- Tabel 1: Belum Ada Order -->
            <div class="mt-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end mb-2">
                    <div class="flex flex-col justify-end gap-2 sm:flex-row sm:items-center sm:gap-3 w-full sm:w-auto sm:ml-auto sm:justify-end sm:text-right">
                        <label for="capSearch1" class="text-sm text-gray-400">Cari Site ID</label>
                        <input
                            id="capSearch1"
                            type="text"
                            placeholder="Cari Site ID"
                            class="px-3 py-2 rounded-md bg-gray-950 border border-gray-800 text-sm text-gray-200 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-red-500"
                            autocomplete="off" />
                        <button id="export1" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
                    </div>
                </div>
                <div class="overflow-x-auto cap-table-wrapper">
                    <table class="min-w-full text-sm cap-table">

                        <thead>
                            <tr class="text-left text-gray-400">
                                <th class="py-2 pr-4 text-right">#</th>
                                <th class="py-2 pr-4 text-left">Site ID</th>
                                <th class="py-2 pr-4 text-right">Avg % Util Tertinggi</th>
                                <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                                <th class="py-2 pr-4 text-left">No Order</th>
                                <th class="py-2 pr-4 text-left">Progress</th>
                                <th class="py-2 pr-4 text-right">Jarak ODP (km)</th>
                                <th class="py-2 pr-4 text-left">Kategori</th>
                                <th class="py-2 pr-4 text-left">Tipe</th>
                                <th class="py-2 pr-4 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="capRows1"></tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between mt-3 text-sm">
                    <div id="capPageInfo1" class="text-gray-400">Menampilkan 0–0 dari 0</div>
                    <div class="flex items-center gap-2">
                        <label for="capPerPage1" class="text-gray-400">Baris per halaman</label>
                        <select id="capPerPage1" class="select-dark">
                            <option value="20">20</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                        </select>
                        <button id="capPrev1" class="btn-red" type="button">Sebelumnya</button>
                        <button id="capNext1" class="btn-red" type="button">Berikutnya</button>
                    </div>
                </div>
            </div>

            <!-- Tabel 2: Sudah Ada Order (Status Kosong) -->
            <div class="pt-4 border-t border-gray-800">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-2">
                    <div>

                        <h2 class="text-xl font-semibold">Order Sedang On Progress</h2>
                        <p class="text-xs text-gray-400">Daftar site yang melampaui threshold 85% selama 5 minggu terakhir dan memiliki order yang on progress.</p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3 w-full sm:w-auto sm:ml-auto sm:justify-end sm:text-right">
                        <label for="capSearch2" class="text-sm text-gray-400">Cari Site ID</label>
                        <input
                            id="capSearch2"
                            type="text"
                            placeholder="Cari Site ID"
                            class="px-3 py-2 rounded-md bg-gray-950 border border-gray-800 text-sm text-gray-200 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-red-500"
                            autocomplete="off" />
                        <button id="export2" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
                    </div>
                </div>
                <div class="overflow-x-auto cap-table-wrapper">
                    <table class="min-w-full text-sm cap-table">

                        <thead>
                            <tr class="text-left text-gray-400">
                                <th class="py-2 pr-4 text-right">#</th>
                                <th class="py-2 pr-4 text-left">Site ID</th>
                                <th class="py-2 pr-4 text-right">Avg % Util Tertinggi</th>
                                <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                                <th class="py-2 pr-4 text-left">No Order</th>
                                <th class="py-2 pr-4 text-left">Progress</th>
                                <th class="py-2 pr-4 text-right">Jarak ODP (km)</th>
                                <th class="py-2 pr-4 text-left">Kategori</th>
                                <th class="py-2 pr-4 text-left">Tipe</th>
                                <th class="py-2 pr-4 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="capRows2"></tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between mt-3 text-sm">
                    <div id="capPageInfo2" class="text-gray-400">Menampilkan 0–0 dari 0</div>
                    <div class="flex items-center gap-2">
                        <label for="capPerPage2" class="text-gray-400">Baris per halaman</label>
                        <select id="capPerPage2" class="select-dark">
                            <option value="20">20</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                        </select>
                        <button id="capPrev2" class="btn-red" type="button">Sebelumnya</button>
                        <button id="capNext2" class="btn-red" type="button">Berikutnya</button>
                    </div>
                </div>
            </div>

            <!-- Tabel 3: Order Selesai -->
            <div class="pt-4 border-t border-gray-800">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-2">
                    <div>

                        <h2 class="text-xl font-semibold">Order Selesai</h2>
                        <p class="text-xs text-gray-400">Daftar site yang melampaui threshold 85% selama 5 minggu terakhir dan memiliki order yang sudah on air.</p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3 w-full sm:w-auto sm:ml-auto sm:justify-end sm:text-right">
                        <label for="capSearch3" class="text-sm text-gray-400">Cari Site ID</label>
                        <input
                            id="capSearch3"
                            type="text"
                            placeholder="Cari Site ID"
                            class="px-3 py-2 rounded-md bg-gray-950 border border-gray-800 text-sm text-gray-200 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-red-500"
                            autocomplete="off" />
                        <button id="export3" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
                    </div>
                </div>
                <div class="overflow-x-auto cap-table-wrapper">
                    <table class="min-w-full text-sm cap-table">

                        <thead>
                            <tr class="text-left text-gray-400">
                                <th class="py-2 pr-4 text-right">#</th>
                                <th class="py-2 pr-4 text-left">Site ID</th>
                                <th class="py-2 pr-4 text-right">Avg % Util Tertinggi</th>
                                <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                                <th class="py-2 pr-4 text-left">No Order</th>
                                <th class="py-2 pr-4 text-left">Progress</th>
                                <th class="py-2 pr-4 text-left">Tanggal On Air</th>
                                <th class="py-2 pr-4 text-right">Jarak ODP (km)</th>
                                <th class="py-2 pr-4 text-left">Kategori</th>
                                <th class="py-2 pr-4 text-left">Tipe</th>
                                <th class="py-2 pr-4 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="capRows3"></tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between mt-3 text-sm">
                    <div id="capPageInfo3" class="text-gray-400">Menampilkan 0–0 dari 0</div>
                    <div class="flex items-center gap-2">
                        <label for="capPerPage3" class="text-gray-400">Baris per halaman</label>
                        <select id="capPerPage3" class="select-dark">
                            <option value="20">20</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                        </select>
                        <button id="capPrev3" class="btn-red" type="button">Sebelumnya</button>
                        <button id="capNext3" class="btn-red" type="button">Berikutnya</button>
                    </div>
                </div>
            </div>

        </div> <!-- /#capContent -->

        <div id="capLoading" class="loading-overlay">
            <div class="spinner" aria-label="Memuat"></div>
        </div>
    </div>
</div>

<!-- Order Modal -->
<div id="orderModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal-shell">
        <button type="button" class="modal-close" onclick="closeOrderModal()" aria-label="Tutup">✕</button>
        <h2>Buat Usulan Order</h2>
        <p class="text-sm text-gray-400 mb-4">Isi data usulan order.</p>
        <div id="orderPrefillStatus" style="display:none;margin-bottom:12px;font-size:12px;color:#a1a1aa;display:none;align-items:center;gap:8px;">
            <div class="spinner" style="width:18px;height:18px;border-width:2px;"></div>
            <span>Memuat data prefill…</span>
        </div>
        <div id="orderErrors" class="error-box"></div>
        <form id="orderForm" data-action="{{ route('orders.store') }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}" />
            <div class="field-grid">
                <div class="field">
                    <label>Requestor *</label>
                    <input name="requestor" required maxlength="100" value="{{ auth()->user()->name ?? '' }}" disabled />
                </div>
                <div class="field">
                    <label>Regional *</label>
                    <input name="regional" required maxlength="50" value="7" disabled />
                </div>
                <div class="field">
                    <label>NOP</label>
                    <select name="nop" id="nopSelect">
                        <option value="">Pilih NOP</option>
                    </select>
                </div>
                <div class="field">
                    <label>SiteID NE</label>
                    <input name="siteid_ne" maxlength="10" disabled />
                </div>

                <div class="field">
                    <label>Transport Type</label>
                    <input name="transport_type" maxlength="20" disabled />
                </div>
                <div class="field">
                    <label>PL Status</label>
                    <input name="pl_status" maxlength="20" disabled />
                </div>
                <div class="field">
                    <label>Transport Category</label>
                    <input name="transport_category" maxlength="20" disabled />
                </div>
                <div class="field">
                    <label>PL Value</label>
                    <input name="pl_value" maxlength="20" disabled />
                </div>
                <div class="field">
                    <label>Link Capacity</label>
                    <input name="link_capacity" type="number" disabled />
                </div>
                <div class="field">
                    <label>Link Util (%)</label>
                    <input name="link_util" type="number" step="0.01" disabled />
                </div>
                <div class="field">
                    <label>Link Owner</label>
                    <input name="link_owner" maxlength="20" disabled />
                </div>
                <div class="field">
                    <label>Propose Solution</label>
                    <select name="propose_solution" id="proposeSolutionSelect">
                        <option value="">Pilih Propose Solution</option>
                    </select>
                </div>

                <div class="field">
                    <label>Jarak ODP (km)</label>
                    <input name="jarak_odp" type="number" step="0.01" disabled />
                </div>
                <div class="field">
                    <label>Cek NIM Order</label>
                    <input
                        name="cek_nim_order"
                        id="cekNimOrderInput"
                        maxlength="50"
                        disabled
                        placeholder="Belum ada order"
                        data-placeholder="Belum ada order" />
                </div>
                <div class="field">
                    <label>Status Order</label>
                    <input
                        name="status_order"
                        id="statusOrderInput"
                        maxlength="50"
                        disabled
                        value="1. ~0%"
                        data-default-value="1. ~0%" />
                </div>
                <div class="field">
                    <label>Remark</label>
                    <input name="remark" maxlength="100" placeholder="Opsional" />
                </div>
                <div class="field">
                    <label>SiteID FE</label>
                    <input name="siteid_fe" maxlength="50" placeholder="Opsional" />
                </div>
                <div class="field" style="grid-column:1 / -1;">
                    <label>Komentar</label>
                    <div id="orderCommentsList" style="display:none;margin-bottom:10px;padding:10px 12px;border:1px solid var(--panel-border);border-radius:12px;background:#0f0f12;max-height:160px;overflow:auto;">
                        <div id="orderCommentsEmpty" style="color:#9ca3af;font-size:12px;">Belum ada komentar.</div>
                        <ul id="orderCommentsUl" style="list-style:none;margin:0;padding:0;display:none;">
                            <!-- existing comments go here as li: requestor – comment -->
                        </ul>
                    </div>
                    <label style="margin-top:6px;display:block;">Tambah Komentar Baru</label>
                    <textarea name="comment" rows="3" maxlength="1000" style="width:100%;resize:vertical;padding:10px 12px;border:1px solid var(--panel-border);border-radius:12px;background:#0f0f12;color:var(--text);font-family:inherit;font-size:13px;line-height:1.4;" placeholder="Tambahkan komentar baru" required></textarea>
                </div>
            </div>
            <div class="form-footer">
                <button type="button" class="btn-secondary" onclick="closeOrderModal()">Batal</button>
                <button id="orderSubmitBtn" type="submit" class="btn-red">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Order Detail Modal -->
<div id="orderDetailModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal-shell modal-detail">
        <button type="button" class="modal-close" onclick="closeOrderDetailModal()" aria-label="Tutup">✕</button>
        <h2>Detail Order - <span id="orderDetailTitleSite">–</span></h2>
        <p class="text-sm text-gray-400 mb-4">Informasi order terbaru untuk site ini.</p>
        <div id="orderDetailLoader" class="detail-loader" style="display:none;">
            <div class="spinner" aria-hidden="true"></div>
            <span>Memuat detail order…</span>
        </div>
        <div id="orderDetailError" class="error-box"></div>
        <div id="orderDetailEmpty" class="detail-empty" style="display:none;">Detail tidak ditemukan.</div>
        <div id="orderDetailBody" class="detail-grid" role="presentation"></div>
    </div>
</div>

<!-- Full-screen loading overlay -->
<div id="screenLoading" class="screen-loading" aria-hidden="true">
    <div class="box">
        <div class="spinner" aria-label="Memuat"></div>
        <div>Mohon tunggu, data sedang dimuat…</div>
    </div>
</div>

<!-- Order modal logic handled by resources/js/orderModal.js -->
@endsection