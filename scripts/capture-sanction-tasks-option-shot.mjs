/**
 * Captura para el manual el grupo «Tareas para el estudiante durante la sanción» del formulario de sanción
 * (sanciones/sanciones-sin-tareas.png): las fechas de vigencia y las dos opciones, con «No solicitar tareas a los
 * docentes» elegida. No guarda nada.
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver scripts/seed/README.md)
 * y una BD con fixtures y `tmp:seed-shots` (alumno con partes sancionables: «Gil Cabrera») y una medida con fechas.
 */
import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const today   = process.env.SHOTS_TODAY ?? '2026-10-05';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await (await browser.newContext({ viewport: { width: 1280, height: 1500 }, locale: 'es-ES' })).newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'carmen.diaz');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

await page.goto(`${baseUrl}/sanciones/nueva`);
await page.waitForLoadState('networkidle');
await page.locator('input[type="search"]').click();
await page.keyboard.type('Gil Cabrera', { delay: 30 });
await page.waitForTimeout(700);
await page.locator('a[href*="/sanciones/nueva?studentId="]').first().click();
await page.waitForLoadState('networkidle');
await hideToolbar();

await page.locator('input[name="reports[]"]').first().check();
const dated = page.locator('input[name="measures[]"][data-has-date-range="1"]').first();
expect(await dated.count() === 1, 'debe haber una medida con fechas en el catálogo');
await dated.check();
await page.fill('#effective_from', today);
await page.fill('#effective_to', '2026-10-07');
await page.waitForTimeout(300);

const dateBlock = page.locator('[data-sanction-measures-target="dateBlock"]');
expect(await dateBlock.isVisible(), 'con una medida con fechas deben verse las fechas');
expect(await page.locator('input[name="request_tasks"][value="1"]').isChecked(), 'por defecto se solicitan tareas');
await page.locator('input[name="request_tasks"][value="0"]').check();
await page.locator('h1').click();
await hideToolbar();
await dateBlock.screenshot({ path: `${root}/sanciones/sanciones-sin-tareas.png` });

await browser.close();
console.log('Captura del grupo «Tareas para el estudiante durante la sanción» generada.');
