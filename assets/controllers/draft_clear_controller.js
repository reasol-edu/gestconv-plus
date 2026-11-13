import { Controller } from '@hotwired/stimulus';

/**
 * Borra borradores guardados en el dispositivo (ver `draft`). Con `key`, borra ese borrador al mostrarse el
 * elemento (pantalla de «partes creados»: el parte ya está guardado en el servidor). Con `prefix` y la acción
 * `purge`, borra todos los borradores al cerrar sesión de forma explícita (equipos compartidos).
 */
export default class extends Controller {
    static values = { key: String, prefix: String };

    connect() {
        if (this.keyValue !== '') {
            localStorage.removeItem(this.keyValue);
        }
    }

    purge() {
        for (const key of Object.keys(localStorage)) {
            if (key.startsWith(this.prefixValue)) {
                localStorage.removeItem(key);
            }
        }
    }
}
