import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const centreId = process.env.SHOTS_CENTRE_ID;
const imgRoot = '/Users/lrlopez/Documents/Proyectos/gestconv-plus/docs/manual/img';

const browser = await chromium.launch();
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 } });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

await goto(`${baseUrl}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar();
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

// Hub: check new "Cursos académicos" card is present
await goto(`${baseUrl}/centro/${centreId}`);
await page.screenshot({ path: `${imgRoot}/centro/centro-hub-check.png` });
console.log('OK centro-hub-check.png');

// Cursos académicos page
await goto(`${baseUrl}/centro/${centreId}/cursos`);
await page.screenshot({ path: `${imgRoot}/centro/centro-cursos.png` });
console.log('OK centro-cursos.png');

await browser.close();
console.log('Listo.');
