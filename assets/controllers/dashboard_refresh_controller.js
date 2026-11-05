import { Controller } from '@hotwired/stimulus';

/**
 * Asks Turbo to refresh the current page when it appears in the page, once: the figures and the chart are then drawn
 * again from the new readings, morphed in place (see the `turbo-refresh-method` meta of the dashboard). Put it on an
 * element whose `id` changes with every result: a morphed element that keeps its `id` is not connected again.
 */
export default class extends Controller {
    connect() {
        const turbo = window.Turbo;

        if (turbo) {
            turbo.session.refresh(window.location.href);
        }
    }
}
