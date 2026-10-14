/**
 * Prueba la sección Salidas en un navegador real y captura las imágenes móviles de las fichas
 * «Autorizar una salida» y «Quién está fuera y registrar el regreso» (docs/cheatsheets/img/).
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con una base de datos DESECHABLE migrada y
 * con fixtures, más datos de salidas sembrados (un comando temporal de consola, no incluido: ver
 * el flujo de skills/screenshots.md). Espera, para el centro «IES Ada Lovelace»:
 *   - roberto.guerrero con 1 salida abierta propia (más de 3 min) y otras 3 de otros docentes con
 *     más de 10, 20 y 30 minutos fuera (el estudiante de la 3.ª salida abierta ajena, con pase);
 *   - carla.gil (Carla Gil Cabrera, 1ºESO A) libre, para concederle el pase y autorizarle una salida.
 * Comprueba además, con aserciones, que el cronómetro avanza, que los niveles de color salen, que
 * no hay desbordes horizontales en 390 px y que no hay errores de JavaScript.
 */
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';
mkdirSync(root, { recursive: true });

const failures = [];
const check = (name, ok, detail = '') => {
    console.log(`${ok ? '  ok ' : 'FAIL '} ${name}${ok ? '' : ` — ${detail}`}`);
    if (!ok) failures.push(name);
};

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const jsErrors = [];

async function open(context) {
    const page = await context.newPage();
    page.on('pageerror', (e) => jsErrors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') jsErrors.push(m.text()); });
    return page;
}

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function login(page, username) {
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', 'ejemplo');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('button:has-text("IES Ada Lovelace")');
        await page.waitForLoadState('networkidle');
    }
    await hideToolbar(page);
}

const noOverflow = (page) => page.evaluate(() => document.scrollingElement.scrollWidth <= document.scrollingElement.clientWidth + 1);

// ── Dirección (escritorio): historial y concesión de un pase prioritario ─────────────────────────
{
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    const page = await open(context);
    await login(page, 'carmen.diaz');

    await page.goto(`${baseUrl}/salidas?tab=history`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    check('dirección: la pestaña Historial lista las salidas', (await page.locator('main tbody tr').count()) >= 6);
    check('dirección: las salidas ya terminadas muestran minutos', (await page.locator('main tbody').innerText()).includes(' min'));
    check('dirección: se ve el badge PASE en el historial', (await page.locator('main tbody').innerText()).includes('PASE'));

    await page.fill('input[type="search"]', 'Montero');
    await page.waitForTimeout(1200);
    check('dirección: la búsqueda filtra por estudiante', (await page.locator('main tbody tr').count()) === 1);

    await page.fill('input[type="search"]', 'Carla');
    await page.waitForTimeout(1200);
    await page.locator('main tbody a', { hasText: 'Gil Cabrera' }).first().click();
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    check('dirección: la ficha del estudiante lista sus salidas', (await page.locator('h2', { hasText: 'Salidas autorizadas' }).count()) === 1);
    await page.fill('#pass-description', 'Alergia grave a los frutos secos: puede ir a por su medicación al botiquín sin esperar permiso.');
    await page.click('form[action$="pase-prioritario"] button[type="submit"]');
    await page.waitForLoadState('networkidle');
    check('dirección: el pase se concede y aparece el badge en la ficha', (await page.locator('h1', { hasText: 'PASE' }).count()) === 1);
    await context.close();
}

// ── Docente (móvil) ──────────────────────────────────────────────────────────────────────────────
const iphone = devices['iPhone 13'];
const context = await browser.newContext({ ...iphone, locale: 'es-ES' });
const page = await open(context);
await login(page, 'roberto.guerrero');

await page.goto(`${baseUrl}/`);
await page.waitForLoadState('networkidle');
check('inicio: hay un acceso rápido «Autorizar salida»', (await page.locator('#quick-actions a[href="/salidas/nueva"]').count()) === 1);

await page.goto(`${baseUrl}/salidas`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
check('salidas: un docente corriente no ve la pestaña Historial', (await page.locator('a[href*="tab=history"]').count()) === 0);
check('salidas: el botón fijo «Autorizar salida» es visible en el móvil', await page.locator('div.fixed a[href="/salidas/nueva"]').isVisible());
check('salidas: el botón de la cabecera no se ve en el móvil', !(await page.locator('main a[href="/salidas/nueva"]:not(div.fixed *)').first().isVisible().catch(() => false)));
check('salidas: sin desbordes horizontales (390 px)', await noOverflow(page));
check('salidas: sección «Tus salidas ahora» con botón «Ha regresado»',
    (await page.locator('section:has(h2:has-text("Tus salidas ahora")) button:has-text("Ha regresado")').count()) === 1);

const levels = await page.locator('[data-controller="exit-timer"]').evaluateAll((els) => els.map((e) => e.dataset.level));
check('salidas: salen los niveles de color 0, 1, 2 y 3', ['0', '1', '2', '3'].every((l) => levels.includes(l)), levels.join(','));

await page.screenshot({ path: `${root}/quien-fuera-1.png` });

await page.locator('section:has(h2:has-text("Tus salidas ahora"))').scrollIntoViewIfNeeded();
await page.locator('section:has(h2:has-text("Tus salidas ahora"))').screenshot({ path: `${root}/quien-fuera-2.png` });

const others = page.locator('section:has(h2:has-text("Otras salidas ahora"))');
await others.scrollIntoViewIfNeeded();
await page.waitForTimeout(300);
await page.screenshot({ path: `${root}/quien-fuera-3.png` });

// ── Autorizar una salida ─────────────────────────────────────────────────────────────────────────
await page.goto(`${baseUrl}/salidas`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.click('div.fixed a[href="/salidas/nueva"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
check('paso 1: aparecen «Tus grupos» antes que el resto', (await page.locator('h2').allInnerTexts()).join('|').startsWith('TUS GRUPOS') || (await page.locator('h2').first().innerText()).toLowerCase().includes('tus grupos'));
check('paso 1: sin desbordes horizontales', await noOverflow(page));
await page.screenshot({ path: `${root}/autorizar-salida-1.png` });

await page.locator('main a', { hasText: '1ºESO A' }).first().click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
check('paso 2: se listan los estudiantes del grupo', (await page.locator('li[data-list-filter-target="item"]').count()) >= 2);
check('paso 2: Pablo/otros ya fuera aparecen marcados o el listado no desborda', await noOverflow(page));
await page.screenshot({ path: `${root}/autorizar-salida-2.png` });
await page.fill('input[type="search"]', 'carla');
await page.waitForTimeout(300);
check('paso 2: el buscador filtra sin acentos ni mayúsculas', (await page.locator('li[data-list-filter-target="item"]:not(.hidden)').count()) === 1);

await page.locator('li[data-list-filter-target="item"]:not(.hidden) a').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
check('paso 3: se muestra la justificación del pase', (await page.locator('[role="note"]').innerText()).includes('frutos secos'));
check('paso 3: la cuadrícula tiene los 9 motivos', (await page.locator('input[name="reason_id"]').count()) === 9);
check('paso 3: sin desbordes horizontales', await noOverflow(page));
const cols = await page.locator('label:has(input[name="reason_id"])').evaluateAll((els) => new Set(els.map((e) => Math.round(e.getBoundingClientRect().left))).size);
check('paso 3: la cuadrícula es de 2 columnas en el móvil', cols === 2, `${cols} columnas`);
const tall = await page.locator('label:has(input[name="reason_id"]) span').first().evaluate((e) => e.getBoundingClientRect().height);
check('paso 3: los botones de motivo miden al menos 44 px', tall >= 44, `${tall}px`);
await page.screenshot({ path: `${root}/autorizar-salida-3.png` });

await page.locator('label:has(input[name="reason_id"]):has-text("Secretaría")').click();
await page.fill('#observations', 'Recoger unas fotocopias');
await page.waitForTimeout(200);
const submit = page.locator('button[type="submit"]:has-text("Autorizar salida")');
const box = await submit.boundingBox();
check('paso 3: el botón «Autorizar salida» queda pegado abajo y es grande', box !== null && box.height >= 56 && box.y + box.height <= 664 + 1, JSON.stringify(box));
await page.screenshot({ path: `${root}/autorizar-salida-4.png` });

await submit.click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
check('alta: vuelve a Salidas con aviso de éxito', page.url().endsWith('/salidas') && (await page.locator('[data-flash="success"]').innerText()).includes('Carla'));
check('alta: ahora hay 2 salidas propias', (await page.locator('section:has(h2:has-text("Tus salidas ahora")) article').count()) === 2);
await page.screenshot({ path: `${root}/autorizar-salida-5.png` });

// La salida recién creada lleva segundos: el cronómetro avanza solo, sin recargar.
const fresh  = page.locator('article:has-text("Carla") [data-exit-timer-target="ago"]');
const before = await fresh.innerText();
await page.waitForTimeout(3500);
const after = await fresh.innerText();
check('salidas: el cronómetro avanza', before !== after && /segundo/.test(after), `${before} → ${after}`);

// El estudiante que ya está fuera no puede volver a salir.
await page.goto(`${baseUrl}/salidas/nueva`);
await page.locator('main a', { hasText: '1ºESO A' }).first().click();
await page.waitForLoadState('networkidle');
await page.fill('input[type="search"]', 'carla');
await page.waitForTimeout(300);
check('paso 2: el estudiante que ya está fuera sale marcado «Fuera»', (await page.locator('li[data-list-filter-target="item"]:not(.hidden)').innerText()).includes('Fuera'));
await page.locator('li[data-list-filter-target="item"]:not(.hidden) a').click();
await page.waitForLoadState('networkidle');
check('paso 3: con una salida abierta el botón está desactivado', await page.locator('button[type="submit"]:has-text("Autorizar salida")').isDisabled());

// ── Registrar el regreso ─────────────────────────────────────────────────────────────────────────
await page.goto(`${baseUrl}/salidas`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
const mineCards = page.locator('section:has(h2:has-text("Tus salidas ahora")) article');
await page.locator('section:has(h2:has-text("Tus salidas ahora")) article:has-text("Carla") button:has-text("Ha regresado")').click();
await page.waitForTimeout(1500);
check('regreso: la tarjeta desaparece de «Tus salidas»', (await mineCards.count()) === 1 && !(await mineCards.allInnerTexts()).join().includes('Carla'));
await page.screenshot({ path: `${root}/quien-fuera-4.png` });

// Sondeo en vivo: una salida nueva de otro docente aparece sin recargar (se simula con otra pestaña).
const other = await open(await browser.newContext({ ...iphone, locale: 'es-ES' }));
await login(other, 'carmen.diaz');
await other.goto(`${baseUrl}/salidas/nueva`);
await other.locator('main a', { hasText: '1ºESO B' }).first().click();
await other.waitForLoadState('networkidle');
await other.locator('li[data-list-filter-target="item"] a:not(:has-text("Fuera"))').first().click();
await other.waitForLoadState('networkidle');
await other.locator('label:has(input[name="reason_id"])').first().click();
await other.click('button[type="submit"]:has-text("Autorizar salida")');
await other.waitForLoadState('networkidle');
const countBefore = await page.locator('section article').count();
await page.waitForTimeout(7500);
check('en vivo: la salida de otra persona aparece sin recargar', (await page.locator('section article').count()) === countBefore + 1,
    `${countBefore} → ${await page.locator('section article').count()}`);

check('sin errores de JavaScript en consola', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));

await browser.close();
console.log(failures.length === 0 ? '\nTodo correcto.' : `\n${failures.length} comprobaciones fallidas: ${failures.join('; ')}`);
process.exit(failures.length === 0 ? 0 : 1);
