/**
 * Captura las imágenes móviles de la ficha rápida «Registrar una incidencia» (docs/cheatsheets/img/registrar-incidencia-*.png):
 *  1. la sección Incidencias con el botón «Nueva incidencia»
 *  2. el tipo, que se busca escribiendo en una lista jerárquica
 *  3. la dependencia y la descripción
 *  4. los adjuntos y el botón para registrarla
 *
 * Mismo patrón que scripts/capture-cheatsheet-shots.mjs (viewport móvil, servidor ya arrancado en SHOTS_BASE_URL con el
 * reloj de la demo, BD DESECHABLE con fixtures, `tmp:seed-shots` y `tmp:seed-issues`). No guarda ninguna incidencia, así que
 * se puede repetir sobre la misma BD.
 */
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';

mkdirSync(root, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ ...devices['iPhone 13'], locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'roberto.guerrero');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// 1. La sección y su botón.
await page.goto(`${baseUrl}/incidencias`);
await page.waitForLoadState('networkidle');
await page.waitForTimeout(600);
await hideToolbar();
expect(await page.locator('a[href="/incidencias/nueva"]').count() >= 1, 'debe haber un botón para registrar');
await page.screenshot({ path: `${root}/registrar-incidencia-1.png`, clip: { x: 0, y: 0, width: 390, height: 330 } });

// 2. El tipo: se busca escribiendo.
await page.goto(`${baseUrl}/incidencias/nueva`);
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.locator('#type ~ .ts-wrapper .ts-control').click();
await page.keyboard.type('elec', { delay: 30 });
await page.waitForTimeout(500);
await hideToolbar();
const typeBox = await page.locator('#type ~ .ts-wrapper').boundingBox();
await page.screenshot({ path: `${root}/registrar-incidencia-2.png`, clip: { x: 0, y: Math.max(0, typeBox.y - 56), width: 390, height: 210 } });
await page.keyboard.press('Enter');
await page.waitForTimeout(300);

// 3. La dependencia (opcional) y la descripción.
await page.locator('#location ~ .ts-wrapper .ts-control').click();
await page.keyboard.type('aula 12', { delay: 30 });
await page.waitForTimeout(400);
await page.keyboard.press('Enter');
await page.waitForTimeout(300);
const editor = page.locator('.ql-editor').first();
await editor.click();
await editor.type('Se ha fundido la luz del aula 12: los fluorescentes de la ventana no encienden.', { delay: 5 });
await hideToolbar();
const locationBox = await page.locator('#location ~ .ts-wrapper').boundingBox();
const editorBox   = await page.locator('.ql-container').first().boundingBox();
const scrollY     = await page.evaluate(() => window.scrollY);
await page.screenshot({
    path: `${root}/registrar-incidencia-3.png`,
    fullPage: true,
    clip: { x: 0, y: locationBox.y + scrollY - 40, width: 390, height: editorBox.y + editorBox.height - locationBox.y + 80 },
});

// 4. Los adjuntos (opcionales) y el botón de registrar.
await page.locator('#attachments').scrollIntoViewIfNeeded();
await hideToolbar();
const dropzone = await page.locator('#attachments').locator('xpath=ancestor::div[contains(@class,"relative") or contains(@class,"space-y") or contains(@class,"border-dashed")][1]').boundingBox();
const submit   = await page.locator('form button[type="submit"]').last().boundingBox();
const top      = await page.evaluate(() => window.scrollY);
await page.screenshot({
    path: `${root}/registrar-incidencia-4.png`,
    fullPage: true,
    clip: { x: 0, y: Math.max(0, (dropzone?.y ?? submit.y - 200) + top - 36), width: 390, height: submit.y + submit.height - (dropzone?.y ?? submit.y - 200) + 72 },
});

await browser.close();
console.log('Imágenes de la ficha «Registrar una incidencia» capturadas.');
