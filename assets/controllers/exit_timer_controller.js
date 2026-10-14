import { Controller } from '@hotwired/stimulus';

// Va sumando segundos al tiempo que lleva fuera un estudiante, sin pedir nada al servidor.
// El punto de partida (`seconds`) lo calcula el servidor con el reloj del centro, de modo que
// no depende de la hora ni de la zona horaria del dispositivo. Cada refresco del listado en
// vivo trae una cifra nueva y vuelve a sincronizar.
//
// Los niveles de urgencia (ámbar, naranja, rojo) se escriben con sus clases completas: Tailwind
// solo genera las que encuentra literalmente en los fuentes.
const LEVEL_CLASSES = [
    ['bg-gray-100', 'text-gray-700'],
    ['bg-amber-100', 'text-amber-800'],
    ['bg-orange-200', 'text-orange-900'],
    ['bg-red-600', 'text-white'],
];

export default class extends Controller {
    static targets = ['ago'];
    static values = {
        seconds: Number,
        labels: Object,
        thresholds: Array,
        running: { type: Boolean, default: true },
    };

    connect() {
        this.sync();
        this.render();
        if (this.runningValue) {
            this.timer = setInterval(() => this.render(), 1000);
        }
    }

    disconnect() {
        clearInterval(this.timer);
    }

    secondsValueChanged() {
        this.sync();
        this.render();
    }

    sync() {
        this.base = this.secondsValue;
        this.startedAt = performance.now();
    }

    elapsed() {
        return this.base + Math.floor((performance.now() - this.startedAt) / 1000);
    }

    level(seconds) {
        let level = 0;
        this.thresholdsValue.forEach((minutes, i) => {
            if (seconds >= minutes * 60) {
                level = i + 1;
            }
        });
        return level;
    }

    label(seconds) {
        const labels = this.labelsValue;
        if (seconds < 60) {
            return (seconds === 1 ? labels.secondsOne : labels.secondsOther).replace('%count%', seconds);
        }
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return (minutes === 1 ? labels.minutesOne : labels.minutesOther).replace('%count%', minutes);
        }
        return labels.hours
            .replace('%hours%', Math.floor(minutes / 60))
            .replace('%minutes%', String(minutes % 60).padStart(2, '0'));
    }

    render() {
        const seconds = this.elapsed();
        if (this.hasAgoTarget) {
            this.agoTarget.textContent = this.label(seconds);
        }

        const level = this.level(seconds);
        LEVEL_CLASSES.forEach((classes) => this.element.classList.remove(...classes));
        this.element.classList.add(...LEVEL_CLASSES[level]);
        this.element.dataset.level = String(level);
    }
}
