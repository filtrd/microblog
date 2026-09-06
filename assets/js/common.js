export function initCommon() {
    const deleteDialog = document.getElementById('delete-dialog');
    let pendingDeleteForm = null;

    if (deleteDialog) {
        document.addEventListener('submit', event => {
            const form = event.target.closest('.post-delete-form');
            if (!form) return;
            event.preventDefault();
            pendingDeleteForm = form;
            deleteDialog.showModal();
        });

        deleteDialog.addEventListener('close', () => {
            if (deleteDialog.returnValue === 'confirm' && pendingDeleteForm) {
                pendingDeleteForm.submit();
            }
            pendingDeleteForm = null;
        });
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('.post-menu-button');
        if (button) {
            event.stopPropagation();
            const dropdown = button.closest('.post-menu')?.querySelector('.post-menu-dropdown');
            if (!dropdown) return;

            const open = !dropdown.hidden;
            document.querySelectorAll('.post-menu-dropdown').forEach(item => item.hidden = true);
            document.querySelectorAll('.post-menu-button').forEach(item => item.setAttribute('aria-expanded', 'false'));
            dropdown.hidden = open;
            button.setAttribute('aria-expanded', String(!open));
            return;
        }

        const replyButton = event.target.closest('.comment-reply-button');
        if (replyButton) {
            const form = document.querySelector('[data-reply-form="' + replyButton.dataset.commentId + '"]');
            if (form) {
                form.hidden = !form.hidden;
                if (!form.hidden) form.querySelector('textarea')?.focus();
            }
            return;
        }

        const cancelButton = event.target.closest('.comment-cancel-button');
        if (cancelButton) {
            const form = document.querySelector('[data-reply-form="' + cancelButton.dataset.commentId + '"]');
            if (form) form.hidden = true;
            return;
        }

        document.querySelectorAll('.post-menu-dropdown').forEach(item => item.hidden = true);
        document.querySelectorAll('.post-menu-button').forEach(item => item.setAttribute('aria-expanded', 'false'));
    });
}
