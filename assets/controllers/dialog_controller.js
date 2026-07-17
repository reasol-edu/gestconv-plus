import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dialog'];

    connect() {
        this.dialogTarget.addEventListener('close', this.handleClose);
        this.dialogTarget.addEventListener('click', this.handleBackdropClick);
    }

    disconnect() {
        this.dialogTarget.removeEventListener('close', this.handleClose);
        this.dialogTarget.removeEventListener('click', this.handleBackdropClick);
    }

    open() {
        this.dialogTarget.showModal();
        this.dispatch('open');
    }

    close() {
        this.dialogTarget.close();
    }

    handleClose = () => {
        this.dispatch('close');
    };

    handleBackdropClick = (event) => {
        if (event.target === this.dialogTarget) {
            this.dialogTarget.close();
        }
    };
}
