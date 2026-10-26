import { Controller } from '@hotwired/stimulus';

/**
 * Asks the toast region for a toast when it appears in the page, once. Put it on an element whose `id`
 * changes with every message: a morphed element that keeps its `id` is not connected again and says nothing.
 *
 * @value variant success, error or info.
 * @value message The text of the toast, already translated.
 */
export default class extends Controller {
    static values = { variant: String, message: String };

    connect() {
        window.dispatchEvent(new CustomEvent('polaris:toast', { detail: { variant: this.variantValue, message: this.messageValue } }));
    }
}
