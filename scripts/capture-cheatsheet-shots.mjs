/**
 * Captura las capturas móviles de las 6 fichas rápidas (docs/cheatsheets/img/).
 *
 * Mismo patrón que scripts/capture-tutorial-shots.mjs y capture-misc-shots.mjs (servidor ya
 * arrancado en SHOTS_BASE_URL, datos sembrados), pero con el viewport móvil de Playwright
 * (devices['iPhone 13']) en vez del viewport de escritorio 1280×900 que usan los scripts del
 * manual — la app es una PWA pensada también para el móvil.
 *
 * Requiere, además de fixtures + tmp:seed-shots (partes/sanciones, ver
 * scripts/capture-misc-shots.mjs), datos que AppFixtures tampoco crea: alguna Absence con
 * actividad y adjuntos, una SanctionTask pendiente para roberto.guerrero, y un TimeSlot con
 * guardias asignadas a un docente para hoy — sembrar con un comando de consola desechable, igual
 * que el resto de datos de demostración (ver memoria project-screenshot-workflow). Para «Editar
 * los datos de contacto» no hace falta sembrar nada más: AppFixtures ya asigna a
 * roberto.guerrero como tutor del primer grupo con alumnado, y el propio script rellena el
 * formulario (los campos empiezan vacíos).
 */
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';

mkdirSync(root, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const iphone  = devices['iPhone 13'];

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
        await page.click('button:has-text("IES Ada Lovelace")');
        await page.waitForLoadState('networkidle');
    }
    await hideToolbar(page);
}

async function fillQuill(page, mountSelector, text) {
    const editor = page.locator(`${mountSelector} .ql-editor`);
    await editor.click();
    await editor.press('ControlOrMeta+a');
    await editor.press('Backspace');
    await editor.type(text, { delay: 5 });
}

// ── Registrar un parte (roberto.guerrero) ───────────────────────────────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/partes/nuevo`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-parte-1.png` });

    const studentsControl = page.locator('#students-select').locator('..').locator('.ts-control');
    await studentsControl.click();
    await page.keyboard.type('Rodríguez Navarro', { delay: 30 });
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${root}/registrar-parte-2.png` });
    await page.locator('.ts-dropdown .option').first().click();
    await page.waitForTimeout(300);

    const locationControl = page.locator('#location-select').locator('..').locator('.ts-control');
    await locationControl.click();
    await page.locator('.ts-dropdown:visible .option[data-selectable]').first().click();
    await page.waitForTimeout(200);
    await page.locator('input[name="behaviors[]"]').first().check();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-parte-3.png` });

    await fillQuill(page, '#description', 'El alumno interrumpió reiteradamente la clase y no atendió a las indicaciones del profesorado.');
    await page.click('input[name="expelled_from_class"][value="yes"]');
    await page.waitForTimeout(200);
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-parte-4.png` });

    await page.locator('button[type="submit"]').scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-parte-5.png` });

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await page.close();
}

// ── Notificar un parte (beatriz.alonso) ──────────────────────────────────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'beatriz.alonso', 'ejemplo');

    await page.goto(`${baseUrl}/notificaciones`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notificar-parte-1.png` });

    // El botón de la campana del encabezado incluye un enlace oculto con el mismo href que la
    // fila de la lista (visible); ":visible" descarta ese duplicado del desplegable de campana.
    const registrarLink = page.locator('a[href*="/notificaciones/partes/"]:not([href*="estudiante"]):visible').first();
    const registrarHref = await registrarLink.getAttribute('href');
    await registrarLink.scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notificar-parte-2.png` });

    await page.goto(`${baseUrl}${registrarHref}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.selectOption('#method_id', { index: 1 });
    await page.fill('input[name="occurred_at"], input[type="datetime-local"]', new Date().toISOString().slice(0, 16));
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notificar-parte-3.png` });

    await page.locator('button[type="submit"]').scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notificar-parte-4.png` });

    await page.close();
}

// ── Registrar una ausencia (roberto.guerrero) ────────────────────────────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/ausencias`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.locator('a:has-text("Nueva ausencia")').scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-ausencia-1.png` });

    await page.goto(`${baseUrl}/ausencias/nuevo`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    const today = new Date().toISOString().slice(0, 10);
    await page.fill('#start_date', today);
    await page.fill('#end_date', today);
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-ausencia-2.png` });

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    const newActivityHref = await page.locator('a:has-text("Nueva actividad")').first().getAttribute('href');
    await page.locator('a:has-text("Nueva actividad")').first().scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-ausencia-3.png` });

    await page.goto(`${baseUrl}${newActivityHref}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.selectOption('#time_slot_id', { index: 1 });
    const firstSubject = page.locator('input[name="subjects[]"]').first();
    if (await firstSubject.count()) {
        await firstSubject.check();
    }
    await fillQuill(page, '#description', 'Ejercicios del tema 4, páginas 32 y 33. Corregir en la próxima clase.');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-ausencia-4.png` });

    await page.locator('#attachments').scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/registrar-ausencia-5.png` });

    await page.close();
}

// ── Cumplimentar tareas para una sanción (roberto.guerrero) ─────────────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/tareas-de-sancion`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/tareas-sancion-1.png` });

    const taskHref = await page.locator('a[href*="/tareas/"]').first().getAttribute('href');
    await page.goto(`${baseUrl}${taskHref}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await fillQuill(page, '#description', 'Resumen del tema 5 y ejercicios 1 a 6, para entregar al reincorporarse.');
    await hideToolbar(page);

    // La página es corta y el bloque de adjuntos ya está en el primer pantallazo, así que un
    // scrollIntoViewIfNeeded no cambia nada entre las capturas 2 y 3 (mismo problema que en
    // «Mis guardias»): se recorta cada bloque con locator().screenshot() en su lugar.
    await page.locator('#description').locator('xpath=../..')
        .screenshot({ path: `${root}/tareas-sancion-2.png` });

    await page.locator('#attachments').locator('xpath=..')
        .screenshot({ path: `${root}/tareas-sancion-3.png` });

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/tareas-sancion-4.png` });

    await page.close();
}

// ── La sección «Mis guardias» (docente con guardia asignada hoy) ────────────
// Capturas de elementos concretos (no de viewport completo): la página es corta y todos los
// bloques caben en el primer pantallazo, así que un scrollIntoViewIfNeeded no cambia nada entre
// capturas — se recorta cada bloque con locator().screenshot() en su lugar.
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/guardias`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.locator('div.flex.items-start.justify-between.gap-4.flex-wrap').first()
        .screenshot({ path: `${root}/mis-guardias-1.png` });

    await page.locator('h3', { hasText: 'Profesorado de guardia' }).first()
        .locator('xpath=..')
        .screenshot({ path: `${root}/mis-guardias-2.png` });

    await page.locator('h3', { hasText: 'Ausencias' }).first()
        .locator('xpath=../..')
        .screenshot({ path: `${root}/mis-guardias-3.png` });

    await page.locator('#sanctioned-students')
        .screenshot({ path: `${root}/mis-guardias-4.png` });

    await page.close();
}

// ── Editar los datos de contacto de un estudiante (roberto.guerrero, tutor de grupo) ────────
{
    const page = await browser.newPage({ ...iphone, locale: 'es-ES' });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/mi-tutoria`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    const viewProfileLink = page.locator('a:has-text("Ver ficha")').first();
    await viewProfileLink.scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/editar-contacto-1.png` });

    await viewProfileLink.click();
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    // El formulario no tiene id en los campos, solo name (a diferencia del resto de formularios de
    // la app), así que se seleccionan por name.
    const contactHeading = page.locator('h2', { hasText: 'Datos de contacto' }).first();
    await contactHeading.scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await contactHeading.locator('xpath=../..').screenshot({ path: `${root}/editar-contacto-2.png` });

    await page.click('button:has-text("Editar contacto")');
    await page.waitForTimeout(200);
    await hideToolbar(page);

    await page.fill('input[name="tutorName1"]', 'María Fernández Ruiz');
    await page.fill('input[name="tutorEmail1"]', 'maria.fernandez@example.com');
    await page.fill('input[name="tutorName2"]', 'José Fernández Ruiz');
    await page.fill('input[name="tutorEmail2"]', 'jose.fernandez@example.com');
    await hideToolbar(page);
    await page.locator('dialog').screenshot({ path: `${root}/editar-contacto-3.png` });

    await page.fill('input[name="contactPhone1"]', '600 111 222');
    await page.fill('input[name="contactPhone1Notes"]', 'Madre, preferible por la tarde');
    await page.fill('input[name="contactPhone2"]', '600 333 444');
    await page.fill('input[name="contactPhone2Notes"]', 'Padre');
    await page.fill('textarea[name="details"]', 'Recogida autorizada solo a los tutores legales.');
    await hideToolbar(page);
    await page.locator('dialog').screenshot({ path: `${root}/editar-contacto-4.png` });

    await page.click('dialog button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    const savedHeading = page.locator('h2', { hasText: 'Datos de contacto' }).first();
    await savedHeading.scrollIntoViewIfNeeded();
    await hideToolbar(page);
    await savedHeading.locator('xpath=../..').screenshot({ path: `${root}/editar-contacto-5.png` });

    await page.close();
}

await browser.close();
console.log('Capturas guardadas en', root);
