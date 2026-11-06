import { Controller } from '@hotwired/stimulus';

/**
 * Vista previa de la importación de la oferta formativa: el árbol de cursos y grupos. Marcar un curso marca todos sus
 * grupos, desmarcarlo los desmarca, y marcar un grupo marca también su curso (un grupo no se puede importar sin él).
 * El servidor valida lo mismo: esto solo facilita elegir.
 */
export default class extends Controller {
    static targets = ['course', 'group'];

    courseToggled(event) {
        const course = event.currentTarget.dataset.course;
        this.groupTargets
            .filter((group) => group.dataset.course === course)
            .forEach((group) => { group.checked = event.currentTarget.checked; });
    }

    groupToggled(event) {
        if (!event.currentTarget.checked) {
            return;
        }
        const course = this.courseTargets.find((target) => target.dataset.course === event.currentTarget.dataset.course);
        if (course) {
            course.checked = true;
        }
    }

    all() {
        [...this.courseTargets, ...this.groupTargets].forEach((input) => { input.checked = true; });
    }

    none() {
        [...this.courseTargets, ...this.groupTargets].forEach((input) => { input.checked = false; });
    }
}
