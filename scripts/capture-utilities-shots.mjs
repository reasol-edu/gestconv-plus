/**
 * Captura las imágenes del capítulo de Utilidades del manual (docs/manual/img/utilidades/):
 *  · utilidades-calendario-periodos.png  — formulario del calendario con un periodo «desde el inicio, con horas»
 *  · utilidades-generador-calendarios.png — listado del generador de calendarios
 *  · utilidades-calculadora-fechas.png    — calculadora de fechas con un resultado
 *  · utilidades-secuencia-formulario.png  — una secuencia de unidades didácticas con sus fechas en directo
 *  · utilidades-secuencia-pdf.png         — el PDF de ese calendario (parte de arriba de la primera página;
 *                                           recorte con SHOTS_PDF_CROP="x,y,ancho,alto" en píxeles a 110 ppp)
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures. SÍ crea un
 * calendario (son datos personales del profesorado y no vienen en los fixtures), así que no se puede
 * repetir sobre la misma BD. Falla si algo no funciona.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

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

// ── 4. Secuencia de unidades didácticas: formulario con las fechas en directo y el PDF ─────────
// Va después del listado (las capturas anteriores cuentan con un único calendario). 7 horas a la
// semana (2+2+1+1+1) desde el miércoles 16/09/2026: UD1 (55 h) acaba el martes 10/11/2026.
await goto(`${baseUrl}/utilidades/generador-calendarios/nuevo`);
await page.fill('#cal-title', 'Programación del módulo');
const card = page.locator('[data-controller~="sequence-items"]').first();
await card.locator('input[name$="[name]"]').fill('Unidades didácticas');
await card.locator('input[name$="[startDate]"]').fill('2026-09-16');
const weekHours = { monday: '2', tuesday: '2', wednesday: '1', thursday: '1', friday: '1' };
for (const [day, hours] of Object.entries(weekHours)) {
    await card.locator(`input[name$="[${day}Hours]"]`).fill(hours);
}
const itemRows = card.locator('[data-sequence-items-target="row"]');
const units = [['UD1', '55'], ['UD2', '10'], ['UD3', '12']];
for (const [i, [description, hours]] of units.entries()) {
    await itemRows.nth(i).locator('input[name$="[description]"]').fill(description);
    await itemRows.nth(i).locator('input[name$="[hours]"]').fill(hours);
}
// Las fechas en directo llegan tras una pequeña espera y una petición al servidor.
await page.waitForFunction(() => document.querySelector('[data-sequence-items-target="summary"]')?.textContent.trim() !== '');
await page.evaluate(() => document.activeElement?.blur()); // sin el anillo de foco del último campo
await card.scrollIntoViewIfNeeded();
await hideToolbar();
await card.screenshot({ path: `${outDir}/utilidades-secuencia-formulario.png` });

await page.click('button:has-text("Guardar")');
await page.waitForLoadState('networkidle');
const calendarId = page.url().match(/generador-calendarios\/([0-9a-f-]{36})\/editar/)?.[1];
if (!calendarId) {
    throw new Error('no se ha guardado el calendario de la secuencia');
}

// El PDF se descarga con la sesión del navegador y se convierte a imagen (necesita pdftoppm, de poppler):
// solo la parte de arriba de la primera página, que es donde están los meses y el resumen.
const pdf = await page.context().request.get(`${baseUrl}/utilidades/generador-calendarios/${calendarId}/pdf`);
if (!pdf.ok()) {
    throw new Error(`el PDF de la secuencia devuelve ${pdf.status()}`);
}
const tmp = mkdtempSync(join(tmpdir(), 'gestconv-secuencia-'));
writeFileSync(join(tmp, 'calendario.pdf'), await pdf.body());
const [cropX, cropY, cropW, cropH] = (process.env.SHOTS_PDF_CROP ?? '30,60,770,500').split(',');
execFileSync('pdftoppm', ['-r', '110', '-png', '-singlefile', '-f', '1', '-l', '1', '-x', cropX, '-y', cropY, '-W', cropW, '-H', cropH,
    join(tmp, 'calendario.pdf'), `${outDir}/utilidades-secuencia-pdf`]);

await browser.close();
console.log('Capturas guardadas en', outDir);
