import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/notificaciones';

const browser = await chromium.launch();
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 } });

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');

if (page.url().includes('/seleccion/centro')) {
    await page.click('text=IES Ada Lovelace');
    await page.waitForLoadState('networkidle');
}

await page.goto(`${baseUrl}/centro/ajustes`);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${outDir}/ajustes-notificaciones-full.png`, fullPage: true });

await page.goto(`${baseUrl}/centro`);
await page.waitForLoadState('networkidle');
const centreHref = await page.locator('a:has-text("Métodos de comunicación")').first().getAttribute('href');
await page.goto(`${baseUrl}${centreHref}`);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${outDir}/admin-metodos-comunicacion.png` });

await browser.close();
console.log('Admin screenshots saved to', outDir);
