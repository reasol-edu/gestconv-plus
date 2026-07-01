import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'noMeasureCheckbox',
        'measureSelector',
        'measureLabel',
        'measureCheckbox',
        'noMeasureReasonBlock',
        'dateBlock',
        'effectiveFrom',
        'effectiveTo',
    ];

    connect() {
        this.sync();
        this.updateDates();
    }

    toggle() {
        this.sync();
    }

    sync() {
        const noMeasure = this.noMeasureCheckboxTarget.checked;

        // Disable / enable measure checkboxes
        this.measureLabelTargets.forEach(label => {
            label.style.opacity = noMeasure ? '0.4' : '';
            label.style.pointerEvents = noMeasure ? 'none' : '';
        });
        this.measureCheckboxTargets.forEach(cb => {
            cb.disabled = noMeasure;
        });

        // Show / hide no-measure reason field
        this.noMeasureReasonBlockTarget.classList.toggle('hidden', !noMeasure);

        // Recalculate date visibility
        this.updateDates();
    }

    updateDates() {
        const noMeasure = this.noMeasureCheckboxTarget.checked;
        const requiresDates = !noMeasure && this.measureCheckboxTargets.some(
            cb => cb.checked && cb.dataset.hasDateRange === '1'
        );

        this.dateBlockTarget.classList.toggle('hidden', !requiresDates);

        if (!requiresDates) {
            if (this.hasEffectiveFromTarget) this.effectiveFromTarget.required = false;
            if (this.hasEffectiveToTarget)   this.effectiveToTarget.required   = false;
        } else {
            if (this.hasEffectiveFromTarget) this.effectiveFromTarget.required = true;
            if (this.hasEffectiveToTarget)   this.effectiveToTarget.required   = true;
        }
    }
}
