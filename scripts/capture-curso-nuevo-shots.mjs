/**
 * Captura las capturas de escritorio de la ficha «Configurar un curso académico nuevo»
 * (docs/cheatsheets/curso-nuevo.md), dirigida al equipo directivo.
 *
 * A diferencia de scripts/capture-cheatsheet-shots.mjs (viewport móvil, datos ya sembrados),
 * este script usa el viewport de escritorio 1280×900 (como scripts/capture-tutorial-shots.mjs,
 * la app se administra desde escritorio) y ejecuta él mismo, de principio a fin, el flujo real de
 * puesta a punto de un curso académico: crea y activa el curso 2026-2027, importa profesorado y
 * alumnado desde los CSV de Séneca reutilizados de src/DataFixtures/data/ (los mismos que usa
 * AppFixtures para 2025-2026), asigna una tutoría, importa asignaciones docente-grupo desde
 * src/DataFixtures/data/asignaciones-ada-lovelace.csv y define un tramo horario con dos docentes
 * de guardia. El paso «Revisar los catálogos del centro» de la ficha no lleva captura (son cuatro
 * pantallas de catálogo con la misma mecánica; el texto ya lo deja claro), así que no tiene
 * bloque correspondiente aquí.
 *
 * Requiere, por tanto, una BASE DE DATOS DESECHABLE PROPIA para esta ejecución (no la reutilices
 * con capture-tutorial-shots.mjs ni capture-cheatsheet-shots.mjs en la misma pasada): este script
 * deja el curso 2026-2027 como curso activo del centro, lo que cambiaría el curso de referencia
 * que dan por hecho el resto de capturas. Ver memoria project-screenshot-workflow para el patrón
 * habitual de BD desechable + servidor PHP integrado.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root     = process.env.SHOTS_OUT_DIR ?? 'docs/cheatsheets/img';
const centreId = process.env.SHOTS_CENTRE_ID ?? '019f7ed5-93ed-74fc-aba1-a8b5cc0085fa'; // IES Ada Lovelace

mkdirSync(root, { recursive: true });

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
        await page.click('text=IES Ada Lovelace');
        await page.waitForLoadState('networkidle');
    }
    await hideToolbar(page);
}

const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });
await login(page, 'carmen.diaz', 'ejemplo');

// ── 1. Crear y activar el curso académico ───────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/cursos`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.fill('input[name="name"]', '2026-2027');
// Ojo: la página tiene varios "button[type=submit]" (el de añadir curso y uno por cada curso
// existente para eliminarlo); hay que acotar al formulario de alta o se puede pulsar el botón
// equivocado.
await page.locator('form[action*="/nuevo"] button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);

const newYearRow = page.locator('li', { hasText: '2026-2027' });
await newYearRow.waitFor();
await newYearRow.locator('button:has-text("Establecer activo")').click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-3-activado.png` });

// ── 2. Añadir el profesorado ─────────────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/docentes-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-5-docentes-listado.png` });

// ── 3. Importar el alumnado (y la oferta formativa) ──────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/estudiantes/importar`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/alumnado-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
// Captura recortada al viewport (no fullPage): la vista previa de un centro con muchos grupos
// puede ser muy alta y descuadraría la maquetación del PDF (ver memoria
// screenshot-height-limit); la parte alta ya deja claro qué cursos y grupos se van a crear.
await page.screenshot({ path: `${root}/curso-nuevo-7-estudiantes-vista-previa.png` });

await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);

// ── 4. Asignar las tutorías de grupo ─────────────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}/offer`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.locator('button', { hasText: '1º ESO' }).first().click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.locator('button', { hasText: '1ºESO A' }).first().click();
await page.waitForLoadState('networkidle');
await hideToolbar(page);

const tutorControl = page.locator('#staff-tutors').locator('..').locator('.ts-control');
await tutorControl.click();
await page.keyboard.type('Guerrero', { delay: 30 });
await page.waitForTimeout(700);
await page.locator('.ts-dropdown:visible .option').first().click();
await page.waitForTimeout(400);
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-11-tutoria-asignada.png` });

// ── 5. Indicar quién imparte clase en cada grupo (opcional) ──────────────────
await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar-asignaciones`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.setInputFiles('#csv', 'src/DataFixtures/data/asignaciones-ada-lovelace.csv');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-13-asignaciones-listado.png` });

// ── 6. Definir los tramos horarios (y el profesorado de guardia) ────────────
await page.goto(`${baseUrl}/centro/${centreId}/tramos-horarios`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.click('button:has-text("Añadir todos los días")');
await page.fill('input[data-model="norender|addName"]', '1ª hora');
await page.fill('input[data-model="norender|addStart"]', '08:15');
await page.fill('input[data-model="norender|addEnd"]', '09:10');
await page.click('form[data-live-action-param="saveAddAllDays"] button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);

await page.locator('button[data-live-action-param="selectTimeSlot"]', { hasText: '1ª hora' }).first().click();
await page.waitForTimeout(300);
await hideToolbar(page);

// El control de Tom Select se cierra tras cada selección; hay que volver a abrirlo (clic) antes
// de teclear la siguiente búsqueda, o el segundo docente nunca aparece en el desplegable.
const guardControl = page.locator('#staff-guards').locator('..').locator('.ts-control');
await guardControl.click();
await page.keyboard.type('Molina', { delay: 30 });
await page.waitForTimeout(700);
await page.locator('.ts-dropdown:visible .option').first().click();
await page.waitForTimeout(300);

await guardControl.click();
await page.keyboard.type('Lozano', { delay: 30 });
await page.waitForTimeout(700);
await page.locator('.ts-dropdown:visible .option').first().click();
await page.waitForTimeout(300);
await hideToolbar(page);

await page.click('form[data-live-action-param="saveDetail"] button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-15-tramos-horarios.png` });

// ── Resultado: el centro, listo para trabajar ────────────────────────────────
await page.goto(`${baseUrl}/centro/${centreId}`);
await page.waitForLoadState('networkidle');
await hideToolbar(page);
await page.screenshot({ path: `${root}/curso-nuevo-14-centro-listo.png` });

await page.close();
await browser.close();
console.log('Capturas guardadas en', root);
