import { PROJECT, ROLES } from './data.js';
import { can } from './domain.js';
import { GOOGLE_MARK, openGoogle } from './google.js';
import { navigate, refresh } from './router.js';
import { store } from './store.js';
import { getPreference, setPreference } from './theme.js';
import { playFarewell, originOf } from './curtain.js';
import { avatar, confirmDialog, icon, openSheet, toast } from './ui.js';
import { digits, esc, firstName } from './util.js';

const THEMES = [
    ['light', 'Claro', 'sun'],
    ['dark', 'Escuro', 'moon'],
    ['auto', 'Automático', 'monitor'],
];

export function openProfile() {
    const user = store.user;
    const phoneDigits = digits(PROJECT.phone);

    openSheet({
        label: 'Perfil e configurações',
        html: `<div style="display:flex;align-items:center;gap:14px;margin-bottom:6px">
                ${avatar(user.name, 'is-lg')}
                <div><h2 style="margin:0">${esc(user.name)}</h2><span class="muted small">${ROLES[user.role]} · ${esc(user.email)}</span></div>
            </div>

            <div class="menu-label">Aparência</div>
            <div class="seg" role="radiogroup" aria-label="Tema">
                ${THEMES.map(([value, label, symbol]) => `<button type="button" role="radio" aria-checked="${getPreference() === value}" data-theme-option="${value}">${icon(symbol)}${label}</button>`).join('')}
            </div>

            ${store.isDemo ? `<div class="menu-label">Ver o app como (demonstração)</div>
            <div class="seg" role="radiogroup" aria-label="Perfil de demonstração">
                ${store.state.users
                    .filter((u) => ['admin', 'team', 'viewer'].includes(u.role) && /^(admin|equipe|visualizador)@/.test(u.email))
                    .map((u) => `<button type="button" role="radio" aria-checked="${u.id === user.id}" data-user="${u.id}">${ROLES[u.role].replace('Membro da equipe', 'Equipe')}</button>`)
                    .join('')}
            </div>
            <p class="hint-text" style="margin-top:6px">O visualizador só consulta: os botões de alteração somem e o sistema recusa qualquer mudança.</p>` : ''}

            <div class="menu">
                ${store.state.google?.available ? `<button class="menu-item" type="button" id="google">${GOOGLE_MARK}<span>Google Agenda<small>${store.state.google.connected ? esc(store.state.google.email) : 'Conectar sua conta Google'}</small></span></button>` : ''}
                ${!store.isDemo ? `<button class="menu-item" type="button" id="reload">${icon('refresh')}<span>Atualizar dados<small>Buscar as alterações da equipe no servidor</small></span></button>` : ''}
                ${can(user, 'services.manage') ? `<a class="menu-item" href="#/servicos" data-dismiss>${icon('briefcase')}<span>Serviços e documentos<small>Demandas e listas do que levar</small></span></a>` : ''}
                ${store.isDemo ? `<button class="menu-item" type="button" id="reset">${icon('refresh')}<span>Restaurar dados de demonstração<small>Volta ao início, com a agenda em torno de hoje</small></span></button>` : ''}
                <button class="menu-item is-danger" type="button" id="logout">${icon('logout')}<span>Sair</span></button>
            </div>

            <div class="menu-label">Contato do projeto</div>
            <p class="small muted" style="margin-bottom:10px">${esc(PROJECT.place)}</p>
            <div class="contact-line">
                <a class="pill-link" href="mailto:${PROJECT.email}">${icon('mail')}${esc(PROJECT.email)}</a>
                <a class="pill-link" href="tel:+55${phoneDigits}">${icon('phone')}${esc(PROJECT.phone)}</a>
            </div>`,

        mount(sheet, close) {
            sheet.querySelectorAll('[data-theme-option]').forEach((button) =>
                button.addEventListener('click', () => {
                    setPreference(button.dataset.themeOption, button);
                    sheet.querySelectorAll('[data-theme-option]').forEach((b) => b.setAttribute('aria-checked', String(b === button)));
                }),
            );

            sheet.querySelectorAll('[data-user]').forEach((button) =>
                button.addEventListener('click', () => {
                    store.switchUser(Number(button.dataset.user));
                    close();
                    toast(`Agora você vê o app como ${ROLES[store.user.role].toLowerCase()}.`);
                    refresh();
                }),
            );

            sheet.querySelector('#google')?.addEventListener('click', () => {
                close();
                openGoogle();
            });

            sheet.querySelector('#reload')?.addEventListener('click', async (event) => {
                event.currentTarget.disabled = true;
                try {
                    await store.reload();
                    close();
                    refresh();
                    toast('Dados atualizados.');
                } catch (error) {
                    close();
                    toast(error.message);
                }
            });

            sheet.querySelector('#reset')?.addEventListener('click', async () => {
                const ok = await confirmDialog({
                    title: 'Restaurar dados de demonstração?',
                    message: 'Tudo o que você cadastrou neste aparelho será apagado e a agenda volta ao exemplo inicial.',
                    confirmLabel: 'Restaurar',
                    danger: true,
                });
                if (!ok) return;
                store.reset();
                close();
                toast('Dados de demonstração restaurados.');
                navigate('/');
                refresh();
            });

            sheet.querySelector('#logout').addEventListener('click', (event) => {
                const origin = originOf(event.currentTarget);
                const name = firstName(user.name);
                close();
                playFarewell(name, origin, async () => {
                    try {
                        await store.logout();
                    } catch (error) {
                        toast(error.message);
                    } finally {
                        navigate('/login');
                    }
                });
            });
        },
    });
}
