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
    const imageUrlsInput = document.getElementById('image-urls');
    const imageOrderInput = document.getElementById('image-order');
    const selectedImages = document.getElementById('selected-images');
    const imageCount = document.getElementById('image-count');
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
    const composerDialog = document.querySelector('.composer-dialog');
    const composerOpen = document.getElementById('composer-open');
    const composerClose = document.getElementById('composer-close');
    const maxPostLength = Number(composer?.dataset.maxPostLength || 0);
    const maxImages = 3;

    initCharacterCounter(textarea, counter, maxPostLength);

    if (composerDialog) {
        const openComposer = () => {
            if (!composerDialog.open) composerDialog.showModal();
            textarea?.focus();
        };

        composerOpen?.addEventListener('click', openComposer);
        composerClose?.addEventListener('click', () => {
            const closeUrl = composerDialog.dataset.closeUrl;
            if (closeUrl) {
                window.location.href = closeUrl;
                return;
            }
            composerDialog.close();
        });
        composerDialog.addEventListener('click', event => {
            if (event.target === composerDialog) {
                const closeUrl = composerDialog.dataset.closeUrl;
                if (closeUrl) {
                    window.location.href = closeUrl;
                    return;
                }
                composerDialog.close();
            }
        });
        composerDialog.addEventListener('close', () => composerOpen?.focus());

        if (composerDialog.dataset.openOnLoad === '1') openComposer();
    }

    if (!selectedImages || !imageUpload || !imageUrlsInput || !imageOrderInput) {
        initEmojiPicker(emojiButton, emojiPicker, textarea);
        return;
    }

    const items = [];

    selectedImages.querySelectorAll('.selected-image').forEach(element => {
        const id = element.dataset.imageId;
        const src = element.dataset.imageSrc;
        if (id && src) items.push({ type: 'existing', id, src });
    });

    function syncFiles() {
        const dataTransfer = new DataTransfer();
        items.filter(item => item.type === 'file').forEach(item => dataTransfer.items.add(item.file));
        imageUpload.files = dataTransfer.files;
    }

    function syncFields() {
        const urls = [];
        const order = [];
        let fileIndex = 0;
        let urlIndex = 0;

        items.forEach(item => {
            if (item.type === 'existing') {
                order.push(`existing:${item.id}`);
            } else if (item.type === 'file') {
                order.push(`file:${fileIndex++}`);
            } else if (item.type === 'url') {
                urls.push(item.url);
                order.push(`url:${urlIndex++}`);
            }
        });

        imageUrlsInput.value = JSON.stringify(urls);
        imageOrderInput.value = JSON.stringify(order);
        if (imageCount) imageCount.textContent = items.length ? `${items.length}/${maxImages}` : '';
        if (imageButton) imageButton.disabled = items.length >= maxImages;
    }

    function renderItems() {
        selectedImages.innerHTML = '';
        items.forEach((item, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'selected-image';
            wrapper.dataset.index = String(index);

            const image = document.createElement('img');
            image.src = item.src;
            image.alt = '';
            wrapper.appendChild(image);

            const actions = document.createElement('div');
            actions.className = 'selected-image-actions';

            const left = document.createElement('button');
            left.type = 'button';
            left.dataset.imageMove = 'left';
            left.setAttribute('aria-label', 'Move image left');
            left.textContent = '‹';
            left.disabled = index === 0;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.imageRemove = '';
            remove.setAttribute('aria-label', 'Remove image');
            remove.textContent = '×';

            const right = document.createElement('button');
            right.type = 'button';
            right.dataset.imageMove = 'right';
            right.setAttribute('aria-label', 'Move image right');
            right.textContent = '›';
            right.disabled = index === items.length - 1;

            actions.append(left, remove, right);
            wrapper.appendChild(actions);
            selectedImages.appendChild(wrapper);
        });
        syncFields();
    }

    function removeItem(index) {
        const item = items[index];
        if (!item) return;
        if (item.type === 'file' && item.src.startsWith('blob:')) URL.revokeObjectURL(item.src);
        items.splice(index, 1);
        syncFiles();
        renderItems();
    }

    function moveItem(index, direction) {
        const target = index + direction;
        if (target < 0 || target >= items.length) return;
        [items[index], items[target]] = [items[target], items[index]];
        syncFiles();
        renderItems();
    }

    selectedImages.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        const wrapper = button.closest('.selected-image');
        if (!wrapper) return;
        const index = Number(wrapper.dataset.index);
        if (button.hasAttribute('data-image-remove')) removeItem(index);
        if (button.dataset.imageMove === 'left') moveItem(index, -1);
        if (button.dataset.imageMove === 'right') moveItem(index, 1);
    });

    function addFiles(fileList) {
        const available = maxImages - items.length;
        if (available <= 0) return;
        Array.from(fileList).slice(0, available).forEach(file => {
            items.push({ type: 'file', file, src: URL.createObjectURL(file) });
        });
        syncFiles();
        renderItems();
    }

    function resetImageDialog() {
        if (imageUrlForm) imageUrlForm.hidden = true;
        if (imageUrlInput) imageUrlInput.value = '';
        if (uploadImageOption) uploadImageOption.hidden = false;
        if (urlImageOption) urlImageOption.hidden = false;
        if (imageDialogCancel) imageDialogCancel.hidden = false;
    }

    if (imageButton && imageDialog) {
        imageButton.addEventListener('click', () => {
            if (items.length >= maxImages) return;
            resetImageDialog();
            imageDialog.showModal();
        });

        uploadImageOption?.addEventListener('click', () => {
            imageDialog.close();
            imageUpload.click();
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
                if (items.length >= maxImages) return;
                items.push({ type: 'url', url: value, src: value });
                renderItems();
                imageDialog.close();
            } catch {
                imageUrlInput?.focus();
            }
        });

        imageDialog.addEventListener('close', resetImageDialog);
    }

    imageUpload.addEventListener('change', () => {
        addFiles(imageUpload.files);
    });

    renderItems();
    initEmojiPicker(emojiButton, emojiPicker, textarea);
}

function initEmojiPicker(emojiButton, emojiPicker, textarea) {
    if (!emojiButton || !emojiPicker || !textarea) return;

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
