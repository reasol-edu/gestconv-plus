import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/notificaciones';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'beatriz.alonso');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');

await page.waitForLoadState('networkidle');

if (page.url().includes('/seleccion/centro')) {
    await page.click('text=IES Ada Lovelace');
    await page.waitForLoadState('networkidle');
}

await page.goto(`${baseUrl}/notificaciones`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/notificaciones-pendientes.png` });

// La página por parte (con historial) se enlaza desde la campana; tomamos su href directamente
const registrarHref = await page
    .locator('a[href*="/notificaciones/partes/"]:not([href*="estudiante"])')
    .first()
    .getAttribute('href');
await page.goto(`${baseUrl}${registrarHref}`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/notificaciones-registrar-parte.png` });

await page.selectOption('select[name="method_id"]', { index: 1 });
await page.fill('textarea[name="description"]', 'Llamada telefónica a la familia. Se informa de los hechos y se acuerda seguimiento.');
await page.click('button:text-matches("Registrar comunicación|Notificar partes seleccionados")');
await page.waitForLoadState('networkidle');

// Tras registrar se redirige al índice; volvemos al parte para ver el historial
await page.goto(`${baseUrl}${registrarHref}`);
await page.waitForLoadState('networkidle');
await hideToolbar();

await page.locator('text=Historial de comunicaciones').scrollIntoViewIfNeeded();
await page.screenshot({ path: `${outDir}/notificaciones-registrar-parte-historial.png` });

const reportLink = await page.locator('a:has-text("Ver parte completo")').getAttribute('href');
await page.goto(`${baseUrl}${reportLink}`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/parte-badge-notificado.png` });

await browser.close();
console.log('Screenshots saved to', outDir);
