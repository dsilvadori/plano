

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('lessonCarousel', () => ({
    atStart: true,
    atEnd: false,
    activeIndex: 0,
    itemCount: 0,

    init() {
        this.itemCount = this.$refs.track?.querySelectorAll('[data-carousel-item]').length ?? 0;
        this.$nextTick(() => this.update());
    },

    scroll(direction) {
        const track = this.$refs.track;

        if (!track) {
            return;
        }

        const firstItem = track.querySelector('[data-carousel-item]');
        const gap = 16;
        const distance = firstItem ? firstItem.getBoundingClientRect().width + gap : track.clientWidth;

        track.scrollBy({
            left: direction * distance,
            behavior: 'smooth',
        });
    },

    goTo(index) {
        const track = this.$refs.track;
        const item = track?.querySelectorAll('[data-carousel-item]')[index];

        item?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'start',
        });
    },

    update() {
        const track = this.$refs.track;

        if (!track) {
            return;
        }

        const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);

        this.atStart = track.scrollLeft <= 4;
        this.atEnd = track.scrollLeft >= maxScroll - 4;

        const items = Array.from(track.querySelectorAll('[data-carousel-item]'));
        const trackLeft = track.getBoundingClientRect().left;
        const nearest = items
            .map((item, index) => ({
                index,
                distance: Math.abs(item.getBoundingClientRect().left - trackLeft),
            }))
            .sort((left, right) => left.distance - right.distance)[0];

        this.activeIndex = nearest?.index ?? 0;
    },
}));

Alpine.start();

const themeColorMeta = document.querySelector('meta[name="theme-color"]');

const applyTheme = (theme) => {
    const normalizedTheme = theme === 'light' ? 'light' : 'dark';

    document.documentElement.dataset.theme = normalizedTheme;
    localStorage.setItem('vc-theme', normalizedTheme);
    themeColorMeta?.setAttribute('content', normalizedTheme === 'light' ? '#f1f5f9' : '#050816');

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String(normalizedTheme === 'light'));
    });
};

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        applyTheme(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light');
    });
});

applyTheme(document.documentElement.dataset.theme || 'dark');

const disabledServiceWorkerHosts = [
    'localhost',
    '127.0.0.1',
    'dev.vencendoconcursos.com.br',
];

const isServiceWorkerDisabled = disabledServiceWorkerHosts.includes(window.location.hostname);

if ('serviceWorker' in navigator && ! isServiceWorkerDisabled) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    });
}

if ('serviceWorker' in navigator && isServiceWorkerDisabled) {
    window.addEventListener('load', async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();

        await Promise.all(registrations.map((registration) => registration.unregister()));

        if ('caches' in window) {
            const keys = await window.caches.keys();
            await Promise.all(keys.map((key) => window.caches.delete(key)));
        }
    });
}

let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;

    const installButton = document.getElementById('install-app-button');

    if (installButton) {
        installButton.classList.remove('hidden');
        installButton.onclick = async () => {
            if (!deferredInstallPrompt) {
                return;
            }

            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            installButton.classList.add('hidden');
        };
    }
});

window.addEventListener('load', () => {
    const isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    const isInStandaloneMode = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    const iosHint = document.getElementById('ios-install-hint');

    if (isIos && !isInStandaloneMode && iosHint) {
        iosHint.classList.remove('hidden');
    }
});
