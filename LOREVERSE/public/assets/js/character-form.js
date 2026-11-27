const characterForm = document.querySelector('[data-character-form]');
if (characterForm) {
    const picture = characterForm.querySelector('[data-character-preview]');
    const file = characterForm.querySelector('[data-character-file]');
    const selected = characterForm.querySelector('[data-character-file-selected]');
    let previewUrl = null;

    file.addEventListener('change', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = file.files[0] ? URL.createObjectURL(file.files[0]) : null;
        picture.src = previewUrl ?? picture.dataset.original;
    });

    if (selected) {
        characterForm.addEventListener('submit', () => {
            selected.value = file.files.length ? '1' : '0';
        });
    }
}
