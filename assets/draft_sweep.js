// Limpieza de borradores caducados. Se ejecuta al cargar cualquier pantalla (también la de inicio de sesión), de modo
// que un borrador no sobrevive en el dispositivo más allá de su caducidad aunque la sesión se cerrara sola, sin pulsar
// «Cerrar sesión». Cada borrador lleva su propia fecha (`expiresAt`, calculada al guardarlo con el ajuste de la
// plataforma), así que no hace falta conocer el ajuste aquí.

const PREFIX = 'gestconv:draft:';
// Borradores guardados antes de existir `expiresAt`: se les aplica el plazo por defecto.
const LEGACY_TTL_MS = 24 * 3600 * 1000;

export function sweepExpiredDrafts(storage = window.localStorage, now = Date.now()) {
    let keys;
    try {
        keys = Object.keys(storage);
    } catch {
        return; // almacenamiento no disponible
    }

    for (const key of keys) {
        if (!key.startsWith(PREFIX)) continue;
        let draft = null;
        try {
            draft = JSON.parse(storage.getItem(key) ?? 'null');
        } catch {
            draft = null;
        }
        const expiresAt = typeof draft?.expiresAt === 'number'
            ? draft.expiresAt
            : (typeof draft?.savedAt === 'number' ? draft.savedAt + LEGACY_TTL_MS : 0);
        if (now > expiresAt) {
            storage.removeItem(key);
        }
    }
}

sweepExpiredDrafts();
