/**
 * Captura las pantallas de las actividades de centro, los colores de grupo y el modo tablón para el manual:
 *  · calendario/actividad-formulario-sin-grupos.png — formulario de actividad sin grupos: no hay nada que elegir
 *  · calendario/actividad-formulario.png            — estado (publicada o borrador) y, con un grupo elegido, la visibilidad y los estudiantes
 *  · centro/centro-oferta-color.png                 — color de un grupo (personalizado, con el botón para volver al calculado)
 *  · centro/centro-tipos-actividad.png              — catálogo de tipos de actividad
 *  · centro/centro-perfiles.png                     — perfiles, con la coordinación de actividades
 *  · ausencias/actividad-nueva.png                  — formulario de una tarea de ausencia, con el aula
 *  · calendario/calendario.png, calendario-dia.png, guardias/guardias-tramo.png
 *  · calendario/calendario-tablon.png y calendario-tablon-proximos-dias.png — las dos pantallas del tablón
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver
 * scripts/seed/README.md) y una BD DESECHABLE con fixtures y `tmp:seed-shots`. También hace falta
 * SHOTS_ABSENCE_ID: el id (UUID) de la ausencia de pedro.fernandez que siembra `tmp:seed-shots`. Crea la actividad «Charla sobre el
 * acoso escolar» (hoy, 09:30–10:15, dentro de la 2ª hora) desde el propio formulario y personaliza el color de
 * 1º ESO A desde la oferta formativa, así que hay que restaurar la BD antes de repetirlo.
 */
import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const today   = process.env.SHOTS_TODAY ?? '2026-10-05';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function newPage() {
    return (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(page, url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
}

async function login(page, username, password) {
    await goto(page, `${baseUrl}/login`);
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

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

// ── Actividad: el formulario sin grupos y con un grupo elegido ────────────────────────────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');
// Un viewport alto evita que la captura de un elemento largo se haga por tramos (sale una banda en blanco).
await admin.setViewportSize({ width: 1280, height: 1600 });

/** Captura desde la tarjeta `from` hasta la tarjeta `to` (índices de las tarjetas del formulario). */
async function shootCards(page, from, to, path) {
    const cards = page.locator('form > div.rounded-2xl');
    const first = await cards.nth(from).boundingBox();
    const last  = await cards.nth(to).boundingBox();
    await page.screenshot({ path, clip: { x: first.x, y: first.y, width: first.width, height: last.y + last.height - first.y } });
}

await goto(admin, `${baseUrl}/actividades/nuevo?date=${today}`);
await admin.fill('#start_time', '09:30');
await admin.fill('#end_time', '10:15');
await admin.fill('#name', 'Charla sobre el acoso escolar');
await admin.fill('#description', 'Charla de prevención para el alumnado de 1º ESO A, a cargo de la orientadora.');
await admin.fill('#location', 'Salón de actos');
await admin.fill('#organizing_department', 'Orientación');
// El coordinador se elige en un Tom Select: se fija el valor por la API del propio componente.
await admin.evaluate(() => {
    const select = document.getElementById('coordinator');
    const option = Array.from(select.options).find((o) => o.textContent.includes('Alonso'));
    select.tomselect.setValue(option.value);
});
expect(await admin.locator('[data-event-scope-target="scopeBlock"]').isHidden(), 'sin grupos no debe verse la visibilidad');
await shootCards(admin, 3, 3, `${root}/calendario/actividad-formulario-sin-grupos.png`);

// Hay otro Tom Select (estudiantes) más abajo: se elige el del campo de grupos por su vecindad.
await admin.locator('#groups ~ .ts-wrapper').click();
await admin.locator('.ts-dropdown .option', { hasText: '1ºESO A' }).first().click();
await admin.keyboard.press('Escape');
await admin.waitForTimeout(200);
expect(await admin.locator('[data-event-scope-target="scopeBlock"]').isVisible(), 'con un grupo debe verse la visibilidad');
await admin.locator('input[name="scope"][value="general"]').check();
expect(await admin.locator('#students-field').isVisible(), 'con un grupo debe verse el campo de estudiantes');
await shootCards(admin, 2, 3, `${root}/calendario/actividad-formulario.png`);

await admin.click('button[type="submit"]');
await admin.waitForLoadState('networkidle');
await admin.setViewportSize({ width: 1280, height: 900 });

// ── Oferta formativa: color de un grupo; catálogo de tipos y perfiles ─────────────────────────────────
const director = await newPage();
await login(director, 'carmen.diaz', 'ejemplo');
await director.click('text=Centro educativo');
await director.waitForLoadState('networkidle');
await director.click('text=Oferta formativa');
await director.waitForLoadState('networkidle');
await hideToolbar(director);
await director.locator('button[data-live-action-param="selectCourse"]', { hasText: '1º ESO' }).first().click();
await director.waitForTimeout(800);
await director.locator('button[data-live-action-param="selectGroup"]', { hasText: '1ºESO A' }).first().click();
await director.waitForTimeout(800);
await hideToolbar(director);

// La casilla «Color personalizado» muestra el selector, que parte del color calculado.
expect(await director.locator('#group-color').count() === 0, 'sin la casilla marcada no hay selector');
await director.locator('#group-custom-color').check();
await director.waitForSelector('#group-color');
await director.evaluate(() => {
    const input = document.getElementById('group-color');
    input.value = '#7c3aed';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
});
await director.waitForTimeout(300);
await director.locator('button[type="submit"]', { hasText: 'Guardar' }).first().click();
await director.waitForTimeout(1200);
await hideToolbar(director);
expect(await director.locator('#group-custom-color').isChecked(), 'la casilla debe seguir marcada tras guardar');
const card = director.locator('div.overflow-hidden', { has: director.locator('#group-color') }).last();
await card.screenshot({ path: `${root}/centro/centro-oferta-color.png` });

await director.click('text=Centro educativo');
await director.waitForLoadState('networkidle');
await director.click('text=Gestionar tipos de actividad');
await director.waitForLoadState('networkidle');
await hideToolbar(director);
expect(await director.locator('text=Extraescolar').first().isVisible(), 'deben verse los tipos por defecto');
await director.screenshot({ path: `${root}/centro/centro-tipos-actividad.png`, clip: { x: 0, y: 0, width: 1280, height: 620 } });

await director.click('text=Centro educativo');
await director.waitForLoadState('networkidle');
await director.click('text=Gestionar perfiles');
await director.waitForLoadState('networkidle');
await hideToolbar(director);
expect(await director.locator('select[name="activity_coordinators[]"]').count() === 1, 'debe verse el perfil de coordinación de actividades');
await director.screenshot({ path: `${root}/centro/centro-perfiles.png`, fullPage: true });

// ── Ausencias: la tarea de una ausencia, con el aula ──────────────────────────────────────────────────
const teacher = await newPage();
await login(teacher, 'pedro.fernandez', 'ejemplo');
// La ausencia de pedro.fernandez que siembra `tmp:seed-shots`: su id (UUID) se pasa en SHOTS_ABSENCE_ID.
expect(process.env.SHOTS_ABSENCE_ID, 'falta SHOTS_ABSENCE_ID (id de la ausencia de pedro.fernandez)');
await goto(teacher, `${baseUrl}/ausencias/${process.env.SHOTS_ABSENCE_ID}`);
await teacher.click('a:has-text("Nueva actividad")');
await teacher.waitForLoadState('networkidle');
await hideToolbar(teacher);
await teacher.selectOption('#time_slot_id', { index: 1 });
const firstSubject = teacher.locator('input[name="subjects[]"]').first();
if (await firstSubject.count()) {
    await firstSubject.check();
}
await teacher.fill('#classroom', '8');
const editor = teacher.locator('#description .ql-editor');
await editor.click();
await editor.type('Ejercicios del tema 4, páginas 32 y 33. Corregir en la próxima clase.', { delay: 5 });
await hideToolbar(teacher);
await teacher.screenshot({ path: `${root}/ausencias/actividad-nueva.png`, fullPage: true });

// ── Calendario, vista de día y guardias con los colores y la actividad en su tramo ───────────────────
await goto(admin, `${baseUrl}/calendario`);
await admin.screenshot({ path: `${root}/calendario/calendario.png` });

await goto(admin, `${baseUrl}/calendario/dia/${today}`);
await admin.screenshot({ path: `${root}/calendario/calendario-dia.png`, clip: { x: 0, y: 0, width: 1280, height: 900 } });

await goto(admin, `${baseUrl}/guardias`);
await admin.locator('section[id^="slot-"]', { hasText: 'Charla sobre el acoso escolar' }).first()
    .screenshot({ path: `${root}/guardias/guardias-tramo.png` });

// El tablón atrapa la sesión del navegador: va el último y en un contexto propio.
const board = await newPage();
await board.clock.setFixedTime(new Date(`${today}T09:30:00`));
await login(board, 'admin', 'admin');
await goto(board, `${baseUrl}/calendario/tablon`);
await board.waitForTimeout(500);
await board.screenshot({ path: `${root}/calendario/calendario-tablon.png` });

await board.click('[data-action="board#next"]');
await board.waitForTimeout(1200);
await board.screenshot({ path: `${root}/calendario/calendario-tablon-proximos-dias.png` });

await browser.close();
console.log('Capturas de actividades, colores de grupo y tablón generadas.');
