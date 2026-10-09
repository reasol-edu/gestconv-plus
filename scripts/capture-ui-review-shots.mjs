/**
 * Capturas añadidas o actualizadas tras la revisión de interfaz de la versión 1.4.7:
 *  · ajustes/ajustes-subida-ficheros.png  — ajustes globales «Subida de ficheros» (límites por fichero y por envío)
 *  · ausencias/adjunto-demasiado-grande.png — aviso del recuadro de adjuntos al elegir un fichero que supera el límite
 *  · admin/admin-registro-actividad.png     — registro de actividad con los tipos de acción y datos ya traducidos
 *  · centro/centro-avisos.png               — registro de avisos por correo con los eventos traducidos
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con datos sembrados (fixtures + tmp:seed-shots) y, para
 * el registro de avisos, entradas en email_notification_log.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

for (const dir of ['ajustes', 'ausencias', 'admin', 'centro']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

const browser = await chromium.launch({ args: ['--lang=es-ES'] });

async function hideToolbar(page) {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
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

// ── Admin global: ajustes de subida de ficheros y registro de actividad ──────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'admin', 'admin');

    await page.goto(`${baseUrl}/admin/ajustes`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.getByRole('heading', { name: 'Subida de ficheros' }).scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${root}/ajustes/ajustes-subida-ficheros.png` });
    console.log('OK ajustes-subida-ficheros.png');

    // La tabla necesita ~1230 px de ancho útil para que se vea la columna «Datos»: se amplía el viewport
    // solo para esta captura y se quitan las filas de inicio de sesión (sin datos), que solo ocupan sitio.
    await page.setViewportSize({ width: 1680, height: 1000 });
    await page.goto(`${baseUrl}/admin/registro-actividad`);
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => {
        document.querySelectorAll('table tbody tr').forEach((tr) => {
            if (tr.textContent.includes('Inicio de sesión')) tr.remove();
        });
    });
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/admin/admin-registro-actividad.png` });
    console.log('OK admin-registro-actividad.png');

    await page.close();
}

// ── Jefatura de estudios (carmen.diaz): registro de avisos por correo ──────────────────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'carmen.diaz', 'ejemplo');

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.click('text=Registro de avisos por correo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-avisos.png`, fullPage: true });
    console.log('OK centro-avisos.png');

    await page.close();
}

// ── Docente: aviso al elegir un adjunto que supera el límite ─────────────────
{
    const big = join(tmpdir(), 'trabajo-del-trimestre.pdf');
    writeFileSync(big, Buffer.alloc(11 * 1024 * 1024, 1));

    const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
    await login(page, 'admin', 'admin');

    await page.goto(`${baseUrl}/ausencias`);
    await page.waitForLoadState('networkidle');
    await page.locator('a[href^="/ausencias/0"]').first().click();
    await page.waitForLoadState('networkidle');
    await page.locator('a[href$="/actividades/nueva"]').first().click();
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.locator('[data-controller~="file-drop"] input[type="file"]').first().setInputFiles(big);
    await page.waitForTimeout(400);
    const zone = page.locator('[data-controller~="file-drop"]').first();
    await zone.scrollIntoViewIfNeeded();
    await zone.screenshot({ path: `${root}/ausencias/adjunto-demasiado-grande.png` });
    console.log('OK adjunto-demasiado-grande.png');

    await page.close();
    rmSync(big, { force: true });
}

await browser.close();
console.log('Listo.');
