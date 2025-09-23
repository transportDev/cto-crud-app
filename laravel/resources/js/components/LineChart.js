// Lightweight ECharts line chart wrapper for reusable, data-agnostic charts
// Relies on global window.echarts loaded via CDN in Blade

function getThemeColors() {
    const css = getComputedStyle(document.documentElement);
    const themeDark =
        window.matchMedia &&
        window.matchMedia("(prefers-color-scheme: dark)").matches;
    return {
        axisColor:
            css.getPropertyValue("--text")?.trim() ||
            (themeDark ? "#e5e7eb" : "#0f172a"),
        gridColor:
            css.getPropertyValue("--grid")?.trim() ||
            (themeDark ? "#2a2f36" : "#e5e7eb"),
        accent: css.getPropertyValue("--accent")?.trim() || "#FF3B30",
    };
}

function deepMerge(target = {}, source = {}) {
    const out = { ...target };
    for (const [k, v] of Object.entries(source)) {
        if (v && typeof v === "object" && !Array.isArray(v)) {
            out[k] = deepMerge(target[k] || {}, v);
        } else {
            out[k] = v;
        }
    }
    return out;
}

export class LineChart {
    constructor(elementId, customOptions = {}) {
        this.element = document.getElementById(elementId);
        if (!this.element) {
            console.error(`Chart element with ID "${elementId}" not found.`);
            return;
        }
        if (!window.echarts) {
            console.error(
                "ECharts (window.echarts) not found. Ensure the CDN script is loaded."
            );
            return;
        }

        this.colors = getThemeColors();
        this.chart = window.echarts.init(this.element, null, {
            renderer: "canvas",
        });

        const oneHour = 3600 * 1000;
        const oneDay = 24 * oneHour;

        const defaultOptions = {
            backgroundColor: "transparent",
            grid: { left: 48, right: 18, top: 28, bottom: 90 },
            tooltip: {
                trigger: "axis",
                backgroundColor: this.colors.accent,
                textStyle: { color: "#fff" },
                borderWidth: 0,
            },
            xAxis: {
                type: "time",
                boundaryGap: false,
                axisLabel: { color: this.colors.axisColor },
                axisLine: { lineStyle: { color: this.colors.gridColor } },
                splitLine: { show: false },
            },
            yAxis: {
                type: "value",
                axisLabel: { color: this.colors.axisColor },
                axisLine: { lineStyle: { color: this.colors.gridColor } },
                splitLine: {
                    show: true,
                    lineStyle: { color: this.colors.gridColor, type: "dashed" },
                },
            },
            dataZoom: [
                {
                    type: "inside",
                    throttle: 50,
                    filterMode: "filter",
                    minValueSpan: oneHour,
                    maxValueSpan: 7 * oneDay,
                },
                {
                    type: "slider",
                    show: true,
                    height: 48,
                    bottom: 18,
                    filterMode: "filter",
                    minValueSpan: oneHour,
                    maxValueSpan: 7 * oneDay,
                    borderColor: "transparent",
                    handleSize: 14,
                    handleStyle: {
                        color: "#fff",
                        borderColor: this.colors.accent,
                    },
                    moveHandleStyle: { color: this.colors.accent },
                    backgroundColor: "rgba(255,255,255,0.03)",
                    dataBackground: {
                        lineStyle: { color: this.colors.accent, opacity: 0.5 },
                        areaStyle: { color: this.colors.accent, opacity: 0.08 },
                    },
                    selectedDataBackground: {
                        lineStyle: { color: this.colors.accent, opacity: 0.9 },
                        areaStyle: { color: this.colors.accent, opacity: 0.18 },
                    },
                    textStyle: { color: this.colors.axisColor },
                },
            ],
            series: [
                {
                    type: "line",
                    name: "Series",
                    showSymbol: false,
                    smooth: true,
                    sampling: "lttb",
                    lineStyle: { width: 2.6, color: this.colors.accent },
                    areaStyle: {
                        color: new window.echarts.graphic.LinearGradient(
                            0,
                            0,
                            0,
                            1,
                            [
                                { offset: 0, color: this.colors.accent + "CC" },
                                { offset: 1, color: this.colors.accent + "10" },
                            ]
                        ),
                    },
                    data: [],
                },
            ],
        };

        // Merge options, but preserve default series shape (type, styles, etc.)
        let finalOptions = deepMerge(defaultOptions, customOptions);
        if (customOptions && Array.isArray(customOptions.series)) {
            const mergedSeries = (defaultOptions.series || []).map((s, i) => ({
                ...s,
                ...(customOptions.series[i] || {}),
            }));
            finalOptions.series = mergedSeries;
        }
        this.chart.setOption(finalOptions, { lazyUpdate: true });
    }

    updateData(points) {
        if (!this.chart) return;
        this.chart.setOption(
            { series: [{ data: Array.isArray(points) ? points : [] }] },
            { lazyUpdate: true }
        );
    }

    setLoading(isLoading) {
        let overlay = this.element?.nextElementSibling;
        if (!(overlay && overlay.classList.contains("loading-overlay"))) {
            overlay = document.getElementById(`${this.element.id}-loading`);
        }
        overlay?.classList.toggle("active", !!isLoading);
    }

    getInstance() {
        return this.chart;
    }
}
