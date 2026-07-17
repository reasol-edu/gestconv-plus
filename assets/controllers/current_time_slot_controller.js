import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['slot'];
    static classes = ['current'];

    connect() {
        this.tick();
        this.timer = setInterval(this.tick, 30000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick = () => {
        const now = new Date();
        const minutes = now.getHours() * 60 + now.getMinutes();

        this.slotTargets.forEach((slot) => {
            const isCurrent = minutes >= this.toMinutes(slot.dataset.start) && minutes < this.toMinutes(slot.dataset.end);
            this.currentClasses.forEach((className) => slot.classList.toggle(className, isCurrent));
        });
    };

    toMinutes(value) {
        const [hours, mins] = value.split(':').map(Number);

        return hours * 60 + mins;
    }
}
