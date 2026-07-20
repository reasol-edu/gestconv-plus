/**
 * Captura las pantallas del manual sin capturas de las secciones Ausencias, Guardias y Tareas de
 * sanción (viewport de escritorio 1280×900, mismo patrón que scripts/capture-tutorial-shots.mjs y
 * capture-misc-shots.mjs).
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos sembrados: fixtures +
 * app:tmp-seed-cheatsheets (crea el tramo horario con guardia de roberto.guerrero, su ausencia con
 * actividad y adjunto ejemplo, y su tarea de sanción pendiente — ver la memoria
 * project-screenshot-workflow).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

for (const dir of ['ausencias', 'guardias', 'tareas-sancion']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function login(page, username, password) {
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    if (page.url().includes('/seleccion/centro')) {
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }
    await hideToolbar(page);
}

async function fillQuill(page, mountSelector, text) {
    const editor = page.locator(`${mountSelector} .ql-editor`);
    await editor.click();
    await editor.type(text, { delay: 5 });
}

const pdfBuffer = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>');

const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
await login(page, 'roberto.guerrero', 'ejemplo');

// ── Ausencias: nueva ausencia, nueva actividad y detalle con adjunto ────────
{
    await page.goto(`${baseUrl}/ausencias/nuevo`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    const today = new Date().toISOString().slice(0, 10);
    await page.fill('#start_date', today);
    await page.fill('#end_date', today);
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/ausencias/ausencia-nueva.png` });

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.click('a:has-text("Nueva actividad")');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.selectOption('#time_slot_id', { index: 1 });
    const firstSubject = page.locator('input[name="subjects[]"]').first();
    if (await firstSubject.count()) {
        await firstSubject.check();
    }
    await fillQuill(page, '#description', 'Ejercicios del tema 4, páginas 32 y 33. Corregir en la próxima clase.');
    await page.setInputFiles('#attachments', { name: 'correccion-tema-4.pdf', mimeType: 'application/pdf', buffer: pdfBuffer });
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/ausencias/actividad-nueva.png`, fullPage: true });

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/ausencias/ausencia-detalle.png` });
}

// ── Guardias: bloque de un tramo y alumnado sancionado con tarea desplegada ─
{
    await page.goto(`${baseUrl}/guardias`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.locator('section[id^="slot-"]').first().screenshot({ path: `${root}/guardias/guardias-tramo.png` });

    await page.locator('#sanctioned-students summary').first().click();
    await page.waitForTimeout(200);
    await hideToolbar(page);
    await page.locator('#sanctioned-students').screenshot({ path: `${root}/guardias/guardias-alumnado-sancionado.png` });
}

// ── Tareas de sanción: listado y formulario de una tarea ────────────────────
{
    await page.goto(`${baseUrl}/tareas-de-sancion`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/tareas-sancion/tareas-listado.png` });

    await page.click('table a[href*="/tareas/"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await fillQuill(page, '#description', 'Resumen del tema 5 y ejercicios 1 a 6, para entregar al reincorporarse.');
    await page.setInputFiles('#attachments', { name: 'resumen-tema-5.pdf', mimeType: 'application/pdf', buffer: pdfBuffer });
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/tareas-sancion/tareas-formulario.png`, fullPage: true });
}

await page.close();
await browser.close();
console.log('Capturas guardadas en', root);
