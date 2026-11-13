/**
 * Capturas de las novedades de la 1.30 posteriores a `capture-v1-30-shots.mjs`: analítica de convivencia,
 * lista de comprobación de inicio de curso y aviso de grupos sin tutoría en la oferta formativa.
 *
 *  · informes/informes-analitica.png            — cifras clave y primeros gráficos (cabecera de la página)
 *  · informes/informes-analitica-graficos.png   — gráficos de conductas y de estudiantes según su número de partes
 *  · centro/centro-inicio-de-curso.png          — pantalla «Inicio de curso» con los pasos y su estado
 *  · inicio/tarjeta-inicio-de-curso.png         — tarjeta del inicio con los pasos pendientes
 *  · centro/centro-oferta-sin-tutoria.png       — aviso desplegado de grupos sin tutoría en la oferta formativa
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con fixtures + tmp:seed-shots y SHOTS_CENTRE_ID (uuid del centro).
 * Solo lee datos: no hace falta restaurar la BD entre ejecuciones. Para el aviso de la oferta, antes hay que dejar
 * sin tutor/a un par de grupos (DELETE FROM group_tutor WHERE group_id IN (…)): con la siembra todos tienen tutoría.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8745';
const root     = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
const centreId = process.env.SHOTS_CENTRE_ID;
if (!centreId) {
    throw new Error('Falta SHOTS_CENTRE_ID');
}

for (const dir of ['informes', 'centro', 'inicio']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
const page = await context.newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; } header.sticky { position: static !important; }' });
}

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// ── Analítica de convivencia ─────────────────────────────────────────────────────────────────────────
await open(`/centro/${centreId}/informes/analitica`);
await page.waitForTimeout(500);
await page.screenshot({ path: `${root}/informes/informes-analitica.png` });

// Segunda captura: bajamos hasta el gráfico de conductas para enseñar también barras horizontales.
await page.getByRole('heading', { name: 'Conductas más registradas' }).evaluate((el) => {
    el.scrollIntoView({ block: 'start' });
    window.scrollBy(0, -24);
});
await page.waitForTimeout(300);
await page.screenshot({ path: `${root}/informes/informes-analitica-graficos.png` });

// ── Inicio de curso ──────────────────────────────────────────────────────────────────────────────────
await open(`/centro/${centreId}/inicio-de-curso`);
await page.waitForTimeout(400);
await page.screenshot({ path: `${root}/centro/centro-inicio-de-curso.png` });

await open('/');
await page.waitForTimeout(400);
const card = page.locator('a[href$="/inicio-de-curso"]').first().locator('xpath=ancestor::*[contains(@class,"rounded")][1]');
if (await card.count()) {
    await card.first().screenshot({ path: `${root}/inicio/tarjeta-inicio-de-curso.png` });
} else {
    console.warn('No se encontró la tarjeta de inicio de curso en el inicio');
}

// ── Oferta formativa: grupos sin tutoría ─────────────────────────────────────────────────────────────
await open(`/centro/${centreId}/offer`);
await page.waitForTimeout(600);
const toggle = page.locator('button[aria-controls="offer-no-tutor-list"]').first();
if (await toggle.count()) {
    await toggle.click();
    await page.waitForSelector('#offer-no-tutor-list', { state: 'visible' });
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${root}/centro/centro-oferta-sin-tutoria.png` });
} else {
    console.warn('No hay aviso de grupos sin tutoría (¿todos los grupos tienen tutor?)');
}

await browser.close();
