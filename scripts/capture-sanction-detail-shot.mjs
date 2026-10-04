/**
 * Captura el detalle de una sanción ya notificada y vigente, con su bloque «Tareas de sanción»
 * (manual, capítulo «Sanciones y comisión de convivencia»).
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos sembrados: una sanción notificada con
 * tareas generadas (alguna cumplimentada y otras pendientes) para el alumno SHOTS_SANCTION_STUDENT
 * (por defecto «García Lorente, Hugo»). Solo lee datos.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
const student = process.env.SHOTS_SANCTION_STUDENT ?? 'García Lorente, Hugo';

mkdirSync(`${root}/sanciones`, { recursive: true });

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

await page.goto(`${baseUrl}/sanciones`);
await page.waitForLoadState('networkidle');
await page.locator('tr', { hasText: student }).locator('a:has-text("Ver")').first().click();
await page.waitForLoadState('networkidle');
await hideToolbar();

// El detalle es largo: se desplaza hasta el bloque de tareas, dejando visibles las pastillas de estado
// de la cabecera por encima si caben; si no, manda el bloque de tareas.
await page.locator('h2', { hasText: 'Tareas de sanción' }).first().scrollIntoViewIfNeeded();
await page.evaluate(() => window.scrollBy(0, 220));
await page.waitForTimeout(150);
await hideToolbar();
await page.screenshot({ path: `${root}/sanciones/sanciones-detalle.png` });

await browser.close();
console.log('Captura guardada en', `${root}/sanciones/sanciones-detalle.png`);
