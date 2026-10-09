import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'polaris:theme';
const THEMES = ['light', 'dark', 'system'];

/**
 * Light / dark / system theme switch, remembered in the browser only. The `dark` class on
 * <html> is first set before the first paint by the inline script of base.html.twig; this
 * controller keeps it in sync when the choice or the system preference changes.
 *
 * Put it on a `DropdownMenu` that holds a `DropdownMenu:RadioGroup` of `RadioItem`s whose
 * values are `light`, `dark` and `system`, each with `click->theme#select`.
 *
 * @action select Store and apply the theme of the clicked radio item.
 */
export default class extends Controller {
    connect() {
        this._media = window.matchMedia('(prefers-color-scheme: dark)');
        this._onSystemChange = () => this._apply(this._stored());
        this._media.addEventListener('change', this._onSystemChange);

        this._apply(this._stored());
        this._syncRadios(this._stored());
    }

    disconnect() {
        this._media.removeEventListener('change', this._onSystemChange);
    }

    select(event) {
        const theme = event.currentTarget.dataset.value;
        if (!THEMES.includes(theme)) {
            return;
        }

        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch {
            // Storage can be blocked: the choice then only lasts until the next page load.
        }

        this._apply(theme);
    }

    _stored() {
        try {
            const theme = localStorage.getItem(STORAGE_KEY);

            return THEMES.includes(theme) ? theme : 'system';
        } catch {
            return 'system';
        }
    }

    _apply(theme) {
        const dark = 'dark' === theme || ('system' === theme && this._media.matches);
        document.documentElement.classList.toggle('dark', dark);
    }

    _syncRadios(theme) {
        const group = this.element.querySelector('[data-slot="dropdown-menu-radio-group"]');
        if (!group) {
            return;
        }

        group.dataset.value = theme;
        for (const radio of group.querySelectorAll('[role="menuitemradio"]')) {
            const selected = radio.dataset.value === theme;
            radio.setAttribute('aria-checked', String(selected));

            const indicator = radio.querySelector('[data-radio-indicator]');
            if (indicator) {
                indicator.hidden = !selected;
            }
        }
    }
}
