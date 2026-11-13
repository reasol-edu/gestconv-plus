/**
 * Capturas de la copia de seguridad y del aviso de versión nueva (capítulo «Administrar la plataforma»):
 *
 *  · admin/admin-sistema-tarjeta.png   — panel de Administración con la tarjeta «Copia de seguridad y versión»
 *  · admin/admin-copia-seguridad.png   — página con la versión instalada y el botón de descarga
 *
 * Con SHOTS_NEWER=1 (el servidor debe ir con una versión instalada anterior a la última publicada; ver más abajo):
 *
 *  · admin/admin-version-nueva-panel.png   — franja de «versión nueva» en el panel de Administración
 *  · admin/admin-version-nueva-pagina.png  — la misma novedad en la página de copia y versión
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD desechable con fixtures (usuario `admin`/`admin`). El aviso
 * consulta de verdad la última release de GitHub, así que para verlo hay que arrancar el servidor con `app.version`
 * por debajo de ella (p. ej. cambiando temporalmente `app.version` en config/services.yaml, sin commitearlo).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
const newer   = process.env.SHOTS_NEWER === '1';
mkdirSync(`${root}/admin`, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
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
if (newer) {
    await page.locator('[data-update-notice]').waitFor();
    await page.screenshot({ path: `${root}/admin/admin-version-nueva-panel.png` });
    await open('/admin/sistema');
    await page.screenshot({ path: `${root}/admin/admin-version-nueva-pagina.png` });
} else {
    await page.screenshot({ path: `${root}/admin/admin-sistema-tarjeta.png` });
    await open('/admin/sistema');
    await page.screenshot({ path: `${root}/admin/admin-copia-seguridad.png` });
}

await browser.close();
console.log('Listo.');
