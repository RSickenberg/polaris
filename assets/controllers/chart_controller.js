import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts';

const TOKEN = /^var\((--[\w-]+)\)$/;

/**
 * Renders one ApexCharts chart from Stimulus values. It knows nothing about what the chart
 * shows: the caller gives the chart type, the series and the ApexCharts options.
 *
 * Any string in `options` written as `var(--token)` is replaced by the computed value of
 * that CSS variable, so colors follow the theme tokens of app.css. The chart is drawn again
 * when the `dark` class of <html> changes (see theme_controller.js), without a reload.
 *
 * Datetime x axes and numeric y axes are formatted in the browser with Intl, for `locale`
 * and `timeZone`; series times are Unix milliseconds (UTC instants).
 *
 * The chart is destroyed when the element disconnects and before Turbo caches the page, so
 * a snapshot never holds chart markup and a restored page never shows two charts.
 *
 * Put `role="img"` and an `aria-label` on the `canvas` target (the element itself when there
 * is none), which holds the chart. Buttons with `data-action="chart#zoom"`, a `min` and a `max`
 * param (Unix milliseconds) and `data-chart-target="range"` zoom the x axis; the clicked one gets
 * `aria-pressed="true"`.
 *
 * @target canvas  Where the chart is drawn; defaults to the element.
 * @target range   A zoom button.
 * @action zoom    Show the x window given by the `min` and `max` params of the clicked button.
 *
 * @value type     ApexCharts chart type (`line`, `area`, ...).
 * @value series   ApexCharts series.
 * @value options  ApexCharts options, merged over the theme defaults of this controller.
 * @value align    When above 0, every series is sampled on one shared x grid (all the x of all the series plus
 *                 this many evenly spaced steps), interpolating linearly inside the x range of each series and
 *                 leaving gaps outside it. ApexCharts' shared tooltip needs the same x in every series: without
 *                 it a series with few points (a projection, a target line) is missing while hovering between them.
 *                 The original points keep their markers, the others only show one under the cursor.
 * @value locale   BCP 47 locale for number and date formats.
 * @value timeZone IANA time zone for date formats.
 */
export default class extends Controller {
    static targets = ['canvas', 'range'];

    static values = {
        type: { type: String, default: 'line' },
        series: { type: Array, default: [] },
        options: { type: Object, default: {} },
        align: { type: Number, default: 0 },
        locale: { type: String, default: 'en' },
        timeZone: { type: String, default: 'UTC' },
    };

    connect() {
        this._dark = this._isDark();
        this._range = null;
        this._chart = new ApexCharts(this.hasCanvasTarget ? this.canvasTarget : this.element, this._config());
        this._rendered = this._chart.render();

        this._observer = new MutationObserver(() => this._onThemeChange());
        this._observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        this._onBeforeCache = () => this._destroy();
        document.addEventListener('turbo:before-cache', this._onBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this._onBeforeCache);
        this._observer?.disconnect();
        this._observer = null;
        this._destroy();
    }

    zoom(event) {
        const { min, max } = event.params;
        if (!this._chart || !Number.isFinite(min) || !Number.isFinite(max)) {
            return;
        }

        this._range = { min, max };
        for (const button of this.rangeTargets) {
            button.setAttribute('aria-pressed', String(button === event.currentTarget));
        }

        this._chart.updateOptions({ xaxis: { min, max } }, false, false);
    }

    _onThemeChange() {
        const dark = this._isDark();
        if (!this._chart || dark === this._dark) {
            return;
        }

        this._dark = dark;
        this._chart.updateOptions(this._config(), false, false);
    }

    async _destroy() {
        const chart = this._chart;
        if (!chart) {
            return;
        }

        this._chart = null;
        try {
            // Destroying a chart that is still rendering would leave its markup behind.
            await this._rendered;
        } finally {
            chart.destroy();
        }
    }

    _isDark() {
        return document.documentElement.classList.contains('dark');
    }

    _config() {
        const style = getComputedStyle(document.documentElement);
        const token = (name) => style.getPropertyValue(name).trim();
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const base = {
            chart: {
                type: this.typeValue,
                height: '100%',
                background: 'transparent',
                fontFamily: 'inherit',
                foreColor: token('--muted-foreground'),
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: !reducedMotion },
            },
            grid: { borderColor: token('--border') },
            tooltip: { theme: this._dark ? 'dark' : 'light' },
            legend: { labels: { colors: token('--foreground') } },
            series: this.seriesValue,
        };

        const range = this._range ? { xaxis: this._range } : {};
        const config = this._merge(base, this._resolve(this.optionsValue, token), this._formatters(), range);

        return this.alignValue > 0 ? this._aligned(config) : config;
    }

    /** Puts the series of a config on one shared x grid, see the `align` value. */
    _aligned(config) {
        const original = config.series;
        const xs = new Set(original.flatMap((serie) => serie.data.map((point) => point[0])));
        const min = Math.min(...xs);
        const max = Math.max(...xs);
        for (let step = 0; step <= this.alignValue; ++step) {
            xs.add(Math.round(min + ((max - min) * step) / this.alignValue));
        }

        const grid = [...xs].sort((a, b) => a - b);
        const index = new Map(grid.map((x, position) => [x, position]));
        const sizes = [].concat(config.markers?.size ?? 0);
        const discrete = [];

        const series = original.map((serie, seriesIndex) => {
            const size = sizes[seriesIndex] ?? sizes[0] ?? 0;
            if (size > 0) {
                const color = [].concat(config.colors ?? [])[seriesIndex];
                for (const [x] of serie.data) {
                    discrete.push({ seriesIndex, dataPointIndex: index.get(x), size, fillColor: color, strokeColor: color });
                }
            }

            return { ...serie, data: grid.map((x) => [x, this._interpolate(serie.data, x)]) };
        });

        return {
            ...config,
            series,
            markers: { ...config.markers, size: 0, discrete, hover: { size: 5 } },
        };
    }

    /** The y of sorted [x, y] points at x, linear between two points, null outside their x range. */
    _interpolate(points, x) {
        const first = points[0];
        const last = points[points.length - 1];
        if (!first || x < first[0] || x > last[0]) {
            return null;
        }

        const after = points.findIndex((point) => point[0] >= x);
        const [x1, y1] = points[after];
        if (x1 === x || 0 === after) {
            return y1;
        }

        const [x0, y0] = points[after - 1];

        return y0 + ((y1 - y0) * (x - x0)) / (x1 - x0);
    }

    _formatters() {
        const number = (fraction) => {
            const format = new Intl.NumberFormat(this.localeValue, { maximumFractionDigits: fraction });

            return (value) => (Number.isFinite(value) ? format.format(value) : '');
        };
        const date = (options) => {
            const format = new Intl.DateTimeFormat(this.localeValue, { timeZone: this.timeZoneValue, ...options });

            // On a datetime axis ApexCharts passes its own formatted label first and the timestamp second.
            return (value, timestamp) => {
                const at = new Date(Number.isFinite(timestamp) ? timestamp : Number(value));

                return Number.isNaN(at.getTime()) ? '' : format.format(at);
            };
        };

        return {
            xaxis: { labels: { formatter: date({ month: 'short', year: 'numeric' }) } },
            yaxis: { labels: { formatter: number(0) } },
            tooltip: { custom: this._tooltip(date({ dateStyle: 'medium' }), number(0)) },
        };
    }

    /**
     * The shared tooltip, in the markup and classes of ApexCharts so that its theme styles apply, with a row only for the
     * series that have a value at the hovered x (ApexCharts keeps a row, label included, for a series with a gap there).
     */
    _tooltip(formatDate, formatNumber) {
        const escape = (text) => String(text).replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);

        return ({ series, dataPointIndex, w }) => {
            const rows = series
                .map((values, index) => [index, values[dataPointIndex]])
                .filter(([, value]) => Number.isFinite(value));
            if (0 === rows.length) {
                return '';
            }

            const title = formatDate(null, w.globals.seriesX[rows[0][0]][dataPointIndex]);
            const items = rows.map(
                ([index, value]) => `<div class="apexcharts-tooltip-series-group apexcharts-active" style="order: ${index + 1}; display: flex;">` +
                    `<span class="apexcharts-tooltip-marker" style="background-color: ${escape(w.globals.colors[index])}; border-radius: 50%;"></span>` +
                    '<div class="apexcharts-tooltip-text"><div class="apexcharts-tooltip-y-group">' +
                    `<span class="apexcharts-tooltip-text-y-label">${escape(w.globals.seriesNames[index])}: </span>` +
                    `<span class="apexcharts-tooltip-text-y-value">${formatNumber(value)}</span>` +
                    '</div></div></div>',
            );

            return `<div class="apexcharts-tooltip-title">${escape(title)}</div>${items.join('')}`;
        };
    }

    /** Replaces `var(--token)` strings, at any depth, by the computed value of the variable. */
    _resolve(value, token) {
        if ('string' === typeof value) {
            const match = TOKEN.exec(value);

            return match ? token(match[1]) : value;
        }

        if (Array.isArray(value)) {
            return value.map((item) => this._resolve(item, token));
        }

        if (value && 'object' === typeof value) {
            return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, this._resolve(item, token)]));
        }

        return value;
    }

    /** Deep merge of plain objects, later sources win; arrays and scalars are replaced. */
    _merge(...sources) {
        const isObject = (value) => value && 'object' === typeof value && !Array.isArray(value);

        return sources.reduce((target, source) => {
            for (const [key, value] of Object.entries(source)) {
                target[key] = isObject(value) && isObject(target[key]) ? this._merge(target[key], value) : value;
            }

            return target;
        }, {});
    }
}
