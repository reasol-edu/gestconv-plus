import { chromium } from 'playwright';

const baseUrl = process.env.SHOTS_BASE_URL ?? 'http://127.0.0.1:8744';
const imgRoot = 'docs/manual/img';

const browser = await chromium.launch();
const page    = await browser.newPage({ viewport: { width: 1280, height: 900 } });

async function hideToolbar() {
    await page.addStyleTag({ content: 'div[id^="sfwdt"] { display: none !important; }' });
}

async function goto(url) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    await hideToolbar();
}

async function login(username, password) {
    await goto(`${baseUrl}/login`);
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await hideToolbar();
    if (page.url().includes('/seleccion/centro')) {
        await page.click('button:has-text("IES Ada Lovelace")');
        await page.waitForLoadState('networkidle');
        await hideToolbar();
    }
}

await login('admin', 'admin');

// 1. Ubicaciones (nueva sección sin captura)
await goto(`${baseUrl}/centro/${process.env.SHOTS_CENTRE_ID}`);
const locationsHref = await page.locator('a:has-text("Ubicaciones")').first().getAttribute('href');
await goto(`${baseUrl}${locationsHref}`);
await page.screenshot({ path: `${imgRoot}/partes/admin-ubicaciones.png` });
console.log('OK admin-ubicaciones.png');

// 2. Formulario de docente (radio buttons: modo de acceso, forzar cambio de contraseña, admin global, cuenta activa)
await goto(`${baseUrl}/admin/docentes/nuevo`);
await page.locator('#first_name').blur();
await page.screenshot({ path: `${imgRoot}/admin/admin-docente-formulario.png`, fullPage: true });
console.log('OK admin-docente-formulario.png');

// 3. Ajustes > Personalización de informes, con el pie de contenido relleno (feature nueva desde la última captura)
await goto(`${baseUrl}/centro/${process.env.SHOTS_CENTRE_ID}/ajustes`);
const footerEditor = page.locator('#setting-richtext-reports_incident_footer .ql-editor');
await footerEditor.scrollIntoViewIfNeeded();
await footerEditor.click();
await footerEditor.type('En Linares a 10 de julio de 2026');
await page.locator('#setting-richtext-reports_incident_footer').scrollIntoViewIfNeeded();
await page.waitForTimeout(300);
await page.screenshot({ path: `${imgRoot}/ajustes/ajustes-informes.png` });
console.log('OK ajustes-informes.png');

await browser.close();
console.log('Listo.');
