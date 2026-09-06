export function initGalleries(root = document) {
    root.querySelectorAll('[data-gallery]:not([data-gallery-ready])').forEach(initGallery);
}

function initGallery(gallery) {
    gallery.dataset.galleryReady = '1';
    const images = Array.from(gallery.querySelectorAll('.post-gallery-image'));
    if (images.length < 2) return;

    const dots = Array.from(gallery.querySelectorAll('[data-gallery-dot]'));
    const previous = gallery.querySelector('[data-gallery-prev]');
    const next = gallery.querySelector('[data-gallery-next]');
    let current = 0;

    function show(index) {
        current = (index + images.length) % images.length;
        images.forEach((image, imageIndex) => {
            image.hidden = imageIndex !== current;
        });
        dots.forEach((dot, dotIndex) => {
            const active = dotIndex === current;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }

    previous?.addEventListener('click', () => show(current - 1));
    next?.addEventListener('click', () => show(current + 1));
    dots.forEach(dot => {
        dot.addEventListener('click', () => show(Number(dot.dataset.galleryDot)));
    });

    show(0);
}
