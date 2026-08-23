

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

Alpine.data('lessonCompletion', (initialCompleted = false) => ({
    completed: initialCompleted,
    loading: false,
    error: '',

    get statusLabel() {
        return this.completed ? 'Concluída' : 'Em andamento';
    },

    get buttonLabel() {
        return this.completed ? 'Desmarcar como concluída' : 'Marcar como concluída';
    },

    async toggle(event) {
        if (this.loading) {
            return;
        }

        this.loading = true;
        this.error = '';

        const form = event.target;
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    completed: ! this.completed,
                }),
            });

            if (!response.ok) {
                throw new Error('Não foi possível atualizar a aula.');
            }

            const data = await response.json();

            this.completed = Boolean(data.completed);
        } catch (error) {
            this.error = error.message || 'Não foi possível atualizar a aula.';
        } finally {
            this.loading = false;
        }
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

const installButtons = () => Array.from(document.querySelectorAll('[data-install-app-button]'));

const isRunningAsInstalledApp = () => {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
};

const updateInstallButtons = () => {
    installButtons().forEach((installButton) => {
        if (isRunningAsInstalledApp()) {
            installButton.classList.add('hidden');
            installButton.onclick = null;
            return;
        }

        installButton.classList.remove('hidden');
        installButton.textContent = 'Instalar aplicativo';

        installButton.onclick = async () => {
            if (!deferredInstallPrompt) {
                return;
            }

            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            updateInstallButtons();
        };
    });
};

updateInstallButtons();

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;

    updateInstallButtons();
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    updateInstallButtons();
});

window.addEventListener('load', () => {
    const isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    const isInStandaloneMode = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

    if (isIos && !isInStandaloneMode) {
        document.querySelectorAll('[data-ios-install-hint]').forEach((iosHint) => {
            iosHint.classList.remove('hidden');
        });
    }
});
