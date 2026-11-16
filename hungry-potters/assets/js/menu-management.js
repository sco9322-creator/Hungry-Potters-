'use strict';

(() => {
    const allowedExtensions = new Set(['jpg', 'jpeg', 'png', 'webp']);
    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const maximumSize = 2 * 1024 * 1024;
    const previewControls = new Map();

    document.querySelectorAll('[data-image-upload-control]').forEach((control) => {
        const input = control.querySelector('[data-image-upload]');
        const preview = control.querySelector('[data-image-preview]');
        const placeholder = control.querySelector('[data-image-placeholder]');
        const hint = control.querySelector('[data-image-hint]');
        const error = control.querySelector('[data-image-error]');
        const form = control.closest('form');
        const state = { invalidSelection: false, previewUrl: null };

        if (!input || !preview || !placeholder || !form) {
            return;
        }
        previewControls.set(control, state);

        const showError = (text) => {
            if (!error) {
                return;
            }
            error.textContent = text;
            error.classList.toggle('hidden', text === '');
        };

        const restoreCurrentImage = () => {
            if (state.previewUrl) {
                URL.revokeObjectURL(state.previewUrl);
                state.previewUrl = null;
            }
            const currentImage = preview.dataset.currentImage === 'true';
            preview.classList.toggle('hidden', !currentImage);
            placeholder.classList.toggle('hidden', currentImage);
            if (hint) {
                hint.textContent = currentImage ? 'Current image' : 'No image yet';
            }
        };

        input.addEventListener('change', () => {
            state.invalidSelection = false;
            showError('');
            const file = input.files?.[0];
            if (!file) {
                restoreCurrentImage();
                return;
            }

            const extension = file.name.split('.').pop()?.toLowerCase() || '';
            if (!allowedExtensions.has(extension) || (file.type && !allowedTypes.has(file.type))) {
                state.invalidSelection = true;
                input.value = '';
                restoreCurrentImage();
                showError('Unsupported image type. Choose a JPG, PNG, or WEBP image.');
                return;
            }
            if (file.size > maximumSize) {
                state.invalidSelection = true;
                input.value = '';
                restoreCurrentImage();
                showError('The image must be no larger than 2 MB.');
                return;
            }

            if (state.previewUrl) {
                URL.revokeObjectURL(state.previewUrl);
            }
            state.previewUrl = URL.createObjectURL(file);
            preview.src = state.previewUrl;
            preview.classList.remove('hidden');
            placeholder.classList.add('hidden');
            if (hint) {
                hint.textContent = file.name;
            }
        });

        form.addEventListener('submit', (event) => {
            if (state.invalidSelection) {
                event.preventDefault();
                input.focus();
            }
        });
    });

    const dialog = document.querySelector('[data-menu-dialog]');
    const form = document.querySelector('#menu-dish-form');
    const recipeRows = dialog?.querySelector('[data-recipe-rows]');
    const recipeTemplate = dialog?.querySelector('[data-recipe-row-template]');
    const recipeEmpty = dialog?.querySelector('[data-recipe-empty]');
    const recipeError = dialog?.querySelector('[data-recipe-error]');
    const modalTitle = dialog?.querySelector('[data-menu-modal-title]');
    const saveButton = dialog?.querySelector('[data-menu-save]');
    const confirmDialog = document.querySelector('[data-menu-confirm-dialog]');
    const confirmForm = document.querySelector('#menu-action-form');
    const confirmTitle = confirmDialog?.querySelector('[data-menu-confirm-title]');
    const confirmMessage = confirmDialog?.querySelector('[data-menu-confirm-message]');
    const confirmButton = confirmDialog?.querySelector('[data-submit-menu-action]');
    let recipeRowIndex = 0;

    const refreshRecipeRows = () => {
        if (!recipeRows || !recipeEmpty || !recipeError) {
            return;
        }
        const rows = [...recipeRows.querySelectorAll('[data-recipe-row]')];
        recipeEmpty.classList.toggle('hidden', rows.length > 0);
        const selectedIngredients = new Set();
        let duplicateFound = false;
        let unitMismatchFound = false;
        rows.forEach((row) => {
            const ingredient = row.querySelector('[data-recipe-ingredient]');
            const unit = row.querySelector('[data-recipe-unit]');
            if (!ingredient || !unit) {
                return;
            }
            ingredient.setCustomValidity('');
            unit.setCustomValidity('');
            if (ingredient.value !== '' && selectedIngredients.has(ingredient.value)) {
                ingredient.setCustomValidity('Choose each ingredient only once.');
                duplicateFound = true;
            }
            if (ingredient.value !== '') {
                selectedIngredients.add(ingredient.value);
            }
            const inventoryUnit = ingredient.selectedOptions[0]?.dataset.unit;
            if (ingredient.value !== '' && unit.value !== inventoryUnit) {
                unit.setCustomValidity('Recipe unit must match the ingredient inventory unit. Unit conversion is not supported.');
                unitMismatchFound = true;
            }
        });
        const errors = [];
        if (duplicateFound) {
            errors.push('Each ingredient can only be added once.');
        }
        if (unitMismatchFound) {
            errors.push('Each recipe unit must match its ingredient inventory unit.');
        }
        recipeError.textContent = errors.join(' ');
        recipeError.classList.toggle('hidden', errors.length === 0);
    };

    const addRecipeRow = (recipe = {}) => {
        if (!recipeRows || !recipeTemplate) {
            return;
        }
        const row = recipeTemplate.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace('__INDEX__', String(recipeRowIndex));
        });
        recipeRowIndex += 1;
        const ingredient = row.querySelector('[data-recipe-ingredient]');
        const unit = row.querySelector('[data-recipe-unit]');
        ingredient.value = String(recipe.inventoryId ?? '');
        row.querySelector('[data-recipe-quantity]').value = String(recipe.quantity ?? '');
        const inventoryUnit = ingredient.selectedOptions[0]?.dataset.unit;
        unit.value = String(inventoryUnit ?? recipe.unit ?? '');
        ingredient.addEventListener('change', () => {
            unit.value = ingredient.selectedOptions[0]?.dataset.unit ?? '';
            refreshRecipeRows();
        });
        unit.addEventListener('change', refreshRecipeRows);
        row.querySelector('[data-remove-recipe-row]').addEventListener('click', () => {
            row.remove();
            refreshRecipeRows();
        });
        recipeRows.append(row);
        refreshRecipeRows();
    };

    const setRecipeRows = (recipes) => {
        if (!recipeRows) {
            return;
        }
        recipeRows.replaceChildren();
        recipeRowIndex = 0;
        (Array.isArray(recipes) ? recipes : []).forEach(addRecipeRow);
        refreshRecipeRows();
    };

    dialog?.querySelector('[data-add-recipe-row]')?.addEventListener('click', () => addRecipeRow());

    form?.addEventListener('submit', (event) => {
        refreshRecipeRows();
        if (recipeRows?.querySelector('select:invalid')) {
            event.preventDefault();
            recipeRows.querySelector('select:invalid')?.focus();
        }
    });

    const setModalImage = (imageUrl) => {
        const control = dialog?.querySelector('[data-image-upload-control]');
        const preview = control?.querySelector('[data-image-preview]');
        const placeholder = control?.querySelector('[data-image-placeholder]');
        const hint = control?.querySelector('[data-image-hint]');
        const error = control?.querySelector('[data-image-error]');
        const state = control ? previewControls.get(control) : null;
        if (!preview || !placeholder) {
            return;
        }
        if (state) {
            state.invalidSelection = false;
        }
        error?.classList.add('hidden');
        if (error) {
            error.textContent = '';
        }
        if (state?.previewUrl) {
            URL.revokeObjectURL(state.previewUrl);
            state.previewUrl = null;
        }
        const hasImage = imageUrl !== '';
        preview.dataset.currentImage = hasImage ? 'true' : 'false';
        if (hasImage) {
            preview.src = imageUrl;
        } else {
            preview.removeAttribute('src');
        }
        preview.classList.toggle('hidden', !hasImage);
        placeholder.classList.toggle('hidden', hasImage);
        if (hint) {
            hint.textContent = hasImage ? 'Current image' : 'No image yet';
        }
    };

    document.querySelector('[data-open-add]')?.addEventListener('click', () => {
        if (!dialog || !form || !modalTitle || !saveButton) {
            return;
        }
        form.reset();
        form.querySelector('[data-menu-item-id]').value = '';
        form.querySelector('[data-menu-available]').checked = true;
        modalTitle.textContent = 'Add Dish';
        saveButton.textContent = 'Add Dish';
        setRecipeRows([]);
        setModalImage('');
        dialog.showModal();
        form.querySelector('[name="name"]').focus();
    });

    document.querySelectorAll('[data-edit-dish]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!dialog || !form || !modalTitle || !saveButton) {
                return;
            }
            const item = JSON.parse(button.dataset.editDish || '{}');
            form.reset();
            form.querySelector('[data-menu-item-id]').value = String(item.id ?? '');
            form.querySelector('[name="name"]').value = String(item.name ?? '');
            form.querySelector('[name="category_id"]').value = String(item.categoryId ?? '');
            form.querySelector('[name="price"]').value = String(item.price ?? '');
            form.querySelector('[data-menu-available]').checked = item.available === true;
            modalTitle.textContent = 'Edit Dish';
            saveButton.textContent = 'Save Changes';
            setRecipeRows(item.recipes);
            setModalImage(String(item.image ?? ''));
            dialog.showModal();
            form.querySelector('[name="name"]').focus();
        });
    });

    document.querySelectorAll('[data-close-menu-dialog]').forEach((button) => {
        button.addEventListener('click', () => dialog?.close());
    });

    document.querySelectorAll('[data-confirm-action]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!confirmDialog || !confirmTitle || !confirmMessage || !confirmForm) {
                return;
            }
            const action = button.dataset.confirmAction || 'archive';
            const itemName = button.dataset.itemName || 'this dish';
            const isArchive = action === 'archive';
            confirmTitle.textContent = isArchive ? 'Archive dish?' : 'Restore dish?';
            confirmMessage.textContent = isArchive
                ? `Archive ${itemName}? It will be removed from the active menu, while past order history remains unchanged.`
                : `Restore ${itemName} to the active menu?`;
            confirmForm.querySelector('[data-menu-action]').value = action;
            confirmForm.querySelector('[data-menu-action-item]').value = button.dataset.itemId || '';
            confirmButton.textContent = isArchive ? 'Archive dish' : 'Restore dish';
            confirmButton.classList.toggle('bg-error-container', isArchive);
            confirmButton.classList.toggle('text-on-error-container', isArchive);
            confirmButton.classList.toggle('bg-primary', !isArchive);
            confirmButton.classList.toggle('text-on-primary', !isArchive);
            confirmDialog.showModal();
        });
    });

    document.querySelector('[data-close-menu-confirm]')?.addEventListener('click', () => confirmDialog?.close());
    confirmButton?.addEventListener('click', () => confirmForm?.submit());
})();
