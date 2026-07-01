import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir  = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/calendario';

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

await page.goto(`${baseUrl}/calendario`);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${outDir}/calendario.png` });

await page.goto(`${baseUrl}/calendario/tablon`);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${outDir}/calendario-tablon.png` });

await browser.close();
console.log('Screenshots saved to', outDir);
