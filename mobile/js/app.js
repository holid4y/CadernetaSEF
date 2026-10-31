import { watchViewport } from './viewport.js';
import { config } from './config.js';
import { applyTheme } from './theme.js';
import { currentPath, onTabPage, refresh, route, start } from './router.js';
import { store } from './store.js';
import { icon, toast } from './ui.js';
import agenda from './views/agenda.js';
import appointmentDetail from './views/appointment.js';
import { clientDetail, clientForm, clientList } from './views/clients.js';
import home from './views/home.js';
import impact from './views/impact.js';
import login from './views/login.js';
import newAppointment from './views/newAppointment.js';
import services from './views/services.js';

if (window.Capacitor?.isNativePlatform?.()) document.documentElement.classList.add('is-native');
watchViewport();
applyTheme();

route('/login', login, { public: true });
route('/', home, { tab: 'home' });
route('/agenda', agenda, { tab: 'agenda' });
route('/atendidos', clientList, { tab: 'clients' });
route('/atendidos/novo', clientForm);
route('/atendidos/:id', clientDetail);
route('/atendidos/:id/editar', clientForm);
route('/atendimentos/novo', newAppointment);
route('/atendimentos/:id', appointmentDetail);
route('/impacto', impact, { tab: 'impact' });
route('/servicos', services);

document.getElementById('tabbar').innerHTML = `
    <a class="tab" href="#/" data-tab="home">${icon('home')}Início</a>
    <a class="tab" href="#/agenda" data-tab="agenda">${icon('calendar')}Agenda</a>
    <a class="tab-new" href="#/atendimentos/novo" aria-label="Novo atendimento">${icon('plus')}</a>
    <a class="tab" href="#/atendidos" data-tab="clients">${icon('users')}Atendidos</a>
    <a class="tab" href="#/impacto" data-tab="impact">${icon('chart')}Impacto</a>`;

start(document.getElementById('view'));

// Back in the app after a while: show what the rest of the team changed.
// Forms and details keep what is on screen until the person moves on.
if (config.mode === 'server') {
    document.addEventListener('visibilitychange', () => {
        const backFromGoogle = store.awaitingGoogle;
        if (document.visibilityState !== 'visible' || !store.user || (!backFromGoogle && Date.now() - store.loadedAt < 30_000)) return;
        store
            .reload()
            .then(() => {
                if (backFromGoogle && store.state.google?.connected) toast(`Google Agenda conectada a ${store.state.google.email}.`);
                if (onTabPage() || (backFromGoogle && /^\/atendimentos\/\d+$/.test(currentPath()))) refresh();
            })
            .catch((error) => toast(error.message));
    });
}

if (config.mode === 'demo' && !window.Capacitor?.isNativePlatform?.() && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js').catch(() => {});
}
