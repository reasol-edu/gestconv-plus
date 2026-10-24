/**
 * Captura las pantallas del modo mantenimiento para el manual (capítulo «Administrar la plataforma»):
 *  · admin/admin-mantenimiento-ajuste.png  — categoría «Mantenimiento» de los ajustes globales
 *  · admin/admin-mantenimiento-aviso.png   — franja ámbar que ven los administradores con el modo activado
 *  · admin/admin-mantenimiento-pagina.png  — página 503 que ve un docente que no es administrador
 *  · admin/admin-mantenimiento-login.png   — aviso en la pantalla de acceso
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures (usuario `admin`/`admin` y
 * `carmen.diaz`/`ejemplo`, ver scripts/seed/README.md). Activa el modo mantenimiento con la propia pantalla de
 * ajustes (comprobando que el cambio se guarda) y lo vuelve a desactivar al terminar; comprueba además que el
 * docente recibe un 503 mientras está activo y vuelve a entrar cuando se desactiva.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
mkdirSync(`${root}/admin`, { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function newPage() {
    return (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(page, url) {
    const response = await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    return response;
}

async function login(page, username, password) {
    await goto(page, `${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    // Con varios centros, el acceso termina en la selección de centro; sin uno elegido, las acciones de los
    // Live Components (como guardar un ajuste) redirigen allí en vez de ejecutarse.
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

// ── Administración global: activar el modo mantenimiento desde la pantalla de ajustes ─────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');
await goto(admin, `${baseUrl}/admin/ajustes`);

const select  = admin.locator('select[data-setting-key="maintenance.enabled"]');
const section = admin.locator('section', { has: select });
await section.scrollIntoViewIfNeeded();
await section.screenshot({ path: `${root}/admin/admin-mantenimiento-ajuste.png` });

await select.selectOption({ label: 'Activado' });
await admin.waitForTimeout(1200);
await goto(admin, `${baseUrl}/admin/ajustes`);
expect((await select.inputValue()) === 'true', 'El ajuste «Modo mantenimiento» no se ha guardado');

// ── Lo que ve un administrador: la franja de aviso ────────────────────────────────────────────────────
await goto(admin, `${baseUrl}/admin`);
await admin.screenshot({ path: `${root}/admin/admin-mantenimiento-aviso.png`, clip: { x: 0, y: 0, width: 1280, height: 330 } });

// ── Lo que ve un docente: la página de mantenimiento (503) ────────────────────────────────────────────
const teacher = await newPage();
await login(teacher, 'carmen.diaz', 'ejemplo');
const blocked = await goto(teacher, `${baseUrl}/perfil`);
expect(blocked.status() === 503, `Un docente debería recibir un 503 y recibió ${blocked.status()}`);
await teacher.waitForTimeout(900);
await teacher.screenshot({ path: `${root}/admin/admin-mantenimiento-pagina.png` });

// ── Quien aún no ha iniciado sesión: el aviso de la pantalla de acceso ────────────────────────────────
const anonymous = await newPage();
await goto(anonymous, `${baseUrl}/login`);
await anonymous.screenshot({ path: `${root}/admin/admin-mantenimiento-login.png` });

// ── Desactivarlo: el docente vuelve a entrar ──────────────────────────────────────────────────────────
await goto(admin, `${baseUrl}/admin/ajustes`);
await select.selectOption({ label: 'Desactivado' });
await admin.waitForTimeout(1200);
const open = await goto(teacher, `${baseUrl}/perfil`);
expect(open.status() === 200, `Con el modo mantenimiento desactivado, el docente debería entrar y recibió ${open.status()}`);

await browser.close();
console.log('Capturas del modo mantenimiento generadas.');
