export function postCharacterCount(value) {
    let count = 0;
    let lastIndex = 0;
    const urlPattern = /https?:\/\/[^\s<]+/gi;
    let match;

    while ((match = urlPattern.exec(value)) !== null) {
        count += Array.from(value.slice(lastIndex, match.index)).length;
        const url = match[0];
        const trailingMatch = url.match(/[.,!?;:)\]}]+$/);
        const trailing = trailingMatch ? trailingMatch[0] : '';
        count += 23;
        count += Array.from(trailing).length;
        lastIndex = match.index + url.length;
    }

    count += Array.from(value.slice(lastIndex)).length;
    return count;
}

export function initCharacterCounter(textarea, counter, maxPostLength) {
    if (!textarea || !counter || !maxPostLength) return;

    function updateCounter() {
        counter.textContent = postCharacterCount(textarea.value) + '/' + maxPostLength;
    }

    function enforcePostLength() {
        const characters = Array.from(textarea.value);
        if (postCharacterCount(textarea.value) <= maxPostLength) return;

        let low = 0;
        let high = characters.length;
        while (low < high) {
            const mid = Math.ceil((low + high) / 2);
            const candidate = characters.slice(0, mid).join('');
            if (postCharacterCount(candidate) <= maxPostLength) low = mid;
            else high = mid - 1;
        }

        const cursor = textarea.selectionStart;
        textarea.value = characters.slice(0, low).join('');
        const newCursor = Math.min(cursor, textarea.value.length);
        textarea.selectionStart = newCursor;
        textarea.selectionEnd = newCursor;
        updateCounter();
    }

    textarea.addEventListener('input', () => {
        enforcePostLength();
        updateCounter();
    });
    updateCounter();
}

export function initComposer() {
    const textarea = document.querySelector('textarea[name="content"]');
    const counter = document.getElementById('char-count');
    const imageButton = document.getElementById('image-button');
    const imageUpload = document.getElementById('image-upload');
    const imageUrl = document.getElementById('image-url');
    const selectedImage = document.getElementById('selected-image');
    const imageDialog = document.getElementById('image-dialog');
    const uploadImageOption = document.getElementById('upload-image-option');
    const urlImageOption = document.getElementById('url-image-option');
    const imageDialogCancel = document.getElementById('image-dialog-cancel');
    const imageUrlForm = document.getElementById('image-url-form');
    const imageUrlInput = document.getElementById('image-url-input');
    const imageUrlCancel = document.getElementById('image-url-cancel');
    const imageUrlAdd = document.getElementById('image-url-add');
    const emojiButton = document.getElementById('emoji-button');
    const emojiPicker = document.getElementById('emoji-picker');
    const composer = document.querySelector('.composer');
    const maxPostLength = Number(composer?.dataset.maxPostLength || 0);

    initCharacterCounter(textarea, counter, maxPostLength);

    function resetImageDialog() {
        if (imageUrlForm) imageUrlForm.hidden = true;
        if (imageUrlInput) imageUrlInput.value = imageUrl?.value || '';
        if (uploadImageOption) uploadImageOption.hidden = false;
        if (urlImageOption) urlImageOption.hidden = false;
        if (imageDialogCancel) imageDialogCancel.hidden = false;
    }

    if (imageButton && imageDialog) {
        imageButton.addEventListener('click', () => {
            resetImageDialog();
            imageDialog.showModal();
        });

        uploadImageOption?.addEventListener('click', () => {
            imageUrl.value = '';
            imageDialog.close();
            imageUpload?.click();
        });

        urlImageOption?.addEventListener('click', () => {
            if (imageUrlForm) imageUrlForm.hidden = false;
            if (uploadImageOption) uploadImageOption.hidden = true;
            if (urlImageOption) urlImageOption.hidden = true;
            if (imageDialogCancel) imageDialogCancel.hidden = true;
            imageUrlInput?.focus();
        });

        imageDialogCancel?.addEventListener('click', () => imageDialog.close());
        imageUrlCancel?.addEventListener('click', () => imageDialog.close());

        imageUrlAdd?.addEventListener('click', () => {
            const value = imageUrlInput?.value.trim() || '';
            try {
                const parsed = new URL(value);
                if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error();
                imageUrl.value = value;
                imageUpload.value = '';
                if (selectedImage) selectedImage.textContent = value;
                imageDialog.close();
            } catch {
                imageUrlInput?.focus();
            }
        });

        imageDialog.addEventListener('close', resetImageDialog);
    }

    if (imageUpload) {
        imageUpload.addEventListener('change', () => {
            imageUrl.value = '';
            if (selectedImage) {
                selectedImage.textContent = imageUpload.files.length ? imageUpload.files[0].name : '';
            }
        });
    }

    if (imageUrl?.value && selectedImage) {
        selectedImage.textContent = imageUrl.value;
    }

    if (emojiButton && emojiPicker && textarea) {
        emojiButton.addEventListener('click', () => {
            emojiPicker.hidden = !emojiPicker.hidden;
        });

        emojiPicker.querySelectorAll('button').forEach(button => {
            button.addEventListener('click', () => {
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const emoji = button.textContent;
                textarea.value = textarea.value.slice(0, start) + emoji + textarea.value.slice(end);
                textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
                textarea.focus();
                textarea.dispatchEvent(new Event('input'));
                emojiPicker.hidden = true;
            });
        });
    }
}
