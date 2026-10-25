/**
 * Captura las pantallas de los colores de grupo y de los eventos asociados a grupos para el manual:
 *  · calendario/evento-formulario-sin-grupos.png — formulario de evento sin grupos: no hay nada que elegir
 *  · calendario/evento-formulario.png            — con un grupo elegido aparecen «General» y «Restringido a grupos»
 *  · centro/centro-oferta-color.png              — color de un grupo (personalizado, con el botón para volver al calculado)
 *  · calendario/calendario.png, calendario-dia.png, calendario-tablon.png y guardias/guardias-tramo.png —
 *    con los colores de grupo y el evento dentro de su tramo horario
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver
 * scripts/seed/README.md) y una BD DESECHABLE con fixtures y `tmp:seed-shots`. Crea el evento «Charla sobre el
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

// ── Evento: el formulario sin grupos y con un grupo elegido ───────────────────────────────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');

await goto(admin, `${baseUrl}/eventos/nuevo?date=${today}`);
await admin.fill('#start_time', '09:30');
await admin.fill('#end_time', '10:15');
await admin.fill('#name', 'Charla sobre el acoso escolar');
await admin.fill('#description', 'Charla de prevención para el alumnado de 1º ESO A, a cargo de la orientadora.');
expect(await admin.locator('[data-event-scope-target="scopeBlock"]').isHidden(), 'sin grupos no debe verse la visibilidad');
await admin.locator('form').screenshot({ path: `${root}/calendario/evento-formulario-sin-grupos.png` });

await admin.locator('.ts-wrapper').click();
await admin.locator('.ts-dropdown .option', { hasText: '1ºESO A' }).first().click();
await admin.keyboard.press('Escape');
await admin.waitForTimeout(200);
expect(await admin.locator('[data-event-scope-target="scopeBlock"]').isVisible(), 'con un grupo debe verse la visibilidad');
await admin.locator('input[name="scope"][value="general"]').check();
await admin.locator('form').screenshot({ path: `${root}/calendario/evento-formulario.png` });

await admin.click('button[type="submit"]');
await admin.waitForLoadState('networkidle');

// ── Oferta formativa: color de un grupo ───────────────────────────────────────────────────────────────
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
expect(await director.locator('button[data-live-action-param="resetGroupColor"]').isVisible(), 'debe verse el botón para volver al color por defecto');
const card = director.locator('div.overflow-hidden', { has: director.locator('#group-color') }).last();
await card.screenshot({ path: `${root}/centro/centro-oferta-color.png` });

// ── Calendario, vista de día y guardias con los colores y el evento en su tramo ───────────────────────
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
await board.screenshot({ path: `${root}/calendario/calendario-tablon.png` });

await browser.close();
console.log('Capturas de colores de grupo y eventos generadas.');
