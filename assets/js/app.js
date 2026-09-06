import { initCommon } from './common.js';
import { initFeed } from './feed.js';
import { initComposer, initCharacterCounter } from './composer.js';
import { initVideoEmbeds } from './video.js';
import { initProfile } from './profile.js';

initCommon();
initFeed();
initVideoEmbeds();

if (document.querySelector('.composer')) {
    initComposer();
}

const editForm = document.querySelector('.edit-post-form');
if (editForm) {
    const textarea = editForm.querySelector('textarea[name="content"]');
    const counter = document.getElementById('edit-char-count');
    const maxPostLength = Number(editForm.dataset.maxPostLength || 0);
    initCharacterCounter(textarea, counter, maxPostLength);
}

if (document.querySelector('.profile-details')) {
    initProfile();
}

const avatarUpload = document.getElementById('avatar-upload');
if (avatarUpload) {
    avatarUpload.addEventListener('change', () => {
        if (avatarUpload.files.length) avatarUpload.closest('form')?.submit();
    });
}
