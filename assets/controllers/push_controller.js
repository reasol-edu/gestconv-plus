import { Controller } from '@hotwired/stimulus';

/**
 * Activa o desactiva los avisos push en ESTE dispositivo (perfil › Ajustes). La suscripción la crea el navegador
 * con la clave pública VAPID del servidor y se envía al servidor con el token CSRF; desactivar la borra en los dos
 * sitios. Si el navegador no admite avisos push (p. ej. iPhone sin la app instalada en la pantalla de inicio),
 * se explica en lugar de ofrecer un botón que no funcionaría.
 */
export default class extends Controller {
    static targets = ['status', 'enable', 'disable', 'test', 'unsupported'];
    static values = {
        publicKey: String,
        csrf: String,
        subscribeUrl: String,
        testUrl: String,
        labelOn: String,
        labelOff: String,
        labelDenied: String,
        labelError: String,
    };

    async connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            this.unsupportedTarget.classList.remove('hidden');
            return;
        }
        await this.#refresh();
    }

    async enable() {
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                this.#status(this.labelDeniedValue);
                return;
            }
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.#key(this.publicKeyValue),
            });
            const response = await this.#send('POST', this.subscribeUrlValue, subscription.toJSON());
            if (!response.ok) {
                await subscription.unsubscribe();
                throw new Error(String(response.status));
            }
        } catch {
            this.#status(this.labelErrorValue);
            return;
        }
        await this.#refresh();
    }

    async disable() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            await this.#send('DELETE', this.subscribeUrlValue, { endpoint: subscription.endpoint });
            await subscription.unsubscribe();
        }
        await this.#refresh();
    }

    async test() {
        await this.#send('POST', this.testUrlValue, {});
    }

    async #refresh() {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        const active = subscription !== null && Notification.permission === 'granted';

        this.enableTarget.classList.toggle('hidden', active);
        this.disableTarget.classList.toggle('hidden', !active);
        this.testTarget.classList.toggle('hidden', !active);
        this.#status(Notification.permission === 'denied' ? this.labelDeniedValue : (active ? this.labelOnValue : this.labelOffValue));
    }

    #status(text) {
        this.statusTarget.textContent = text;
    }

    #send(method, url, body) {
        return fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
            body: JSON.stringify(body),
        });
    }

    #key(base64Url) {
        const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
        const raw = atob((base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, (c) => c.charCodeAt(0));
    }
}
