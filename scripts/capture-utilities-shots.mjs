/**
 * Captura las imágenes del capítulo de Utilidades del manual (docs/manual/img/utilidades/):
 *  · utilidades-calendario-periodos.png  — formulario del calendario con un periodo «desde el inicio, con horas»
 *  · utilidades-generador-calendarios.png — listado del generador de calendarios
 *  · utilidades-calculadora-fechas.png    — calculadora de fechas con un resultado
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures. SÍ crea un
 * calendario (son datos personales del profesorado y no vienen en los fixtures), así que no se puede
 * repetir sobre la misma BD. Falla si algo no funciona.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/utilidades';

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

await goto(`${baseUrl}/login`);
await page.fill('#username', 'roberto.guerrero');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// ── 1. Nuevo calendario, con un periodo «desde el inicio, con horas» ─────────
await goto(`${baseUrl}/utilidades/generador-calendarios/nuevo`);
await page.fill('#cal-title', 'FFEOE 2º DAM');
await page.locator('.ql-editor').click();
await page.keyboard.type('Seguimiento de la FFEOE.');

await page.fill('input[name="periods[0][description]"]', 'FFEOE');
// Los radios del modo están ocultos (sr-only): se marca el input con force, no la etiqueta.
await page.locator('input[name="periods[0][mode]"][value="start_with_hours"]').check({ force: true });
await page.click('input[name="periods[0][showJourneySummary]"]');
// startDate y totalHours existen en más de un modo (solo uno es visible cada vez).
await page.fill('input[name="periods[0][startDate]"]:visible', '2027-03-01');
await page.fill('input[name="periods[0][totalHours]"]:visible', '160');
for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']) {
    await page.fill(`input[name="periods[0][${day}Hours]"]:visible`, '6');
}

await page.click('button:has-text("Guardar")');
await page.waitForLoadState('networkidle');

await page.locator('h2:has-text("Periodos")').evaluate((el) => el.scrollIntoView({ block: 'start' }));
await hideToolbar();
await page.screenshot({ path: `${outDir}/utilidades-calendario-periodos.png` });

// ── 2. Listado del generador de calendarios ──────────────────────────────────
await goto(`${baseUrl}/utilidades/generador-calendarios`);
await page.screenshot({ path: `${outDir}/utilidades-generador-calendarios.png` });

// ── 3. Calculadora de fechas, con un resultado ───────────────────────────────
await goto(`${baseUrl}/utilidades/calculadora-fechas`);
await page.fill('input[name="startDate"]:visible', '2027-03-01');
await page.fill('input[name="endDate"]:visible', '2027-03-19');
for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']) {
    await page.fill(`input[name="${day}Hours"]`, '6');
}
await page.click('button:has-text("Calcular")');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/utilidades-calculadora-fechas.png` });

await browser.close();
console.log('Capturas guardadas en', outDir);
