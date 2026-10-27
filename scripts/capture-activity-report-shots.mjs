/**
 * Captura para el manual el listado de actividades (con una en borrador), la ficha de una actividad con su
 * alumnado y las casillas del informe, y la primera página del informe PDF:
 *  · calendario/actividad-listado.png — listado de gestión: la actividad en borrador lleva su etiqueta
 *  · calendario/actividad-ficha.png   — ficha con los datos, el alumnado por grupos y las casillas del informe
 *  · calendario/actividad-informe.png — primera página del PDF (un grupo con nombres, el otro solo con el recuento)
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver
 * scripts/seed/README.md), una BD DESECHABLE con fixtures y `tmp:seed-shots` (siembra la excursión con alumnado y la
 * actividad en borrador) y `pdftoppm` (poppler) para pasar el PDF a imagen. No modifica datos.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
const page    = await context.newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

await goto(`${baseUrl}/login`);
await page.fill('#username', 'carmen.diaz');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// ── Listado de gestión, con la actividad en borrador ──────────────────────────────────────────────────
await goto(`${baseUrl}/actividades`);
await page.waitForTimeout(500);
expect(await page.locator('span', { hasText: 'Borrador' }).count() >= 1, 'el listado debe marcar la actividad en borrador');
await page.screenshot({ path: `${root}/calendario/actividad-listado.png`, clip: { x: 0, y: 0, width: 1280, height: 626 } });

// ── Ficha de la excursión ─────────────────────────────────────────────────────────────────────────────
const link = page.locator('a', { hasText: 'Excursión a la Sierra de Aracena' }).first();
const href = await link.getAttribute('href');
await link.click();
await page.waitForLoadState('networkidle');
await hideToolbar();
const checkboxes = page.locator('input[name="groups[]"]');
expect(await checkboxes.count() === 2, 'la ficha debe ofrecer las casillas de los dos grupos con alumnado');

await page.setViewportSize({ width: 1280, height: 1500 });
const height = await page.evaluate(() => {
    const form = document.querySelector('form[action$="/informe"]').getBoundingClientRect();

    return Math.ceil(form.bottom + window.scrollY + 24);
});
await page.screenshot({ path: `${root}/calendario/actividad-ficha.png`, clip: { x: 0, y: 0, width: 1280, height } });

// ── Informe PDF: solo se listan los nombres del primer grupo ──────────────────────────────────────────
const firstGroup = await checkboxes.first().getAttribute('value');
const response   = await context.request.get(`${baseUrl}${href}/informe?options=1&groups[]=${firstGroup}`);
expect(response.ok() && (response.headers()['content-type'] ?? '').includes('application/pdf'), 'el informe debe ser un PDF');
const dir = mkdtempSync(join(tmpdir(), 'informe-actividad-'));
writeFileSync(join(dir, 'informe.pdf'), await response.body());
execFileSync('pdftoppm', ['-png', '-r', '90', '-f', '1', '-l', '1', '-singlefile', join(dir, 'informe.pdf'), join(dir, 'pagina')]);
// Se recorta la parte en blanco de la página y se añade un borde fino para que destaque sobre el fondo del manual.
execFileSync('magick', [join(dir, 'pagina.png'), '-crop', '744x640+0+0', '+repage', '-bordercolor', '#d1d5db', '-border', '1', `${root}/calendario/actividad-informe.png`]);

await browser.close();
console.log('Capturas del listado, la ficha y el informe de actividades generadas.');
