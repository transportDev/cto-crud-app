// Dashboard page script: charts enabled
import { LineChart } from "../components/LineChart.js";
import { PieChart } from "../components/PieChart.js";
import { exportAoa } from "../utils/export.js";

function parseDashData() {
    const el = document.getElementById("dash-data");
    if (!el) return {};
    try {
        return JSON.parse(el.textContent || "{}");
    } catch {
        return {};
    }
}

// Date helpers
const monthsID = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "Mei",
    "Jun",
    "Jul",
    "Agu",
    "Sep",
    "Okt",
    "Nov",
    "Des",
];
const oneHour = 3600 * 1000;
const oneDay = 24 * oneHour;
function parseTsMs(s) {
    try {
        if (/^\d{4}-\d{2}-\d{2} /.test(s))
            return new Date(s.replace(" ", "T") + ":00Z").getTime();
        return new Date(s).getTime();
    } catch {
        return NaN;
    }
}
function fmtHourLabel(ms) {
    const d = new Date(ms);
    const dd = String(d.getUTCDate()).padStart(2, "0");
    const mm = monthsID[d.getUTCMonth()];
    const hh = String(d.getUTCHours()).padStart(2, "0");
    return `${dd} ${mm} ${hh}:00`;
}
function fmtDayLabel(ms) {
    const d = new Date(ms);
    const dd = String(d.getUTCDate()).padStart(2, "0");
    const mm = monthsID[d.getUTCMonth()];
    return `${dd} ${mm}`;
}

// Simple screen loading overlay controls
function screenLoading(flag) {
    document
        .getElementById("screenLoading")
        ?.classList.toggle("active", !!flag);
}

// Capacity tables small module
function initCapTable(suffix) {
    const rowsEl = document.getElementById(`capRows${suffix}`);
    const perEl = document.getElementById(`capPerPage${suffix}`);
    const prevEl = document.getElementById(`capPrev${suffix}`);
    const nextEl = document.getElementById(`capNext${suffix}`);
    const infoEl = document.getElementById(`capPageInfo${suffix}`);

    const state = { all: [], page: 1 };

    function esc(str) {
        return String(str ?? "").replace(
            /[&<>"']/g,
            (s) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                }[s])
        );
    }

    function buildRowHtml(r, i) {
        const pct = r.avg_highest_persentase * 100;
        const maxPct =
            r.max_highest_persentase != null
                ? r.max_highest_persentase * 100
                : null;
        let pctClass = "pct-normal";
        if (pct >= 98) pctClass = "pct-critical";
        else if (pct >= 95) pctClass = "pct-warning";
        const orderVal =
            r.no_order && String(r.no_order).trim() !== ""
                ? esc(r.no_order)
                : "–";
        const orderBadge =
            orderVal === "–"
                ? `<span class="order-badge order-none">–</span>`
                : `<span class="order-badge order-has" title="Order: ${orderVal}">${orderVal}</span>`;
        const statusValRaw = r.status_order ?? "";
        const statusVal =
            String(statusValRaw).trim() === "" ? "–" : esc(statusValRaw);
        const jarak =
            (r.jarak ?? null) === null ? "–" : Number(r.jarak).toFixed(1);
        const pl =
            (r.packet_loss ?? null) === null
                ? "–"
                : Number(r.packet_loss).toFixed(2) + "%";
        const canCreateOrderRecord =
            r.no_order == null || String(r.no_order).trim() === "";
        const allowCreateUi = window.canCreateOrders === true;
        const actionHtml =
            allowCreateUi && canCreateOrderRecord
                ? `<button class="btn-ghost" type="button" title="Buat Order" onclick="openOrderModal({siteid_ne: '${esc(
                      r.site_id
                  )}', site_id: '${esc(r.site_id)}', link_util: ${
                      r.avg_highest_persentase ?? "null"
                  }, jarak_odp: ${r.jarak ?? "null"}})">Order</button>`
                : `<span class="text-gray-400">–</span>`;
        return `
            <tr>
                <td class="py-1 pr-4 text-right">${i + 1}</td>
                <td class="py-1 pr-4 font-medium text-left">${esc(
                    r.site_id
                )}</td>
                <td class="py-1 pr-4 text-right"><span class="pct-chip ${pctClass}">${pct.toFixed(
            1
        )}%</span></td>
                <td class="py-1 pr-4 text-right">${
                    maxPct == null ? "–" : maxPct.toFixed(1) + "%"
                }</td>
                <td class="py-1 pr-4 text-right text-gray-400">${
                    r.day_count
                }</td>
                <td class="py-1 pr-4 text-right">${pl}</td>
                <td class="py-1 pr-4 text-left">${orderBadge}</td>
                <td class="py-1 pr-4 text-left">${statusVal}</td>
                <td class="py-1 pr-4 text-right">${jarak}</td>
                <td class="py-1 pr-4 text-left truncate" title="${esc(
                    r.alpro_category ?? ""
                )}">${esc(r.alpro_category ?? "–")}</td>
                <td class="py-1 pr-4 text-left truncate" title="${esc(
                    r.alpro_type ?? ""
                )}">${esc(r.alpro_type ?? "–")}</td>
                <td class="py-1 pr-4 text-left">${actionHtml}</td>
            </tr>`;
    }

    function render() {
        const per = parseInt(perEl?.value || "50", 10);
        const total = state.all.length;
        const pages = Math.max(1, Math.ceil(total / per));
        if (state.page > pages) state.page = pages;
        const start = (state.page - 1) * per;
        const end = Math.min(start + per, total);
        const view = state.all.slice(start, end);
        const html = view.map((r, i) => buildRowHtml(r, i)).join("");
        rowsEl.innerHTML =
            html ||
            '<tr><td colspan="10" class="py-2 text-gray-400">Tidak ada data.</td></tr>';
        infoEl.textContent = total
            ? `Menampilkan ${start + 1}–${end} dari ${total}`
            : "Menampilkan 0–0 dari 0";
        prevEl.disabled = state.page <= 1;
        nextEl.disabled = state.page >= pages;
    }

    perEl?.addEventListener("change", () => {
        state.page = 1;
        render();
    });
    prevEl?.addEventListener("click", () => {
        if (state.page > 1) {
            state.page--;
            render();
        }
    });
    nextEl?.addEventListener("click", () => {
        const per = parseInt(perEl?.value || "50", 10);
        const pages = Math.max(1, Math.ceil(state.all.length / per));
        if (state.page < pages) {
            state.page++;
            render();
        }
    });

    return {
        setRows(arr) {
            state.all = Array.isArray(arr) ? arr : [];
            state.page = 1;
            render();
        },
        loading(msg = "Memuat data…") {
            rowsEl.innerHTML = `<tr><td colspan=\"10\" class=\"py-2 text-gray-400\">${msg}</td></tr>`;
        },
        getRows() {
            return state.all.slice();
        },
    };
}

// Main bootstrap
window.addEventListener("DOMContentLoaded", () => {
    const state = parseDashData();

    // Populate Site select from embedded state (may be empty on SSR; we'll repopulate from /api/traffic)
    const siteSelect = document.getElementById("siteSelect");
    if (siteSelect) {
        const urlSel = new URL(window.location.href);
        const selected =
            urlSel.searchParams.get("site_id") ?? state.selectedSiteId ?? "";
        const sites = Array.isArray(state.sites)
            ? [...new Set(state.sites.filter(Boolean))].sort()
            : [];
        siteSelect.innerHTML =
            '<option value="">All Sites</option>' +
            sites
                .map(
                    (s) =>
                        `<option value="${s}" ${
                            String(s) === String(selected) ? "selected" : ""
                        }>${s}</option>`
                )
                .join("");
    }

    // Charts
    let trafficChart = new LineChart("trafficChart", {
        series: [{ name: "S1 DL rata-rata (Mbps)" }],
    });
    let orderSummaryChart = new PieChart("orderSummaryChart", {});

    // Capacity tables init BEFORE loadCapacity()
    const capTable1 = initCapTable("1");
    const capTable2 = initCapTable("2");
    const capTable3 = initCapTable("3");

    // Seed traffic chart if server embedded data exists
    if (
        trafficChart &&
        Array.isArray(state.s1dlLabels) &&
        Array.isArray(state.s1dlValues) &&
        state.s1dlLabels.length === state.s1dlValues.length &&
        state.s1dlLabels.length > 0
    ) {
        const seededPoints = state.s1dlLabels.map((ts, i) => [
            parseTsMs(ts),
            Number(state.s1dlValues[i] ?? 0),
        ]);
        trafficChart.updateData(seededPoints);
    }

    // API helpers
    async function refreshTraffic(siteId) {
        const url = new URL("/api/traffic", window.location.origin);
        if (siteId) url.searchParams.set("site_id", siteId);
        try {
            trafficChart?.setLoading(true);
            const res = await fetch(url.toString(), {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            // If SSR skipped sites, populate dropdown now
            if (
                siteSelect &&
                (!Array.isArray(state.sites) || state.sites.length === 0)
            ) {
                const selectedNow =
                    siteId ??
                    new URL(window.location.href).searchParams.get("site_id") ??
                    "";
                const apiSites = Array.isArray(data.sites)
                    ? [...new Set(data.sites.filter(Boolean))].sort()
                    : [];
                siteSelect.innerHTML =
                    '<option value="">All Sites</option>' +
                    apiSites
                        .map(
                            (s) =>
                                `<option value="${s}" ${
                                    String(s) === String(selectedNow)
                                        ? "selected"
                                        : ""
                                }>${s}</option>`
                        )
                        .join("");
            }
            // Update traffic chart
            if (
                trafficChart &&
                Array.isArray(data.s1dlLabels) &&
                Array.isArray(data.s1dlValues)
            ) {
                const pts = data.s1dlLabels.map((ts, i) => [
                    parseTsMs(ts),
                    Number(data.s1dlValues[i] ?? 0),
                ]);
                trafficChart.updateData(pts);
            }
        } finally {
            trafficChart?.setLoading(false);
        }
    }

    // Capacity trend removed

    async function loadCapacity() {
        const capLoading = document.getElementById("capLoading");
        const capContent = document.getElementById("capContent");
        capContent?.classList.add("is-hidden");
        capLoading?.classList.add("active");
        try {
            const url = new URL(window.location.origin + "/api/capacity");
            const res = await fetch(url.toString(), {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || "Gagal memuat");

            const all = Array.isArray(data.rows) ? data.rows : [];
            const group1 = all.filter((r) => r.no_order == null);
            const group3 = all.filter((r) => {
                const prog = String(r.progress ?? "")
                    .trim()
                    .toUpperCase();
                return r.no_order != null && prog === "5.CLOSE";
            });
            const group2 = all.filter((r) => {
                const prog = String(r.progress ?? "")
                    .trim()
                    .toUpperCase();
                return (
                    r.no_order != null &&
                    prog !== "5.CLOSE" &&
                    prog !== "0.DROP"
                );
            });

            capTable1.setRows(group1);
            capTable2.setRows(group2);
            capTable3.setRows(group3);
        } catch (e) {
            console.error(e);
        } finally {
            capLoading?.classList.remove("active");
            capContent?.classList.remove("is-hidden");
        }
    }

    // Order summary via API (for pie chart)
    async function loadOrderSummary() {
        try {
            orderSummaryChart?.setLoading(true);
            const url = new URL("/api/order-summary", window.location.origin);
            const res = await fetch(url.toString(), {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || "Gagal memuat summary");
            const s = data.summary || { belum: 0, onProgress: 0, done: 0 };
            const pieData = [
                { value: Number(s.belum || 0), name: "Belum Ada Order" },
                { value: Number(s.onProgress || 0), name: "Order On Progress" },
                { value: Number(s.done || 0), name: "Order Selesai" },
            ];
            orderSummaryChart.updateData(pieData);
            const total = pieData.reduce((a, b) => a + (b.value || 0), 0);
            orderSummaryChart.updateGraphicText(`Total\n${total}`);
        } catch (e) {
            console.error(e);
        } finally {
            orderSummaryChart?.setLoading(false);
        }
    }

    // Export helpers
    function rowsToAoa(rows) {
        const header = [
            "#",
            "Site ID",
            "Avg % Util Tertinggi",
            "Max % Util Harian",
            "Jumlah Hari",
            "Avg PL (%)",
            "No Order",
            "Status Order",
            "Jarak (km)",
            "Kategori",
            "Tipe",
        ];
        const body = rows.map((r, idx) => {
            const pct = (r.avg_highest_persentase ?? 0) * 100;
            const maxPct = r.max_highest_persentase ?? null;
            const maxPctVal = maxPct == null ? null : maxPct * 100;
            const pl = r.packet_loss ?? null;
            return [
                idx + 1,
                r.site_id ?? "",
                pct.toFixed(2) + "%",
                maxPctVal == null ? "–" : maxPctVal.toFixed(2) + "%",
                r.day_count ?? "",
                pl == null ? "–" : Number(pl).toFixed(2) + "%",
                r.no_order ?? "",
                r.status_order == null || String(r.status_order).trim() === ""
                    ? ""
                    : String(r.status_order),
                r.jarak == null ? "" : Number(r.jarak).toFixed(1),
                r.alpro_category ?? "",
                r.alpro_type ?? "",
            ];
        });
        return [header, ...body];
    }

    async function exportExcel(fileBaseName, rows) {
        const aoa = rowsToAoa(rows);
        await exportAoa(fileBaseName, aoa);
    }

    // Wire events
    document.getElementById("refreshPie")?.addEventListener("click", () => {
        loadCapacity();
        loadOrderSummary();
    });

    // Export buttons
    document
        .getElementById("export1")
        ?.addEventListener("click", () =>
            exportExcel("Belum-Ada-Order", capTable1.getRows())
        );
    document
        .getElementById("export2")
        ?.addEventListener("click", () =>
            exportExcel("Sudah-Ada-Order-Status-Kosong", capTable2.getRows())
        );
    document
        .getElementById("export3")
        ?.addEventListener("click", () =>
            exportExcel("Order-Selesai", capTable3.getRows())
        );

    // Site select
    let debounceTimer;
    siteSelect?.addEventListener("change", (e) => {
        clearTimeout(debounceTimer);
        const val = e.target.value;
        debounceTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            if (val) url.searchParams.set("site_id", val);
            else url.searchParams.delete("site_id");
            window.history.replaceState({}, "", url);
            refreshTraffic(val);
        }, 200);
    });

    // Initial load
    (async () => {
        screenLoading(true);
        try {
            const hasEmbedded =
                Array.isArray(state.s1dlLabels) && state.s1dlLabels.length;
            if (!hasEmbedded)
                await refreshTraffic(state.selectedSiteId ?? null);
            await Promise.allSettled([loadCapacity(), loadOrderSummary()]);
        } finally {
            screenLoading(false);
        }
    })();
});
