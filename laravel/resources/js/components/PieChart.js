// Lightweight ECharts pie/donut chart wrapper
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

export class PieChart {
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

        const defaultOptions = {
            backgroundColor: "transparent",
            tooltip: { trigger: "item", formatter: "{b}: {c} ({d}%)" },
            legend: { bottom: 0, textStyle: { color: this.colors.axisColor } },
            graphic: [],
            series: [
                {
                    name: "Ringkasan",
                    type: "pie",
                    radius: ["40%", "70%"],
                    avoidLabelOverlap: true,
                    itemStyle: {
                        borderRadius: 6,
                        borderColor: "#111418",
                        borderWidth: 1,
                    },
                    label: { color: this.colors.axisColor },
                    labelLine: { lineStyle: { color: this.colors.axisColor } },
                    data: [],
                },
            ],
        };

        const finalOptions = deepMerge(defaultOptions, customOptions);
        this.chart.setOption(finalOptions, { lazyUpdate: true });
    }

    updateData(data) {
        if (!this.chart) return;
        const arr = Array.isArray(data) ? data : [];
        this.chart.setOption({ series: [{ data: arr }] }, { lazyUpdate: true });
    }

    updateGraphicText(text) {
        if (!this.chart) return;
        const fill =
            getComputedStyle(document.documentElement)
                .getPropertyValue("--text")
                ?.trim() || "#e5e7eb";
        this.chart.setOption(
            {
                graphic: [
                    {
                        type: "text",
                        left: "center",
                        top: "center",
                        style: {
                            text,
                            textAlign: "center",
                            fill,
                            fontSize: 16,
                            fontWeight: 600,
                            lineHeight: 20,
                        },
                    },
                ],
            },
            { lazyUpdate: true }
        );
    }

    setLoading(isLoading) {
        const loadingOverlay = this.element?.nextElementSibling;
        if (
            loadingOverlay &&
            loadingOverlay.classList.contains("loading-overlay")
        ) {
            loadingOverlay.classList.toggle("active", !!isLoading);
        }
    }

    getInstance() {
        return this.chart;
    }
}
