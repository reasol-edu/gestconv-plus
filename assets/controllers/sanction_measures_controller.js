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
        'followupBlock',
        'familyClaimedRadio',
        'familyClaimAttitudeBlock',
    ];

    connect() {
        this.sync();
        this.updateDates();
        this.updateFollowup();
    }

    toggle() {
        this.sync();
    }

    sync() {
        if (!this.hasNoMeasureCheckboxTarget) {
            this.updateFollowup();
            return;
        }

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
        if (this.hasNoMeasureReasonBlockTarget) {
            this.noMeasureReasonBlockTarget.classList.toggle('hidden', !noMeasure);
        }

        // Show / hide follow-up block (only meaningful when a measure was applied)
        if (this.hasFollowupBlockTarget) {
            this.followupBlockTarget.classList.toggle('hidden', noMeasure);
        }

        // Recalculate date visibility
        this.updateDates();
        this.updateFollowup();
    }

    updateDates() {
        if (!this.hasNoMeasureCheckboxTarget) return;
        const noMeasure = this.noMeasureCheckboxTarget.checked;
        const requiresDates = !noMeasure && this.measureCheckboxTargets.some(
            cb => cb.checked && cb.dataset.hasDateRange === '1'
        );

        if (this.hasDateBlockTarget) {
            this.dateBlockTarget.classList.toggle('hidden', !requiresDates);
        }

        if (!requiresDates) {
            if (this.hasEffectiveFromTarget) this.effectiveFromTarget.required = false;
            if (this.hasEffectiveToTarget)   this.effectiveToTarget.required   = false;
        } else {
            if (this.hasEffectiveFromTarget) this.effectiveFromTarget.required = true;
            if (this.hasEffectiveToTarget)   this.effectiveToTarget.required   = true;
        }
    }

    updateFollowup() {
        if (!this.hasFamilyClaimAttitudeBlockTarget) return;

        const claimed = this.hasFamilyClaimedRadioTarget
            && this.familyClaimedRadioTargets.some(r => r.value === '1' && r.checked);

        this.familyClaimAttitudeBlockTarget.classList.toggle('hidden', !claimed);
    }
}
