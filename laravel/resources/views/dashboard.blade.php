<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BI Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>

    <!-- Fonts: Poppins -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:400,500,600&display=swap" rel="stylesheet" />

    <style>
        :root {
            --bg: #0f0f11;
            /* page background */
            --panel: #17181c;
            /* card background */
            --panel-border: #25262b;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #FF3B30;
            /* red */
            --accent-600: #e6362c;
            --grid: #2a2f36;
            --warn: #f59e0b;
            --warn-bg: rgba(245, 158, 11, 0.15);
            --crit: #ef4444;
            --crit-bg: rgba(239, 68, 68, 0.18);
            --ok: #10b981;
            --ok-bg: rgba(16, 185, 129, 0.15);
            --row-alt: rgba(255, 255, 255, 0.025);
            --row-hover: rgba(255, 255, 255, 0.06);
        }

        @media (prefers-color-scheme: light) {
            :root {
                --bg: #f7f7f8;
                --panel: #ffffff;
                --panel-border: #e5e7eb;
                --text: #0f172a;
                --muted: #475569;
                --grid: #e5e7eb;
                --warn-bg: rgba(245, 158, 11, 0.18);
                --crit-bg: rgba(239, 68, 68, 0.20);
                --ok-bg: rgba(16, 185, 129, 0.20);
                --row-alt: rgba(0, 0, 0, 0.035);
                --row-hover: rgba(0, 0, 0, 0.08);
            }
        }

        html,
        body {
            height: 100%;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: Poppins, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
        }

        .btn-red {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 600;
            transition: transform .15s ease, box-shadow .15s ease, background .2s ease;
        }

        .btn-red:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(255, 59, 48, .35);
            background: var(--accent-600);
        }

        /* Dark select */
        .select-dark {
            background: #0f0f12;
            color: var(--text);
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            padding: 10px 12px;
            outline: none;
            transition: box-shadow .2s ease, border-color .2s ease;
        }

        .select-dark:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 59, 48, .25);
        }

        /* Tooltip font consistency */
        .echarts-tooltip {
            font-family: Poppins, ui-sans-serif, system-ui, sans-serif;
        }

        /* Loading overlay */
        .loading-overlay {
            position: absolute;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(23, 24, 28, .55);
            backdrop-filter: blur(2px);
            border-radius: 16px;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid rgba(255, 255, 255, .2);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Capacity table enhancements */
        .cap-table-wrapper {
            max-height: 640px;
            overflow: auto;
        }

        .cap-table thead th {
            position: sticky;
            top: 0;
            background: var(--panel);
            z-index: 5;
            box-shadow: 0 1px 0 var(--panel-border);
        }

        .cap-table tbody tr:nth-child(even) {
            background: var(--row-alt);
        }

        .cap-table tbody tr:hover {
            background: var(--row-hover);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: .25px;
            padding: 2px 6px 3px;
            border-radius: 999px;
            line-height: 1;
            text-transform: uppercase;
        }

        .status-critical {
            background: var(--crit-bg);
            color: var(--crit);
            border: 1px solid rgba(239, 68, 68, 0.35);
        }

        .status-warning {
            background: var(--warn-bg);
            color: var(--warn);
            border: 1px solid rgba(245, 158, 11, 0.35);
        }

        .status-normal {
            background: var(--ok-bg);
            color: var(--ok);
            border: 1px solid rgba(16, 185, 129, 0.35);
        }

        .pct-chip {
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 8px;
            display: inline-block;
        }

        .pct-critical {
            background: var(--crit-bg);
            color: var(--crit);
        }

        .pct-warning {
            background: var(--warn-bg);
            color: var(--warn);
        }

        .pct-normal {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
        }

        .order-badge {
            font-size: 11px;
            font-weight: 500;
            padding: 2px 10px 3px;
            border-radius: 999px;
            display: inline-block;
            white-space: nowrap;
        }

        .order-none {
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
        }

        .order-has {
            background: linear-gradient(90deg, var(--crit) 0%, var(--warn) 100%);
            color: #fff;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.15) inset;
        }

        .text-right {
            text-align: right;
        }

        .text-left {
            text-align: left;
        }

        .truncate {
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cursor-pointer {
            cursor: pointer;
        }
    </style>

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

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const state = JSON.parse(document.getElementById('dash-data').textContent);

            // Helpers: time formatting to Indonesian short
            const monthsID = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            const parseTsMs = (s) => {
                try {
                    if (/^\d{4}-\d{2}-\d{2} /.test(s)) return new Date(s.replace(' ', 'T') + ':00Z').getTime();
                    return new Date(s).getTime();
                } catch {
                    return NaN;
                }
            };
            const fmtHourLabel = (ms) => {
                const d = new Date(ms);
                const dd = String(d.getUTCDate()).padStart(2, '0');
                const mm = monthsID[d.getUTCMonth()];
                const hh = String(d.getUTCHours()).padStart(2, '0');
                return `${dd} ${mm} ${hh}:00`;
            };
            const fmtDayLabel = (ms) => {
                const d = new Date(ms);
                const dd = String(d.getUTCDate()).padStart(2, '0');
                const mm = monthsID[d.getUTCMonth()];
                return `${dd} ${mm}`;
            };
            const oneHour = 3600 * 1000;
            const oneDay = 24 * oneHour;
            const defaultViewRange = 24 * oneHour;
            const latestMs = state.latestTs ? parseTsMs(state.latestTs) : undefined;
            const earliestMs = state.earliestTs ? parseTsMs(state.earliestTs) : undefined;
            // Build chart data [timestamp, value]
            const points = (state.s1dlLabels || []).map((ts, i) => [parseTsMs(ts), state.s1dlValues?.[i] ?? null]).filter(p => !isNaN(p[0]));
            const themeDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const css = getComputedStyle(document.documentElement);
            const axisColor = css.getPropertyValue('--text')?.trim() || (themeDark ? '#e5e7eb' : '#0f172a');
            const gridColor = css.getPropertyValue('--grid')?.trim() || (themeDark ? '#2a2f36' : '#e5e7eb');
            const accent = css.getPropertyValue('--accent')?.trim() || '#FF3B30';

            // Populate Site select
            const siteSelect = document.getElementById('siteSelect');
            if (siteSelect) {
                const urlSel = new URL(window.location.href);
                const selected = urlSel.searchParams.get('site_id') ?? (state.selectedSiteId ?? '');
                const sites = Array.isArray(state.sites) ? [...new Set(state.sites.filter(Boolean))].sort() : [];
                siteSelect.innerHTML = '<option value="">All Sites</option>' +
                    sites.map(s => `<option value="${s}" ${String(s)===String(selected)?'selected':''}>${s}</option>`).join('');
            }

            const el = document.getElementById('chartS1');
            const chart = echarts.init(el, null, {
                renderer: 'canvas'
            });

            let currentRange = 24 * oneHour;
            const iv = initialViewRange();

            const option = {
                backgroundColor: 'transparent',
                grid: {
                    left: 48,
                    right: 18,
                    top: 28,
                    bottom: 90
                },
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: accent,
                    textStyle: {
                        color: '#fff'
                    },
                    borderWidth: 0,
                    formatter: (params) => {
                        const p = params && params[0];
                        if (!p) return '';
                        const ms = p.value[0];
                        const title = (currentRange <= 24 * oneHour) ? fmtHourLabel(ms) : fmtDayLabel(ms);
                        return `${title}<br/>${p.marker} ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(p.value[1])} Mbps`;
                    }
                },
                xAxis: {
                    type: 'time',
                    boundaryGap: false,
                    axisLabel: {
                        color: axisColor,
                        formatter: (val) => {
                            if (currentRange <= 24 * oneHour) {
                                const label = fmtHourLabel(val);
                                const [day, month, hour] = label.split(' ');
                                return `${day} ${month} ${hour}`;
                            } else {
                                return fmtDayLabel(val);
                            }
                        }
                    },
                    axisLine: {
                        lineStyle: {
                            color: gridColor
                        }
                    },
                    splitLine: {
                        show: false
                    }
                },
                yAxis: {
                    type: 'value',
                    axisLabel: {
                        color: axisColor,
                        formatter: (v) => new Intl.NumberFormat('id-ID', {
                            maximumFractionDigits: 2
                        }).format(v)
                    },
                    axisLine: {
                        lineStyle: {
                            color: gridColor
                        }
                    },
                    splitLine: {
                        show: true,
                        lineStyle: {
                            color: gridColor,
                            type: 'dashed'
                        }
                    }
                },
                dataZoom: [{
                        type: 'inside',
                        throttle: 50,
                        filterMode: 'filter',
                        minValueSpan: oneHour,
                        maxValueSpan: 7 * oneDay,
                        startValue: iv?.min,
                        endValue: iv?.max,
                    },
                    {
                        type: 'slider',
                        show: true,
                        height: 48,
                        bottom: 18,
                        filterMode: 'filter',
                        minValueSpan: oneHour,
                        maxValueSpan: 7 * oneDay,
                        borderColor: 'transparent',
                        handleSize: 14,
                        handleStyle: {
                            color: '#fff',
                            borderColor: accent
                        },
                        moveHandleStyle: {
                            color: accent
                        },
                        backgroundColor: 'rgba(255,255,255,0.03)',
                        dataBackground: {
                            lineStyle: {
                                color: accent,
                                opacity: 0.5
                            },
                            areaStyle: {
                                color: accent,
                                opacity: 0.08
                            }
                        },
                        selectedDataBackground: {
                            lineStyle: {
                                color: accent,
                                opacity: 0.9
                            },
                            areaStyle: {
                                color: accent,
                                opacity: 0.18
                            }
                        },
                        textStyle: {
                            color: axisColor
                        },
                        startValue: iv?.min,
                        endValue: iv?.max
                    }
                ],
                series: [{
                    type: 'line',
                    name: 'S1 DL rata-rata (Mbps)',
                    showSymbol: false, // show the dots
                    // symbol: 'circle', // circle shape
                    // symbolSize: 6, // adjust size
                    // itemStyle: {
                    //     color: '#ff0000', // fill color of circle
                    //     borderColor: '#ffffff', // red border
                    //     borderWidth: 2
                    // },
                    smooth: true,
                    sampling: 'lttb',
                    lineStyle: {
                        width: 2.6,
                        color: accent
                    },
                    areaStyle: {
                        color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{
                                offset: 0,
                                color: accent + 'CC'
                            },
                            {
                                offset: 1,
                                color: accent + '10'
                            }
                        ])
                    },
                    data: points
                }]
            };

            chart.setOption(option, {
                lazyUpdate: true
            });

            // Track range for dynamic label/tooltip formatting
            chart.on('dataZoom', () => {
                const dz = chart.getOption().dataZoom;
                const min = dz && dz.length ? (dz[0].startValue ?? iv?.min) : iv?.min;
                const max = dz && dz.length ? (dz[0].endValue ?? iv?.max) : iv?.max;
                currentRange = (max - min) || currentRange;
            });

            // When site selected, fetch JSON and update chart
            async function refreshFor(siteId) {
                loading(true);
                const url = new URL('/api/traffic', window.location.origin);
                if (siteId) url.searchParams.set('site_id', siteId);
                try {
                    const res = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    const labels = Array.isArray(data.s1dlLabels) ? data.s1dlLabels : [];
                    const values = Array.isArray(data.s1dlValues) ? data.s1dlValues : [];
                    const minLen = Math.min(labels.length, values.length);
                    const newPts = labels.slice(0, minLen)
                        .map((ts, i) => [parseTsMs(ts), values[i] ?? null])
                        .filter(p => !isNaN(p[0]));
                    chart.setOption({
                        series: [{
                            data: newPts
                        }]
                    }, {
                        lazyUpdate: true
                    });
                    // Update globals for range helpers
                    if (newPts.length) {
                        points.length = 0; newPts.forEach(p => points.push(p));
                    }
                } finally {
                    loading(false);
                }
            }

            // Debounce site selection to avoid burst requests when scrolling options
            let debounceTimer;
            siteSelect?.addEventListener('change', (e) => {
                clearTimeout(debounceTimer);
                const val = e.target.value;
                debounceTimer = setTimeout(() => {
                    // Reflect selection in URL without reloading
                    const url = new URL(window.location.href);
                    if (val) url.searchParams.set('site_id', val);
                    else url.searchParams.delete('site_id');
                    window.history.replaceState({}, '', url);
                    refreshFor(val);
                }, 200);
            });

            function loading(flag) {
                document.getElementById('chartLoading')?.classList.toggle('active', !!flag);
            }

            function getCurrentRange() {
                return currentRange || defaultViewRange;
            }

            function initialViewRange() {
                const end = latestMs ?? (points.length ? points[points.length - 1][0] : undefined);
                if (!end) return undefined;
                const start = Math.max((earliestMs ?? end - 7 * oneDay), end - defaultViewRange);
                return {
                    min: start,
                    max: end
                };
            }

            // Capacity widget
            const capRows = document.getElementById('capRows');
            const capHead = document.getElementById('capHead');
            const capSampleInfo = document.getElementById('capSampleInfo');
            const capLoading = document.getElementById('capLoading');
            const capPerPageEl = document.getElementById('capPerPage');
            const capPrev = document.getElementById('capPrev');
            const capNext = document.getElementById('capNext');
            const capPageInfo = document.getElementById('capPageInfo');

            let capAllRows = [];
            let capPage = 1;

            function renderCapPage() {
                const per = parseInt(capPerPageEl?.value || '50', 10);
                const total = capAllRows.length;
                const pages = Math.max(1, Math.ceil(total / per));
                if (capPage > pages) capPage = pages;
                const start = (capPage - 1) * per;
                const end = Math.min(start + per, total);
                const view = capAllRows.slice(start, end);

                function esc(str) {
                    return String(str ?? '').replace(/[&<>"']/g, s => ({
                        "&": "&amp;",
                        "<": "&lt;",
                        ">": "&gt;",
                        "\"": "&quot;",
                        "'": "&#39;"
                    } [s]));
                }
                const rowsHtml = view.map((r, i) => {
                    const pct = (r.avg_highest_persentase * 100);
                    const maxPct = (r.max_highest_persentase != null ? r.max_highest_persentase * 100 : null);
                    let pctClass = 'pct-normal';
                    if (pct >= 98) {
                        pctClass = 'pct-critical';
                    } else if (pct >= 95) {
                        pctClass = 'pct-warning';
                    }
                    const orderVal = (r.no_order && String(r.no_order).trim() !== '') ? esc(r.no_order) : '–';
                    const orderBadge = (orderVal === '–') ? `<span class="order-badge order-none">–</span>` : `<span class="order-badge order-has" title="Order: ${orderVal}">${orderVal}</span>`;
                    const jarak = (r.jarak ?? null) === null ? '–' : Number(r.jarak).toFixed(1);
                    const pl = (r.packet_loss ?? null) === null ? '–' : Number(r.packet_loss).toFixed(2) + '%';
                    const title = `Site ${r.site_id}\nAvg % tertinggi: ${pct.toFixed(2)}%\nMax % harian: ${maxPct == null ? '–' : maxPct.toFixed(2) + '%'}\nHari Sampel: ${r.day_count}\nAvg PL: ${pl}\nJarak: ${jarak}`;
                    return `
                    <tr class="cursor-pointer" title="${esc(title)}">
                        <td class="py-1 pr-4 text-gray-500 text-right">${start + i + 1}</td>
                        <td class="py-1 pr-4 font-medium text-left">${esc(r.site_id)}</td>
                        <td class="py-1 pr-4 text-right"><span class="pct-chip ${pctClass}">${pct.toFixed(1)}%</span></td>
                        <td class="py-1 pr-4 text-right">${maxPct == null ? '–' : maxPct.toFixed(1) + '%'}</td>
                        <td class="py-1 pr-4 text-right text-gray-400">${r.day_count}</td>
                        <td class="py-1 pr-4 text-right">${pl}</td>
                        <td class="py-1 pr-4 text-left">${orderBadge}</td>
                        <td class="py-1 pr-4 text-right">${jarak}</td>
                        <td class="py-1 pr-4 text-left truncate" title="${esc(r.alpro_category ?? '')}">${esc(r.alpro_category ?? '–')}</td>
                        <td class="py-1 pr-4 text-left truncate" title="${esc(r.alpro_type ?? '')}">${esc(r.alpro_type ?? '–')}</td>
                    </tr>`;
                }).join('');
                capRows.innerHTML = rowsHtml || '<tr><td colspan="10" class="py-2 text-gray-400">Tidak ada data.</td></tr>';
                capPageInfo.textContent = total ? `Menampilkan ${start + 1}–${end} dari ${total}` : 'Menampilkan 0–0 dari 0';
                capPrev.disabled = capPage <= 1;
                capNext.disabled = capPage >= pages;
            }

            async function loadCapacity() {
                const url = new URL(window.location.origin + '/api/capacity');
                try {
                    if (capHead) capHead.style.display = 'none';
                    capLoading?.classList.add('active');
                    capRows.innerHTML = '<tr><td colspan="5" class="py-2 text-gray-400">Memuat data…</td></tr>';
                    const res = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.error || 'Gagal memuat');


                    capAllRows = Array.isArray(data.rows) ? data.rows : [];
                    capPage = 1;
                    renderCapPage();
                } catch (e) {
                    function escapeHtml(str) {
                        return String(str)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#39;');
                    }
                    capRows.innerHTML = `<tr><td colspan="5" class="py-2 text-red-300">${escapeHtml(e.message)}</td></tr>`;
                } finally {
                    capLoading?.classList.remove('active');
                    if (capHead) capHead.style.display = '';
                }
            }

            // If no points embedded, kick off an initial fetch to render client-side quickly
            (async () => {
                const hasEmbedded = Array.isArray(state.s1dlLabels) && state.s1dlLabels.length;
                if (!hasEmbedded) {
                    await refreshFor(state.selectedSiteId ?? null);
                }
            })();

            loadCapacity();

            capPerPageEl?.addEventListener('change', () => {
                capPage = 1;
                renderCapPage();
            });
            capPrev?.addEventListener('click', () => {
                if (capPage > 1) {
                    capPage--;
                    renderCapPage();
                }
            });
            capNext?.addEventListener('click', () => {
                const per = parseInt(capPerPageEl?.value || '50', 10);
                const pages = Math.max(1, Math.ceil(capAllRows.length / per));
                if (capPage < pages) {
                    capPage++;
                    renderCapPage();
                }
            });
        });
    </script>

    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/favicon.ico">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="referrer" content="same-origin">
    <meta name="color-scheme" content="dark">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
</head>

<body>
    <div class="max-w-7xl mx-auto p-6">
        <header class="mb-6">
            <h1 class="text-3xl font-semibold tracking-tight">CTO Panel</h1>
            <p class="text-sm text-gray-400">BI Dashboard • S1 DL rata-rata (7 hari, default tampilan 24 jam)</p>
        </header>

        <div id="errorBox" class="hidden mb-4 p-3 rounded-md" style="background:#2b1211;color:#fecaca;border:1px solid #7f1d1d"></div>

        <div class="card relative">
            <div class="flex items-center gap-3 mb-4">
                <label for="siteSelect" class="text-sm text-gray-300">Site</label>
                <select id="siteSelect" class="select-dark min-w-[220px]">
                    <option value="">All Sites</option>
                </select>
            </div>
            <div id="chartS1" style="height: 480px;"></div>
            <div id="chartLoading" class="loading-overlay">
                <div class="spinner" aria-label="Loading"></div>
            </div>
        </div>

        <div class="mt-8 card relative">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="text-xl font-semibold">Site mendekati kapasitas downlink</h2>
                    <p class="text-xs text-gray-400">Rata-rata harian dari rasio (trafik rata-rata / trafik puncak) selama 5 minggu terakhir.</p>
                </div>
            </div>
            <div id="capSampleInfo" class="text-xs text-gray-400 mb-2 hidden"></div>
            <div class="overflow-x-auto cap-table-wrapper">
                <table class="min-w-full text-sm cap-table">
                    <thead id="capHead">
                        <tr class="text-left text-gray-400">
                            <th class="py-2 pr-4 text-right">#</th>
                            <th class="py-2 pr-4 text-left">Site ID</th>
                            <th class="py-2 pr-4 text-right">Avg % tertinggi</th>
                            <th class="py-2 pr-4 text-right">Max % Harian</th>
                            <th class="py-2 pr-4 text-right">Jumlah Hari</th>
                            <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                            <th class="py-2 pr-4 text-left">No Order</th>
                            <th class="py-2 pr-4 text-right">Jarak (km)</th>
                            <th class="py-2 pr-4 text-left">Kategori</th>
                            <th class="py-2 pr-4 text-left">Tipe</th>
                        </tr>
                    </thead>
                    <tbody id="capRows"></tbody>
                </table>
            </div>
            <div class="flex items-center justify-between mt-3 text-sm">
                <div id="capPageInfo" class="text-gray-400">Menampilkan 0–0 dari 0</div>
                <div class="flex items-center gap-2">
                    <label for="capPerPage" class="text-gray-400">Baris per halaman</label>
                    <select id="capPerPage" class="select-dark">
                        <option value="20">20</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                    <button id="capPrev" class="btn-red" type="button">Sebelumnya</button>
                    <button id="capNext" class="btn-red" type="button">Berikutnya</button>
                </div>
            </div>
            <div id="capLoading" class="loading-overlay">
                <div class="spinner" aria-label="Memuat"></div>
            </div>
        </div>
    </div>
</body>

</html>