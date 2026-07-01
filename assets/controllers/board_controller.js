import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['currentWeek', 'nextWeek'];
    static values = { currentSeconds: Number, nextSeconds: Number };

    connect() {
        this.showPanel(this.currentWeekTarget);

        if (this.hasNextWeekTarget && this.currentSecondsValue > 0 && this.nextSecondsValue > 0) {
            this.scheduleNext(this.currentWeekTarget, this.currentSecondsValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    scheduleNext(activePanel, seconds) {
        this.timer = setTimeout(() => {
            const panel   = activePanel === this.currentWeekTarget ? this.nextWeekTarget : this.currentWeekTarget;
            const forNext = panel === this.nextWeekTarget ? this.nextSecondsValue : this.currentSecondsValue;
            this.showPanel(panel);
            this.scheduleNext(panel, forNext);
        }, seconds * 1000);
    }

    showPanel(panel) {
        const panels = this.hasNextWeekTarget
            ? [this.currentWeekTarget, this.nextWeekTarget]
            : [this.currentWeekTarget];

        for (const candidate of panels) {
            const active = candidate === panel;
            candidate.classList.toggle('opacity-100', active);
            candidate.classList.toggle('opacity-0', !active);
            candidate.classList.toggle('pointer-events-none', !active);
        }

        for (const column of panel.querySelectorAll('[data-controller~="board-column"]')) {
            column.scrollTop = 0;
            column.dispatchEvent(new CustomEvent('board:show'));
        }
    }
}
