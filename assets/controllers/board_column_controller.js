import { Controller } from '@hotwired/stimulus';

const PAUSE_MS = 2500;
const PIXELS_PER_SECOND = 60;

export default class extends Controller {
    connect() {
        this.element.addEventListener('board:show', this.restart);
        this.restart();
    }

    disconnect() {
        this.element.removeEventListener('board:show', this.restart);
        clearTimeout(this.timer);
    }

    restart = () => {
        clearTimeout(this.timer);
        this.element.scrollTop = 0;
        this.loop();
    };

    loop = () => {
        const distance = this.element.scrollHeight - this.element.clientHeight;
        if (distance <= 0) {
            return;
        }

        this.timer = setTimeout(() => {
            this.element.scrollTo({ top: distance, behavior: 'smooth' });
            this.timer = setTimeout(() => {
                this.timer = setTimeout(() => {
                    this.element.scrollTo({ top: 0, behavior: 'auto' });
                    this.timer = setTimeout(this.loop, PAUSE_MS);
                }, PAUSE_MS);
            }, (distance / PIXELS_PER_SECOND) * 1000);
        }, PAUSE_MS);
    };
}
