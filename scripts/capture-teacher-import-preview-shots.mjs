/**
 * Captura la vista previa de la importación de docentes desde Séneca (manual, capítulo «Preparar el
 * curso académico»): el listado con la acción de cada fila y el panel de docentes que se retirarían.
 *
 * Necesita un servidor ya arrancado en SHOTS_BASE_URL con los fixtures cargados (cualquier BD con
 * el curso activo de IES Ada Lovelace y su profesorado) y el id del centro en SHOTS_CENTRE_ID. No
 * confirma la importación, así que no modifica datos.
 */
import { chromium } from 'playwright';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl  = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const centreId = process.env.SHOTS_CENTRE_ID;
const root     = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';

mkdirSync(`${root}/centro`, { recursive: true });

// Un fichero pequeño con un caso de cada tipo de fila:
//  · docentes que ya están en el curso sin correo → «Rellenar correo»
//  · uno ya en el curso y sin correo en el fichero → «Sin cambios»
//  · docentes que existen pero no están en este curso → «Añadir al curso»
//  · docentes nuevos → «Registrar y añadir» (el segundo repite el correo del primero → «ya lo usa otro docente»)
const csv = join(mkdtempSync(join(tmpdir(), 'docentes-')), 'docentes.csv');
writeFileSync(csv, [
    '"Empleado/a","Usuario IdEA","Cuenta Google/Microsoft"',
    '"Expósito Moreno, Rafael","rafael.exposito","rafael.exposito@iesadalovelace.es"',
    '"Vega Ortiz, Lucía","lucia.vega","lucia.vega@iesadalovelace.es"',
    '"Martín Pozo, Andrés","andres.martin","lucia.vega@iesadalovelace.es"',
    '"Serrano, Alfonso","alfonso.serrano","alfonso.serrano@iesadalovelace.es"',
    '"Guerrero Campos, Roberto","roberto.guerrero",""',
    '"Díaz Jiménez, Carmen","carmen.diaz","carmen.diaz@iesadalovelace.es"',
    '"Fuentes, Amelia","amelia.fuentes","amelia.fuentes@iesadalovelace.es"',
].join('\n') + '\n');

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

await page.goto(`${baseUrl}/login`);
await page.fill('#username', 'carmen.diaz');
await page.fill('#password', 'ejemplo');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

await page.goto(`${baseUrl}/centro/${centreId}/docentes-curso/importar`);
await page.waitForLoadState('networkidle');
await page.setInputFiles('#csv', csv);
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
await hideToolbar();
await page.screenshot({ path: `${root}/centro/centro-docentes-importar-vista-previa.png` });

// Al activar «Retirar del curso…» aparece el panel con los docentes sin ninguna vinculación este curso.
await page.check('#opt-remove');
await page.locator('#remove-panel').scrollIntoViewIfNeeded();
await page.evaluate(() => window.scrollBy(0, -40));
await page.waitForTimeout(150);
await hideToolbar();
await page.screenshot({ path: `${root}/centro/centro-docentes-importar-retirar.png` });

await browser.close();
console.log('Capturas guardadas en', `${root}/centro`);
