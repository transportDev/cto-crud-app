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
        this.legendValues = {};
        this.numberFormatter = new Intl.NumberFormat("id-ID");
        this.chart = window.echarts.init(this.element, null, {
            renderer: "canvas",
        });

        const defaultOptions = {
            backgroundColor: "transparent",
            tooltip: {
                trigger: "item",
                formatter: (params) =>
                    `${params.marker} ${
                        params.name
                    }: ${this.numberFormatter.format(params.value)}`,
            },
            legend: {
                bottom: 0,

                textStyle: { color: this.colors.axisColor, fontSize: 10 },
                formatter: (name) => this.formatLegend(name),
            },
            graphic: [],
            series: [
                {
                    name: "Ringkasan",
                    type: "pie",
                    radius: ["42%", "65%"],
                    avoidLabelOverlap: false,
                    center: ["50%", "46%"],
                    minAngle: 4,
                    itemStyle: {
                        borderRadius: 6,
                        borderColor: "#111418",
                        borderWidth: 1,
                    },
                    label: {
                        show: true,
                        color: this.colors.axisColor,
                        position: "outside",
                        overflow: "break",
                        fontSize: 10,
                        formatter: (params) => this.formatLabel(params),
                    },
                    labelLine: {
                        show: true,
                        length: 20,
                        length2: 16,
                        smooth: true,
                        lineStyle: { color: this.colors.axisColor },
                    },
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
        const normalized = arr.map((item, idx) => {
            const name =
                item && typeof item.name === "string" && item.name.trim()
                    ? item.name
                    : `Group ${idx + 1}`;
            const value = Number(item?.value ?? 0);
            return { ...item, name, value };
        });
        this.legendValues = normalized.reduce((acc, item) => {
            acc[item.name] = item.value ?? 0;
            return acc;
        }, {});
        this.chart.setOption(
            {
                legend: { data: normalized.map((item) => item.name) },
                series: [{ data: normalized }],
            },
            { lazyUpdate: true }
        );
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
                        top: "40%",
                        style: {
                            text,
                            textAlign: "center",
                            fill,
                            fontSize: 16,
                            fontWeight: 700,
                            lineHeight: 26,
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

    formatLegend(name) {
        const value = this.legendValues?.[name] ?? 0;
        const formatted = this.numberFormatter.format(value);
        return `${name} (${formatted})`;
    }

    formatLabel(params) {
        const name = params?.name ?? "";
        const value = Number(params?.value ?? 0);
        const formatted = this.numberFormatter.format(value);
        return name ? `${name}\n${formatted}` : formatted;
    }
}
