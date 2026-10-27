/**
 * Captura las pantallas de las reservas de recursos compartidos para el manual:
 *  · reservas/reservas-calendario.png       — calendario semanal (tramos en filas, días en columnas)
 *  · reservas/reservas-calendario-mes.png   — vista mensual
 *  · reservas/reservas-nueva.png            — formulario de una reserva con periodicidad semanal
 *  · reservas/reservas-previsualizacion.png — fechas de la periodicidad: libres y ocupadas
 *  · reservas/reservas-listado.png          — listado paginado («Todas las que gestiono»)
 *  · centro/centro-recursos.png             — catálogo de recursos compartidos por categorías
 *  · centro/centro-recursos-categoria.png   — formulario de una categoría (responsables y reglas)
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver
 * scripts/seed/README.md) y una BD DESECHABLE con fixtures y `tmp:seed-shots` (que siembra las categorías,
 * los recursos y las reservas). No crea nada: la previsualización de la periodicidad no guarda reservas.
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

/** Captura desde la tarjeta `from` hasta la tarjeta `to` (índices de las tarjetas del formulario). */
async function shootCards(page, from, to, path) {
    const cards = page.locator('form > div.rounded-2xl');
    const first = await cards.nth(from).boundingBox();
    const last  = await cards.nth(to).boundingBox();
    await page.screenshot({ path, clip: { x: first.x, y: first.y, width: first.width, height: last.y + last.height - first.y } });
}

// ── Calendario semanal y mensual, vistos por un docente ───────────────────────────────────────────────
const teacher = await newPage();
await login(teacher, 'roberto.guerrero', 'ejemplo');

await goto(teacher, `${baseUrl}/reservas?date=${today}`);
expect(await teacher.locator('td a[href*="/reservas/"]').count() >= 6, 'el calendario semanal debe mostrar las reservas sembradas');
expect(await teacher.locator('a.border-dashed').count() >= 1, 'debe verse alguna reserva pendiente (borde discontinuo)');
await teacher.screenshot({ path: `${root}/reservas/reservas-calendario.png` });

await goto(teacher, `${baseUrl}/reservas?view=month&date=${today}`);
await teacher.screenshot({ path: `${root}/reservas/reservas-calendario-mes.png` });

// ── Formulario y previsualización de una reserva periódica, vistos por la dirección ──────────────────
const director = await newPage();
await login(director, 'carmen.diaz', 'ejemplo');
// Un viewport alto evita que la captura de un elemento largo se haga por tramos (sale una banda en blanco).
await director.setViewportSize({ width: 1280, height: 1900 });

await goto(director, `${baseUrl}/reservas/nueva?date=${today}`);
await director.selectOption('#resource', { label: 'Aula de informática 1 · Planta 2' });
await director.waitForTimeout(200);
const slots = director.locator('input[name="slots[]"]:not([disabled])');
expect(await slots.count() === 3, 'solo deben ofrecerse los tres tramos del día elegido');
await slots.nth(0).check();
await slots.nth(1).check();
await director.evaluate(() => {
    const select = document.getElementById('groups');
    const option = Array.from(select.options).find((o) => o.textContent.includes('1ºESO A'));
    select.tomselect.setValue([option.value]);
});
await director.fill('#description', 'Práctica de redes: configuración de un router.');
await director.check('input[name="repeat"]');
await director.fill('#repeat_until', '2026-10-26');
await director.locator('h1').click(); // quita el foco del campo de fecha antes de capturar
await director.waitForTimeout(300);
await shootCards(director, 0, 1, `${root}/reservas/reservas-nueva.png`);

await director.click('button[type="submit"]');
await director.waitForLoadState('networkidle');
await hideToolbar(director);
expect(await director.locator('h1', { hasText: 'Fechas de la reserva periódica' }).count() === 1, 'debe abrirse la previsualización');
expect(await director.locator('input[name="dates[]"]').count() === 4, 'cuatro lunes entre el 5 y el 26 de octubre');
expect(await director.locator('input[name="dates[]"]:disabled').count() >= 1, 'el primer lunes ya está ocupado');
await director.setViewportSize({ width: 1280, height: 900 });
await director.screenshot({ path: `${root}/reservas/reservas-previsualizacion.png`, clip: { x: 256, y: 0, width: 1024, height: 780 } });

// ── Listado con las solicitudes pendientes ─────────────────────────────────────────────────────────────
await goto(director, `${baseUrl}/reservas/listado?scope=managed`);
await director.waitForTimeout(500);
expect(await director.locator('tbody tr').count() >= 5, 'el listado debe tener filas');
await director.screenshot({ path: `${root}/reservas/reservas-listado.png`, clip: { x: 0, y: 0, width: 1280, height: 900 } });

// ── Catálogo de recursos compartidos ───────────────────────────────────────────────────────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');
await admin.click('text=Centro educativo');
await admin.waitForLoadState('networkidle');
await admin.click('text=Gestionar recursos compartidos');
await admin.waitForLoadState('networkidle');
await hideToolbar(admin);
expect(await admin.locator('tbody span.font-medium', { hasText: 'Carro de portátiles 1' }).first().isVisible(), 'deben verse los recursos sembrados');
await admin.setViewportSize({ width: 1280, height: 1500 });
await hideToolbar(admin);
// Hasta el final de la última categoría: la página sigue con el formulario de «Nueva categoría».
const height = await admin.evaluate(() => {
    const sections = document.querySelectorAll('section[aria-labelledby^="category-"]');
    const last = sections[sections.length - 1].getBoundingClientRect();

    return Math.ceil(last.bottom + window.scrollY + 24);
});
await admin.screenshot({ path: `${root}/centro/centro-recursos.png`, clip: { x: 0, y: 0, width: 1280, height } });

await admin.locator('a[href*="/categorias/"][href$="/editar"]').first().click();
await admin.waitForLoadState('networkidle');
await hideToolbar(admin);
expect(await admin.locator('#managers').count() === 1, 'debe abrirse el formulario de la categoría');
await shootCards(admin, 0, 1, `${root}/centro/centro-recursos-categoria.png`);

await browser.close();
console.log('Capturas de reservas y de recursos compartidos generadas.');
