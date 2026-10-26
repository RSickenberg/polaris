import { Controller } from '@hotwired/stimulus';

/**
 * One toast: slides in, leaves after `delay` milliseconds (0 keeps it until it is dismissed), and stays
 * while the pointer or the keyboard focus is on it.
 *
 * @value delay Milliseconds before it leaves on its own, 0 for never.
 * @action dismiss Remove the toast.
 * @action pause Stop the countdown.
 * @action resume Restart the countdown.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 6000 } };

    connect() {
        this.element.classList.add('transition-all', 'duration-200', 'opacity-0', 'translate-y-2');
        requestAnimationFrame(() => this.element.classList.remove('opacity-0', 'translate-y-2'));
        this.resume();
    }

    disconnect() {
        this.pause();
    }

    pause() {
        clearTimeout(this._timer);
    }

    resume() {
        this.pause();

        if (this.delayValue > 0) {
            this._timer = setTimeout(() => this.dismiss(), this.delayValue);
        }
    }

    dismiss() {
        this.pause();
        this.element.classList.add('opacity-0', 'translate-y-2');
        setTimeout(() => this.element.remove(), 200);
    }
}
