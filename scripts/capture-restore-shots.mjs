/**
 * Capturas de la restauración de copias de seguridad desde la web (capítulo «Administrar la plataforma»):
 *
 *  · admin/admin-copias-servidor.png   — resultado de la última restauración y lista de copias guardadas en el servidor
 *                                        (una de ellas de otra plataforma, que no se puede restaurar)
 *  · admin/admin-restaurar-copia.png   — pantalla de revisión y confirmación de una restauración
 *  · admin/admin-restaurando.png       — la página de mantenimiento que ve todo el mundo mientras se restaura
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures (usuario `admin`/`admin`) y con
 * APP_BACKUP_DIR apuntando a una carpeta vacía y desechable (SHOTS_BACKUP_DIR, la misma). NO confirma ninguna restauración:
 * solo la prepara y la mira. La página «restaurando» se obtiene escribiendo a mano el bloqueo en `var/restore/` (y se borra
 * al terminar); el resultado de la última restauración, igual.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync, rmSync, copyFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const baseUrl   = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const root      = process.env.SHOTS_OUT_ROOT ?? 'docs/manual/img';
const backupDir = process.env.SHOTS_BACKUP_DIR;
if (!backupDir) {
    throw new Error('Falta SHOTS_BACKUP_DIR (la carpeta de copias del servidor de capturas)');
}
mkdirSync(`${root}/admin`, { recursive: true });
mkdirSync('var/restore', { recursive: true });

const browser = await chromium.launch({ args: ['--lang=es-ES'] });
const page    = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-ES' })).newPage();

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function open(path) {
    await page.goto(`${baseUrl}${path}`);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

await open('/login');
await page.fill('#username', 'admin');
await page.fill('#password', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
if (page.url().includes('/seleccion/centro')) {
    await page.click('button:has-text("IES Ada Lovelace")');
    await page.waitForLoadState('networkidle');
}

// Dos copias guardadas con el botón (los nombres llevan los segundos: se espera entre una y otra).
for (let i = 0; i < 2; i++) {
    await open('/admin/sistema');
    await page.click('form[action$="/copias/guardar"] button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1200);
}

// Una copia «de otro servidor» (PostgreSQL), que aquí no se puede restaurar: se parte de una real y se cambia su manifiesto.
const first = readdirSync(backupDir).filter((n) => n.endsWith('.zip')).sort()[0];
const other = `${backupDir}/gestconv-copia-de-otro-servidor.zip`;
copyFileSync(`${backupDir}/${first}`, other);
execFileSync('php', ['-r', `
    $z = new ZipArchive(); $z->open('${other}');
    $m = json_decode($z->getFromName('manifest.json'), true);
    $m['platform'] = 'postgresql';
    $z->addFromString('manifest.json', json_encode($m));
    $z->close();
`]);

// Más copias, para que la lista se parezca a la de un servidor con uso: la de antes de una restauración anterior y una antigua.
for (const extra of ['gestconv-antes-de-restaurar-20260930-081500.zip', 'gestconv-copia-20260928-070000.zip']) {
    copyFileSync(`${backupDir}/${first}`, `${backupDir}/${extra}`);
}

// Resultado de la última restauración (como lo deja el proceso que la hizo).
writeFileSync('var/restore/last-result.json', JSON.stringify({
    ok: true, by: 'carmen.diaz', file: first, tables: 76, rows: 1603, at: Math.floor(Date.now() / 1000) - 3600,
}));

await open('/admin/sistema');
// La carpeta del servidor de capturas es una ruta desechable: se muestra la de un despliegue con Docker.
await page.evaluate((dir) => {
    for (const p of document.querySelectorAll('p')) {
        p.textContent = p.textContent.replace(dir, '/app/var/backups');
    }
}, backupDir);
const result = page.locator('section.border-green-200').first();
const copies = page.locator('section', { hasText: 'Copias guardadas en el servidor' }).last();
await result.scrollIntoViewIfNeeded();
const a = await result.boundingBox();
const b = await copies.boundingBox();
const scrollY = await page.evaluate(() => window.scrollY);
await page.screenshot({
    path: `${root}/admin/admin-copias-servidor.png`,
    clip: { x: Math.max(0, a.x - 16), y: a.y + scrollY - 16, width: Math.min(1280 - a.x, a.width + 32), height: (b.y + b.height) - a.y + 32 },
    fullPage: true,
});

// Pantalla de revisión y confirmación (mirarla no cambia nada).
const name = (await page.locator('a[href$="/restaurar"]').first().getAttribute('href')).split('/copias/')[1].split('/')[0];
await open(`/admin/sistema/copias/${name}/restaurar`);
await page.screenshot({ path: `${root}/admin/admin-restaurar-copia.png`, fullPage: true });

// La página 503 de mantenimiento mientras se restaura: bloqueo escrito a mano (sin proceso: caduca al minuto, se borra al terminar).
writeFileSync('var/restore/restore.lock', JSON.stringify({ started: Math.floor(Date.now() / 1000), pid: 0, by: 'admin', file: name }));
await page.goto(`${baseUrl}/admin/sistema`);
await page.waitForLoadState('load');
await page.screenshot({ path: `${root}/admin/admin-restaurando.png` });
rmSync('var/restore', { recursive: true, force: true });

await browser.close();
console.log('Listo.');
