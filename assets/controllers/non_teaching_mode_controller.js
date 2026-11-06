import { Controller } from '@hotwired/stimulus';

/** Alta de personal no docente: la contraseña inicial solo se pide si no se envía invitación. */
export default class extends Controller {
    static targets = ['passwordBlock', 'radio'];

    connect() {
        this.#apply();
    }

    toggle() {
        this.#apply();
    }

    #apply() {
        const invitation = this.radioTargets.find(r => r.checked)?.value === 'invitation';
        this.passwordBlockTarget.classList.toggle('hidden', invitation);
        this.passwordBlockTarget.querySelectorAll('input').forEach(input => {
            input.disabled = invitation;
        });
    }
}
