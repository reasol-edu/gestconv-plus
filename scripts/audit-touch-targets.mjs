/**
 * Auditoría de usabilidad en móvil: recorre las pantallas accesibles desde el menú (y los paneles de Centro educativo y de
 * Administración) con un móvil emulado y, por cada una, avisa de
 *
 *  · desbordes horizontales de la página;
 *  · botones y enlaces con aspecto de botón más bajos que MIN_HEIGHT (por defecto, 40 px), agrupados por «firma» (etiqueta y
 *    clases) para ver los patrones repetidos, no cada caso;
 *  · elementos interactivos cuyo borde derecho queda fuera de la pantalla.
 *
 * Necesita un servidor arrancado en SHOTS_BASE_URL con una BD DESECHABLE con fixtures y la siembra de las capturas
 * (scripts/seed/README.md). Solo hace GET: no pulsa nada. Uso: `node scripts/audit-touch-targets.mjs [anchoPx]`.
 */
import { chromium, devices } from 'playwright';

const baseUrl   = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const minHeight = Number(process.env.MIN_HEIGHT ?? 40);
const width     = Number(process.argv[2] ?? 390);
const users     = [['admin', 'admin'], ['carmen.diaz', 'ejemplo'], ['roberto.guerrero', 'ejemplo']];

// Rutas que no son pantallas (descargas, salida, modos que atrapan la sesión).
const SKIP = /\/(logout|salir|tablon|pdf|excel|xlsx|csv|export|exportar|descargar|informe|acceder|cambiar-docente|impersonar|seleccion)\b|_switch_user|\.(pdf|xlsx|zip|png|svg)$/i;

const browser = await chromium.launch();
const signatures = new Map();   // firma → {count, pages:Set, example:{text,h,w}}
const overflows  = [];
const offscreen  = [];
let visited = 0;

for (const [username, password] of users) {
    const context = await browser.newContext({ ...devices['iPhone 13'], viewport: { width, height: 844 }, locale: 'es-ES' });
    const page    = await context.newPage();
    await page.goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    if (page.url().includes('/seleccion/centro')) {
        await page.click('button:has-text("IES Ada Lovelace")');
        await page.waitForLoadState('networkidle');
    }

    // Puntos de partida: el menú lateral y los paneles de centro y de administración.
    const seeds = new Set(['/']);
    const collect = async () => {
        for (const href of await page.$$eval('a[href^="/"]', (as) => as.map((a) => a.getAttribute('href') ?? ''))) {
            const path = href.split('#')[0];
            if (path !== '' && !SKIP.test(path) && !path.includes('?')) { seeds.add(path); }
        }
    };
    await collect();
    for (const hub of [...seeds].filter((p) => /^\/(centro\/[^/]+|admin)$/.test(p))) {
        await page.goto(`${baseUrl}${hub}`);
        await page.waitForLoadState('networkidle');
        await collect();
    }

    for (const path of [...seeds].slice(0, 90)) {
        const response = await page.goto(`${baseUrl}${path}`).catch(() => null);
        if (!response || response.status() >= 400) { continue; }
        await page.waitForLoadState('networkidle').catch(() => {});
        ++visited;

        const result = await page.evaluate((minH) => {
            const vw = document.documentElement.clientWidth;
            const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && e.closest('[hidden], .hidden') === null; };
            // Botones y enlaces «con forma de botón» (borde, fondo o redondeo), dentro del contenido o de la cabecera.
            const candidates = [...document.querySelectorAll('main button, main summary, main input[type="submit"], main a, header button, header a')]
                .filter((e) => visible(e) && !e.closest('nav') && (e.matches('button, summary, input[type="submit"]') || /\b(rounded|border|bg-)/.test(e.className)));
            const small = [];
            const out   = [];
            // Un elemento dentro de un contenedor con desplazamiento horizontal (una tabla ancha, un calendario) puede salirse de la pantalla.
            const inScroller = (e) => { for (let p = e.parentElement; p; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll') { return true; } } return false; };
            // La zona táctil puede ampliarse sin cambiar el aspecto con un ::after absoluto (ver «Objetivos táctiles» en app.css).
            const hasHitArea = (e) => { const a = getComputedStyle(e, '::after'); return a.content !== 'none' && a.position === 'absolute'; };
            for (const e of candidates) {
                const r = e.getBoundingClientRect();
                if (hasHitArea(e)) { if (r.right > vw + 1 && !inScroller(e)) { out.push({ text: (e.innerText || '').trim().slice(0, 30), right: Math.round(r.right) }); } continue; }
                if (inScroller(e)) { if (r.height < minH) { /* sigue contando para el tamaño */ } else { continue; } }
                const text = (e.innerText || e.getAttribute('aria-label') || e.title || '').trim().replace(/\s+/g, ' ').slice(0, 30);
                const sig  = e.tagName.toLowerCase() + '.' + String(e.className).split(/\s+/).filter((c) => /^(px|py|text|rounded|p-|h-|w-)/.test(c)).slice(0, 6).join('.');
                if (r.height < minH) { small.push({ sig, text, h: Math.round(r.height), w: Math.round(r.width) }); }
                if (r.right > vw + 1 && !inScroller(e)) { out.push({ text, right: Math.round(r.right) }); }
            }
            return { overflow: document.documentElement.scrollWidth > vw, small, out };
        }, minHeight);

        if (result.overflow) { overflows.push(`${username} ${path}`); }
        for (const o of result.out) { offscreen.push(`${username} ${path}: «${o.text}» llega a ${o.right}px`); }
        for (const s of result.small) {
            const entry = signatures.get(s.sig) ?? { count: 0, pages: new Set(), example: s };
            entry.count++;
            entry.pages.add(path.replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, '{id}'));
            signatures.set(s.sig, entry);
        }
    }
    await context.close();
}
await browser.close();

console.log(`\nPantallas visitadas: ${visited} (ancho ${width}px, mínimo ${minHeight}px)\n`);
console.log(`Desbordes horizontales: ${overflows.length}`);
for (const o of overflows) { console.log('  · ' + o); }
console.log(`\nElementos fuera de pantalla: ${offscreen.length}`);
for (const o of offscreen.slice(0, 25)) { console.log('  · ' + o); }
console.log('\nFirmas de botones más bajos que el mínimo (por número de apariciones):');
for (const [sig, e] of [...signatures.entries()].sort((a, b) => b[1].count - a[1].count).slice(0, 40)) {
    console.log(`  ${String(e.count).padStart(4)} × ${sig}  [${e.example.h}px, «${e.example.text}»]  en ${e.pages.size} pantalla(s), p. ej. ${[...e.pages][0]}`);
}
