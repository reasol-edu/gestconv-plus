/**
 * Capturas de las copias automáticas y del estado del sistema (capítulo «Administrar la plataforma»):
 *
 *  · admin/admin-sistema-tarjeta.png    — panel de Administración con las tarjetas «Copia de seguridad y versión» y «Estado del sistema»
 *  · admin/admin-copia-seguridad.png    — página de copia y versión, con el aviso de «última copia» y el estado de las automáticas
 *  · admin/admin-copias-automaticas.png — ajustes globales › Copias de seguridad
 *  · admin/admin-estado-sistema.png     — pantalla «Estado del sistema»
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures y la siembra de estado (el
 * comando temporal que activa la política «Recomendada», guarda una copia manual y otra automática, escribe una señal de
 * vida del worker y deja un mensaje fallido en la cola `failed`). Solo lee: no pulsa nada que cambie datos.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
mkdirSync(`${root}/admin`, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

await open('/login');
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

await open('/admin');
await page.screenshot({ path: `${root}/admin/admin-sistema-tarjeta.png` });

await open('/admin/sistema');
await page.screenshot({ path: `${root}/admin/admin-copia-seguridad.png` });

await open('/admin/estado');
// Sin el menú lateral: la pantalla es una sola columna y así cabe en menos de 1000 px de alto.
await page.screenshot({ path: `${root}/admin/admin-estado-sistema.png`, fullPage: true, clip: { x: 380, y: 100, width: 780, height: 965 } });

await open('/admin/ajustes');
// La sección de la categoría «Copias de seguridad» (sus dos ajustes).
const section = page.locator('#settings-cat-settings_category_backup');
await section.scrollIntoViewIfNeeded();
await section.screenshot({ path: `${root}/admin/admin-copias-automaticas.png` });

await browser.close();
console.log('Listo.');
