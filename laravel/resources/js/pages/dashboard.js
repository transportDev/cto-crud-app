// Dashboard page script: charts enabled
import { LineChart } from "../components/LineChart.js";
import { PieChart } from "../components/PieChart.js";
import { BarChart } from "../components/BarChart.js";
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

    rowsEl?.addEventListener("click", (event) => {
        const detailBtn = event.target.closest(".cap-detail-btn");
        if (detailBtn && typeof window.openOrderDetailModal === "function") {
            const siteId = detailBtn.dataset.siteId;
            if (siteId) {
                window.openOrderDetailModal(siteId);
                return;
            }
        }

        const orderBtn = event.target.closest(".cap-order-btn");
        if (orderBtn && typeof window.openOrderModal === "function") {
            const siteId = orderBtn.dataset.siteId;
            if (!siteId) return;
            const linkUtilRaw = orderBtn.dataset.linkUtil;
            const jarakOdpRaw = orderBtn.dataset.jarakOdp;
            const prefill = {
                siteid_ne: siteId,
                site_id: siteId,
            };
            const linkUtil = linkUtilRaw == null ? null : Number(linkUtilRaw);
            if (!Number.isNaN(linkUtil) && linkUtilRaw !== "") {
                prefill.link_util = linkUtil;
            }
            const jarakOdp = jarakOdpRaw == null ? null : Number(jarakOdpRaw);
            if (!Number.isNaN(jarakOdp) && jarakOdpRaw !== "") {
                prefill.jarak_odp = jarakOdp;
            }
            window.openOrderModal(prefill);
        }
    });

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

    function getUtil(row) {
        const raw =
            row?.s1_util != null
                ? row.s1_util
                : row?.avg_highest_persentase ?? 0;
        const util = Number(raw);
        return Number.isFinite(util) ? util : 0;
    }

    function getPacketLoss(row) {
        const raw = Number(row?.packet_loss ?? 0);
        return Number.isFinite(raw) ? raw : 0;
    }

    function calcScore(row) {
        const util = getUtil(row);
        const packetLoss = getPacketLoss(row);
        return util * 100 + packetLoss * 10;
    }

    function getBadgeClass(score) {
        if (!Number.isFinite(score)) score = 0;
        if (score >= 98) return "pct-critical";
        if (score >= 95) return "pct-warning";
        return "pct-normal";
    }

    function formatOnAir(value) {
        if (value == null) return null;
        const str = String(value).trim();
        if (!str) return null;
        const parsed = new Date(str);
        let display = str;
        if (!Number.isNaN(parsed.getTime())) {
            display = parsed.toLocaleDateString("id-ID", {
                day: "2-digit",
                month: "short",
                year: "numeric",
            });
        }
        return esc(display);
    }

    function buildRowHtml(r, i) {
        // New format uses s1_util (ratio 0..1) instead of avg_highest_persentase
        const util = getUtil(r);
        const pct = util * 100;
        const packetLoss = r.packet_loss;
        const packetLossNum = packetLoss == null ? null : Number(packetLoss);
        const score = typeof r.__score === "number" ? r.__score : calcScore(r);
        const badgeClass = getBadgeClass(score);
        const safeSiteId = esc(r.site_id ?? "");
        const hasSiteId = safeSiteId !== "";
        const orderVal =
            r.no_order && String(r.no_order).trim() !== ""
                ? esc(r.no_order)
                : "–";
        const orderBadge =
            orderVal === "–"
                ? `<span class="order-badge order-none">–</span>`
                : `<span class="order-badge order-has" title="Order: ${orderVal}">${orderVal}</span>`;
        const jarak =
            (r.jarak ?? null) === null ? "–" : Number(r.jarak).toFixed(1);
        const canCreateOrderRecord =
            r.no_order == null || String(r.no_order).trim() === "";
        const allowCreateUi = window.canCreateOrders === true;
        const allowDetail = suffix !== "1";
        const showOnAir = suffix === "3";
        const onAirDisplay = showOnAir ? formatOnAir(r.tgl_on_air) : null;
        const progressHtml = esc((r.progress ?? "–") || "–");
        const actionParts = [];
        if (hasSiteId && allowDetail) {
            actionParts.push(
                `<button class="btn-ghost cap-detail-btn" type="button" title="Detail Order" data-site-id="${safeSiteId}">Detail</button>`
            );
        }
        if (hasSiteId && allowCreateUi && canCreateOrderRecord) {
            const linkUtilData = Number.isFinite(util) ? util : "";
            const jarakData =
                r.jarak == null || Number.isNaN(Number(r.jarak))
                    ? ""
                    : Number(r.jarak);
            actionParts.push(
                `<button class="btn-ghost cap-order-btn" type="button" title="Buat Order" data-site-id="${safeSiteId}" data-link-util="${linkUtilData}" data-jarak-odp="${jarakData}">Order</button>`
            );
        }
        const actionHtml = actionParts.length
            ? `<div class="cap-actions">${actionParts.join("")}</div>`
            : `<span class="text-gray-400">–</span>`;

        const cells = [
            `<td class="py-1 pr-4 text-right">${i + 1}</td>`,
            `<td class="py-1 pr-4 font-medium text-left">${esc(
                r.site_id
            )}</td>`,
            `<td class="py-1 pr-4 text-right"><span class="pct-chip ${badgeClass}">${pct.toFixed(
                1
            )}%</span></td>`,
            `<td class="py-1 pr-4 text-right"><span class="pct-chip ${badgeClass}">${
                packetLossNum == null || Number.isNaN(packetLossNum)
                    ? "–"
                    : `${packetLossNum.toFixed(2)}%`
            }</span></td>`,
            `<td class="py-1 pr-4 text-left">${orderBadge}</td>`,
            `<td class="py-1 pr-4 text-left">${progressHtml}</td>`,
        ];

        if (showOnAir) {
            cells.push(
                `<td class="py-1 pr-4 text-left">${onAirDisplay ?? "–"}</td>`
            );
        }

        cells.push(
            `<td class="py-1 pr-4 text-right">${jarak}</td>`,
            `<td class="py-1 pr-4 text-left truncate" title="${esc(
                r.alpro_category ?? ""
            )}">${esc(r.alpro_category ?? "–")}</td>`,
            `<td class="py-1 pr-4 text-left truncate" title="${esc(
                r.alpro_type ?? ""
            )}">${esc(r.alpro_type ?? "–")}</td>`,
            `<td class="py-1 pr-4 text-left">${actionHtml}</td>`
        );

        return `<tr>${cells.join("")}</tr>`;
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
            const safe = Array.isArray(arr) ? arr.slice() : [];
            safe.forEach((row) => {
                row.__score = calcScore(row);
            });
            safe.sort((a, b) => (b.__score || 0) - (a.__score || 0));
            state.all = safe;
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
function boot() {
    const state = parseDashData();
    const numberFormatter = new Intl.NumberFormat("id-ID");
    const kpiEls = {
        total: document.getElementById("kpiTotalValue"),
        progress: document.getElementById("kpiProgressValue"),
        done: document.getElementById("kpiDoneValue"),
        totalDelta: document.getElementById("kpiTotalDelta"),
        progressDelta: document.getElementById("kpiProgressDelta"),
        doneDelta: document.getElementById("kpiDoneDelta"),
    };

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
    let orderSummaryNopChart = new BarChart("orderSummaryNopChart", {
        orientation: "vertical",
        grid: { left: 48, right: 24, top: 48, bottom: 88 },
        legend: {
            top: null,
            bottom: 8,
            textStyle: { fontSize: 11 },
            itemWidth: 12,
            itemHeight: 12,
        },
    });

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
                {
                    value: Number(s.belum || 0),
                    name: "Belum Ada Order",
                    itemStyle: { color: "#ef4444" },
                },
                {
                    value: Number(s.onProgress || 0),
                    name: "Order On Progress",
                    itemStyle: { color: "#3B82F6" },
                },
                {
                    value: Number(s.done || 0),
                    name: "Order Selesai",
                    itemStyle: { color: "#10B981" },
                },
            ];
            orderSummaryChart.updateData(pieData);
            const total = pieData.reduce((a, b) => a + (b.value || 0), 0);
            orderSummaryChart.updateGraphicText(
                `Total\n${numberFormatter.format(total)}`
            );
            updateKpiCards(s);
            loadOrderSummaryByNop();
        } catch (e) {
            console.error(e);
        } finally {
            orderSummaryChart?.setLoading(false);
        }
    }

    function updateKpiCards(summary) {
        if (!summary) return;

        const belum = Number(summary.belum || 0);
        const belumYesterday = Number(
            summary.belumYesterday ?? summary.belum_yesterday ?? 0
        );
        const onProgress = Number(summary.onProgress || 0);
        const onProgressYesterday = Number(
            summary.onProgressYesterday ?? summary.on_progress_yesterday ?? 0
        );
        const done = Number(summary.done || 0);
        const doneYesterday = Number(
            summary.doneYesterday ?? summary.order_done_yesterday ?? 0
        );

        const totalToday = Number(
            summary.totalToday ?? belum + onProgress + done
        );
        const totalYesterday = Number(
            summary.totalYesterday ??
                belumYesterday + onProgressYesterday + doneYesterday
        );
        const total =
            typeof summary.totalUsulan === "number"
                ? summary.totalUsulan
                : totalToday;

        if (kpiEls.total)
            kpiEls.total.textContent = numberFormatter.format(total);
        if (kpiEls.progress)
            kpiEls.progress.textContent = numberFormatter.format(onProgress);
        if (kpiEls.done) kpiEls.done.textContent = numberFormatter.format(done);

        setKpiDelta(
            kpiEls.totalDelta,
            totalToday,
            totalYesterday,
            summary.dates
        );
        setKpiDelta(
            kpiEls.progressDelta,
            onProgress,
            onProgressYesterday,
            summary.dates
        );
        setKpiDelta(kpiEls.doneDelta, done, doneYesterday, summary.dates);
    }

    function setKpiDelta(el, todayValue, yesterdayValue, dates) {
        if (!el) return;

        const iconEl = el.querySelector(".kpi-card__delta-icon");
        const valueEl = el.querySelector(".kpi-card__delta-value");
        const labelEl = el.querySelector(".kpi-card__delta-label");

        if (!iconEl || !valueEl) return;

        const isNumber = (value) =>
            typeof value === "number" && !Number.isNaN(value);

        if (!isNumber(todayValue) || !isNumber(yesterdayValue)) {
            el.classList.remove("is-hidden");
            el.dataset.trend = "flat";
            iconEl.textContent = "—";
            valueEl.textContent = "No Data";
            if (labelEl) labelEl.textContent = "";
            el.title = "Tidak ada data pembanding";
            el.setAttribute("aria-label", "Perubahan tidak tersedia");
            return;
        }

        const diff = todayValue - yesterdayValue;

        if (diff === 0) {
            el.dataset.trend = "flat";
            iconEl.textContent = "";
            valueEl.textContent = "";
            if (labelEl) labelEl.textContent = "";
            el.classList.add("is-hidden");
            el.removeAttribute("title");
            el.removeAttribute("aria-label");
            return;
        }

        el.classList.remove("is-hidden");

        let trend = "flat";
        let icon = "—";
        let valueText = "";

        if (diff > 0) {
            trend = "up";
            icon = "▲";
            valueText = `+${numberFormatter.format(diff)}`;
        } else {
            trend = "down";
            icon = "▼";
            valueText = numberFormatter.format(diff);
        }

        iconEl.textContent = icon;
        valueEl.textContent = valueText;

        el.dataset.trend = trend;

        const prevLabel = dates?.previous ?? "Kemarin";
        const latestLabel = dates?.latest ?? "Hari Ini";

        // Update the contextual label - more formal and subtle
        if (labelEl) {
            labelEl.textContent = `dari ${prevLabel.toLowerCase()}`;
        }

        const formattedToday = numberFormatter.format(todayValue);
        const formattedYesterday = numberFormatter.format(yesterdayValue);
        const formattedDiff =
            diff > 0
                ? `+${numberFormatter.format(diff)}`
                : numberFormatter.format(diff);

        // Create contextual label based on trend
        let contextLabel = "";
        if (diff > 0) {
            contextLabel = `Naik ${numberFormatter.format(
                diff
            )} dari ${prevLabel}`;
        } else {
            contextLabel = `Turun ${numberFormatter.format(
                Math.abs(diff)
            )} dari ${prevLabel}`;
        }

        el.title = `${contextLabel}\n${latestLabel}: ${formattedToday} • ${prevLabel}: ${formattedYesterday}`;
        el.setAttribute(
            "aria-label",
            `Perubahan ${latestLabel} dibanding ${prevLabel}: ${formattedDiff}`
        );

        el.classList.remove("is-animating");
        // Force reflow to restart the glow animation on updates
        void el.offsetWidth;
        el.classList.add("is-animating");
    }

    async function loadOrderSummaryByNop() {
        try {
            orderSummaryNopChart?.setLoading(true);
            const url = new URL(
                "/api/order-summary-nop",
                window.location.origin
            );
            const res = await fetch(url.toString(), {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.error || "Gagal memuat ringkasan per NOP");
            }
            const parsedRows = (Array.isArray(data.rows) ? data.rows : []).map(
                (row) => {
                    const belum = Number(row["Belum ada Order"] || 0);
                    const progress = Number(row["Order On Progress"] || 0);
                    const done = Number(row["Order Done"] || 0);
                    return {
                        nop: row.nop || "Tidak diketahui",
                        belum,
                        progress,
                        done,
                        total: belum + progress + done,
                    };
                }
            );
            parsedRows.sort((a, b) => b.total - a.total);

            const categories = parsedRows.map((row) => row.nop);
            const belumSeries = parsedRows.map((row) => row.belum);
            const progressSeries = parsedRows.map((row) => row.progress);
            const doneSeries = parsedRows.map((row) => row.done);

            orderSummaryNopChart?.updateData({
                categories,
                series: [
                    {
                        name: "Belum Ada Order",
                        data: belumSeries,
                        stack: "total",
                    },
                    {
                        name: "Order Dalam Proses",
                        data: progressSeries,
                        stack: "total",
                    },
                    {
                        name: "Order Selesai",
                        data: doneSeries,
                        stack: "total",
                    },
                ],
            });
        } catch (e) {
            console.error(e);
        } finally {
            orderSummaryNopChart?.setLoading(false);
        }
    }

    // Export helpers
    function rowsToAoa(rows) {
        const header = [
            "#",
            "Site ID",
            "Avg % Util Tertinggi",
            "Avg PL (%)",
            "No Order",
            "Progress",
            "Jarak (km)",
            "Kategori",
            "Tipe",
        ];
        const body = rows.map((r, idx) => {
            const util = Number(
                r.s1_util != null ? r.s1_util : r.avg_highest_persentase ?? 0
            );
            const pct = util * 100;
            const pl = r.packet_loss ?? null;
            return [
                idx + 1,
                r.site_id ?? "",
                pct.toFixed(2) + "%",
                pl == null ? "–" : Number(pl).toFixed(2) + "%",
                r.no_order ?? "",
                r.progress == null || String(r.progress).trim() === ""
                    ? ""
                    : String(r.progress),
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
    document
        .getElementById("refreshNop")
        ?.addEventListener("click", () => loadOrderSummaryByNop());

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

    document
        .getElementById("manualOrderButton")
        ?.addEventListener("click", () => {
            if (typeof window.openOrderModal === "function") {
                window.openOrderModal();
            }
        });

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

    // Simulate KPI data changes for testing
    function simulateKpiChanges() {
        let simulationInterval;
        let simulationCount = 0;
        const maxSimulations = 10;

        function generateRandomSummary() {
            const baseTotal = Math.floor(Math.random() * 50) + 100;
            const baseProgress = Math.floor(Math.random() * 30) + 20;
            const baseDone = Math.floor(Math.random() * 40) + 30;

            const totalToday = baseTotal + Math.floor(Math.random() * 20) - 10;
            const totalYesterday =
                baseTotal + Math.floor(Math.random() * 15) - 7;

            const onProgress =
                baseProgress + Math.floor(Math.random() * 15) - 7;
            const onProgressYesterday =
                baseProgress + Math.floor(Math.random() * 10) - 5;

            const done = baseDone + Math.floor(Math.random() * 20) - 10;
            const doneYesterday = baseDone + Math.floor(Math.random() * 15) - 7;

            const belum = totalToday - onProgress - done;
            const belumYesterday =
                totalYesterday - onProgressYesterday - doneYesterday;

            return {
                totalUsulan: totalToday,
                belum: belum > 0 ? belum : 0,
                belumYesterday: belumYesterday > 0 ? belumYesterday : 0,
                onProgress,
                onProgressYesterday,
                done,
                doneYesterday,
                totalToday,
                totalYesterday,
                dates: {
                    latest: "Hari Ini",
                    previous: "Kemarin",
                },
            };
        }

        function runSimulation() {
            if (simulationCount >= maxSimulations) {
                clearInterval(simulationInterval);
                console.log("KPI simulation completed");
                return;
            }

            const mockData = generateRandomSummary();
            console.log(`Simulation ${simulationCount + 1}:`, mockData);
            updateKpiCards(mockData);
            simulationCount++;
        }

        // Start simulation
        console.log(
            "Starting KPI simulation (10 iterations, 3 seconds each)..."
        );
        simulationInterval = setInterval(runSimulation, 3000);
        runSimulation(); // Run immediately first time
    }

    // Expose simulation function to window for testing
    window.simulateKpiChanges = simulateKpiChanges;

    // Add keyboard shortcut: Ctrl+Shift+K to trigger simulation
    document.addEventListener("keydown", (e) => {
        if (e.ctrlKey && e.shiftKey && e.key === "K") {
            e.preventDefault();
            console.log(
                "KPI simulation triggered via keyboard shortcut (Ctrl+Shift+K)"
            );
            simulateKpiChanges();
        }
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
}

if (document.readyState === "loading") {
    window.addEventListener("DOMContentLoaded", boot);
} else {
    boot();
}
