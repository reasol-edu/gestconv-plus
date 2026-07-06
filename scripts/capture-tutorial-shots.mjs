import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

for (const dir of ['sanciones', 'alumnado', 'buscar', 'centro', 'ajustes', 'admin']) {
    mkdirSync(`${root}/${dir}`, { recursive: true });
}

const browser = await chromium.launch();

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
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }
    await hideToolbar(page);
}

async function fillQuill(page, mountSelector, text) {
    const editor = page.locator(`${mountSelector} .ql-editor`);
    await editor.click();
    await editor.type(text, { delay: 5 });
}

// ── Tutor/a: registrar un parte y ver la ficha del alumno ──────────────────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await login(page, 'roberto.guerrero', 'ejemplo');

    // Registrar un parte para el alumno objetivo (María Rodríguez Navarro, 1ºBachillerato).
    await page.goto(`${baseUrl}/partes/nuevo`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    const studentControl = page.locator('#students-select').locator('..').locator('.ts-control');
    await studentControl.click();
    await page.keyboard.type('Rodríguez Navarro', { delay: 30 });
    await page.waitForTimeout(700);
    await page.locator('.ts-dropdown .option').first().click();
    await page.waitForTimeout(300);

    await page.locator('input[name="behaviors[]"]').first().check();
    await fillQuill(page, '#description', 'El alumno interrumpió reiteradamente la clase y no atendió a las indicaciones del profesorado.');
    await hideToolbar(page);

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    // El id del parte recién creado viaja en la query string de /partes/creados.
    const reportId = new URL(page.url()).searchParams.get('ids');

    // Notificar el parte a la familia: un parte solo es "elegible" para una sanción
    // una vez notificado (SanctionRepository::findEligibleReports exige notifiedCommunication).
    await page.goto(`${baseUrl}/notificaciones/partes/${reportId}/registrar`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.selectOption('#method_id', { label: 'Correo electrónico' });
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    // Ver el parte completo y saltar a la ficha del alumno desde ahí.
    await page.goto(`${baseUrl}/partes/${reportId}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.click('a[href*="/alumnado/"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/alumnado/alumnado-ficha.png`, fullPage: true });

    await page.close();
}

// ── Comisión de convivencia (admin de centro): sanciones ───────────────────
// Solo admins de centro / miembros de la comisión pueden crear sanciones (SanctionVoter::CREATE),
// no los tutores — por eso se usa carmen.diaz (admin de IES Ada Lovelace) en vez de roberto.guerrero.
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await login(page, 'carmen.diaz', 'ejemplo');

    // Sanciones: buscador de alumnado con partes sancionables.
    await page.goto(`${baseUrl}/sanciones/nueva`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.fill('input[data-model="norender|search"]', 'Rodríguez Navarro');
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${root}/sanciones/sanciones-buscar-alumno.png`, fullPage: true });

    await page.click('a:has-text("Nueva sanción")');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.locator('input[name="reports[]"]').first().check();

    // Elegir una medida sin rango de fechas para no depender de campos condicionales.
    const noDateMeasure = page.locator('input[name="measures[]"][data-has-date-range="0"]').first();
    if (await noDateMeasure.count()) {
        await noDateMeasure.check();
    } else {
        await page.locator('input[name="measures[]"]').first().check();
    }
    await page.waitForTimeout(200);

    if (await page.locator('#effective_from').isVisible().catch(() => false)) {
        await page.fill('#effective_from', new Date().toISOString().slice(0, 10));
    }

    await fillQuill(page, '#details', 'Se impone la medida tras valorar los partes de convivencia asociados y la reincidencia del alumno.');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/sanciones/sanciones-nueva.png`, fullPage: true });

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.goto(`${baseUrl}/sanciones`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/sanciones/sanciones-listado.png`, fullPage: true });

    await page.close();
}

// ── Búsqueda global (⌘K) ──────────────────────────────────────────────────
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await login(page, 'roberto.guerrero', 'ejemplo');

    await page.goto(`${baseUrl}/calendario`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.keyboard.press('Control+k');
    await page.waitForTimeout(300);
    await page.keyboard.type('Rodríguez', { delay: 40 });
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${root}/buscar/buscar-global.png` });

    await page.close();
}

// ── Equipo directivo / admin de centro: oferta, docentes, estudiantes, perfiles, medidas ──
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await login(page, 'carmen.diaz', 'ejemplo');

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);

    await page.click('text=Oferta formativa');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-oferta.png`, fullPage: true });

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.click('text=Docentes del centro');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-docentes.png`, fullPage: true });

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.click('a:has-text("Estudiantes")');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-estudiantes.png`, fullPage: true });

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.click('text=Medidas disciplinarias');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-medidas.png`, fullPage: true });

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.click('text=Perfiles');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-perfiles.png`, fullPage: true });

    await page.click('text=Centro educativo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.click('text=Registro de avisos por correo');
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/centro/centro-avisos.png`, fullPage: true });

    await page.close();
}

// ── Admin global: ajustes (candados + personalización de informes) y registro de actividad ──
{
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await login(page, 'admin', 'admin');

    await page.goto(`${baseUrl}/admin/ajustes`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/ajustes/ajustes-candados.png` });

    await page.locator('text=Personalización de informes').scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${root}/ajustes/ajustes-informes.png` });

    await page.goto(`${baseUrl}/admin/registro-actividad`);
    await page.waitForLoadState('networkidle');
    await hideToolbar(page);
    await page.screenshot({ path: `${root}/admin/admin-registro-actividad.png`, fullPage: true });

    await page.close();
}

await browser.close();
console.log('Listo.');
