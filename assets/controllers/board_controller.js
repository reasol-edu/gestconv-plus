import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel'];

    connect() {
        this.paused = false;
        this.index = 0;
        this.showPanel(0);
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    pause() {
        this.paused = true;
        clearTimeout(this.timer);
    }

    resume() {
        if (!this.paused) {
            return;
        }
        this.paused = false;
        this.schedule();
    }

    next() {
        this.goTo((this.index + 1) % this.panelTargets.length);
    }

    prev() {
        this.goTo((this.index - 1 + this.panelTargets.length) % this.panelTargets.length);
    }

    goTo(index) {
        this.index = index;
        this.showPanel(index);
        this.schedule();
    }

    schedule() {
        clearTimeout(this.timer);
        if (this.paused || this.panelTargets.length <= 1) {
            return;
        }

        const seconds = Number(this.panelTargets[this.index].dataset.seconds || 0);
        this.timer = setTimeout(() => this.next(), seconds * 1000);
    }

    showPanel(index) {
        this.panelTargets.forEach((panel, i) => {
            const active = i === index;
            panel.classList.toggle('opacity-100', active);
            panel.classList.toggle('opacity-0', !active);
            panel.classList.toggle('pointer-events-none', !active);
        });

        for (const column of this.panelTargets[index].querySelectorAll('[data-controller~="board-column"]')) {
            column.scrollTop = 0;
            column.dispatchEvent(new CustomEvent('board:show'));
        }
    }
}
