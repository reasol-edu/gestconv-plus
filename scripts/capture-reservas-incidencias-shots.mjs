/**
 * Captura para el manual las incidencias de las reservas:
 *  · reservas/reservas-incidencia-registrar.png  — tarjeta «Incidencia» del docente de la reserva, con el texto escrito
 *  · reservas/reservas-incidencia-responder.png  — la misma tarjeta vista por la dirección, con el formulario de respuesta
 *  · reservas/reservas-incidencia-calendario.png — calendario con la marca de la incidencia en la reserva
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver
 * scripts/seed/README.md) y una BD DESECHABLE con fixtures y `tmp:seed-shots`. SÍ crea datos: registra una incidencia
 * en la reserva de «Aula de informática 1» de Roberto Guerrero del lunes, así que no se puede repetir sobre la misma BD.
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

async function shootCard(page, path) {
    const box = await page.locator('section[aria-labelledby="issue-title"]').boundingBox();
    await page.screenshot({ path, clip: { x: box.x - 16, y: box.y - 16, width: box.width + 32, height: box.height + 32 } });
}

// ── El docente registra la incidencia en su reserva ────────────────────────────────────────────────────
const teacher = await newPage();
await login(teacher, 'roberto.guerrero', 'ejemplo');
await goto(teacher, `${baseUrl}/reservas?date=${today}`);
const chip = teacher.locator('td a[href*="/reservas/"]', { hasText: 'Roberto Guerrero' }).filter({ hasText: 'Aula de informática 1' }).first();
const href = await chip.getAttribute('href');
await goto(teacher, `${baseUrl}${href}`);
expect(await teacher.locator('#issue-description').count() === 1, 'el docente debe ver el formulario de la incidencia');
await teacher.fill('#issue-description', 'Tres de los portátiles del aula no arrancan y el proyector se apaga a los diez minutos.');
await teacher.setViewportSize({ width: 1280, height: 1400 });
await hideToolbar(teacher);
await shootCard(teacher, `${root}/reservas/reservas-incidencia-registrar.png`);
await teacher.click('button:has-text("Registrar incidencia")');
await teacher.waitForLoadState('networkidle');
expect(await teacher.locator('section[aria-labelledby="issue-title"]').getByText('Registrada').count() >= 1, 'la incidencia debe quedar registrada');

// ── La dirección la ve y la responde ───────────────────────────────────────────────────────────────────
const director = await newPage();
await login(director, 'carmen.diaz', 'ejemplo');
await director.setViewportSize({ width: 1280, height: 1400 });
await goto(director, `${baseUrl}${href}`);
expect(await director.locator('#issue-comment').count() === 1, 'la dirección debe ver el formulario de respuesta');
await director.fill('#issue-comment', 'Avisado el servicio técnico: pasan el miércoles a revisarlo.');
await shootCard(director, `${root}/reservas/reservas-incidencia-responder.png`);

// ── Calendario con la marca ────────────────────────────────────────────────────────────────────────────
await director.setViewportSize({ width: 1280, height: 900 });
await goto(director, `${baseUrl}/reservas?date=${today}`);
expect(await director.locator('text=Incidencia: Registrada').count() >= 1, 'el calendario debe mostrar la marca');
await director.screenshot({ path: `${root}/reservas/reservas-incidencia-calendario.png`, clip: { x: 256, y: 440, width: 1024, height: 330 } });

await browser.close();
console.log('Capturas de las incidencias de reservas generadas.');
