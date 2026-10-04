/**
 * Captura las pantallas del manual que no cubre ningún otro script:
 *  · centro/centro-tramos.png                 — tablero de tramos horarios con guardias
 *  · ajustes/ajustes-plantillas-pdf.png       — plantillas PDF de los informes (con una plantilla subida)
 *  · notas/notas-estudiantes.png              — listado de estudiantes por tipo de nota, con el umbral alcanzado
 *  · notas/parte-notas-desactivadas.png       — confirmación de un parte que desactiva las notas del estudiante
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos sembrados (fixtures + tmp:seed-shots:
 * tramos con guardias del lunes, y dos notas «Retraso a primera hora» previas de Carla Gil Cabrera)
 * y el id del centro en SHOTS_CENTRE_ID. Crea una tercera nota y un parte, así que conviene ejecutarlo
 * sobre una base restaurada (ver scripts/seed/).
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const centreId = process.env.SHOTS_CENTRE_ID;
const root     = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

for (const dir of ['centro', 'ajustes', 'notas']) mkdirSync(`${root}/${dir}`, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
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
    await hideToolbar(page);
}

async function fillQuill(page, mountSelector, text) {
    const editor = page.locator(`${mountSelector} .ql-editor`);
    await editor.click();
    await editor.press('ControlOrMeta+a');
    await editor.press('Backspace');
    await editor.type(text, { delay: 5 });
}

// ── Tablero de tramos horarios (comisión/administración del centro) ──────────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'carmen.diaz', 'ejemplo');
    await page.goto(`${baseUrl}/centro/${centreId}/tramos-horarios`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-tramos.png` });
    await page.close();
}

// ── Plantillas PDF de los informes (administración): se sube un membrete de ejemplo ──────
{
    // Un PDF de una sola página con un membrete sencillo, generado con la propia mPDF del proyecto.
    const letterhead = join(mkdtempSync(join(tmpdir(), 'membrete-')), 'membrete-ejemplo.pdf');
    execFileSync('php', ['-r', `
        require 'vendor/autoload.php';
        $m = new \\Mpdf\\Mpdf(['tempDir' => sys_get_temp_dir()]);
        $m->WriteHTML('<div style="border-bottom:2px solid #576490;color:#2b3350;font-size:16pt;padding-bottom:4px"><b>IES Ada Lovelace</b><br><span style="font-size:9pt">Consejería de Educación · Linares (Jaén)</span></div>');
        file_put_contents($argv[1], $m->Output('', 'S'));
    `, letterhead]);

    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'admin', 'admin');
    await page.goto(`${baseUrl}/centro/${centreId}/ajustes`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    const row = page.locator('li', { has: page.locator('p', { hasText: 'Plantilla PDF de informe de partes' }) });
    await row.locator('input[type="file"]').setInputFiles(letterhead);
    await row.locator('button:has-text("Subir")').click();
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.locator('text=Plantilla PDF general (vertical)').first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await page.evaluate(() => window.scrollBy(0, -80));
    await page.waitForTimeout(150);
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/ajustes/ajustes-plantillas-pdf.png` });
    await page.close();
}

// ── Notas: la tercera nota alcanza el umbral; el parte posterior desactiva las notas activas ──────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/notas/nuevo`);
    await page.waitForLoadState('networkidle');
    await page.locator('#student-select').locator('..').locator('.ts-control').click();
    await page.keyboard.type('Gil Cabrera', { delay: 30 });
    await page.waitForTimeout(700);
    await page.locator('.ts-dropdown .option').first().click();
    await page.waitForTimeout(400);
    await page.locator('.type-radio-label', { hasText: 'Retraso' }).click();
    await page.waitForTimeout(300);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await page.goto(`${baseUrl}/notas`);
    await page.waitForLoadState('networkidle');
    await page.locator('text=Listado de estudiantes').first().click();
    await page.waitForLoadState('networkidle');
    await page.locator('label', { hasText: 'Retraso a primera hora' }).first().click();
    await page.waitForTimeout(700);
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notas/notas-estudiantes.png` });

    // «Registrar parte» abre el formulario con estudiante y grupo ya fijados.
    await page.locator('a:has-text("Registrar parte")').first().click();
    await page.waitForLoadState('networkidle');
    await page.fill('#occurred_at', `${process.env.SHOTS_TODAY ?? new Date().toISOString().slice(0, 10)}T10:20`);
    await page.locator('#location-select').locator('..').locator('.ts-control').click();
    await page.locator('.ts-dropdown:visible .option[data-selectable]').first().click();
    await page.locator('input[name="behaviors[]"]').first().check();
    await fillQuill(page, '#description', 'Tres retrasos a primera hora en la misma semana sin justificar.');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notas/parte-notas-desactivadas.png` });
    await page.close();
}

await browser.close();
console.log('Capturas guardadas en', root);
