import { emailLink, whatsappLink } from '../contacts.js';
import { CLIENT_TYPES } from '../data.js';
import { can, clientAppointments, clientOf, searchClients, ValidationError } from '../domain.js';
import { navigate } from '../router.js';
import { store } from '../store.js';
import { appointmentRow, avatar, backButton, emptyState, icon, toast } from '../ui.js';
import { openProfile } from '../profile.js';
import { dateFull, dialNumber, esc, formatDocument, formatPhone, maskDocument, plural } from '../util.js';

const typeIcon = (type) => icon(type === 'organization' ? 'building' : 'user');

const clientRow = (state, client) => {
    const total = clientAppointments(state, client.id).length;
    return `<a class="row is-client" href="#/atendidos/${client.id}">
        ${avatar(client.name, '', client.type === 'organization' ? 'tone-ochre' : 'tone-primary')}
        <div class="row-main">
            <span class="row-title">${esc(client.name)}</span>
            <span class="row-sub">${esc(client.tradeName ?? CLIENT_TYPES[client.type])}${client.phone ? ` · ${esc(client.phone)}` : ''}</span>
            <div class="row-meta">
                <span class="badge is-neutral is-plain">${typeIcon(client.type)}${CLIENT_TYPES[client.type]}</span>
                <span class="meta-item">${plural(total, 'atendimento', 'atendimentos')}</span>
                ${client.active ? '' : '<span class="badge is-neutral is-plain">Inativo</span>'}
            </div>
        </div>
        <span class="row-chevron">${icon('chev-r')}</span>
    </a>`;
};

const filter = { term: '', type: null };

export function clientList({ state, user }) {
    const canWrite = can(user, 'clients.write');

    const results = (term, type) => {
        const found = searchClients(state, term, { type });
        return found.length
            ? `<div class="list">${found.map((c) => clientRow(state, c)).join('')}</div>`
            : emptyState({
                  icon: 'search',
                  title: term ? `Ninguém encontrado com "${term}"` : 'Nenhum atendido ainda',
                  text: canWrite ? 'Cadastre quem for atendido para agendar o primeiro atendimento.' : '',
                  action: canWrite ? `<a class="btn btn-primary" href="#/atendidos/novo">${icon('plus')}Cadastrar atendido</a>` : '',
              });
    };

    return {
        html: `<header class="appbar">
                <h1>Atendidos</h1>
                ${canWrite ? `<a class="btn btn-primary btn-sm" href="#/atendidos/novo">${icon('plus')}Novo</a>` : ''}
                <button class="icon-btn" type="button" id="profile" aria-label="Perfil e configurações" style="width:auto">${avatar(user.name)}</button>
            </header>

            <div class="search" role="search">
                ${icon('search')}
                <label class="sr-only" for="q">Buscar atendido</label>
                <input class="input" id="q" type="search" inputmode="search" autocomplete="off" placeholder="Nome, telefone, e-mail ou CPF/CNPJ" value="${esc(filter.term)}">
            </div>

            <div class="seg" role="group" aria-label="Tipo de atendido" style="margin:12px 0 16px">
                ${[[null, 'Todos'], ['individual', 'Pessoa física'], ['organization', 'Pessoa jurídica']].map(([value, text]) => `<button type="button" data-type="${value ?? ''}" aria-pressed="${filter.type === value}">${text}</button>`).join('')}
            </div>

            <div id="results" aria-live="polite">${results(filter.term, filter.type)}</div>`,

        mount(root) {
            const box = root.querySelector('#results');
            const input = root.querySelector('#q');
            const update = () => {
                filter.term = input.value.trim();
                box.innerHTML = results(filter.term, filter.type);
            };

            input.addEventListener('input', update);
            root.querySelector('#profile').addEventListener('click', openProfile);
            root.querySelectorAll('[data-type]').forEach((button) =>
                button.addEventListener('click', () => {
                    filter.type = button.dataset.type || null;
                    root.querySelectorAll('[data-type]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
                    update();
                }),
            );
        },
    };
}

export function clientDetail({ params, state, user }) {
    const client = clientOf(state, Number(params.id));
    if (!client) return { html: `<header class="appbar">${backButton('/atendidos')}<h1>Atendido</h1></header>${emptyState({ title: 'Atendido não encontrado' })}` };

    const now = Date.now();
    const history = clientAppointments(state, client.id);
    const upcoming = history.filter((a) => a.start >= now && ['scheduled', 'confirmed', 'in_progress'].includes(a.status)).reverse();
    const past = history.filter((a) => !upcoming.includes(a));
    const number = dialNumber(client.phone);
    const canWrite = can(user, 'clients.write');

    return {
        html: `<header class="appbar">${backButton('/atendidos')}<h1>Ficha do atendido</h1>
                ${canWrite ? `<a class="icon-btn is-boxed" href="#/atendidos/${client.id}/editar" aria-label="Editar atendido">${icon('edit')}</a>` : ''}
            </header>

            <section class="banner rise">
                <div style="display:flex;gap:14px;align-items:center">
                    ${avatar(client.name, 'is-lg', client.type === 'organization' ? 'tone-ochre' : 'tone-primary')}
                    <div style="min-width:0">
                        <span class="badge is-neutral is-plain">${typeIcon(client.type)}${CLIENT_TYPES[client.type]}${client.active ? '' : ' · Inativo'}</span>
                    </div>
                </div>
                <h1>${esc(client.name)}</h1>
                ${client.tradeName ? `<p class="muted">${esc(client.tradeName)}</p>` : ''}
                <div class="contact-line" style="margin-top:14px">
                    ${number ? `<a class="pill-link" href="tel:+${number}">${icon('phone')}Ligar</a><a class="pill-link" href="${esc(whatsappLink(client.phone))}" target="_blank" rel="noopener">${icon('chat')}WhatsApp</a>` : ''}
                    ${emailLink(client.email) ? `<a class="pill-link" href="${esc(emailLink(client.email))}">${icon('mail')}E-mail</a>` : ''}
                </div>
                ${!number ? '<p class="hint-text" style="margin-top:8px">Cadastre um telefone válido com DDD para ligar ou abrir o WhatsApp.</p>' : ''}
                ${!emailLink(client.email) ? '<p class="hint-text" style="margin-top:8px">Cadastre um e-mail válido para escrever ao atendido.</p>' : ''}
            </section>

            <section class="card rise" style="margin-top:12px">
                <dl class="kv">
                    <div><dt>Telefone</dt><dd>${esc(client.phone ?? '—')}</dd></div>
                    <div><dt>${client.type === 'organization' ? 'CNPJ' : 'CPF'}</dt><dd>${client.document ? esc(formatDocument(client.document)) : '—'}</dd></div>
                    <div class="wide"><dt>E-mail</dt><dd style="overflow-wrap:anywhere">${esc(client.email ?? '—')}</dd></div>
                    <div class="wide"><dt>Lembretes</dt><dd>${client.acceptsReminders ? `Autorizou em ${dateFull(client.consentAt)}` : 'Não autorizou lembretes'}</dd></div>
                    ${client.notes ? `<div class="wide"><dt>Observações</dt><dd>${esc(client.notes)}</dd></div>` : ''}
                </dl>
            </section>

            ${canWrite && client.active ? `<a class="btn btn-primary btn-block" style="margin-top:14px" href="#/atendimentos/novo?atendido=${client.id}">${icon('plus')}Novo atendimento</a>` : ''}

            <div class="section-title"><h2>Próximos atendimentos</h2></div>
            ${upcoming.length ? `<div class="list">${upcoming.map((a) => appointmentRow(state, a)).join('')}</div>` : emptyState({ title: 'Nada agendado' })}

            <div class="section-title"><h2>Histórico</h2><span class="muted small">${past.length}</span></div>
            ${past.length ? `<div class="list">${past.map((a) => appointmentRow(state, a)).join('')}</div>` : emptyState({ title: 'Sem atendimentos anteriores' })}`,
    };
}

export function clientForm({ params, state, user }) {
    const editing = params.id !== undefined;
    const client = editing ? clientOf(state, Number(params.id)) : null;
    const back = editing ? `/atendidos/${params.id}` : '/atendidos';

    if (!can(user, 'clients.write') || (editing && !client)) {
        return { html: `<header class="appbar">${backButton(back)}<h1>Atendido</h1></header>${emptyState({ icon: 'info', title: 'Você não pode fazer isso', text: 'Seu perfil só consulta os atendidos.' })}` };
    }

    return {
        html: `<header class="appbar">${backButton(back)}<h1>${editing ? 'Editar atendido' : 'Novo atendido'}</h1></header>
            ${clientFormBody(client)}`,
        mount(root) {
            mountClientForm(root, client, (saved) => {
                toast(editing ? 'Dados salvos.' : `${saved.name} cadastrado.`);
                navigate(`/atendidos/${saved.id}`, { replace: true });
            });
        },
    };
}

export function clientFormBody(client = null, { compact = false } = {}) {
    const type = client?.type ?? 'individual';
    return `<form class="form" id="client-form" novalidate>
        <div class="field">
            <span class="label" id="type-label">Tipo de atendido</span>
            <div class="seg" role="radiogroup" aria-labelledby="type-label">
                ${Object.entries(CLIENT_TYPES).map(([value, text]) => `<button type="button" role="radio" aria-checked="${type === value}" data-client-type="${value}">${text}</button>`).join('')}
            </div>
        </div>
        <div class="field">
            <label for="name" id="name-label">${type === 'organization' ? 'Razão social' : 'Nome completo'}</label>
            <input class="input" id="name" name="name" autocomplete="off" value="${esc(client?.name ?? '')}" required>
            <span class="field-error" data-error="name" hidden></span>
        </div>
        <div class="field" id="trade-field" ${type === 'organization' ? '' : 'hidden'}>
            <label for="tradeName">Nome fantasia <span class="opt">(opcional)</span></label>
            <input class="input" id="tradeName" name="tradeName" autocomplete="off" value="${esc(client?.tradeName ?? '')}">
        </div>
        <div class="field">
            <label for="phone">Telefone <span class="opt">(com DDD)</span></label>
            <input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="(51) 99999-9999" value="${esc(client?.phone ?? '')}">
            <span class="field-error" data-error="phone" hidden></span>
        </div>
        <div class="field">
            <label for="email">E-mail <span class="opt">(opcional)</span></label>
            <input class="input" id="email" name="email" type="email" inputmode="email" autocomplete="off" value="${esc(client?.email ?? '')}">
            <span class="field-error" data-error="email" hidden></span>
        </div>
        ${
            compact
                ? ''
                : `<div class="field">
            <label for="document" id="doc-label">${type === 'organization' ? 'CNPJ' : 'CPF'} <span class="opt">(opcional)</span></label>
            <input class="input" id="document" name="document" inputmode="${type === 'organization' ? 'text' : 'numeric'}" autocapitalize="characters" autocomplete="off" value="${esc(client?.document ? formatDocument(client.document) : '')}">
            <span class="field-error" data-error="document" hidden></span>
        </div>
        <div class="field">
            <label for="notes">Observações <span class="opt">(opcional)</span></label>
            <textarea class="textarea" id="notes" name="notes" placeholder="O que a equipe precisa saber">${esc(client?.notes ?? '')}</textarea>
        </div>`
        }
        <label class="toggle-row">
            <input type="checkbox" name="acceptsReminders" ${client?.acceptsReminders ? 'checked' : ''}>
            <span>Aceita receber lembretes por e-mail<small>O atendido concordou em receber avisos antes do atendimento (LGPD).</small></span>
        </label>
        ${client ? `<label class="toggle-row"><input type="checkbox" name="active" ${client.active ? 'checked' : ''}><span>Atendido ativo<small>Inativos continuam no histórico, mas não aparecem para novos agendamentos.</small></span></label>` : ''}
        <p class="error-text" id="form-error" role="alert" hidden></p>
        <button class="btn btn-primary btn-block" type="submit">${client ? 'Salvar alterações' : 'Cadastrar atendido'}</button>
    </form>`;
}

export function mountClientForm(root, client, onSaved) {
    const form = root.querySelector('#client-form');
    let type = client?.type ?? 'individual';

    const syncType = () => {
        const org = type === 'organization';
        root.querySelectorAll('[data-client-type]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.clientType === type)));
        root.querySelector('#name-label').textContent = org ? 'Razão social' : 'Nome completo';
        root.querySelector('#trade-field').hidden = !org;
        const docLabel = root.querySelector('#doc-label');
        if (docLabel) docLabel.innerHTML = `${org ? 'CNPJ' : 'CPF'} <span class="opt">(opcional)</span>`;
        if (form.document) {
            form.document.inputMode = org ? 'text' : 'numeric';
            form.document.value = maskDocument(form.document.value, type);
        }
    };

    root.querySelectorAll('[data-client-type]').forEach((button) =>
        button.addEventListener('click', () => {
            type = button.dataset.clientType;
            syncType();
        }),
    );

    form.phone.addEventListener('input', () => (form.phone.value = formatPhone(form.phone.value)));
    form.document?.addEventListener('input', () => (form.document.value = maskDocument(form.document.value, type)));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        if (submit.disabled) return;
        submit.disabled = true;
        root.querySelectorAll('[data-error]').forEach((el) => (el.hidden = true));
        root.querySelector('#form-error').hidden = true;
        form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));

        try {
            const saved = await store.action(
                'saveClient',
                {
                    type,
                    name: form.name.value,
                    tradeName: form.tradeName.value,
                    phone: form.phone.value,
                    email: form.email.value,
                    document: form.document?.value ?? client?.document ?? '',
                    notes: form.notes?.value ?? client?.notes ?? '',
                    acceptsReminders: form.acceptsReminders.checked,
                    active: form.active ? form.active.checked : true,
                },
                client?.id ?? null,
            );
            onSaved(saved);
        } catch (error) {
            if (!(error instanceof ValidationError)) throw error;
            const spot = error.field && root.querySelector(`[data-error="${error.field}"]`);
            if (spot) {
                spot.textContent = error.message;
                spot.hidden = false;
                form[error.field]?.setAttribute('aria-invalid', 'true');
                form[error.field]?.focus();
            } else {
                const box = root.querySelector('#form-error');
                box.textContent = error.message;
                box.hidden = false;
            }
        } finally {
            submit.disabled = false;
        }
    });
}

