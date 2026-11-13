// Limpieza de borradores caducados. Se ejecuta al cargar cualquier pantalla (también la de inicio de sesión), de modo
// que un borrador no sobrevive en el dispositivo más allá de su caducidad aunque la sesión se cerrara sola, sin pulsar
// «Cerrar sesión».
//
// Caduca lo que antes ocurra de dos cosas: la fecha con la que se guardó (`expiresAt`, calculada con el ajuste de la
// plataforma de entonces) y el plazo vigente ahora (`data-draft-retention-hours` del <body>), de modo que acortar el
// ajuste surte efecto en los borradores ya guardados. Con 0 horas (borradores desactivados) se borran todos.

const PREFIX = 'gestconv:draft:';
const HOUR_MS = 3600 * 1000;
// Borradores guardados antes de existir `expiresAt`: se les aplica el plazo por defecto.
const LEGACY_TTL_MS = 24 * HOUR_MS;

/** Horas vigentes según la página, o null si no constan (entonces solo cuenta la fecha guardada con cada borrador). */
function currentRetentionHours() {
    const raw = document.body?.dataset.draftRetentionHours;
    const hours = raw === undefined || raw === '' ? NaN : Number(raw);

    return Number.isFinite(hours) && hours >= 0 ? hours : null;
}

export function sweepExpiredDrafts(storage = window.localStorage, now = Date.now(), retentionHours = currentRetentionHours()) {
    let keys;
    try {
        keys = Object.keys(storage);
    } catch {
        return; // almacenamiento no disponible
    }

    for (const key of keys) {
        if (!key.startsWith(PREFIX)) continue;
        if (retentionHours === 0) {
            storage.removeItem(key);
            continue;
        }

        let draft = null;
        try {
            draft = JSON.parse(storage.getItem(key) ?? 'null');
        } catch {
            draft = null;
        }
        const savedAt = typeof draft?.savedAt === 'number' ? draft.savedAt : null;
        let expiresAt = typeof draft?.expiresAt === 'number'
            ? draft.expiresAt
            : (savedAt !== null ? savedAt + LEGACY_TTL_MS : 0);
        if (savedAt !== null && retentionHours !== null) {
            expiresAt = Math.min(expiresAt, savedAt + retentionHours * HOUR_MS);
        }
        if (now > expiresAt) {
            storage.removeItem(key);
        }
    }
}

sweepExpiredDrafts();
