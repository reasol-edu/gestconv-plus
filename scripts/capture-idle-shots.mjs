/**
 * Capturas del cierre de sesión por inactividad (capítulo «Administrar la plataforma»):
 *
 *  · admin/login-sesion-caducada.png  — pantalla de inicio de sesión con el aviso de sesión cerrada por inactividad
 *  · ajustes/ajustes-seguridad.png    — Ajustes globales, categoría «Seguridad» con el tiempo de inactividad
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures (usuario `admin`/`admin`). Solo lee:
 * no guarda ningún ajuste.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
mkdirSync(`${root}/admin`, { recursive: true });
mkdirSync(`${root}/ajustes`, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

// La pantalla de inicio de sesión a la que se llega tras el cierre por inactividad (no hace falta sesión).
await open('/login?sesion=caducada');
await page.screenshot({ path: `${root}/admin/login-sesion-caducada.png` });

await open('/login');
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

await open('/admin/ajustes');
const section = page.locator('#settings-cat-settings_category_security');
await section.scrollIntoViewIfNeeded();
await section.screenshot({ path: `${root}/ajustes/ajustes-seguridad.png` });

await browser.close();
console.log('Listo.');
