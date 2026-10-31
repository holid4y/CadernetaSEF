import { config } from './config.js';
import { esc } from './util.js';

const LABELS = { pending: 'Pendente', in_progress: 'Em preenchimento', completed: 'Concluído', deleted: 'Removido na plataforma de formulários' };

export function formUrl(value, hosts = config.formsHosts ?? []) {
    if (typeof value !== 'string' || value.length > 2048 || /[\s\\\u0000-\u001f\u007f]/u.test(value) || !value.startsWith('https://')) return null;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !url.username && !url.password && !url.port && hosts.includes(url.hostname)
            ? url.href : null;
    } catch {
        return null;
    }
}

const actionLabel = (status) => (status === 'completed' ? 'Ver formulário feito' : 'Preencher formulário');

const validReference = (value) => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);

// The one button an appointment shows for its form: the latest one, done or
// still to do, or a new one when there is none yet.
export function formAction(appointment, canWrite, settings = config) {
    if (settings.mode !== 'server' || appointment.formsEnabled !== true) return null;
    const hosts = settings.formsHosts ?? [];
    const forms = Array.isArray(appointment.forms) ? appointment.forms : [];

    for (const form of forms) {
        const url = Object.hasOwn(LABELS, form.status) && form.status !== 'deleted' ? formUrl(form.url, hosts) : null;
        if (url) return { label: actionLabel(form.status), url };
    }

    const create = canWrite && validReference(appointment.integrationId) ? formUrl(appointment.formCreateUrl, hosts) : null;
    return create ? { label: 'Preencher formulário', url: create } : null;
}

export function formsCard(appointment, canWrite, settings = config) {
    if (settings.mode !== 'server' || appointment.formsEnabled !== true) {
        return '<section class="card"><h2>Formulários</h2><p class="hint-text">A integração com a plataforma de formulários ainda não foi configurada.</p></section>';
    }

    const hosts = settings.formsHosts ?? [];
    const forms = Array.isArray(appointment.forms) ? appointment.forms : [];
    const reference = validReference(appointment.integrationId) ? appointment.integrationId : null;
    const create = canWrite && reference ? formUrl(appointment.formCreateUrl, hosts) : null;

    return `<section class="card">
        <div class="card-head"><h2>Formulários</h2></div>
        ${reference ? `<label class="hint-text" for="form-reference">Identificador para vincular o atendimento</label><input class="input" id="form-reference" type="text" readonly spellcheck="false" value="${esc(reference)}">` : ''}
        ${forms.length ? `<ul style="list-style:none;padding:0">${forms.map((form) => {
            const label = LABELS[form.status] ?? 'Indisponível';
            const url = Object.hasOwn(LABELS, form.status) && form.status !== 'deleted' ? formUrl(form.url, hosts) : null;
            return `<li style="margin-top:12px"><b>${esc(form.title ?? 'Formulário')}</b><p class="muted small">${esc(label)}</p>${url ? `<div class="btn-row"><a class="btn btn-secondary btn-sm" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${actionLabel(form.status)}</a></div>` : ''}</li>`;
        }).join('')}</ul>` : '<p class="hint-text">Nenhum formulário vinculado a este atendimento.</p>'}
        ${create ? `<div class="btn-row"><a class="btn btn-primary btn-sm" href="${esc(create)}" target="_blank" rel="noopener noreferrer">Preencher formulário</a></div>` : ''}
        <p class="hint-text">A plataforma de formulários pode solicitar seu próprio login. Vínculos pendentes podem ser revisados na versão web.</p>
    </section>`;
}
