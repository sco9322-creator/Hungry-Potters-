'use strict';

(() => {
    const unitSelect = document.querySelector('[data-inventory-unit]');
    const customUnitField = document.querySelector('[data-custom-unit-field]');
    const customUnitInput = document.querySelector('[data-custom-unit]');

    if (!unitSelect || !customUnitField || !customUnitInput) {
        return;
    }

    const updateCustomUnit = () => {
        const usesCustomUnit = unitSelect.value === '__custom__';
        customUnitField.classList.toggle('hidden', !usesCustomUnit);
        customUnitField.classList.toggle('flex', usesCustomUnit);
        customUnitInput.required = usesCustomUnit;
        if (!usesCustomUnit) {
            customUnitInput.value = '';
        }
    };

    unitSelect.addEventListener('change', updateCustomUnit);
    updateCustomUnit();
})();
