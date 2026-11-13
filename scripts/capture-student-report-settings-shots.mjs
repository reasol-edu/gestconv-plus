/**
 * Captura de los ajustes del informe PDF del estudiante (encabezado izquierdo y derecho, margen y pie):
 *
 *  · ajustes/ajustes-informe-estudiante.png — Ajustes › Personalización de informes, filas del informe del estudiante
 *
 * Necesita un servidor en SHOTS_BASE_URL con las fixtures de demostración y SHOTS_CENTRE_ID con el id del
 * «IES Ada Lovelace». Se entra como `rafael.exposito` (contraseña `ejemplo`, administrador de centro y global).
 * La imagen se recorta a las filas de ese informe (menos de 1000 px: ver skills/screenshots.md).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8760';
const centreId = process.env.SHOTS_CENTRE_ID;
const root     = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
if (!centreId) throw new Error('Define SHOTS_CENTRE_ID');
mkdirSync(`${root}/ajustes`, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 1400 }, locale: 'es-ES' });

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'rafael.exposito');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.locator('button').filter({ hasText: /IES Ada/ }).first().click();
    await page.waitForLoadState('networkidle');
}

await page.goto(`${baseUrl}/centro/${centreId}/ajustes`);
await page.waitForLoadState('networkidle');
// Sin la barra de depuración y con todo lo fijo (cabecera, buscador de ajustes) suelto para que no tape las filas.
await page.addStyleTag({ content: 'div[id^="sfwdt"]{display:none!important} .sticky,.fixed{position:static!important}' });

const first = page.locator('#setting-richtext-reports_student_header_left');
const last  = page.locator('#setting-richtext-reports_student_footer');
await first.scrollIntoViewIfNeeded();

// Los editores se muestran vacíos hasta que se personalizan: se rellenan con ejemplos de marcadores.
for (const [key, text] of [
    ['student_header_left', 'Informe de convivencia · {student_name} ({group_name})'],
    ['student_header_right', '{centre_name} · {academic_year}'],
    ['student_footer', 'En {city} a {current_day} de {current_month_name} de {current_year}'],
]) {
    const editor = page.locator(`#setting-richtext-reports_${key} .ql-editor`);
    await editor.click();
    await editor.type(text);
}
await page.locator('h1, h2').first().click(); // saca el foco del último editor
await page.waitForTimeout(500);

// Desde el título del encabezado izquierdo hasta el final del editor del pie.
const top    = await first.evaluate((el) => el.closest('li, div[id^="setting-"], div')?.parentElement?.getBoundingClientRect().top ?? el.getBoundingClientRect().top - 90);
const bottom = (await last.boundingBox()).y + (await last.boundingBox()).height + 24;
const box    = await first.evaluate((el) => {
    // El título («Encabezado de informe del estudiante (izquierda)») queda justo encima del editor.
    const row = el.closest('li') ?? el.parentElement;
    const r = row.getBoundingClientRect();
    return { y: r.top + window.scrollY };
});
const y      = box.y;
const height = Math.min(bottom + (await page.evaluate(() => window.scrollY)) - y, 1000);
await page.screenshot({ path: `${root}/ajustes/ajustes-informe-estudiante.png`, fullPage: true, clip: { x: 440, y, width: 660, height } });
console.log(`OK ajustes-informe-estudiante.png (${Math.round(height)} px)`, { top: Math.round(top) });

await browser.close();
