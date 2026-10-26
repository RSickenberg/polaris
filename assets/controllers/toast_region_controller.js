import { Controller } from '@hotwired/stimulus';

/**
 * The place toasts stack up in. Besides the flash messages the server renders into it, it shows the toasts
 * that other controllers ask for with a `polaris:toast` event on `window` (`detail: {variant, message}`).
 * Twig renders one empty toast per variant, so the markup and the translated texts stay out of the JavaScript.
 *
 * @value toasts The HTML of an empty toast for each variant (success, warning, info, danger), each with a
 *               `[data-toast-message]` element.
 */
export default class extends Controller {
    static values = { toasts: Object };

    connect() {
        this._onToast = (event) => this.add(event.detail);
        window.addEventListener('polaris:toast', this._onToast);
    }

    disconnect() {
        window.removeEventListener('polaris:toast', this._onToast);
    }

    add({ variant = 'info', message = '' }) {
        const html = this.toastsValue[variant];

        if (!html || '' === message) {
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const toast = template.content.firstElementChild;
        toast.querySelector('[data-toast-message]').textContent = message;
        this.element.appendChild(toast);
    }
}
