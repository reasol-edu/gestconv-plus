import { Controller } from '@hotwired/stimulus';

const SIZE_UNITS = ['B', 'KB', 'MB', 'GB'];

function formatFileSize(bytes) {
    if (bytes <= 0) {
        return `0 ${SIZE_UNITS[0]}`;
    }

    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), SIZE_UNITS.length - 1);
    const value    = bytes / (1024 ** exponent);
    const decimals = exponent === 0 ? 0 : 1;

    return `${value.toFixed(decimals).replace('.', ',').replace(/,0$/, '')} ${SIZE_UNITS[exponent]}`;
}

// Zona de arrastrar y soltar para adjuntar ficheros. Sincroniza los ficheros
// soltados o seleccionados con el <input type="file"> nativo mediante
// DataTransfer (el input se conserva como alternativa accesible: clic o
// teclado abren el selector nativo del sistema) y muestra una vista previa
// donde se puede quitar cada fichero antes de enviar el formulario.
//
// Los límites (por fichero, total por envío y número de ficheros) llegan del servidor ya acotados
// por los de PHP. Si la selección no los cumple se avisa y se impide el envío: el servidor lo
// rechazaría igualmente y, si el cuerpo supera post_max_size, PHP lo descarta entero.
export default class extends Controller {
    static targets = ['dropzone', 'input', 'list', 'itemTemplate', 'clientError'];
    static values  = {
        maxSize: Number,
        maxTotal: Number,
        maxFiles: Number,
        tooLargeMessage: String,
        totalTooLargeMessage: String,
        tooManyMessage: String,
        single: Boolean,
    };

    connect() {
        this.dragDepth = 0;
        this.invalid   = false;
        this.form      = this.element.closest('form');
        if (this.form) {
            this.form.addEventListener('submit', this.onSubmit);
        }
        this.render();
    }

    disconnect() {
        if (this.form) {
            this.form.removeEventListener('submit', this.onSubmit);
        }
    }

    // Se cancela el envío si hay una selección inválida. El controlador form-submit comprueba
    // event.defaultPrevented (con setTimeout) antes de deshabilitar el botón, así que el
    // formulario queda utilizable para corregir la selección.
    onSubmit = (event) => {
        if (!this.invalid) {
            return;
        }

        event.preventDefault();
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.clientErrorTarget.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    };

    dragEnter(event) {
        event.preventDefault();
        this.dragDepth++;
        this.setActive(true);
    }

    dragOver(event) {
        event.preventDefault();
    }

    dragLeave(event) {
        event.preventDefault();
        this.dragDepth = Math.max(0, this.dragDepth - 1);
        if (this.dragDepth === 0) {
            this.setActive(false);
        }
    }

    drop(event) {
        event.preventDefault();
        this.dragDepth = 0;
        this.setActive(false);
        this.addFiles(event.dataTransfer.files);
    }

    triggerBrowse() {
        this.inputTarget.click();
    }

    change() {
        this.render();
    }

    removeFile(event) {
        const index = Number(event.params.index);
        this.assignFiles(Array.from(this.inputTarget.files).filter((_, i) => i !== index));
        this.render();
    }

    addFiles(fileList) {
        if (this.singleValue) {
            // Modo de un solo fichero (p. ej. plantillas PDF de ajustes): un nuevo
            // fichero soltado o seleccionado sustituye al anterior, no se acumula.
            const [file] = fileList;
            if (file) {
                this.assignFiles([file]);
            }
            this.render();
            return;
        }

        const existing = Array.from(this.inputTarget.files);
        const incoming = Array.from(fileList).filter((file) => !existing.some(
            (current) => current.name === file.name && current.size === file.size && current.lastModified === file.lastModified,
        ));

        this.assignFiles([...existing, ...incoming]);
        this.render();
    }

    assignFiles(files) {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        this.inputTarget.files = transfer.files;
    }

    setActive(active) {
        this.dropzoneTarget.classList.toggle('border-forest-400', active);
        this.dropzoneTarget.classList.toggle('bg-forest-50/50', active);
        this.dropzoneTarget.classList.toggle('border-gray-200', !active);
        this.dropzoneTarget.classList.toggle('bg-gray-50', !active);
    }

    render() {
        const files = Array.from(this.inputTarget.files);

        this.listTarget.innerHTML = '';
        files.forEach((file, index) => this.listTarget.appendChild(this.buildItem(file, index)));

        this.validate(files);
    }

    // Muestra el primer problema de la selección (fichero demasiado grande, demasiados ficheros o
    // total excesivo) y recuerda si el formulario puede enviarse.
    validate(files) {
        let message = '';

        const oversized = this.maxSizeValue > 0 ? files.find((file) => file.size > this.maxSizeValue) : null;
        if (oversized) {
            message = this.tooLargeMessageValue.replace('%filename%', oversized.name);
        } else if (this.maxFilesValue > 0 && files.length > this.maxFilesValue) {
            message = this.tooManyMessageValue;
        } else if (this.maxTotalValue > 0) {
            const total = files.reduce((sum, file) => sum + file.size, 0);
            if (total > this.maxTotalValue) {
                message = this.totalTooLargeMessageValue.replace('%total%', formatFileSize(total));
            }
        }

        this.invalid = message !== '';
        this.clientErrorTarget.textContent = message;
        this.clientErrorTarget.classList.toggle('hidden', !this.invalid);
    }

    buildItem(file, index) {
        const fragment = this.itemTemplateTarget.content.cloneNode(true);

        fragment.querySelector('[data-role="name"]').textContent = file.name;
        fragment.querySelector('[data-role="size"]').textContent = formatFileSize(file.size);
        fragment.querySelector('[data-action*="removeFile"]').dataset.fileDropIndexParam = String(index);

        return fragment;
    }
}
