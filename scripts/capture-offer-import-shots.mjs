/**
 * Prueba la exportación e importación de la oferta formativa en un navegador real y captura sus imágenes para el manual:
 *  · centro/centro-oferta-botones.png    — cabecera de la oferta con Exportar e Importar
 *  · centro/centro-oferta-importar.png   — vista previa: aviso de docentes que faltan, información y modo
 *  · centro/centro-oferta-seleccion.png  — vista previa: árbol de cursos y grupos con «Nuevo» / «Ya existe»
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL y una BD DESECHABLE con fixtures y `tmp:seed-shots`. SÍ modifica datos
 * (importa un curso y un grupo nuevos), así que no se puede repetir sobre la misma BD. Falla si algo no funciona.
 */
import { chromium } from 'playwright';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root    = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
const page    = await context.newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

await goto(`${baseUrl}/login`);
await page.fill('#username', 'carmen.diaz');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// ── La oferta y sus botones ────────────────────────────────────────────────────────────────────────────
await page.click('text=Centro educativo');
await page.waitForLoadState('networkidle');
await page.click('text=Oferta formativa');
await page.waitForLoadState('networkidle');
await hideToolbar();
const offerUrl = page.url();
expect(await page.locator('a[href$="/offer/export"]').count() === 1, 'debe haber un botón Exportar');
expect(await page.locator('a[href$="/offer/import"]').count() === 1, 'debe haber un botón Importar');
await page.screenshot({ path: `${root}/centro/centro-oferta-botones.png`, clip: { x: 256, y: 60, width: 1024, height: 170 } });

// ── Exportar ───────────────────────────────────────────────────────────────────────────────────────────
const response = await context.request.get(`${offerUrl}/export`);
expect(response.ok() && (response.headers()['content-disposition'] ?? '').includes('attachment'), 'la exportación debe descargarse');
const offer = await response.json();
expect(offer.format === 'gestconv.offer' && offer.courses.length >= 5, 'el fichero debe ser una oferta con cursos');
const withTutor = offer.courses.flatMap((c) => c.groups).find((g) => g.tutors.length > 0 && g.subjects.length > 0);
expect(withTutor !== undefined, 'debe haber un grupo con tutor y materias');
expect(withTutor.tutors[0].username && withTutor.tutors[0].name, 'los docentes van como {username, name}');

// Se prepara un fichero con un curso y un grupo nuevos (y un docente que no existe en el destino), como si fuera de otro centro.
const sample = offer.courses[0].groups[0];
// El curso nuevo va el primero: así sale en la parte alta de la vista previa, que es la que se captura.
offer.courses.unshift({
    name: '3º ESO Diversificación',
    details: 'Programa de diversificación curricular',
    groups: [{
        name: '3ºDIV',
        details: null,
        color: '#7c3aed',
        tutors: [sample.tutors[0] ?? { username: 'roberto.guerrero', name: 'Roberto Guerrero Campos' }],
        subjects: [
            { subject: 'Ámbito científico', teacher: sample.subjects[0]?.teacher ?? { username: 'roberto.guerrero', name: 'Roberto Guerrero Campos' } },
            { subject: 'Ámbito lingüístico', teacher: { username: 'docente.otro.centro', name: 'Elena Docente Foránea' } },
        ],
    }],
});
const dir  = mkdtempSync(join(tmpdir(), 'oferta-'));
const path = join(dir, 'oferta-formativa.json');
writeFileSync(path, JSON.stringify(offer));

// ── Importar: vista previa ─────────────────────────────────────────────────────────────────────────────
await page.click('a[href$="/offer/import"]');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.setInputFiles('input[type="file"]', path);
await page.click('button:has-text("Ver contenido")');
await page.waitForLoadState('networkidle');
await hideToolbar();

expect(await page.locator('h1', { hasText: 'Vista previa' }).count() === 1, 'debe abrirse la vista previa');
const courseBoxes = await page.locator('input[name="courses[]"]').count();
expect(courseBoxes === offer.courses.length, 'una casilla por curso');
expect(await page.locator('input[name="courses[]"]:checked').count() === courseBoxes, 'todo viene marcado');
expect(await page.locator('text=Nuevo').count() >= 2, 'el curso y el grupo nuevos deben figurar como Nuevo');
expect(await page.locator('text=Ya existe').count() >= 2, 'lo ya existente debe figurar como Ya existe');
expect(await page.locator('input[name="mode"][value="ignore"]').isChecked(), 'por defecto se ignora lo existente');
expect(await page.locator('text=Elena Docente Foránea').count() >= 1, 'el docente que no existe debe avisarse');

// Elegir un grupo implica su curso; desmarcar un curso desmarca sus grupos.
const firstCourse = page.locator('input[name="courses[]"]').first();
await firstCourse.uncheck();
expect(await page.locator('input[name="groups[0][]"]:checked').count() === 0, 'desmarcar un curso desmarca sus grupos');
await page.locator('input[name="groups[0][]"]').first().check();
expect(await firstCourse.isChecked(), 'marcar un grupo marca su curso');
await page.click('button:has-text("Todo")');
expect(await page.locator('input[name="courses[]"]:checked').count() === courseBoxes, 'Todo marca todo');

// Capturas con coordenadas del documento y la página completa: así la barra superior fija no se cuela en el recorte.
await page.evaluate(() => window.scrollTo(0, 0));
const docBox = async (locator) => {
    const box = await locator.boundingBox();
    const y   = await page.evaluate(() => window.scrollY);

    return { x: box.x, y: box.y + y, width: box.width, height: box.height };
};
const modeBox = await docBox(page.locator('section', { hasText: 'Si ya existe' }).first());
await page.screenshot({
    path: `${root}/centro/centro-oferta-importar.png`,
    fullPage: true,
    clip: { x: 256, y: 56, width: 1024, height: modeBox.y + modeBox.height - 56 + 16 },
});
const treeBox = await docBox(page.locator('section', { hasText: 'Cursos y grupos' }).last());
await page.screenshot({
    path: `${root}/centro/centro-oferta-seleccion.png`,
    fullPage: true,
    clip: { x: treeBox.x, y: treeBox.y, width: treeBox.width, height: 640 },
});

// ── Confirmar: solo el curso nuevo; las materias sin el docente que falta ─────────────────────────────
await page.click('button:has-text("Nada")');
await page.locator('input[name="courses[]"]').first().check();
await page.locator('input[name="groups[0][]"]').first().check();
await page.click('button:has-text("Importar lo seleccionado")');
await page.waitForLoadState('networkidle');
await hideToolbar();

expect(page.url().endsWith('/offer'), 'tras confirmar se vuelve a la oferta');
const body = await page.locator('body').innerText();
expect(body.includes('Importación terminada') && body.includes('docente.otro.centro'), 'el aviso final debe resumir y listar al docente omitido');
await page.click('text=3º ESO Diversificación');
// Los grupos del curso se cargan de forma asíncrona (componente en vivo).
await page.waitForSelector('text=3ºDIV', { timeout: 10000 });

await browser.close();
console.log('Exportación e importación de la oferta comprobadas y capturadas.');
