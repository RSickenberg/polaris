import { Controller } from '@hotwired/stimulus';

/**
 * Filters the tables of the debug page: a text typed in `query` keeps the rows that contain it (any column, case
 * ignored), `table` keeps one table. A table with no row left is hidden while a text is typed, and `empty` shows
 * when nothing is left at all.
 */
export default class extends Controller {
    static targets = ['query', 'table', 'empty'];

    connect() {
        this.apply();
    }

    apply() {
        const text = this.queryTarget.value.trim().toLowerCase();
        const only = this.tableSelect ? this.tableSelect.value : '';
        let visibleTables = 0;

        this.tableTargets.forEach((table) => {
            const rows = Array.from(table.querySelectorAll('[data-filter-row]'));
            let matches = 0;

            rows.forEach((row) => {
                const match = '' === text || row.textContent.toLowerCase().includes(text);
                row.hidden = !match;
                if (match) {
                    matches += 1;
                }
            });

            const chosen = '' === only || only === table.dataset.debugTable;
            const hasRows = 0 < rows.length;
            // Without a text, a table shows even when it has no row; with one, only when a row matches.
            const visible = chosen && ('' === text || (hasRows && 0 < matches));
            table.hidden = !visible;
            if (visible) {
                visibleTables += 1;
            }
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = 0 < visibleTables;
        }
    }

    get tableSelect() {
        return this.element.querySelector('[data-table-filter-select]');
    }
}
