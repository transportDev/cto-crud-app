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

export class BarChart {
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
        this.palette = [
            this.colors.accent,
            "#3B82F6",
            "#10B981",
            "#F59E0B",
            "#A855F7",
        ];
        this.numberFormatter = new Intl.NumberFormat("id-ID");
        this.chart = window.echarts.init(this.element, null, {
            renderer: "canvas",
        });
        const { orientation = "vertical", ...chartOptions } =
            customOptions || {};
        this.orientation =
            orientation === "horizontal" ? "horizontal" : "vertical";
        const isHorizontal = this.orientation === "horizontal";
        const numberFormatter = this.numberFormatter;

        const defaultOptions = {
            color: this.palette,
            backgroundColor: "transparent",
            tooltip: {
                trigger: "axis",
                axisPointer: { type: "shadow" },
                formatter(params) {
                    if (!Array.isArray(params)) return "";
                    const category = params?.[0]?.axisValue ?? "";
                    const lines = params.map((item) => {
                        const value = Number(item?.data ?? 0);
                        const formatted = numberFormatter.format(value);
                        return `${item.marker} ${item.seriesName}: ${formatted}`;
                    });
                    return [category, ...lines].join("<br/>");
                },
            },
            legend: {
                top: 0,
                textStyle: { color: this.colors.axisColor },
            },
            grid: isHorizontal
                ? { left: 90, right: 18, top: 50, bottom: 12 }
                : { left: 48, right: 24, top: 60, bottom: 60 },
            xAxis: isHorizontal
                ? {
                      type: "value",
                      axisLabel: {
                          color: this.colors.axisColor,
                          formatter(value) {
                              return numberFormatter.format(value);
                          },
                      },
                      axisLine: {
                          lineStyle: { color: this.colors.gridColor },
                      },
                      splitLine: {
                          show: true,
                          lineStyle: {
                              color: this.colors.gridColor,
                              type: "dashed",
                          },
                      },
                      max: (value) => {
                          const max = value?.max ?? 0;
                          if (!Number.isFinite(max)) return null;
                          return max === 0 ? 1 : max * 1.05;
                      },
                  }
                : {
                      type: "category",
                      data: [],
                      axisLabel: {
                          color: this.colors.axisColor,
                          rotate: 0,
                          interval: 0,
                      },
                      axisLine: {
                          lineStyle: { color: this.colors.gridColor },
                      },
                      axisTick: { alignWithLabel: true },
                  },
            yAxis: isHorizontal
                ? {
                      type: "category",
                      data: [],
                      axisLabel: {
                          color: this.colors.axisColor,
                      },
                      axisLine: {
                          lineStyle: { color: this.colors.gridColor },
                      },
                      axisTick: { alignWithLabel: true },
                      boundaryGap: [0, 0.02],
                  }
                : {
                      type: "value",
                      name: "Jumlah",
                      nameTextStyle: { color: this.colors.axisColor },
                      axisLabel: {
                          color: this.colors.axisColor,
                          formatter(value) {
                              return numberFormatter.format(value);
                          },
                      },
                      axisLine: {
                          lineStyle: { color: this.colors.gridColor },
                      },
                      splitLine: {
                          show: true,
                          lineStyle: {
                              color: this.colors.gridColor,
                              type: "dashed",
                          },
                      },
                  },
            series: [],
        };

        const finalOptions = deepMerge(defaultOptions, chartOptions);
        this.chart.setOption(finalOptions, { lazyUpdate: true });
    }

    updateData({ categories = [], series = [] } = {}) {
        if (!this.chart) return;
        const safeCategories = Array.isArray(categories)
            ? categories.map((item) =>
                  item == null ? "Tidak diketahui" : String(item)
              )
            : [];
        const isHorizontal = this.orientation === "horizontal";

        const normalizedSeries = Array.isArray(series)
            ? series.map((item, index) => {
                  const name =
                      item && typeof item.name === "string"
                          ? item.name
                          : `Seri ${index + 1}`;
                  const dataArray = Array.isArray(item?.data)
                      ? item.data.map((value) => Number(value) || 0)
                      : new Array(safeCategories.length).fill(0);
                  const base = {
                      name,
                      type: "bar",
                      barGap: 0.2,
                      stack: item?.stack,
                      barCategoryGap: "35%",
                      emphasis: { focus: "series" },
                      itemStyle: {
                          color: this.palette[index % this.palette.length],
                      },
                      data: dataArray,
                  };
                  if (isHorizontal) {
                      base.label = {
                          show: true,
                          position: "right",
                          color: this.colors.axisColor,
                          formatter: (params) =>
                              this.numberFormatter.format(
                                  Number(params?.value ?? 0)
                              ),
                      };
                  }
                  return base;
              })
            : [];

        const optionPayload = isHorizontal
            ? {
                  yAxis: { data: safeCategories },
                  series: normalizedSeries,
              }
            : {
                  xAxis: { data: safeCategories },
                  series: normalizedSeries,
              };

        this.chart.setOption(optionPayload, { lazyUpdate: true });
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
