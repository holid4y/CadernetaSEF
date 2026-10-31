// Livewire 4 bundles Alpine; a second copy would break x-data.
import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import { startCurtains } from './curtain';
import { trackNavigation } from './navigation';
import { startSmoothScroll } from './scroll';
import themeToggle, { keepThemeAcrossPages } from './theme';

Alpine.data('themeToggle', themeToggle);

let unmountAgenda = null;

document.addEventListener('livewire:navigating', () => {
    unmountAgenda?.();
    unmountAgenda = null;
});

document.addEventListener('livewire:navigated', async () => {
    const element = document.querySelector('[data-agenda]');

    if (element === null) {
        return;
    }

    const { mountAgenda } = await import('./agenda');
    unmountAgenda = mountAgenda(element, (url) => Livewire.navigate(url));
});

keepThemeAcrossPages();
trackNavigation();
startCurtains();
startSmoothScroll();

if (document.documentElement.dataset.preview !== undefined) {
    import('./preview').then(({ enablePreview }) => enablePreview(Livewire));
}

Livewire.start();
