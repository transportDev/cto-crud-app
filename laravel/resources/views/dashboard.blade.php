<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BI Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.19.3/dist/xlsx.full.min.js"></script>

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

        /* Full-screen loading overlay */
        .screen-loading {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(10, 11, 14, .55);
            backdrop-filter: blur(3px);
            z-index: 999;
        }

        .screen-loading.active {
            display: flex;
        }

        .screen-loading .box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border: 1px solid var(--panel-border);
            border-radius: 14px;
            background: rgba(17, 18, 22, 0.9);
            color: #fff;
            font-size: 14px;
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

        /* User menu */
        .user-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--panel);
            border: 1px solid var(--panel-border);
            padding: 8px 14px 8px 10px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text);
            line-height: 1;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .35);
            transition: background .2s, border-color .2s, box-shadow .2s;
        }

        .user-btn:hover {
            background: #1f2024;
        }

        .user-btn:focus {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 12px;
            background: linear-gradient(135deg, #FF3B30, #e6362c);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: .5px;
        }

        .user-name {
            max-width: 140px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-btn .caret {
            opacity: .6;
            transition: transform .25s ease, opacity .2s;
        }

        .user-btn[aria-expanded="true"] .caret {
            transform: rotate(180deg);
            opacity: 1;
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            width: 230px;
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 14px;
            box-shadow: 0 12px 34px -6px rgba(0, 0, 0, .55), 0 4px 14px rgba(0, 0, 0, .4);
            padding: 6px 0;
            display: none;
            z-index: 140;
            backdrop-filter: blur(10px);
        }

        .user-dropdown.open {
            display: block;
            animation: fadeScale .18s ease;
        }

        .dropdown-item {
            width: 100%;
            text-align: left;
            background: transparent;
            border: 0;
            color: var(--text);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            cursor: pointer;
            transition: background .15s, color .15s;
        }

        .dropdown-item:hover {
            background: #222328;
        }

        .dropdown-item svg {
            opacity: .8;
        }

        .user-meta {
            background: rgba(255, 255, 255, 0.02);
        }

        @keyframes fadeScale {
            from {
                opacity: 0;
                transform: translateY(-4px) scale(.97);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
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

        /* Utility */
        .is-hidden {
            display: none !important;
        }

        /* Subtle action button */
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.10);
            transform: translateY(-1px);
        }

        /* Order Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 90;
        }

        .modal-backdrop.active {
            display: flex;
        }

        .modal-shell {
            width: 100%;
            max-width: 820px;
            max-height: 75%;
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            padding: 28px 32px 34px;
            position: relative;
            box-shadow: 0 20px 42px -8px rgba(0, 0, 0, .55);
            overflow-y: scroll;
        }

        .modal-shell h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 12px;
        }

        .modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid var(--panel-border);
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, .16);
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px 18px;
        }

        .field label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .field input,
        .field select {
            width: 100%;
            background: #101114;
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            padding: 9px 11px;
            font-size: 13px;
            color: var(--text);
        }

        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(255, 59, 48, .25);
        }

        .form-footer {
            margin-top: 22px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, .07);
            color: var(--text);
            border: 1px solid var(--panel-border);
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 500;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, .12);
        }

        .error-box {
            background: #2b1211;
            border: 1px solid #7f1d1d;
            color: #fecaca;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 14px;
            display: none;
        }

        .error-box.active {
            display: block;
        }

        .saving-spinner {
            width: 22px;
            height: 22px;
            border: 3px solid rgba(255, 255, 255, .2);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .75s linear infinite;
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
            const chart = (el && typeof echarts !== 'undefined') ? echarts.init(el, null, {
                renderer: 'canvas'
            }) : null;

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

            if (chart) {
                chart.setOption(option, {
                    lazyUpdate: true
                });
            }
            // Pie chart setup for order summary
            const pieEl = document.getElementById('chartPieOrders');
            const pie = (pieEl && typeof echarts !== 'undefined') ? echarts.init(pieEl, null, {
                renderer: 'canvas'
            }) : null;
            const pieColors = [accent, '#10b981', '#f59e0b'];
            const pieOption = {
                backgroundColor: 'transparent',
                tooltip: {
                    trigger: 'item',
                    formatter: '{b}: {c} ({d}%)'
                },
                legend: {
                    bottom: 0,
                    textStyle: {
                        color: axisColor
                    }
                },
                graphic: [{
                    type: 'text',
                    left: 'center',
                    top: 'center',
                    style: {
                        text: 'Total\n0',
                        textAlign: 'center',
                        fill: axisColor,
                        fontSize: 16,
                        fontWeight: 600,
                        lineHeight: 20,
                    }
                }],
                series: [{
                    name: 'Ringkasan Order',
                    type: 'pie',
                    radius: ['40%', '70%'],
                    avoidLabelOverlap: true,
                    itemStyle: {
                        borderRadius: 6,
                        borderColor: '#111418',
                        borderWidth: 1
                    },
                    label: {
                        color: axisColor
                    },
                    labelLine: {
                        lineStyle: {
                            color: axisColor
                        }
                    },
                    color: pieColors,
                    data: [{
                            value: 0,
                            name: 'Belum Ada Order'
                        },
                        {
                            value: 0,
                            name: 'Order Selesai'
                        },
                        {
                            value: 0,
                            name: 'Sudah Ada (Status Kosong)'
                        },
                    ]
                }]
            };
            if (pie) {
                pie.setOption(pieOption, {
                    lazyUpdate: true
                });
            }

            function updatePieCounts({
                none,
                done,
                empty
            }) {
                const total = (none || 0) + (done || 0) + (empty || 0);
                if (pie) pie.setOption({
                    series: [{
                        data: [{
                                value: none,
                                name: 'Belum Ada Order'
                            },
                            {
                                value: done,
                                name: 'Order Selesai'
                            },
                            {
                                value: empty,
                                name: 'Sudah Ada (Status Kosong)'
                            },
                        ]
                    }],
                    graphic: [{
                        type: 'text',
                        left: 'center',
                        top: 'center',
                        style: {
                            text: `Total\n${total}`,
                            textAlign: 'center',
                            fill: axisColor,
                            fontSize: 16,
                            fontWeight: 600,
                            lineHeight: 20
                        }
                    }]
                }, {
                    lazyUpdate: true
                });
            }

            // Track range for dynamic label/tooltip formatting
            chart && chart.on('dataZoom', () => {
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
                    if (chart) {
                        chart.setOption({
                            series: [{
                                data: newPts
                            }]
                        }, {
                            lazyUpdate: true
                        });
                    }
                    // Update globals for range helpers
                    if (newPts.length) {
                        points.length = 0;
                        newPts.forEach(p => points.push(p));
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

            // Capacity widget - 3 kelompok tabel
            const capLoading = document.getElementById('capLoading');
            const capContent = document.getElementById('capContent');
            const pieLoading = document.getElementById('pieLoading');

            function esc(str) {
                return String(str ?? '').replace(/[&<>"']/g, s => ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    "\"": "&quot;",
                    "'": "&#39;"
                } [s]));
            }

            function buildRowHtml(r, i) {
                const pct = (r.avg_highest_persentase * 100);
                const maxPct = (r.max_highest_persentase != null ? r.max_highest_persentase * 100 : null);
                let pctClass = 'pct-normal';
                if (pct >= 98) pctClass = 'pct-critical';
                else if (pct >= 95) pctClass = 'pct-warning';

                const orderVal = (r.no_order && String(r.no_order).trim() !== '') ? esc(r.no_order) : '–';
                const orderBadge = (orderVal === '–') ?
                    `<span class="order-badge order-none">–</span>` :
                    `<span class="order-badge order-has" title="Order: ${orderVal}">${orderVal}</span>`;
                const statusValRaw = (r.status_order ?? '');
                const statusVal = String(statusValRaw).trim() === '' ? '–' : esc(statusValRaw);
                const jarak = (r.jarak ?? null) === null ? '–' : Number(r.jarak).toFixed(1);
                const pl = (r.packet_loss ?? null) === null ? '–' : Number(r.packet_loss).toFixed(2) + '%';
                const canCreateOrderRecord = (r.no_order == null || String(r.no_order).trim() === '');
                const allowCreateUi = (window.canCreateOrders === true);
                const actionHtml = (allowCreateUi && canCreateOrderRecord) ?
                    `<button class="btn-ghost" type="button" title="Buat Order" onclick="openOrderModal({siteid_ne: '${esc(r.site_id)}', site_id: '${esc(r.site_id)}', link_util: ${r.avg_highest_persentase ?? 'null'}, jarak_odp: ${r.jarak ?? 'null'}})">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Order
                       </button>` :
                    `<span class="text-gray-400">–</span>`;

                return `
                <tr>
                    <td class="py-1 pr-4 text-right">${i + 1}</td>
                    <td class="py-1 pr-4 font-medium text-left">${esc(r.site_id)}</td>
                    <td class="py-1 pr-4 text-right"><span class="pct-chip ${pctClass}">${pct.toFixed(1)}%</span></td>
                    <td class="py-1 pr-4 text-right">${maxPct == null ? '–' : maxPct.toFixed(1) + '%'}</td>
                    <td class="py-1 pr-4 text-right text-gray-400">${r.day_count}</td>
                    <td class="py-1 pr-4 text-right">${pl}</td>
                    <td class="py-1 pr-4 text-left">${orderBadge}</td>
                    <td class="py-1 pr-4 text-left">${statusVal}</td>
                    <td class="py-1 pr-4 text-right">${jarak}</td>
                    <td class="py-1 pr-4 text-left truncate" title="${esc(r.alpro_category ?? '')}">${esc(r.alpro_category ?? '–')}</td>
                    <td class="py-1 pr-4 text-left truncate" title="${esc(r.alpro_type ?? '')}">${esc(r.alpro_type ?? '–')}</td>
                    <td class="py-1 pr-4 text-left">${actionHtml}</td>
                </tr>`;
            }

            function initCapTable(suffix) {
                const rowsEl = document.getElementById(`capRows${suffix}`);
                const perEl = document.getElementById(`capPerPage${suffix}`);
                const prevEl = document.getElementById(`capPrev${suffix}`);
                const nextEl = document.getElementById(`capNext${suffix}`);
                const infoEl = document.getElementById(`capPageInfo${suffix}`);

                const state = {
                    all: [],
                    page: 1
                };

                function render() {
                    const per = parseInt(perEl?.value || '50', 10);
                    const total = state.all.length;
                    const pages = Math.max(1, Math.ceil(total / per));
                    if (state.page > pages) state.page = pages;
                    const start = (state.page - 1) * per;
                    const end = Math.min(start + per, total);
                    const view = state.all.slice(start, end);
                    const html = view.map((r, i) => buildRowHtml(r, i)).join('');
                    rowsEl.innerHTML = html || '<tr><td colspan="10" class="py-2 text-gray-400">Tidak ada data.</td></tr>';
                    infoEl.textContent = total ? `Menampilkan ${start + 1}–${end} dari ${total}` : 'Menampilkan 0–0 dari 0';
                    prevEl.disabled = state.page <= 1;
                    nextEl.disabled = state.page >= pages;
                }

                perEl?.addEventListener('change', () => {
                    state.page = 1;
                    render();
                });
                prevEl?.addEventListener('click', () => {
                    if (state.page > 1) {
                        state.page--;
                        render();
                    }
                });
                nextEl?.addEventListener('click', () => {
                    const per = parseInt(perEl?.value || '50', 10);
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
                    loading(msg = 'Memuat data…') {
                        rowsEl.innerHTML = `<tr><td colspan=\"10\" class=\"py-2 text-gray-400\">${esc(msg)}</td></tr>`;
                    },
                    getRows() {
                        return state.all.slice();
                    }
                };
            }

            const capTable1 = initCapTable('1'); // no_order == null
            const capTable2 = initCapTable('2'); // no_order != null && status_order == null
            const capTable3 = initCapTable('3'); // no_order != null && status_order == 'Done'

            async function loadCapacity() {
                const url = new URL(window.location.origin + '/api/capacity');
                try {
                    // Hide tables during loading and show overlay
                    capContent?.classList.add('is-hidden');
                    capLoading?.classList.add('active');
                    // pieLoading overlay disabled with chart
                    const res = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.error || 'Gagal memuat');

                    const all = Array.isArray(data.rows) ? data.rows : [];
                    const group1 = all.filter(r => r.no_order == null);
                    const group2 = all.filter(r => r.no_order != null && (r.status_order == null || String(r.status_order).trim() === ''));
                    const group3 = all.filter(r => r.no_order != null && String(r.status_order ?? '').trim().toLowerCase() === 'done');

                    capTable1.setRows(group1);
                    capTable2.setRows(group2);
                    capTable3.setRows(group3);

                    updatePieCounts({
                        none: group1.length,
                        empty: group2.length,
                        done: group3.length
                    });
                } catch (e) {
                    const msg = (e && e.message) ? e.message : 'Gagal memuat';
                    // Keep content hidden, overlay communicates loading/error
                    console.error(msg);
                } finally {
                    capLoading?.classList.remove('active');
                    capContent?.classList.remove('is-hidden');
                    // pieLoading overlay disabled with chart
                }
            }

            // Export helpers
            function rowsToAoa(rows) {
                const header = [
                    '#', 'Site ID', 'Avg % Util Tertinggi', 'Max % Util Harian', 'Jumlah Hari',
                    'Avg PL (%)', 'No Order', 'Status Order', 'Jarak (km)', 'Kategori', 'Tipe'
                ];
                const body = rows.map((r, idx) => {
                    const pct = (r.avg_highest_persentase ?? 0) * 100;
                    const maxPct = (r.max_highest_persentase ?? null);
                    const maxPctVal = maxPct == null ? null : (maxPct * 100);
                    const pl = (r.packet_loss ?? null);
                    return [
                        idx + 1,
                        r.site_id ?? '',
                        pct.toFixed(2) + '%',
                        maxPctVal == null ? '–' : maxPctVal.toFixed(2) + '%',
                        r.day_count ?? '',
                        pl == null ? '–' : Number(pl).toFixed(2) + '%',
                        r.no_order ?? '',
                        (r.status_order == null || String(r.status_order).trim() === '') ? '' : String(r.status_order),
                        r.jarak == null ? '' : Number(r.jarak).toFixed(1),
                        r.alpro_category ?? '',
                        r.alpro_type ?? ''
                    ];
                });
                return [header, ...body];
            }

            function exportExcel(fileBaseName, rows) {
                try {
                    if (typeof XLSX === 'undefined') throw new Error('XLSX not available');
                    const aoa = rowsToAoa(rows);
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet(aoa);
                    XLSX.utils.book_append_sheet(wb, ws, 'Data');
                    const date = new Date().toISOString().slice(0, 10);
                    XLSX.writeFile(wb, `${fileBaseName}-${date}.xlsx`);
                } catch (err) {
                    // Fallback to CSV
                    const aoa = rowsToAoa(rows);
                    const csv = aoa.map(row => row.map(cell => {
                        const s = String(cell ?? '');
                        if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
                        return s;
                    }).join(',')).join('\n');
                    const blob = new Blob([csv], {
                        type: 'text/csv;charset=utf-8;'
                    });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    const date = new Date().toISOString().slice(0, 10);
                    a.download = `${fileBaseName}-${date}.csv`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }
            }

            // Track initial traffic fetch so we can gate the first-screen overlay
            let initialTrafficPromise = Promise.resolve();
            // If no points embedded, kick off an initial fetch to render client-side quickly
            (async () => {
                const hasEmbedded = Array.isArray(state.s1dlLabels) && state.s1dlLabels.length;
                if (!hasEmbedded) {
                    initialTrafficPromise = refreshFor(state.selectedSiteId ?? null);
                    await initialTrafficPromise;
                }
            })();

            // Capacity Trend (daily count of sites >= threshold)
            const trendEl = document.getElementById('chartCapTrend');
            const trend = trendEl ? echarts.init(trendEl, null, {
                renderer: 'canvas'
            }) : null;
            const trendLoading = (flag) => document.getElementById('chartCapTrendLoading')?.classList.toggle('active', !!flag);

            async function loadTrend() {
                if (!trend) return;
                trendLoading(true);
                try {
                    const url = new URL(window.location.origin + '/api/capacity-trend');
                    const res = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.error || 'Gagal memuat');
                    const labels = Array.isArray(data.labels) ? data.labels : [];
                    const values = Array.isArray(data.values) ? data.values : [];
                    const thrPct = Math.round(((data.threshold ?? 0.85) * 100));
                    const pts = labels.map((d, i) => [new Date(d + 'T00:00:00Z').getTime(), values[i] ?? 0]);
                    const tmin = pts.length ? pts[0][0] : undefined;
                    const tmax = pts.length ? pts[pts.length - 1][0] : undefined;
                    const win = tmax && tmin ? {
                        start: Math.max(tmin, tmax - 14 * oneDay),
                        end: tmax
                    } : undefined;

                    const optionTrend = {
                        backgroundColor: 'transparent',
                        grid: {
                            left: 48,
                            right: 18,
                            top: 28,
                            bottom: 40
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
                                return `${fmtDayLabel(ms)}<br/>${p.marker} ${p.value[1]} site`;
                            }
                        },
                        xAxis: {
                            type: 'time',
                            boundaryGap: false,
                            axisLabel: {
                                color: axisColor,
                                formatter: (val) => fmtDayLabel(val)
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
                                color: axisColor
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
                                minValueSpan: oneDay,
                                startValue: win?.start,
                                endValue: win?.end,
                            },
                            {
                                type: 'slider',
                                show: true,
                                height: 48,
                                bottom: 18,
                                filterMode: 'filter',
                                minValueSpan: oneDay,
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
                                startValue: win?.start,
                                endValue: win?.end
                            }
                        ],
                        series: [{
                            type: 'line',
                            name: `Site ≥${thrPct}%/hari`,
                            showSymbol: false,
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
                                }, {
                                    offset: 1,
                                    color: accent + '10'
                                }])
                            },
                            data: pts
                        }]
                    };

                    trend.setOption(optionTrend, {
                        lazyUpdate: true
                    });
                } catch (e) {
                    console.error(e);
                } finally {
                    trendLoading(false);
                }
            }

            document.getElementById('refreshTrend')?.addEventListener('click', () => loadTrend());

            // Wire export buttons
            const export1 = document.getElementById('export1');
            const export2 = document.getElementById('export2');
            const export3 = document.getElementById('export3');

            export1?.addEventListener('click', () => exportExcel('Belum-Ada-Order', capTable1.getRows()));
            export2?.addEventListener('click', () => exportExcel('Sudah-Ada-Order-Status-Kosong', capTable2.getRows()));
            export3?.addEventListener('click', () => exportExcel('Order-Selesai', capTable3.getRows()));

            document.getElementById('refreshPie')?.addEventListener('click', () => loadCapacity());

            // Full-screen overlay for first page load while fetching initial data
            (async () => {
                const screen = document.getElementById('screenLoading');
                if (screen) screen.classList.add('active');
                try {
                    await Promise.allSettled([
                        initialTrafficPromise,
                        loadTrend(),
                        loadCapacity(),
                    ]);
                } finally {
                    if (screen) screen.classList.remove('active');
                }
            })();
        });
    </script>

    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/favicon.ico">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="can-create-orders" content="{{ auth()->check() && auth()->user()->can('create orders') ? '1' : '0' }}">
    <script>
        (function() {
            const meta = document.querySelector('meta[name="can-create-orders"]');
            window.canCreateOrders = meta && meta.getAttribute('content') === '1';
        })();
    </script>
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
        <header class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">CTO Panel</h1>
                <p class="text-sm text-gray-400">BI Dashboard • S1 DL rata-rata (7 hari, default tampilan 24 jam)</p>
            </div>
            @auth
            <div class="relative" id="userMenuRoot">
                <button type="button" id="userMenuButton" class="user-btn" aria-haspopup="true" aria-expanded="false">
                    <span class="user-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span>
                    <span class="user-name">{{ auth()->user()->name }}</span>
                    <svg class="caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9l6 6 6-6" />
                    </svg>
                </button>
                <div id="userDropdown" class="user-dropdown" role="menu" aria-hidden="true">
                    <div class="user-meta px-3 py-2 text-xs text-gray-400 border-b border-[var(--panel-border)]">
                        Masuk sebagai<br><span class="text-sm text-gray-200 font-medium">{{ auth()->user()->email }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="m-0 p-0" id="logoutForm">
                        @csrf
                        <button type="submit" class="dropdown-item" role="menuitem">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                <polyline points="16 17 21 12 16 7" />
                                <line x1="21" y1="12" x2="9" y2="12" />
                            </svg>
                            <span>Keluar</span>
                        </button>
                    </form>
                </div>
            </div>
            @endauth
        </header>

        <div id="errorBox" class="hidden mb-4 p-3 rounded-md" style="background:#2b1211;color:#fecaca;border:1px solid #7f1d1d"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Traffic Chart Card (disabled)
            <div class="card relative">
                <div class="flex items-center gap-3 mb-4">
                    <label for="siteSelect" class="text-sm text-gray-300">Site</label>
                    <select id="siteSelect" class="select-dark min-w-[220px]">
                        <option value="">All Sites</option>
                    </select>
                </div>
                <div id="chartS1" style="height: 420px;"></div>
                <div id="chartLoading" class="loading-overlay">
                    <div class="spinner" aria-label="Loading"></div>
                </div>
            </div>
            -->

            <!-- Pie Chart Card (disabled)
            <div class="card relative">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold">Ringkasan Order Kapasitas</h2>
                    <button id="refreshPie" type="button" class="btn-ghost" title="Segarkan">Segarkan</button>
                </div>
                <div id="chartPieOrders" style="height: 420px;"></div>
                <div id="pieLoading" class="loading-overlay">
                    <div class="spinner" aria-label="Loading"></div>
                </div>
            </div>
            -->

            <!-- Capacity Trend Chart Card -->
            <!-- <div class="card relative">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold">Jumlah site ≥85% per hari</h2>
                    <button id="refreshTrend" type="button" class="btn-ghost" title="Segarkan">Segarkan</button>
                </div>
                <div id="chartCapTrend" style="height: 420px;"></div>
                <div id="chartCapTrendLoading" class="loading-overlay">
                    <div class="spinner" aria-label="Loading"></div>
                </div>
            </div> -->
        </div>

        <div class="mt-8 card relative">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="text-xl font-semibold">Site mendekati kapasitas downlink</h2>
                    <p class="text-xs text-gray-400">Rata-rata harian dari rasio (trafik rata-rata / trafik puncak) selama 5 minggu terakhir.</p>
                </div>
            </div>

            <div id="capContent">
                <!-- Tabel 1: Belum Ada Order -->
                <div class="mt-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-300">Belum Ada Order</h3>
                        <button id="export1" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
                    </div>
                    <div class="overflow-x-auto cap-table-wrapper">
                        <table class="min-w-full text-sm cap-table">
                            <thead>
                                <tr class="text-left text-gray-400">
                                    <th class="py-2 pr-4 text-right">#</th>
                                    <th class="py-2 pr-4 text-left">Site ID</th>
                                    <th class="py-2 pr-4 text-right">Avg % Util Tertinggi</th>
                                    <th class="py-2 pr-4 text-right">Max % Util Harian</th>
                                    <th class="py-2 pr-4 text-right">Jumlah Hari</th>
                                    <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                                    <th class="py-2 pr-4 text-left">No Order</th>
                                    <th class="py-2 pr-4 text-left">Status Order</th>
                                    <th class="py-2 pr-4 text-right">Jarak (km)</th>
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
                <div class="mt-8">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-300">Sudah Ada Order (Status Kosong)</h3>
                        <button id="export2" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
                    </div>
                    <div class="overflow-x-auto cap-table-wrapper">
                        <table class="min-w-full text-sm cap-table">
                            <thead>
                                <tr class="text-left text-gray-400">
                                    <th class="py-2 pr-4 text-right">#</th>
                                    <th class="py-2 pr-4 text-left">Site ID</th>
                                    <th class="py-2 pr-4 text-right">Avg % Util Tertinggi</th>
                                    <th class="py-2 pr-4 text-right">Max % Util Harian</th>
                                    <th class="py-2 pr-4 text-right">Jumlah Hari</th>
                                    <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                                    <th class="py-2 pr-4 text-left">No Order</th>
                                    <th class="py-2 pr-4 text-left">Status Order</th>
                                    <th class="py-2 pr-4 text-right">Jarak (km)</th>
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
                <div class="mt-8">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-300">Order Selesai</h3>
                        <button id="export3" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
                    </div>
                    <div class="overflow-x-auto cap-table-wrapper">
                        <table class="min-w-full text-sm cap-table">
                            <thead>
                                <tr class="text-left text-gray-400">
                                    <th class="py-2 pr-4 text-right">#</th>
                                    <th class="py-2 pr-4 text-left">Site ID</th>
                                    <th class="py-2 pr-4 text-right">Avg % Util Tertinggi</th>
                                    <th class="py-2 pr-4 text-right">Max % Util Harian</th>
                                    <th class="py-2 pr-4 text-right">Jumlah Hari</th>
                                    <th class="py-2 pr-4 text-right">Avg PL (%)</th>
                                    <th class="py-2 pr-4 text-left">No Order</th>
                                    <th class="py-2 pr-4 text-left">Status Order</th>
                                    <th class="py-2 pr-4 text-right">Jarak (km)</th>
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
            <h2>Buat Order Kapasitas</h2>
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
                        <input name="requestor" required maxlength="100" />
                    </div>
                    <div class="field">
                        <label>Regional *</label>
                        <input name="regional" required maxlength="50" value="7" />
                    </div>
                    <div class="field">
                        <label>NOP</label>
                        <input name="nop" maxlength="50" />
                    </div>
                    <div class="field">
                        <label>SiteID NE</label>
                        <input name="siteid_ne" maxlength="10" />
                    </div>
                    <div class="field">
                        <label>SiteID FE</label>
                        <input name="siteid_fe" maxlength="50" />
                    </div>
                    <div class="field">
                        <label>Transport Type</label>
                        <input name="transport_type" maxlength="20" />
                    </div>
                    <div class="field">
                        <label>PL Status</label>
                        <input name="pl_status" maxlength="20" />
                    </div>
                    <div class="field">
                        <label>Transport Category</label>
                        <input name="transport_category" maxlength="20" />
                    </div>
                    <div class="field">
                        <label>PL Value</label>
                        <input name="pl_value" maxlength="20" />
                    </div>
                    <div class="field">
                        <label>Link Capacity</label>
                        <input name="link_capacity" type="number" />
                    </div>
                    <div class="field">
                        <label>Link Util (%)</label>
                        <input name="link_util" type="number" step="0.01" />
                    </div>
                    <div class="field">
                        <label>Link Owner</label>
                        <input name="link_owner" maxlength="20" />
                    </div>
                    <div class="field">
                        <label>Propose Solution</label>
                        <input name="propose_solution" maxlength="100" />
                    </div>
                    <div class="field">
                        <label>Remark</label>
                        <input name="remark" maxlength="100" />
                    </div>
                    <div class="field">
                        <label>Jarak ODP (km)</label>
                        <input name="jarak_odp" type="number" step="0.01" />
                    </div>
                    <div class="field">
                        <label>Cek NIM Order</label>
                        <input name="cek_nim_order" maxlength="50" />
                    </div>
                    <div class="field">
                        <label>Status Order</label>
                        <input name="status_order" maxlength="50" />
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
                        <textarea name="comment" rows="3" maxlength="1000" style="width:100%;resize:vertical;padding:10px 12px;border:1px solid var(--panel-border);border-radius:12px;background:#0f0f12;color:var(--text);font-family:inherit;font-size:13px;line-height:1.4;" placeholder="Tambahkan komentar baru (opsional)"></textarea>
                    </div>
                </div>
                <div class="form-footer">
                    <button type="button" class="btn-secondary" onclick="closeOrderModal()">Batal</button>
                    <button id="orderSubmitBtn" type="submit" class="btn-red">Simpan</button>
                </div>
            </form>
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
    <script>
        (function() {
            const btn = document.getElementById('userMenuButton');
            const dd = document.getElementById('userDropdown');
            if (!btn || !dd) return;

            function close() {
                dd.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }

            function open() {
                dd.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = dd.classList.contains('open');
                isOpen ? close() : open();
            });
            document.addEventListener('click', (e) => {
                if (!dd.contains(e.target) && e.target !== btn) {
                    close();
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    close();
                }
            });
        })();
    </script>
</body>

</html>