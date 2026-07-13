/**
 * Captura la pantalla de vista previa de importación de estudiantes,
 * incluyendo el bloque de grupos con nombre ambiguo (conflicto de curso).
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos de fixture cargados.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

mkdirSync(`${root}/centro`, { recursive: true });

const browser = await chromium.launch();

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

// CSV con grupo "A" apareciendo bajo dos cursos distintos → activa el bloque de conflicto
const conflictCsv = [
    '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
    '"","SHOT-C001","García","López","Ana","A","1º ESO"',
    '"","SHOT-C002","Martínez","Pérez","Carlos","A","2º ESO"',
    '"","SHOT-C003","Sánchez","Ruiz","Elena","B","1º ESO"',
].join('\n');

const tmpCsv = join(tmpdir(), 'gestconv_conflict_preview.csv');
writeFileSync(tmpCsv, conflictCsv, 'utf8');

{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await login(page, 'carmen.diaz', 'ejemplo');

    // Extraemos el centreId de cualquier enlace /centro/{uuid} de la página de inicio
    const centreHref = await page.locator('a[href*="/centro/"]').first().getAttribute('href');
    const centreId   = centreHref?.match(/\/centro\/([0-9a-f-]{36})/)?.[1];
    if (!centreId) {
        throw new Error(`No se pudo extraer centreId. href encontrado: ${centreHref}`);
    }

    await page.goto(`${baseUrl}/centro/${centreId}/estudiantes/importar`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    // Subir el CSV con conflicto
    const fileInput = page.locator('input[type="file"]');
    await fileInput.setInputFiles(tmpCsv);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.screenshot({
        path: `${root}/centro/centro-importar-preview.png`,
        fullPage: true,
    });

    await page.close();
}

unlinkSync(tmpCsv);
await browser.close();
console.log('Captura completada: docs/manual/img/centro/centro-importar-preview.png');
