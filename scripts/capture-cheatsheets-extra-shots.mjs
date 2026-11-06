/**
 * Captura las imágenes móviles de dos fichas rápidas (docs/cheatsheets/img/):
 *  · atender-incidencia-1..4.png — «Atender una incidencia» (responsable: Beatriz, tipo Mantenimiento)
 *  · crear-actividad-1..4.png    — «Crear una actividad de centro» (con el usuario `admin`, que siempre puede publicar)
 *
 * Mismo patrón que scripts/capture-issues-cheatsheet-shots.mjs: viewport móvil, servidor arrancado en SHOTS_BASE_URL con el
 * reloj de la demo, BD DESECHABLE con fixtures, `tmp:seed-shots` y `tmp:seed-issues`. No guarda nada, así que se puede
 * repetir sobre la misma BD.
 */
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';

mkdirSync(root, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function newPage() {
    return (await browser.newContext({ ...devices['iPhone 13'], locale: 'es-ES' })).newPage();
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    await hideToolbar(page);
}

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function login(page, username, password) {
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('button:has-text("IES Ada Lovelace")');
        await page.waitForLoadState('networkidle');
    }
}

/** Captura de la franja vertical que va de lo alto del elemento `from` al final del elemento `to`. */
async function shootBetween(page, from, to, path, pad = 16) {
    const a   = await from.boundingBox();
    const b   = await to.boundingBox();
    const top = await page.evaluate(() => window.scrollY);
    const y   = Math.max(0, a.y + top - pad);
    await page.screenshot({ path, fullPage: true, clip: { x: 0, y, width: 390, height: b.y + top + b.height - y + pad } });
}

// ── Atender una incidencia ───────────────────────────────────────────────────────────────────────────
const beatriz = await newPage();
await login(beatriz, 'beatriz.alonso', 'ejemplo');

// 1. El listado: las incidencias que tiene que atender.
await beatriz.goto(`${baseUrl}/incidencias`);
await settle(beatriz);
await beatriz.screenshot({ path: `${root}/atender-incidencia-1.png`, clip: { x: 0, y: 0, width: 390, height: 640 } });

// Una incidencia abierta y sin prioridad.
const link = beatriz.locator('a[href^="/incidencias/"]:visible:not([href$="/nueva"]):not([href$="/informe"])').first();
expect(await link.count() === 1, 'debe haber alguna incidencia');
await link.click();
await settle(beatriz);

// 2. La ficha: qué se ha comunicado.
await beatriz.screenshot({ path: `${root}/atender-incidencia-2.png`, clip: { x: 0, y: 0, width: 390, height: 640 } });

// 3. El panel de gestión: prioridad, estado y a quién se asigna.
const priority = beatriz.locator('#manage-priority');
expect(await priority.count() === 1, 'el responsable debe poder gestionar');
await beatriz.selectOption('#manage-priority', { label: 'Alta' });
await priority.scrollIntoViewIfNeeded();
await hideToolbar(beatriz);
const assignee = beatriz.locator('#manage-assignee ~ .ts-wrapper, #manage-assignee').first();
await shootBetween(beatriz, priority, assignee, `${root}/atender-incidencia-3.png`, 24);

// 4. La observación (con el nuevo estado) para dejar constancia.
const observation = beatriz.locator('#observation-content');
await observation.scrollIntoViewIfNeeded();
await observation.fill('Pedido el material al proveedor. Lo cambiamos esta semana.');
await hideToolbar(beatriz);
const observeButton = beatriz.locator('form[action$="/observacion"] button[type="submit"]').first();
await shootBetween(beatriz, observation, observeButton, `${root}/atender-incidencia-4.png`, 40);

// ── Crear una actividad de centro ────────────────────────────────────────────────────────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');

// 1. La sección y su botón.
await admin.goto(`${baseUrl}/actividades`);
await settle(admin);
await admin.screenshot({ path: `${root}/crear-actividad-1.png`, clip: { x: 0, y: 0, width: 390, height: 560 } });

await admin.goto(`${baseUrl}/actividades/nuevo`);
await settle(admin);

// 2. Fecha, horario y nombre.
await admin.fill('#date', '2026-11-12');
await admin.fill('#start_time', '09:00');
await admin.fill('#end_time', '14:00');
await admin.fill('#name', 'Visita al parque de las ciencias');
await hideToolbar(admin);
await shootBetween(admin, admin.locator('#date'), admin.locator('#name'), `${root}/crear-actividad-2.png`, 28);

// 3. El tipo y el lugar (texto libre, con sugerencias del centro).
const type = admin.locator('#type');
await type.scrollIntoViewIfNeeded();
await admin.selectOption('#type', { index: 1 });
await hideToolbar(admin);
await shootBetween(admin, type, admin.locator('#location ~ .ts-wrapper').first(), `${root}/crear-actividad-3.png`, 28);

// 4. Los grupos (opcional) y el botón de guardar.
const groups = admin.locator('#groups ~ .ts-wrapper').first();
await groups.scrollIntoViewIfNeeded();
await hideToolbar(admin);
await shootBetween(admin, groups, admin.locator('form button[type="submit"]').last(), `${root}/crear-actividad-4.png`, 60);

await browser.close();
console.log('Imágenes de las fichas «Atender una incidencia» y «Crear una actividad» capturadas.');
