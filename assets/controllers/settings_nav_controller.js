import { Controller } from '@hotwired/stimulus';

/**
 * Búsqueda y navegación de la pantalla de ajustes. Todo ocurre en el navegador, sobre el HTML que ya pinta el
 * componente: escribir filtra los ajustes (todas las palabras deben aparecer, sin distinguir mayúsculas ni tildes, en
 * el nombre, la descripción, la categoría o la clave), «solo los modificados» deja los que se han personalizado, las
 * categorías son accesos directos con su recuento y la que se está leyendo queda resaltada.
 *
 * El componente se repinta al guardar un ajuste, y eso deshace las clases que se añaden aquí: por eso el filtro se
 * vuelve a aplicar con cada evento «live:render». La barra de búsqueda lleva data-live-ignore y no se repinta.
 */
export default class extends Controller {
    static targets = ['input', 'clear', 'hint', 'modified', 'modifiedCount', 'chip', 'section', 'item', 'empty', 'status'];
    static values  = { countAll: String, countFiltered: String, empty: String };

    #spy = null;
    #visible = new Set();
    #root = null;

    connect() {
        this.#root = this.element.parentElement;
        this.#root?.addEventListener('live:render', this.#onRender);
        document.addEventListener('keydown', this.#onKey);

        this.#spy = new IntersectionObserver((entries) => this.#spyEntries(entries), { rootMargin: '-140px 0px -55% 0px' });
        this.sectionTargets.forEach((section) => this.#spy.observe(section));

        this.apply();
        this.#scrollToHash();
    }

    disconnect() {
        this.#root?.removeEventListener('live:render', this.#onRender);
        document.removeEventListener('keydown', this.#onKey);
        this.#spy?.disconnect();
    }

    sectionTargetConnected(section) {
        this.#spy?.observe(section);
    }

    sectionTargetDisconnected(section) {
        this.#spy?.unobserve(section);
        this.#visible.delete(section);
    }

    filter() {
        this.apply();
    }

    clear() {
        this.inputTarget.value = '';
        if (this.hasModifiedTarget) {
            this.modifiedTarget.checked = false;
        }
        this.apply();
        this.inputTarget.focus();
    }

    inputKey(event) {
        if (event.key === 'Escape' && this.inputTarget.value !== '') {
            event.preventDefault();
            event.stopPropagation();
            this.clear();
        }
    }

    /** Pulsar una categoría lleva a su sección; si el filtro la oculta, primero se quita. */
    go(event) {
        event.preventDefault();
        const slug = event.currentTarget.dataset.category;
        const section = this.sectionTargets.find((s) => s.dataset.category === slug);
        if (!section) {
            return;
        }
        if (section.classList.contains('hidden')) {
            this.clear();
        }
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', `#${section.id}`);
        this.#markActive(slug);
    }

    apply() {
        const terms    = this.#normalize(this.inputTarget.value).split(/\s+/).filter(Boolean);
        const onlyMod  = this.hasModifiedTarget && this.modifiedTarget.checked;
        const filtered = terms.length > 0 || onlyMod;
        const shown    = {};
        let total      = 0;
        let matches    = 0;

        this.itemTargets.forEach((item) => {
            total++;
            const haystack = this.#normalize(item.dataset.search ?? item.textContent ?? '');
            const match    = terms.every((term) => haystack.includes(term)) && (!onlyMod || item.dataset.modified === '1');
            item.classList.toggle('hidden', !match);
            if (match) {
                matches++;
                shown[item.dataset.category] = (shown[item.dataset.category] ?? 0) + 1;
            }
        });

        this.sectionTargets.forEach((section) => section.classList.toggle('hidden', (shown[section.dataset.category] ?? 0) === 0));

        this.chipTargets.forEach((chip) => {
            const count = shown[chip.dataset.category] ?? 0;
            const badge = chip.querySelector('[data-role="count"]');
            if (badge) {
                badge.textContent = filtered ? String(count) : chip.dataset.total;
            }
            chip.classList.toggle('opacity-40', filtered && count === 0);
        });

        if (this.hasModifiedCountTarget) {
            this.modifiedCountTarget.textContent = String(this.itemTargets.filter((item) => item.dataset.modified === '1').length);
        }
        this.clearTarget.classList.toggle('hidden', this.inputTarget.value === '');
        this.hintTarget.classList.toggle('sm:inline-block', this.inputTarget.value === '');
        this.statusTarget.textContent = filtered
            ? this.countFilteredValue.replace('%shown%', String(matches)).replace('%total%', String(total))
            : this.countAllValue.replace('%count%', String(total));

        this.emptyTarget.classList.toggle('hidden', matches > 0);
        this.emptyTarget.textContent = this.emptyValue.replace('%query%', this.inputTarget.value.trim());
    }

    #onRender = () => {
        // El repintado puede traer secciones y ajustes nuevos: se aplica de nuevo el filtro vigente.
        queueMicrotask(() => this.apply());
    };

    /** «/» lleva el foco al buscador, como en tantas aplicaciones, salvo que ya se esté escribiendo en un campo. */
    #onKey = (event) => {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        const target = event.target;
        if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
            return;
        }
        // Con varios paneles de ajustes en la página, solo responde el que está a la vista.
        if (this.inputTarget.offsetParent === null) {
            return;
        }
        event.preventDefault();
        this.inputTarget.focus();
        this.inputTarget.select();
    };

    #spyEntries(entries) {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                this.#visible.add(entry.target);
            } else {
                this.#visible.delete(entry.target);
            }
        });

        const current = this.sectionTargets.find((section) => this.#visible.has(section) && !section.classList.contains('hidden'));
        if (current) {
            this.#markActive(current.dataset.category);
        }
    }

    #markActive(slug) {
        this.chipTargets.forEach((chip) => {
            const active = chip.dataset.category === slug;
            chip.classList.toggle('bg-forest-600', active);
            chip.classList.toggle('border-forest-600', active);
            chip.classList.toggle('text-white', active);
            chip.classList.toggle('bg-white', !active);
            chip.classList.toggle('text-gray-600', !active);
            if (active) {
                chip.setAttribute('aria-current', 'true');
                chip.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            } else {
                chip.removeAttribute('aria-current');
            }
        });
    }

    #scrollToHash() {
        if (!location.hash.startsWith('#settings-cat-')) {
            return;
        }
        const section = this.sectionTargets.find((s) => `#${s.id}` === location.hash);
        if (section) {
            requestAnimationFrame(() => section.scrollIntoView({ block: 'start' }));
        }
    }

    #normalize(text) {
        return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }
}
