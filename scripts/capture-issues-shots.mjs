/**
 * Prueba el módulo de incidencias en un navegador real y captura sus imágenes para el manual:
 *  · incidencias/incidencias-nueva.png         — formulario de alta (tipo, descripción, adjuntos)
 *  · incidencias/incidencias-ficha.png         — ficha vista por quien la registró (descripción, adjuntos, observaciones)
 *  · incidencias/incidencias-gestionar.png     — panel de gestión del responsable (prioridad, estado, tipo, dependencia)
 *  · incidencias/incidencias-observacion.png   — observación con cambio de estado a la vez
 *  · incidencias/incidencias-listado.png       — listado del responsable: cifras, filtros y tabla
 *  · incidencias/incidencias-listado-docente.png — el mismo listado para quien solo informa
 *  · incidencias/incidencias-lote.png          — varias marcadas, con las acciones en bloque
 *  · incidencias/incidencias-campana.png       — la campana con lo pendiente
 *  · incidencias/incidencias-panel.png         — accesos del panel de inicio
 *  · incidencias/incidencias-buscador.png      — el buscador global con incidencias
 *  · incidencias/incidencias-reserva.png       — la ficha de una reserva con sus incidencias
 *  · incidencias/incidencias-reserva-calendario.png — marca en el calendario de reservas
 *  · incidencias/incidencias-boton-ajustes.png — cabecera del listado para la dirección, con el botón «Tipos y prioridades»
 *  · incidencias/incidencias-ajustes.png       — configuración del centro: tipos y prioridades
 *  · incidencias/incidencias-tipo.png          — edición de un tipo con sus responsables
 *  · incidencias/incidencias-importar.png      — vista previa de la importación
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL (con el reloj fijado al lunes de la demo, ver scripts/seed/README.md)
 * y una BD DESECHABLE con fixtures, `tmp:seed-shots` y `tmp:seed-issues`. SÍ modifica datos (cambia un estado, importa
 * un tipo nuevo), así que no se puede repetir sobre la misma BD. Falla si algo no funciona.
 */
import { chromium } from 'playwright';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const today   = process.env.SHOTS_TODAY ?? '2026-10-05';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
const out     = `${root}/incidencias`;

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function newPage(width = 1280, height = 900) {
    return (await browser.newContext({ viewport: { width, height }, locale: 'es-ES' })).newPage();
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(page, url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
}

async function login(page, username, password = 'ejemplo') {
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

async function shootLocator(page, locator, path, margin = 16) {
    const box = await locator.boundingBox();
    expect(box !== null, `no se encuentra la región de ${path}`);
    // La región puede quedar fuera de la ventana: se captura la página entera con coordenadas de documento.
    const scrollY = await page.evaluate(() => window.scrollY);
    await page.screenshot({
        path,
        fullPage: true,
        clip: { x: Math.max(0, box.x - margin), y: Math.max(0, box.y + scrollY - margin), width: box.width + 2 * margin, height: box.height + 2 * margin },
    });
}

/** Abre la ficha de la incidencia con ese número desde el listado (con todos los estados). */
async function openIssue(page, number) {
    await goto(page, `${baseUrl}/incidencias`);
    await page.selectOption('select[aria-label="Estado"]', 'all');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
    await page.locator('tbody a', { hasText: `#${number}` }).first().click();
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
}

// ── Roberto registra una incidencia ────────────────────────────────────────────────────────────────────
const roberto = await newPage();
await login(roberto, 'roberto.guerrero');
await goto(roberto, `${baseUrl}/incidencias/nueva`);
await roberto.evaluate(() => {
    const select = document.getElementById('type');
    const option = [...select.options].find((o) => o.textContent.includes('Climatización'));
    select.tomselect.setValue(option.value);
    const location = document.getElementById('location');
    location.tomselect.setValue([...location.options].find((o) => o.textContent.includes('Aula 3')).value);
});
await roberto.locator('.ql-editor').first().click();
await roberto.keyboard.type('El aire acondicionado del aula 3 hace un ruido metálico y cuando lleva diez minutos encendido deja de enfriar.');
await roberto.waitForTimeout(400);
await roberto.setViewportSize({ width: 1280, height: 1000 });
await hideToolbar(roberto);
await roberto.screenshot({ path: `${out}/incidencias-nueva.png` });
await roberto.setViewportSize({ width: 1280, height: 900 });

// ── La ficha, vista por quien la registró ──────────────────────────────────────────────────────────────
await openIssue(roberto, 1);
expect(await roberto.locator('form[action$="/gestionar"]').count() === 0, 'quien registra no debe ver la gestión');
expect(await roberto.locator('img[loading="lazy"]').count() >= 1, 'la ficha debe mostrar la miniatura del adjunto');
await roberto.setViewportSize({ width: 1280, height: 1000 });
await hideToolbar(roberto);
await roberto.screenshot({ path: `${out}/incidencias-ficha.png` });
await roberto.setViewportSize({ width: 1280, height: 900 });

// ── Su listado y el buscador ───────────────────────────────────────────────────────────────────────────
await goto(roberto, `${baseUrl}/incidencias`);
await roberto.waitForTimeout(600);
await roberto.screenshot({ path: `${out}/incidencias-listado-docente.png` });

await goto(roberto, `${baseUrl}/`);
await roberto.keyboard.press('Control+k');
await roberto.keyboard.type('luz');
await roberto.waitForTimeout(1200);
const palette = roberto.locator('[data-command-palette-target="dialog"]');
if (await palette.isVisible()) {
    await shootLocator(roberto, palette, `${out}/incidencias-buscador.png`, 24);
} else {
    await roberto.screenshot({ path: `${out}/incidencias-buscador.png` });
}

// ── La incidencia de la reserva ────────────────────────────────────────────────────────────────────────
await openIssue(roberto, 2);
const bookingLink = roberto.locator('dl a[href^="/reservas/"]').first();
expect(await bookingLink.count() === 1, 'la incidencia debe enlazar con su reserva');
const bookingHref = await bookingLink.getAttribute('href');
await goto(roberto, `${baseUrl}${bookingHref}`);
const section = roberto.locator('section[aria-labelledby="issue-title"]');
expect(await section.count() === 1, 'la reserva debe listar sus incidencias');
await roberto.setViewportSize({ width: 1280, height: 1300 });
await hideToolbar(roberto);
await shootLocator(roberto, section, `${out}/incidencias-reserva.png`);
const bookingDate = await roberto.locator('main, body').first().evaluate(() => document.body.innerText.match(/\d{2}\/\d{2}\/\d{4}/)?.[0] ?? '');
await roberto.setViewportSize({ width: 1280, height: 900 });
await goto(roberto, `${baseUrl}/reservas?date=${today}`);
const mark = roberto.locator('a[href*="/reservas/"] span.text-amber-600').first();
if (await mark.count() > 0) {
    await shootLocator(roberto, mark.locator('xpath=ancestor::a[1]'), `${out}/incidencias-reserva-calendario.png`, 40);
} else {
    console.warn(`(sin marca en el calendario de ${today}; la reserva es del ${bookingDate})`);
}

// ── Beatriz, responsable de Mantenimiento (la variable `pedro` es la responsable: se llamó así antes de cambiar de docente) ──
const pedro = await newPage();
await login(pedro, 'beatriz.alonso');
await goto(pedro, `${baseUrl}/`);
const quick = pedro.locator('#quick-actions');
expect(await quick.locator('a[href="/incidencias/nueva"]').count() === 1, 'el panel debe ofrecer registrar una incidencia');
await shootLocator(pedro, quick, `${out}/incidencias-panel.png`, 8);
await pedro.click('button[aria-label="Notificaciones"]');
await pedro.waitForTimeout(500);
// Las incidencias son las últimas secciones de la campana: se desplaza hasta ellas.
await pedro.locator('div.max-h-96.overflow-y-auto').evaluate((el) => { el.scrollTop = el.scrollHeight; });
await pedro.waitForTimeout(300);
await pedro.screenshot({ path: `${out}/incidencias-campana.png`, clip: { x: 840, y: 40, width: 440, height: 520 } });
await pedro.keyboard.press('Escape');

await goto(pedro, `${baseUrl}/incidencias`);
await pedro.waitForTimeout(700);
await pedro.screenshot({ path: `${out}/incidencias-listado.png` });

// Marcar varias para el cambio en bloque.
const boxes = pedro.locator('input[type="checkbox"][data-model="selected"]');
expect(await boxes.count() >= 2, 'el responsable debe poder marcar incidencias');
await boxes.nth(0).check();
await pedro.waitForTimeout(700);
await boxes.nth(1).check();
await pedro.waitForTimeout(900);
expect(await pedro.getByText('incidencias marcadas').count() >= 1, 'debe aparecer la barra de acciones en bloque');
await pedro.screenshot({ path: `${out}/incidencias-lote.png` });
await boxes.nth(0).uncheck();
await boxes.nth(1).uncheck();

// Gestionar la incidencia 8 (abierta, sin prioridad): ficha, panel de gestión y observación con cambio de estado.
await openIssue(pedro, 8);
const manage = pedro.locator('section[aria-labelledby="issue-manage-title"]');
expect(await manage.count() === 1, 'el responsable debe ver el panel de gestión');
await pedro.setViewportSize({ width: 1280, height: 1300 });
await hideToolbar(pedro);
await shootLocator(pedro, manage, `${out}/incidencias-gestionar.png`);
const observations = pedro.locator('section[aria-labelledby="issue-observations-title"]');
await pedro.fill('#observation-content', 'Revisado: es el cebador. Lo cambio mañana a primera hora.');
await shootLocator(pedro, observations, `${out}/incidencias-observacion.png`);
await pedro.setViewportSize({ width: 1280, height: 900 });

// Con prioridad se puede poner en proceso.
await pedro.selectOption('#manage-priority', { label: 'Alta' });
await pedro.selectOption('#manage-status', 'in_progress');
await pedro.click('section[aria-labelledby="issue-manage-title"] button[type="submit"]');
await pedro.waitForLoadState('networkidle');
expect(await pedro.getByText('Cambios guardados').count() >= 1, 'debe confirmarse el cambio');
expect(await pedro.locator('header, main').getByText('En proceso').count() >= 1, 'la incidencia debe quedar en proceso');

// ── La dirección configura el módulo ───────────────────────────────────────────────────────────────────
const carmen = await newPage();
await login(carmen, 'carmen.diaz');
await goto(carmen, `${baseUrl}/incidencias`);
expect(await carmen.locator('a[href^="/centro/"][href$="/incidencias"]').count() === 1, 'la dirección debe tener el botón de tipos y prioridades');
await carmen.screenshot({ path: `${out}/incidencias-boton-ajustes.png`, clip: { x: 256, y: 90, width: 1024, height: 90 } });
await carmen.click('text=Centro educativo');
await carmen.waitForLoadState('networkidle');
await carmen.click('a[href^="/centro/"][href$="/incidencias"]');
await carmen.waitForLoadState('networkidle');
await hideToolbar(carmen);
const settingsUrl = carmen.url();
expect(settingsUrl.endsWith('/incidencias'), 'la configuración debe abrirse');
await carmen.screenshot({ path: `${out}/incidencias-ajustes.png` });

await carmen.locator('a[href*="/tipos/"][href$="/editar"]').first().click();
await carmen.waitForLoadState('networkidle');
await hideToolbar(carmen);
await carmen.screenshot({ path: `${out}/incidencias-tipo.png` });

// Exportar, añadir un tipo al fichero e importarlo.
const response = await carmen.context().request.get(`${settingsUrl}/exportar`);
expect(response.ok(), 'la exportación debe responder');
const exported = await response.json();
exported.types.push({ name: 'Seguridad y accesos', active: true, managers: [{ username: 'rafael.exposito', name: 'Rafael Expósito' }], children: [{ name: 'Cerraduras y llaves', active: true, managers: [], children: [] }] });
const path = join(mkdtempSync(join(tmpdir(), 'issues-')), 'incidencias-ada-lovelace.json');
writeFileSync(path, JSON.stringify(exported, null, 2));

await goto(carmen, `${settingsUrl}/importar`);
await carmen.setInputFiles('input[type="file"]', path);
await carmen.click('button:has-text("Ver contenido")');
await carmen.waitForLoadState('networkidle');
await hideToolbar(carmen);
expect(await carmen.getByText('Seguridad y accesos').count() >= 1, 'la vista previa debe mostrar el tipo nuevo');
await carmen.setViewportSize({ width: 1280, height: 1100 });
await hideToolbar(carmen);
await carmen.screenshot({ path: `${out}/incidencias-importar.png` });

await browser.close();
console.log('Módulo de incidencias comprobado y capturado.');
