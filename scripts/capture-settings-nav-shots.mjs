/**
 * Prueba la búsqueda y la navegación de la pantalla de ajustes y captura sus imágenes para el manual:
 *  · ajustes/ajustes-candados.png    — ajustes globales: barra fija con buscador y categorías, y los candados
 *  · ajustes/ajustes-busqueda.png    — «Mis ajustes» filtrados al escribir «prescripción»
 *  · ajustes/ajustes-categorias.png  — grupos de avisos (reservas) con su categoría resaltada en la barra
 *  · ajustes/ajustes-modificados.png — «Solo los modificados», con la etiqueta de los ajustes personalizados
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL y una BD DESECHABLE con fixtures y `tmp:seed-shots`. SÍ modifica
 * datos (desactiva un aviso del docente), así que no se puede repetir sobre la misma BD. Falla si algo no funciona.
 */
import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
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

const visibleItems = (page) => page.locator('li[data-settings-nav-target="item"]:not(.hidden)').count();
const visibleSections = (page) => page.locator('section[data-settings-nav-target="section"]:not(.hidden)').count();

// ── Ajustes globales: la barra y los candados ──────────────────────────────────────────────────────────
const admin = await newPage();
await login(admin, 'admin', 'admin');
await goto(admin, `${baseUrl}/admin/ajustes`);
expect(await admin.locator('[data-settings-nav-target="chip"]').count() >= 8, 'debe haber una pestaña por categoría');
expect(await admin.locator('button[title*="Bloquear"], button[title*="bloquear"]').count() >= 1, 'deben verse los candados');
await admin.screenshot({ path: `${root}/ajustes/ajustes-candados.png` });

// ── «Mis ajustes»: búsqueda ────────────────────────────────────────────────────────────────────────────
const page = await newPage();
await login(page, 'carmen.diaz', 'ejemplo');
await goto(page, `${baseUrl}/perfil/ajustes`);

const total = await visibleItems(page);
expect(total >= 22, `debe haber al menos los 22 avisos que se reciben (hay ${total})`);
expect(await page.locator('[data-settings-nav-target="chip"]').count() >= 5, 'los grupos de avisos deben ser categorías propias');
expect(await page.locator('[data-settings-nav-target="chip"]', { hasText: 'Recibir avisos de reservas' }).count() === 1, 'debe existir el grupo de reservas');

// El buscador filtra sin distinguir tildes ni mayúsculas, y exige todas las palabras.
await page.fill('input[type="search"]', 'PRESCRIPCION');
const found = await visibleItems(page);
expect(found > 0 && found < total, `«prescripción» debe dejar solo algunos ajustes (${found} de ${total})`);
expect(await page.locator('[data-settings-nav-target="status"]').innerText().then((t) => t.includes(`${found} de ${total}`)), 'el recuento debe decir cuántos ajustes quedan');
await page.fill('input[type="search"]', 'prescripcion zzzz');
expect(await visibleItems(page) === 0, 'una palabra que no existe debe vaciar el resultado');
expect(await page.locator('[data-settings-nav-target="empty"]:not(.hidden)').count() === 1, 'debe salir el mensaje de sin resultados');
await page.fill('input[type="search"]', 'prescripción');
await page.waitForTimeout(200);
await page.screenshot({ path: `${root}/ajustes/ajustes-busqueda.png`, clip: { x: 256, y: 56, width: 1024, height: 640 } });

// Escape borra la búsqueda y «/» devuelve el foco al buscador.
await page.press('input[type="search"]', 'Escape');
expect(await page.inputValue('input[type="search"]') === '' && await visibleItems(page) === total, 'Esc debe borrar la búsqueda');
await page.locator('h1').click();
await page.keyboard.press('/');
expect(await page.evaluate(() => document.activeElement?.getAttribute('type') === 'search'), '«/» debe enfocar el buscador');
await page.keyboard.press('Tab');

// ── Categorías: acceso directo, barra fija y categoría resaltada ───────────────────────────────────────
await page.locator('[data-settings-nav-target="chip"]', { hasText: 'Recibir avisos de reservas' }).click();
await page.waitForTimeout(1500);
const barTop = await page.evaluate(() => document.querySelector('div[data-live-ignore]').getBoundingClientRect().top);
expect(Math.abs(barTop - 56) <= 2, `la barra debe quedar fija bajo la cabecera (top=${barTop})`);
expect(await page.locator('[data-settings-nav-target="chip"][aria-current="true"]').innerText().then((t) => t.includes('reservas')), 'la categoría visitada debe quedar resaltada');
await page.screenshot({ path: `${root}/ajustes/ajustes-categorias.png`, clip: { x: 256, y: 56, width: 1024, height: 700 } });

// ── Guardar repinta el componente: el filtro vigente y la barra deben sobrevivir ───────────────────────
await page.fill('input[type="search"]', 'reserva');
const filtered = await visibleItems(page);
const select = page.locator('select[data-setting-key="notifications.receive_booking_rejected"]');
await select.selectOption('false');
await page.waitForSelector('text=Guardado');
await page.waitForTimeout(400);
expect(await page.inputValue('input[type="search"]') === 'reserva', 'la búsqueda escrita no debe perderse al guardar');
expect(await visibleItems(page) === filtered, 'el filtro debe seguir aplicado tras repintar');
expect(await page.locator('li[data-modified="1"]').count() === 1, 'el ajuste guardado debe figurar como modificado');
expect(await page.locator('text=Modificado').count() >= 1, 'debe verse la etiqueta «Modificado»');

// ── Solo los modificados ───────────────────────────────────────────────────────────────────────────────
await page.fill('input[type="search"]', '');
await page.locator('input[data-settings-nav-target="modified"]').check();
expect(await visibleItems(page) === 1, 'solo debe quedar el ajuste modificado');
expect(await visibleSections(page) === 1, 'solo debe quedar su categoría');
await page.locator('h1').click();
await page.screenshot({ path: `${root}/ajustes/ajustes-modificados.png`, clip: { x: 256, y: 56, width: 1024, height: 420 } });

// ── Ajustes del centro: la misma barra ─────────────────────────────────────────────────────────────────
await goto(admin, `${baseUrl}/admin/ajustes`);
await admin.fill('input[type="search"]', 'candado');
expect(await admin.locator('[data-settings-nav-target="empty"]:not(.hidden)').count() === 1, 'sin coincidencias debe verse el mensaje');

await browser.close();
console.log('Búsqueda y navegación de los ajustes comprobadas y capturadas.');
