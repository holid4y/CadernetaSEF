import { LOCATION_TYPES, STATUS } from './data.js';
import { categoryOf, clientOf, pendingDocuments, serviceOf } from './domain.js';
import { esc, initials, plural, timeStr } from './util.js';

const PATHS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.2-5.5 6.5-5.5s5.9 2.1 6.5 5.5"/><path d="M16 4.7a3.5 3.5 0 0 1 0 6.6M18 14.8c2 .7 3.2 2.5 3.5 5.2"/>',
    chart: '<path d="M4 20h17"/><path d="M7 16v-4M12 16V6M17 16v-7"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
    phone: '<path d="M5 4h3.5l1.8 4.6-2.3 1.4a11 11 0 0 0 5 5l1.4-2.3L19 14.5V18a2 2 0 0 1-2 2A13 13 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7.5 8 6 8-6"/>',
    chat: '<path d="M4 20l1.3-4.2A8.5 8.5 0 1 1 8.4 19L4 20Z"/><path d="M9 10.500h6M9 13.500h3.500"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    'chev-l': '<path d="m14.500 6-6 6 6 6"/>',
    'chev-r': '<path d="m9.500 6 6 6-6 6"/>',
    clock: '<circle cx="12" cy="12" r="8.500"/><path d="M12 7.500V12l3 2"/>',
    pin: '<path d="M12 21s7-6 7-11.500A7 7 0 0 0 5 9.500C5 15 12 21 12 21Z"/><circle cx="12" cy="9.500" r="2.500"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.500v2M12 19.500v2M2.500 12h2M19.500 12h2M5.300 5.300l1.400 1.400M17.300 17.300l1.400 1.400M5.300 18.700l1.400-1.400M17.300 6.700l1.400-1.400"/>',
    moon: '<path d="M20 14.500A8.500 8.500 0 1 1 9.500 4a7 7 0 0 0 10.500 10.500Z"/>',
    monitor: '<rect x="3" y="4" width="18" height="12" rx="2.500"/><path d="M8 20h8M12 16v4"/>',
    file: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h6"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4.500 20.500c.8-4 3.800-6 7.500-6s6.700 2 7.500 6"/>',
    building: '<path d="M4 21V5.500A1.500 1.500 0 0 1 5.500 4h8A1.500 1.500 0 0 1 15 5.500V21M15 10h3.500a1.500 1.500 0 0 1 1.500 1.500V21M2.500 21h19M8 8h3M8 12h3M8 16h3"/>',
    download: '<path d="M12 4v11M7.500 10.500 12 15l4.500-4.500M5 20h14"/>',
    logout: '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 8l4 4-4 4M19 12H9"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.500a5.500 5.500 0 0 1 0 11H11"/>',
    list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    edit: '<path d="M4 20h4L19 9a2.800 2.800 0 0 0-4-4L4 16v4Z"/>',
    sliders: '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    briefcase: '<rect x="3" y="7" width="18" height="13" rx="3"/><path d="M9 7V5a1.500 1.500 0 0 1 1.500-1.500h3A1.500 1.500 0 0 1 15 5v2M3 13h18"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
    refresh: '<path d="M20 11a8 8 0 0 0-14.500-3.500L4 9"/><path d="M4 4v5h5M4 13a8 8 0 0 0 14.500 3.500L20 15"/><path d="M20 20v-5h-5"/>',
    star: '<path d="m12 3.500 2.700 5.500 6 .9-4.400 4.200 1 6-5.300-2.800-5.400 2.800 1-6L3.300 9.900l6-.9L12 3.500Z"/>',
    alert: '<path d="M12 4 2.500 20h19L12 4Z"/><path d="M12 10v4M12 17v.01"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
};

export const icon = (name, attrs = '') =>
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ${attrs}>${PATHS[name]}</svg>`;

export const logo = () => `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.1 17.8A18 18 0 0 1 40.9 17.8M37.1 14.6L40.9 17.8L41.8 12.9"/><path d="M40.9 30.2A18 18 0 0 1 7.1 30.2M10.9 33.4L7.1 30.2L6.2 35.1"/><path d="M28.6 18.6c-.9-1.5-2.6-2.4-4.6-2.4-2.7 0-4.6 1.4-4.6 3.5 0 4.8 9.6 2.6 9.6 7.9 0 2.2-2.1 3.8-5 3.8-2.2 0-4.1-.9-5-2.6M24 13v22"/></svg>`;

export const statusBadge = (status) => `<span class="badge tone-${STATUS[status].tone}">${STATUS[status].label}</span>`;

export const avatar = (name, size = '', tone = '') => `<span class="avatar ${size} ${tone}" aria-hidden="true">${esc(initials(name))}</span>`;

export const backButton = (fallback) => `<button type="button" class="icon-btn" data-back="${esc(fallback)}" aria-label="Voltar">${icon('chev-l')}</button>`;

export const emptyState = ({ icon: name = 'calendar', title, text = '', action = '' }) =>
    `<div class="empty">${icon(name)}<strong>${esc(title)}</strong>${text ? `<p>${esc(text)}</p>` : ''}${action}</div>`;

export const appointmentRow = (state, appointment, { showDate = false } = {}) => {
    const client = clientOf(state, appointment.clientId);
    const service = serviceOf(state, appointment.serviceSlug);
    const pending = pendingDocuments(appointment);
    const open = ['scheduled', 'confirmed', 'in_progress'].includes(appointment.status);
    const faded = ['cancelled', 'no_show'].includes(appointment.status);
    const category = categoryOf(service.categorySlug);
    const sub = appointment.serviceDetails ? `${service.name}: ${appointment.serviceDetails}` : service.name;

    return `<a class="row tone-${STATUS[appointment.status].tone} ${faded ? 'is-faded' : ''}" href="#/atendimentos/${appointment.id}"
        aria-label="Atendimento de ${esc(client.name)}, ${esc(service.name)}, ${timeStr(appointment.start)}, ${STATUS[appointment.status].label}">
        <div class="row-time">${timeStr(appointment.start)}<span>${timeStr(appointment.end)}</span></div>
        <div class="row-main">
            <span class="row-title">${esc(client.name)}</span>
            <span class="row-sub">${esc(sub)}</span>
            <div class="row-meta">
                ${statusBadge(appointment.status)}
                <span class="meta-item">${icon('pin')}${LOCATION_TYPES[appointment.locationType]}</span>
                ${open && pending > 0 ? `<span class="count-pill" title="Documentos pendentes">${pending} ${pending === 1 ? 'doc.' : 'docs.'}</span>` : ''}
                ${showDate ? '' : `<span class="sr-only">${esc(category.name)}</span>`}
            </div>
        </div>
        <span class="row-chevron">${icon('chev-r')}</span>
    </a>`;
};

export const countLabel = (n, one, many) => plural(n, one, many);

const overlayRoot = () => document.getElementById('overlay');
let lastFocus = null;

const trapEscape = (close) => (event) => {
    if (event.key === 'Escape') close();
};

export function openSheet({ title, lead = '', html, mount, label }) {
    lastFocus = document.activeElement;
    const root = overlayRoot();
    const wrapper = document.createElement('div');
    wrapper.className = 'overlay';
    wrapper.innerHTML = `<div class="scrim" data-close></div>
        <div class="sheet" role="dialog" aria-modal="true" aria-label="${esc(label ?? title)}" tabindex="-1">
            ${title ? `<h2>${esc(title)}</h2>` : ''}
            ${lead ? `<p class="lead">${esc(lead)}</p>` : ''}
            ${html}
        </div>`;

    const onKey = trapEscape(() => close());
    const close = () => {
        document.removeEventListener('keydown', onKey);
        wrapper.remove();
        lastFocus?.focus?.({ preventScroll: true });
    };

    wrapper.querySelector('[data-close]').addEventListener('click', close);
    wrapper.querySelectorAll('[data-dismiss]').forEach((el) => el.addEventListener('click', close));
    document.addEventListener('keydown', onKey);
    root.append(wrapper);

    const sheet = wrapper.querySelector('.sheet');
    mount?.(sheet, close);
    sheet.focus({ preventScroll: true });

    return close;
}

export function confirmDialog({ title, message, confirmLabel = 'Confirmar', cancelLabel = 'Voltar', danger = false }) {
    return new Promise((resolve) => {
        lastFocus = document.activeElement;
        const wrapper = document.createElement('div');
        wrapper.className = 'overlay is-center';
        wrapper.innerHTML = `<div class="scrim" data-cancel></div>
            <div class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="dlg-title" aria-describedby="dlg-text">
                <h2 id="dlg-title">${esc(title)}</h2>
                <p id="dlg-text">${esc(message)}</p>
                <div class="btn-row">
                    <button type="button" class="btn btn-ghost" data-cancel>${esc(cancelLabel)}</button>
                    <button type="button" class="btn ${danger ? 'btn-danger is-solid' : 'btn-primary'}" data-ok>${esc(confirmLabel)}</button>
                </div>
            </div>`;

        const finish = (value) => {
            document.removeEventListener('keydown', onKey);
            wrapper.remove();
            lastFocus?.focus?.({ preventScroll: true });
            resolve(value);
        };
        const onKey = trapEscape(() => finish(false));

        wrapper.querySelectorAll('[data-cancel]').forEach((el) => el.addEventListener('click', () => finish(false)));
        wrapper.querySelector('[data-ok]').addEventListener('click', () => finish(true));
        document.addEventListener('keydown', onKey);
        overlayRoot().append(wrapper);
        wrapper.querySelector('[data-cancel].btn').focus();
    });
}

export function toast(message, { actionLabel = null, onAction = null, duration = 5000 } = {}) {
    const root = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.innerHTML = `<span>${esc(message)}</span>${actionLabel ? `<button type="button">${esc(actionLabel)}</button>` : ''}`;

    const remove = () => el.remove();
    el.querySelector('button')?.addEventListener('click', () => {
        remove();
        onAction?.();
    });

    root.replaceChildren(el);
    setTimeout(remove, actionLabel ? Math.max(duration, 6000) : duration);
}

export const field = ({ id, label, optional = false, error = '', control, hint = '' }) =>
    `<div class="field">
        <label for="${id}">${esc(label)}${optional ? ' <span class="opt">(opcional)</span>' : ''}</label>
        ${control}
        ${hint ? `<span class="hint-text">${esc(hint)}</span>` : ''}
        ${error ? `<span class="field-error" id="${id}-error">${esc(error)}</span>` : ''}
    </div>`;
