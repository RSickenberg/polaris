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
 * @value locale   BCP 47 locale for number and date formats.
 * @value timeZone IANA time zone for date formats.
 */
export default class extends Controller {
    static targets = ['canvas', 'range'];

    static values = {
        type: { type: String, default: 'line' },
        series: { type: Array, default: [] },
        options: { type: Object, default: {} },
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

        return this._merge(base, this._resolve(this.optionsValue, token), this._formatters(), range);
    }

    _formatters() {
        const number = (fraction) => {
            const format = new Intl.NumberFormat(this.localeValue, { maximumFractionDigits: fraction });

            return (value) => (null === value || undefined === value ? '' : format.format(value));
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
            tooltip: {
                x: { formatter: date({ dateStyle: 'medium' }) },
                y: { formatter: number(0) },
            },
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
