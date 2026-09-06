import { initCommon } from './common.js';
import { initFeed } from './feed.js';
import { initComposer } from './composer.js';
import { initVideoEmbeds } from './video.js';
import { initProfile } from './profile.js';

initCommon();
initFeed();
initVideoEmbeds();

if (document.querySelector('.composer')) {
    initComposer();
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
