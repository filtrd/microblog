import { initVideoEmbeds } from './video.js';
import { initGalleries } from './gallery.js';

export function initFeed() {
    const feed = document.getElementById('feed');
    const feedSentinel = document.getElementById('feed-sentinel');
    const feedStatus = document.getElementById('feed-status');

    if (!feed || !feedSentinel || !('IntersectionObserver' in window)) return;

    const profileUsername = feed.dataset.profileUsername || '';
    const params = new URLSearchParams();
    if (profileUsername) params.set('u', profileUsername);
    const endpoint = 'feed.php?' + params.toString();
    const separator = endpoint.endsWith('?') ? '' : '&';

    let loadingFeed = false;
    let feedObserverArmed = true;

    const observer = new IntersectionObserver(entries => {
        const entry = entries[0];
        if (!entry) return;

        if (!entry.isIntersecting) {
            feedObserverArmed = true;
            return;
        }

        if (!feedObserverArmed || loadingFeed) return;
        feedObserverArmed = false;
        observer.disconnect();
        loadMorePosts();
    }, { rootMargin: '0px' });

    async function loadMorePosts() {
        if (loadingFeed || feed.dataset.hasMore !== '1') return;
        const cursor = feed.dataset.nextCursor;
        if (!cursor) return;

        loadingFeed = true;
        feedObserverArmed = false;
        observer.disconnect();
        if (feedStatus) {
            feedStatus.hidden = false;
            feedStatus.textContent = 'Loading more posts…';
        }

        try {
            // Temporary delay for testing infinite-scroll pagination visibility.
            await new Promise(resolve => setTimeout(resolve, 3000));
            const response = await fetch(endpoint + separator + 'cursor=' + encodeURIComponent(cursor), {
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) throw new Error('Feed request failed');

            const data = await response.json();
            if (data.html) {
                feed.insertAdjacentHTML('beforeend', data.html);
                initVideoEmbeds(feed);
                initGalleries(feed);
            }
            feed.dataset.nextCursor = data.next_cursor || '';
            feed.dataset.hasMore = data.has_more ? '1' : '0';
            if (!data.has_more && feedStatus) feedStatus.hidden = true;
        } catch (error) {
            if (feedStatus) {
                feedStatus.hidden = false;
                feedStatus.textContent = 'Could not load more posts. Try again.';
            }
        } finally {
            loadingFeed = false;
            if (feed.dataset.hasMore === '1') observer.observe(feedSentinel);
        }
    }

    observer.observe(feedSentinel);

    window.addEventListener('scroll', () => {
        if (!loadingFeed && feed.dataset.hasMore === '1' && !feedObserverArmed) {
            observer.observe(feedSentinel);
        }
    }, { passive: true });
}
