/**
 * Captura las pantallas de escritorio (1280×900) de la sección Salidas para el manual:
 *  · salidas/salidas-ahora.png            — quién está fuera ahora (docente con salidas propias)
 *  · salidas/salidas-autorizar.png        — último paso de «Autorizar salida», con el aviso de pase prioritario
 *  · salidas/salidas-historial.png        — pestaña Historial de la dirección
 *  · alumnado/alumnado-pase-prioritario.png — tarjeta del pase y salidas en la ficha del estudiante
 *  · centro/centro-motivos-salida.png     — catálogo de motivos de salida
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures, tmp:seed-shots y
 * tmp:seed-exits (ver scripts/seed/README.md) y el reloj fijado con scripts/seed/router-clock.php. Concede un
 * pase prioritario como efecto secundario: restaurar la BD antes de repetirlo.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root     = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
const centreId = process.env.SHOTS_CENTRE_ID;
if (!centreId) {
    throw new Error('Falta SHOTS_CENTRE_ID');
}
for (const dir of ['salidas', 'alumnado', 'centro']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function newPage() {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();
    return page;
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

// ── Dirección: pase prioritario, historial ────────────────────────────────────────────────────────
const manager = await newPage();
await login(manager, 'carmen.diaz', 'ejemplo');

await goto(manager, `${baseUrl}/salidas/nueva`);
await manager.locator('main a', { hasText: '1ºESO A' }).first().click();
await manager.waitForLoadState('networkidle');
const link = manager.locator('li[data-list-filter-target="item"]:not(:has-text("Fuera")) a').first();
const href = await link.getAttribute('href');
const studentId = new URL(href, baseUrl).searchParams.get('studentId');
const groupId   = new URL(href, baseUrl).searchParams.get('groupId');

await goto(manager, `${baseUrl}/alumnado/${studentId}`);
await manager.fill('#pass-description', 'Alergia grave a los frutos secos: puede ir a por su medicación al botiquín sin esperar permiso.');
await manager.click('form[action$="pase-prioritario"] button[type="submit"]');
await manager.waitForLoadState('networkidle');
await hideToolbar(manager);
await manager.locator('h2:has-text("Pase prioritario")').scrollIntoViewIfNeeded();
await manager.screenshot({ path: `${root}/alumnado/alumnado-pase-prioritario.png` });

await goto(manager, `${baseUrl}/salidas?tab=history`);
await manager.waitForTimeout(600);
await manager.screenshot({ path: `${root}/salidas/salidas-historial.png` });

// ── Administración global: catálogo de motivos ───────────────────────────────────────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');
await goto(admin, `${baseUrl}/centro/${centreId}/motivos-salida`);
await admin.screenshot({ path: `${root}/centro/centro-motivos-salida.png` });

// ── Docente: quién está fuera y autorizar ────────────────────────────────────────────────────────
const teacher = await newPage();
await login(teacher, 'roberto.guerrero', 'ejemplo');
await goto(teacher, `${baseUrl}/salidas`);
await teacher.screenshot({ path: `${root}/salidas/salidas-ahora.png` });

await goto(teacher, `${baseUrl}/salidas/nueva?groupId=${groupId}&studentId=${studentId}`);
await teacher.screenshot({ path: `${root}/salidas/salidas-autorizar.png` });

await browser.close();
console.log('Capturas de Salidas listas.');
