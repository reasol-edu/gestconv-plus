/**
 * Captura las pantallas del manual para la funcionalidad de notas diarias:
 * formulario de alta, tipo con historial resaltado, listado y catálogo de
 * tipos de nota, más el bloque de notas en la ficha del estudiante.
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos sembrados
 * (fixtures + `tmp:seed-daily-notes`, ver src/Command/TmpSeedDailyNotesCommand.php):
 * dos notas previas de tipo «Retraso» (umbral 3) para el mismo estudiante, para
 * que la tercera nota del formulario resalte en rojo y dispare el aviso de
 * parte, más una nota ya ignorada para mostrar el tratamiento visual atenuado.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

mkdirSync(`${root}/notas`, { recursive: true });

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

// ── Formulario de alta, tipo+historial, y ficha del estudiante (roberto.guerrero) ──
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/notas/nuevo`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notas/nueva-nota-vacio.png`, fullPage: true });

    const studentControl = page.locator('#student-select').locator('..').locator('.ts-control');
    await studentControl.click();
    await page.keyboard.type('Gil Cabrera', { delay: 30 });
    await page.waitForTimeout(700);
    await page.locator('.ts-dropdown .option').first().click();
    await page.waitForTimeout(500);

    await page.locator('.type-radio-label', { hasText: 'Retraso' }).click();
    await page.waitForTimeout(500);
    await hideToolbar(page);
    await page.locator('#type-radios').locator('xpath=..').screenshot({ path: `${root}/notas/nueva-nota-tipo-historial.png` });

    await page.close();
}

{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    // Listado: filtros de tutoría visibles y una fila ignorada mostrada en gris.
    await page.goto(`${baseUrl}/notas`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notas/notas-listado.png` });

    // Ficha del estudiante: bloque de notas diarias con estadísticas por tipo. Se busca a la
    // estudiante sembrada con dos notas de tipo «Retraso» (umbral ya alcanzado tras la nota de
    // scripts/capture-cheatsheet-shots.mjs) y una nota ignorada, para mostrar ambos casos a la vez.
    await page.goto(`${baseUrl}/notas`);
    await page.waitForLoadState('networkidle');
    await page.fill('input[type="search"]', 'Gil Cabrera');
    await page.waitForTimeout(600);
    const studentHref = await page.locator('a[href*="/alumnado/"]').first().getAttribute('href');
    await page.goto(`${baseUrl}${studentHref}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    const notesHeading = page.locator('h2', { hasText: 'Notas diarias' }).first();
    await notesHeading.scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await notesHeading.locator('xpath=../..').screenshot({ path: `${root}/notas/ficha-notas.png` });

    await page.close();
}

// ── Catálogo de tipos de nota (carmen.diaz, admin de centro) ────────────────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'carmen.diaz', 'ejemplo');

    const centreHref = await page.locator('a[href*="/centro/"]').first().getAttribute('href');
    const centreId   = centreHref?.match(/\/centro\/([0-9a-f-]{36})/)?.[1];
    if (!centreId) {
        throw new Error(`No se pudo extraer centreId. href encontrado: ${centreHref}`);
    }

    await page.goto(`${baseUrl}/centro/${centreId}/tipos-notas`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notas/admin-tipos-notas.png` });

    await page.close();
}

await browser.close();
console.log('Capturas guardadas en', root);
