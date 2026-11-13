import { Controller } from '@hotwired/stimulus';

/**
 * Borra datos guardados en el dispositivo. Con `key`, borra ese borrador al mostrarse el elemento (pantalla de
 * «partes creados»: el parte ya está guardado en el servidor). Con `prefix` y la acción `purge`, al cerrar sesión de
 * forma explícita (equipos compartidos de la sala de profesores) borra todos los borradores y, si el dispositivo
 * estaba suscrito a los avisos push, da de baja la suscripción: si no, la siguiente persona que use el equipo vería
 * en la pantalla de bloqueo avisos con nombres de estudiantes. Quien quiera avisos en su móvil personal los reactiva
 * al volver a entrar (Perfil › Ajustes).
 */
export default class extends Controller {
    static values = { key: String, prefix: String, pushUrl: String, pushCsrf: String };

    connect() {
        if (this.keyValue !== '') {
            localStorage.removeItem(this.keyValue);
        }
    }

    async purge(event) {
        for (const key of Object.keys(localStorage)) {
            if (key.startsWith(this.prefixValue)) {
                localStorage.removeItem(key);
            }
        }

        if (this.pushUrlValue === '' || !('serviceWorker' in navigator) || !('PushManager' in window)) {
            return;
        }

        // El cierre de sesión espera a la baja del dispositivo (con un tope corto: nunca debe impedir salir).
        event.preventDefault();
        const href = this.element.href;
        try {
            await Promise.race([this.#unsubscribe(), new Promise((resolve) => setTimeout(resolve, 3000))]);
        } catch {
            // Sin conexión o sin suscripción: se cierra la sesión igualmente.
        }
        window.location.href = href;
    }

    async #unsubscribe() {
        const registration = await navigator.serviceWorker.getRegistration();
        const subscription = registration ? await registration.pushManager.getSubscription() : null;
        if (!subscription) {
            return;
        }
        await fetch(this.pushUrlValue, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.pushCsrfValue },
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });
        await subscription.unsubscribe();
    }
}
