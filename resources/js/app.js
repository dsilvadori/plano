

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
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
