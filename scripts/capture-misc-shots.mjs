/**
 * Captura las pantallas del manual no cubiertas por los demás scripts:
 * inicio, listado y formulario de partes, catálogos de conductas/ubicaciones
 * y estadísticas por grupo.
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos sembrados
 * (fixtures + tmp:seed-shots).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

for (const dir of ['partes', 'informes']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

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

// ── Vistas de docente (beatriz.alonso, tutora con partes pendientes) ─────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'beatriz.alonso', 'ejemplo');

    await page.goto(`${baseUrl}/`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/inicio.png` });

    await page.goto(`${baseUrl}/partes/nuevo`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/partes/nuevo-parte-vacio.png`, fullPage: true });

    // Selector de alumnado con resultados desplegados
    const studentsControl = page.locator('#students-select + .ts-wrapper .ts-control, .ts-wrapper:has(#students-select) .ts-control').first();
    await studentsControl.click();
    await page.keyboard.type('gar', { delay: 60 });
    await page.waitForSelector('.ts-dropdown .option, .ts-dropdown [data-selectable]', { timeout: 5000 });
    await page.screenshot({ path: `${root}/partes/nuevo-parte-selector-alumnado.png` });

    await page.close();
}

// ── Vistas de dirección (carmen.diaz) ────────────────────────────────────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'carmen.diaz', 'ejemplo');

    const centreHref = await page.locator('a[href*="/centro/"]').first().getAttribute('href');
    const centreId   = centreHref?.match(/\/centro\/([0-9a-f-]{36})/)?.[1];
    if (!centreId) {
        throw new Error(`No se pudo extraer centreId. href encontrado: ${centreHref}`);
    }

    await page.goto(`${baseUrl}/partes`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/partes/partes-listado.png` });

    // Edición de un parte: primer parte del listado
    const parteHref = await page.locator('a[href^="/partes/"]:not([href*="nuevo"])').first().getAttribute('href');
    await page.goto(`${baseUrl}${parteHref}/editar`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/partes/parte-editar.png`, fullPage: true });

    await page.goto(`${baseUrl}/centro/${centreId}/conductas`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/partes/admin-conductas.png`, fullPage: true });

    await page.goto(`${baseUrl}/centro/${centreId}/ubicaciones`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/partes/admin-ubicaciones.png`, fullPage: true });

    await page.goto(`${baseUrl}/centro/${centreId}/informes/estadisticas-grupo?from=2025-09-15&to=2026-07-15`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/informes/informes-estadisticas-grupo.png`, fullPage: true });

    await page.close();
}

await browser.close();
console.log('Capturas guardadas en', root);
