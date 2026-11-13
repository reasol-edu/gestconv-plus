/**
 * Capturas de las novedades de la versión 1.30 (selección rápida y antecedentes en el parte, notas para varios
 * estudiantes, borrador del parte, búsqueda por número, filtro de Séneca, exportación a Excel, cierre automático
 * de salidas, informe PDF del estudiante, icono de ayuda y avisos push):
 *
 *  · partes/nuevo-parte-selector-alumnado.png, partes/nuevo-parte-antecedentes.png, partes/nuevo-parte-seleccion-rapida.png, partes/nuevo-parte-borrador.png
 *  · notas/nueva-nota-vacio.png, notas/nueva-nota-varios.png, notas/notas-creadas-varios.png
 *  · partes/partes-listado.png (con el botón «Exportar a Excel»), sanciones/sanciones-listado.png (filtro de Séneca)
 *  · salidas/salidas-historial.png, salidas/salidas-cierre-automatico.png
 *  · buscar/buscar-por-numero.png
 *  · alumnado/alumnado-informe-pdf.png, alumnado/alumnado-informe-pdf-pagina.png
 *  · inicio/ayuda-cabecera.png, inicio/sin-conexion.png, ajustes/avisos-push-perfil.png
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con fixtures + tmp:seed-shots + tmp:seed-exits, con una salida
 * marcada con auto_closed_at (UPDATE a mano) y con VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY definidas (para la tarjeta de avisos).
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8745';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

for (const dir of ['partes', 'notas', 'sanciones', 'salidas', 'buscar', 'alumnado', 'inicio', 'ajustes']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function hideToolbar(page) {
    // Además de la barra de depuración, la cabecera fija se suelta para que no tape las capturas de elementos.
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; } header.sticky { position: static !important; }' });
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

async function open(page, path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
}

// Con el permiso de notificaciones concedido, la tarjeta de avisos muestra su estado normal («no activados») y no el de «bloqueadas».
/** Captura la primera tarjeta del formulario, recortada por abajo: una lista de 20 estudiantes no aporta nada más (ver skills/screenshots.md). */
async function cardShot(page, path, maxHeight) {
    const card = page.locator('form > div.rounded-2xl').first();
    await card.scrollIntoViewIfNeeded();
    const box = await card.boundingBox();
    const scrollY = await page.evaluate(() => window.scrollY);
    await page.screenshot({ path, clip: { x: box.x, y: box.y + scrollY, width: box.width, height: Math.min(box.height, maxHeight) }, fullPage: true });
}

const newPage = async () => (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES', permissions: ['notifications'] })).newPage();

/** Elige un grupo en la selección rápida y marca a los `count` primeros estudiantes. */
async function quickPick(page, count) {
    await page.locator('[data-action="quick-selection#open"]').first().click();
    const boxes = page.locator('[data-quick-selection-target="list"] input[type="checkbox"]');
    await boxes.first().waitFor();
    for (let i = 0; i < count; i++) {
        await boxes.nth(i).check();
    }
    await page.waitForTimeout(400);
}

// ── Profesorado: partes y notas ──────────────────────────────────────────────────────────────────────
{
    const page = await newPage();
    await login(page, 'roberto.guerrero', 'ejemplo');

    // Selección rápida: botones de grupo bajo el buscador.
    await open(page, '/partes/nuevo');
    await page.locator('[data-controller="quick-selection"]').scrollIntoViewIfNeeded();
    await page.locator('#students-select').locator('..').locator('.ts-control').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${root}/partes/nuevo-parte-seleccion-rapida.png` });

    // Estudiantes marcados y antecedentes.
    await quickPick(page, 1);
    // Una estudiante con un parte reciente, para que los antecedentes tengan contenido.
    await page.locator('[data-quick-selection-target="list"] label', { hasText: 'Rodríguez Navarro' }).locator('input').check();
    await page.locator('[data-controller="student-context"] li').first().waitFor({ timeout: 8000 });
    await page.waitForTimeout(500);
    await hideToolbar(page);
    await cardShot(page, `${root}/partes/nuevo-parte-selector-alumnado.png`, 760);
    // Las tarjetas de antecedentes van tras la selección rápida: se captura solo su bloque.
    await page.locator('[data-controller="student-context"]').screenshot({ path: `${root}/partes/nuevo-parte-antecedentes.png` });

    // Borrador: se escribe, se espera al guardado y se vuelve a abrir el formulario.
    await page.locator('.ql-editor').first().click();
    await page.keyboard.type('Interrumpe la explicación de forma reiterada y se niega a guardar el móvil.', { delay: 5 });
    await page.waitForTimeout(1500);
    await open(page, '/partes/nuevo');
    await page.locator('[data-draft-target="banner"]:not(.hidden)').waitFor({ timeout: 8000 });
    await page.screenshot({ path: `${root}/partes/nuevo-parte-borrador.png`, clip: { x: 0, y: 0, width: 1280, height: 520 } });

    // Comprobación (no es una captura): «Recuperar» devuelve estudiantes y texto al formulario.
    await page.click('[data-action="draft#restore"]');
    await page.waitForTimeout(600);
    const restored = await page.locator('.ql-editor').first().innerText();
    const items = await page.locator('#students-select').evaluate((el) => el.tomselect.items.length);
    if (!restored.includes('Interrumpe la explicación') || items !== 2) {
        throw new Error(`El borrador no se ha restaurado bien: texto=«${restored}», estudiantes=${items}`);
    }

    // Icono de ayuda en la cabecera.
    await page.screenshot({ path: `${root}/inicio/ayuda-cabecera.png`, clip: { x: 760, y: 0, width: 520, height: 56 } });

    // Notas: formulario vacío y con varios estudiantes.
    await open(page, '/notas/nuevo');
    await page.screenshot({ path: `${root}/notas/nueva-nota-vacio.png` });
    await quickPick(page, 3);
    await page.locator('.type-radio-label').first().click();
    await page.waitForTimeout(600);
    await hideToolbar(page);
    await cardShot(page, `${root}/notas/nueva-nota-varios.png`, 760);
    await page.locator('textarea#observations').fill('Llegan tarde a primera hora tras el recreo.');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/notas/notas-creadas-varios.png`, fullPage: true });

    // Avisos push en el perfil (la tarjeta solo existe con claves VAPID). Chromium sin interfaz da el permiso por
    // denegado; para la captura se simula el estado normal de un navegador que aún no ha preguntado.
    await page.addInitScript(() => Object.defineProperty(Notification, 'permission', { get: () => 'default' }));
    await open(page, '/perfil/ajustes');
    await page.locator('[data-push-target="enable"]:not(.hidden), [data-push-target="unsupported"]:not(.hidden)').first().waitFor({ timeout: 8000 });
    await page.locator('[data-controller="push"]').screenshot({ path: `${root}/ajustes/avisos-push-perfil.png` });
    await page.close();
}

// ── Dirección: listados, historial de salidas, búsqueda por número e informe PDF ─────────────────────
{
    const page = await newPage();
    await login(page, 'admin', 'admin');

    await open(page, '/partes');
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${root}/partes/partes-listado.png` });

    await open(page, '/sanciones');
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${root}/sanciones/sanciones-listado.png` });

    await open(page, '/salidas?tab=history');
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${root}/salidas/salidas-historial.png` });
    // El estado está tras «Más filtros» (un <details> plegado).
    await page.locator('details > summary').first().click();
    await page.selectOption('select[data-model="norender|status"]', 'auto_closed');
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${root}/salidas/salidas-cierre-automatico.png` });

    // Búsqueda por número: «#1» abre el parte 1.
    await open(page, '/');
    await page.keyboard.press('Control+k');
    await page.keyboard.type('#1', { delay: 60 });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${root}/buscar/buscar-por-numero.png` });
    await page.keyboard.press('Escape');

    // Informe PDF de un estudiante con partes: desplegable de la ficha y primera página del PDF.
    await open(page, '/partes');
    const href = await page.locator('a[href^="/alumnado/"]').first().getAttribute('href');
    await open(page, href);
    await page.locator('summary:has-text("Informe en PDF")').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${root}/alumnado/alumnado-informe-pdf.png`, clip: { x: 0, y: 0, width: 1280, height: 420 } });

    const pdf = await page.context().request.get(`${baseUrl}${href}/informe`);
    const dir = join(tmpdir(), 'gc-informe');
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'informe.pdf'), await pdf.body());
    execFileSync('pdftoppm', ['-r', '90', '-png', '-singlefile', '-f', '1', '-l', '1', '-x', '0', '-y', '0', '-W', '744', '-H', '720', join(dir, 'informe.pdf'), `${root}/alumnado/alumnado-informe-pdf-pagina`]);
    await page.close();
}

// ── Página «sin conexión» ───────────────────────────────────────────────────────────────────────────
{
    const page = await browser.newPage({ viewport: { width: 800, height: 520 }, locale: 'es-ES' });
    await page.goto(`${baseUrl}/offline.html`);
    await page.screenshot({ path: `${root}/inicio/sin-conexion.png` });
    await page.close();
}

await browser.close();
