import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/notificaciones';

const browser = await chromium.launch();
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 } });

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
await page.screenshot({ path: `${outDir}/notificaciones-pendientes.png` });

await page.click('a[href*="/notificaciones/partes/"]');
await page.waitForURL(/\/notificaciones\/partes\/.+\/registrar/);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${outDir}/notificaciones-registrar-parte.png` });

await page.selectOption('select[name="method_id"]', { index: 1 });
await page.fill('textarea[name="description"]', 'Llamada telefónica a la familia. Se informa de los hechos y se acuerda seguimiento.');
await page.click('button:has-text("Registrar comunicación")');
await page.waitForLoadState('networkidle');

await page.locator('text=Historial de comunicaciones').scrollIntoViewIfNeeded();
await page.screenshot({ path: `${outDir}/notificaciones-registrar-parte-historial.png` });

const reportLink = await page.locator('a:has-text("Ver parte completo")').getAttribute('href');
await page.goto(`${baseUrl}${reportLink}`);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${outDir}/parte-badge-notificado.png` });

await browser.close();
console.log('Screenshots saved to', outDir);
