import { config } from './config.js';
import { can } from './domain.js';
import { refresh } from './router.js';
import { store } from './store.js';
import { confirmDialog, openSheet, toast } from './ui.js';
import { dateTime, esc, isSameDay, timeStr } from './util.js';

// Google's colour "G", always on a white background.
export const GOOGLE_MARK = `<span class="g-mark"><svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
</svg></span>`;

// Links come from Google but end up in an href: only Google Calendar over HTTPS.
export function googleLink(value) {
    if (typeof value !== 'string' || value.length > 2048) return null;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !url.username && !url.port && ['calendar.google.com', 'www.google.com'].includes(url.hostname) ? url.href : null;
    } catch {
        return null;
    }
}

export const googleEventOf = (state, id) => (state.googleEvents ?? []).find((event) => event.id === id) ?? null;

export const googleEventsOnDay = (state, ms, { responsibleId = null } = {}) =>
    (state.googleEvents ?? [])
        .filter((event) => isSameDay(event.start, ms) && (responsibleId === null || event.ownerId === responsibleId))
        .sort((a, b) => a.start - b.start);

export function googleEventRow(state, event, user) {
    const owner = state.users.find((u) => u.id === event.ownerId);
    const body = `<div class="row-time">${timeStr(event.start)}<span>${timeStr(event.end)}</span></div>
        <div class="row-main">
            <span class="row-title">${esc(event.title)}</span>
            <span class="row-sub">Do Google Agenda${owner ? ` · ${esc(owner.name)}` : ''}</span>
            <div class="row-meta"><span class="badge tone-google">${GOOGLE_MARK}A completar</span></div>
        </div>`;

    return can(user, 'appointments.write')
        ? `<a class="row is-google" href="#/atendimentos/novo?google=${encodeURIComponent(event.id)}" aria-label="Evento do Google: ${esc(event.title)}, ${timeStr(event.start)}. Toque para completar.">${body}</a>`
        : `<div class="row is-google">${body}</div>`;
}

const sameServer = (url) => {
    try {
        return new URL(url).origin === new URL(config.apiUrl).origin;
    } catch {
        return false;
    }
};

// Google refuses sign-ins inside the app, so the connection goes on in the
// phone's browser through a link that works once.
export async function connectGoogle(fallback) {
    const { url } = await store.google('POST', '/connect');
    if (!sameServer(url)) throw new Error('Resposta inválida do servidor.');

    store.awaitGoogle();
    window.open(url, '_blank');

    if (fallback) {
        fallback.href = url;
        fallback.hidden = false;
    }
}

export function googleCard(state, appointment, user) {
    const google = state.google;
    if (!google?.available) return '';

    const info = appointment.google ?? {};
    const link = googleLink(info.link);
    const notes = [
        info.fromGoogle ? '<p class="hint-text">Veio do Google Agenda.</p>' : '',
        info.removed ? '<p class="hint-text is-danger">Este evento foi apagado no Google Agenda. Cancele aqui se o atendimento não vai acontecer.</p>' : '',
        info.error
            ? `<p class="hint-text is-danger">No Google o horário passou para ${esc(dateTime(info.googleStart))}, mas não deu para mudar aqui: ${esc(info.error)}</p>`
            : '',
    ].join('');

    const action = cardAction(google, appointment, user, link);

    return `<section class="card"><div class="card-head"><h2 class="with-mark">${GOOGLE_MARK}Google Agenda</h2></div>${notes}${action}</section>`;
}

function cardAction(google, appointment, user, link) {
    if (link) return `<a class="google-btn" href="${esc(link)}" target="_blank" rel="noopener noreferrer">${GOOGLE_MARK}<span>Abrir no Google Agenda</span></a>`;
    if (appointment.status === 'cancelled') return '<p class="hint-text">Atendimentos cancelados saem da Google Agenda.</p>';
    if (!google.connected || google.needsReconnect) {
        return `<p class="hint-text">Conecte sua conta para os atendimentos em que você é responsável aparecerem sozinhos na sua Google Agenda.</p>
            <button class="google-btn" type="button" data-google-connect>${GOOGLE_MARK}<span>Continuar com o Google</span></button>
            <a class="link small" data-google-fallback hidden target="_blank" rel="noopener noreferrer">O navegador não abriu? Toque aqui.</a>`;
    }
    if (appointment.responsibleId !== user.id) return '<p class="hint-text">Este atendimento aparece na Google Agenda de quem é responsável por ele.</p>';
    return '<p class="hint-text">A cópia na sua agenda “Caderneta SEF” fica pronta em instantes.</p>';
}

export function mountGoogleCard(root) {
    root.querySelector('[data-google-connect]')?.addEventListener('click', async (event) => {
        event.currentTarget.disabled = true;
        try {
            await connectGoogle(root.querySelector('[data-google-fallback]'));
        } catch (error) {
            toast(error.message);
        } finally {
            event.currentTarget.disabled = false;
        }
    });
}

export function openGoogle() {
    const google = store.state.google;
    const connected = google.connected && !google.needsReconnect;

    openSheet({
        title: 'Google Agenda',
        html: connected
            ? `<p class="muted">${GOOGLE_MARK} Conectada a <b>${esc(google.email)}</b>.${google.pulledAt ? ` Última leitura às ${esc(timeStr(google.pulledAt))}.` : ''}</p>
                <a class="google-btn" href="${esc(google.webUrl)}" target="_blank" rel="noopener noreferrer" style="margin-top:12px">${GOOGLE_MARK}<span>Abrir no Google Agenda</span></a>
                <form class="form" id="google-source" style="margin-top:18px">
                    <div class="field">
                        <label for="google-calendar">Agenda que vem para a Caderneta</label>
                        <select class="select" id="google-calendar" name="calendar" disabled><option>Carregando suas agendas…</option></select>
                        <p class="hint-text">Cada evento com horário dessa agenda aparece aqui em até cinco minutos, como “a completar”.</p>
                    </div>
                    <button class="btn btn-primary btn-block" type="submit" disabled>Salvar</button>
                </form>
                <button class="btn btn-secondary btn-block is-danger" type="button" id="google-disconnect" style="margin-top:12px">Desconectar</button>`
            : `<p class="muted">Os atendimentos em que você é responsável vão para uma agenda “Caderneta SEF” na sua conta Google, e os eventos de uma agenda que você escolher entram aqui para completar.</p>
                ${google.needsReconnect ? '<p class="hint-text is-danger">O Google não aceita mais a conexão. Conecte de novo.</p>' : ''}
                <button class="google-btn" type="button" data-google-connect style="margin-top:14px">${GOOGLE_MARK}<span>Continuar com o Google</span></button>
                <a class="link small" data-google-fallback hidden target="_blank" rel="noopener noreferrer" style="display:block;margin-top:10px">O navegador não abriu? Toque aqui.</a>
                <p class="hint-text" style="margin-top:10px">O Google abre no navegador do celular. Depois de autorizar, volte para o app.</p>`,

        async mount(sheet, close) {
            mountGoogleCard(sheet);
            if (!connected) return;

            const form = sheet.querySelector('#google-source');
            const select = form.querySelector('select');

            try {
                const { calendars } = await store.google('GET', '/calendars');
                select.innerHTML = `<option value="">Não trazer eventos do Google</option>${calendars
                    .map((c) => `<option value="${esc(c.id)}" ${c.id === google.calendarId ? 'selected' : ''}>${esc(c.name)}${c.primary ? ' (principal)' : ''}</option>`)
                    .join('')}`;
                select.disabled = false;
                form.querySelector('[type="submit"]').disabled = false;
            } catch (error) {
                select.innerHTML = `<option>${esc(error.message)}</option>`;
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                try {
                    const { google: saved } = await store.google('PUT', '', { calendarId: select.value || null });
                    close();
                    toast(saved.calendarName ? `Os eventos de “${saved.calendarName}” vão aparecer na agenda em alguns minutos.` : 'Nenhuma agenda do Google está sendo importada.');
                    refresh();
                } catch (error) {
                    toast(error.message);
                }
            });

            sheet.querySelector('#google-disconnect').addEventListener('click', async () => {
                const ok = await confirmDialog({
                    title: 'Desconectar a conta Google?',
                    message: 'A agenda “Caderneta SEF” é apagada da sua conta Google. Os atendimentos continuam aqui.',
                    confirmLabel: 'Desconectar',
                    danger: true,
                });
                if (!ok) return;
                try {
                    await store.google('DELETE');
                    await store.reload();
                    close();
                    toast('A conta Google foi desconectada.');
                    refresh();
                } catch (error) {
                    toast(error.message);
                }
            });
        },
    });
}
