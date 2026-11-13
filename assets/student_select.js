// Contenido común del buscador de estudiantes (Tom Select) de los formularios de parte y de nota: cada opción muestra
// nombre y grupo, y cada estudiante elegido muestra su nombre con el grupo en pequeño. El aspecto de las etiquetas y su
// icono de estudiante son CSS global (`assets/styles/app.css`, clase `ts-person-student` en el <select>), así que aquí
// solo está el texto. Un único sitio para que los dos formularios no diverjan.
//
// Uso, en un `<script type="module">` de la página:
//     import { studentSelectRender } from 'student-select';
//     new TomSelect('#...', { ..., render: studentSelectRender('Sin resultados') });

/** Escapa texto para meterlo en HTML (el `no_results` no pasa por el `esc` de Tom Select). */
function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/**
 * Opciones `render` de Tom Select para el buscador de estudiantes.
 *
 * @param {string} noResultsText Texto (ya traducido) cuando la búsqueda no encuentra a nadie.
 */
export function studentSelectRender(noResultsText) {
    return {
        option: (data, esc) => `<div><span class="font-medium">${esc(data.label)}</span> <span class="text-xs text-gray-400 ml-1">${esc(data.secondary || '')}</span></div>`,
        item: (data, esc) => {
            const secondary = data.secondary ? `<span style="opacity:.5;font-size:.6875rem;font-weight:400"> · ${esc(data.secondary)}</span>` : '';

            return `<span>${esc(data.label)}${secondary}</span>`;
        },
        no_results: () => `<div class="no-results">${escapeHtml(noResultsText)}</div>`,
    };
}
