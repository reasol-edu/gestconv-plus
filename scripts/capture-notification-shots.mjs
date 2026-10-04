import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/notificaciones';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

const today = process.env.SHOTS_TODAY ?? new Date().toISOString().slice(0, 10);

// El campo «Fecha y hora» se rellena con el reloj real del navegador/servidor; se fija al día lectivo de la demo.
async function setPerformedAt() {
    const input = page.locator('#performed_at');
    if (await input.count() > 0) await input.fill(`${today}T10:15`);
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

// Notificación en bloque desde el listado «Estudiantes con notificaciones pendientes»: un botón por
// tipo (partes / sanciones) en la fila del estudiante. Se capturan antes de registrar nada.
for (const [label, file] of [['Notificar partes', 'notificaciones-notificar-partes.png'], ['Notificar sanciones', 'notificaciones-notificar-sanciones.png']]) {
    const href = await page.locator(`a:has-text("${label}")`).first().getAttribute('href');
    await page.goto(`${baseUrl}${href}`);
    await page.waitForLoadState('networkidle');
    await setPerformedAt();
    await hideToolbar();
    await page.screenshot({ path: `${outDir}/${file}` });
    await page.goBack();
    await page.waitForLoadState('networkidle');
}

// La página por parte (con historial) se enlaza desde la campana; tomamos su href directamente
const registrarHref = await page
    .locator('a[href*="/notificaciones/partes/"]:not([href*="estudiante"])')
    .first()
    .getAttribute('href');
await page.goto(`${baseUrl}${registrarHref}`);
await page.waitForLoadState('networkidle');
await setPerformedAt();
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

await setPerformedAt();
await page.locator('text=Historial de comunicaciones').scrollIntoViewIfNeeded();
await page.screenshot({ path: `${outDir}/notificaciones-registrar-parte-historial.png` });

const reportLink = await page.locator('a:has-text("Ver parte completo")').getAttribute('href');
await page.goto(`${baseUrl}${reportLink}`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/parte-badge-notificado.png` });

await browser.close();
console.log('Screenshots saved to', outDir);
