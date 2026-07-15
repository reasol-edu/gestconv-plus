/**
 * Captura los ajustes de notificación a familias y el catálogo de métodos
 * de comunicación del centro.
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con datos sembrados y el
 * id del centro en SHOTS_CENTRE_ID.
 */
import { chromium } from 'playwright';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const outDir   = process.env.SHOTS_OUT_DIR ?? 'docs/manual/img/notificaciones';
const centreId = process.env.SHOTS_CENTRE_ID;

if (!centreId) {
    throw new Error('Falta SHOTS_CENTRE_ID');
}

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'carmen.diaz');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');

if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// Ajustes: sección «Notificaciones a familias» (quién notifica partes y sanciones)
await page.goto(`${baseUrl}/centro/${centreId}/ajustes`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.locator('text=Notificaciones a familias')
    .evaluate(el => el.scrollIntoView({ block: 'start' }));
await page.waitForTimeout(300);
await page.screenshot({ path: `${outDir}/ajustes-notificaciones.png` });

// Catálogo de métodos de comunicación
await page.goto(`${baseUrl}/centro/${centreId}/metodos-comunicacion`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${outDir}/admin-metodos-comunicacion.png` });

await browser.close();
console.log('Admin screenshots saved to', outDir);
