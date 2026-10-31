import { emailLink, whatsappLink } from '../contacts.js';
import { formAction, formsCard } from '../forms.js';
import { googleCard, mountGoogleCard } from '../google.js';
import { PROJECT, STATUS, TRANSITIONS } from '../data.js';
import {
    appointmentOf,
    busyOn,
    can,
    categoryOf,
    clientOf,
    describeActivity,
    findConflict,
    conflictMessage,
    locationLabel,
    reminderBlocker,
    responsibles,
    serviceOf,
    snapshotAppointment,
    userOf,
    ValidationError,
} from '../domain.js';
import { refresh } from '../router.js';
import { store } from '../store.js';
import { avatar, backButton, confirmDialog, emptyState, icon, openSheet, statusBadge, toast } from '../ui.js';
import { at, capitalize, dateLong, dateTime, dayKey, dayMonth, dialNumber, esc, timeStr, weekday } from '../util.js';

const CONFIRM = {
    cancelled: {
        title: 'Cancelar este atendimento?',
        message: 'O registro continua no histórico e o horário fica livre.',
        confirmLabel: 'Cancelar atendimento',
        cancelLabel: 'Manter',
    },
    no_show: {
        title: 'Marcar que o atendido não compareceu?',
        message: 'O horário fica livre e a falta entra nos indicadores.',
        confirmLabel: 'Marcar falta',
        cancelLabel: 'Voltar',
    },
};

const reminderText = (state, appointment) => {
    const client = clientOf(state, appointment.clientId);
    const service = serviceOf(state, appointment.serviceSlug);
    const pending = appointment.documents.map((d) => `• ${d.name}`);
    const lines = [
        `Olá, ${client.name.split(' ')[0]}!`,
        `Seu atendimento de ${service.name} é ${weekday(appointment.start)}, ${dayMonth(appointment.start)}, às ${timeStr(appointment.start)}.`,
        appointment.location ? `Local: ${appointment.location}.` : null,
        pending.length ? 'Traga, por favor:' : null,
        ...pending,
        '',
        `Equipe do projeto ${PROJECT.kicker} ${PROJECT.name}`,
    ];
    return lines.filter((line) => line !== null).join('\n');
};

const reminderCard = (state, appointment, canWrite) => {
    const client = clientOf(state, appointment.clientId);
    const number = dialNumber(client.phone);
    const open = ['scheduled', 'confirmed'].includes(appointment.status);
    const blocker = reminderBlocker(client);

    const status = appointment.reminderSentAt
        ? `Enviado por e-mail em ${dateTime(appointment.reminderSentAt)}.`
        : blocker ?? (open ? (store.isDemo ? 'O envio automático ficará disponível com o servidor.' : 'Lembrete automático configurado no servidor.') : 'Este atendimento não recebe mais lembretes.');

    const allowed = canWrite && client.active && client.acceptsReminders && open;
    const mail = allowed ? emailLink(client.email, 'Seu atendimento na Caderneta SEF', reminderText(state, appointment)) : null;
    const whatsapp = number && allowed
        ? `<a class="btn btn-soft btn-sm" href="${esc(whatsappLink(client.phone, reminderText(state, appointment)))}" target="_blank" rel="noopener">${icon('chat')}Enviar pelo WhatsApp</a>`
        : '';
    const email = !store.isDemo && canWrite && open && !blocker
        ? `<button class="btn btn-secondary btn-sm" type="button" id="send-reminder">${icon('mail')}${appointment.reminderSentAt ? 'Reenviar e-mail' : 'Enviar e-mail agora'}</button>`
        : '';

    return `<section class="card">
        <div class="card-head"><h2>Lembrete</h2></div>
        <p class="muted">${esc(status)}</p>
        ${whatsapp || mail || email ? `<div class="btn-row">${whatsapp}${mail ? `<a class="btn btn-secondary btn-sm" href="${esc(mail)}">${icon('mail')}Escrever e-mail</a>` : ''}${email}</div>` : ''}
        ${!client.acceptsReminders ? '<p class="hint-text">O atendido não autorizou lembretes.</p>' : ''}
        ${!number ? '<p class="hint-text">Cadastre um telefone válido com DDD para abrir o WhatsApp.</p>' : ''}
        ${!mail && allowed ? '<p class="hint-text">Cadastre um e-mail válido para escrever o lembrete.</p>' : ''}
        <p class="hint-text" style="margin-top:8px">Você escolhe a conta remetente e confirma o envio no aplicativo de e-mail ou WhatsApp. Abrir a mensagem não confirma o envio.</p>
    </section>`;
};

export default function appointmentDetail({ params, state, user }) {
    const appointment = appointmentOf(state, Number(params.id));
    if (!appointment) {
        return { html: `<header class="appbar">${backButton('/agenda')}<h1>Atendimento</h1></header>${emptyState({ title: 'Atendimento não encontrado' })}` };
    }

    const client = clientOf(state, appointment.clientId);
    const service = serviceOf(state, appointment.serviceSlug);
    const category = categoryOf(service.categorySlug);
    const responsible = userOf(state, appointment.responsibleId);
    const canWrite = can(user, 'appointments.write');
    const received = appointment.documents.filter((d) => d.receivedAt).length;
    const total = appointment.documents.length;
    const next = TRANSITIONS[appointment.status].filter((s) => s !== 'scheduled');
    const canReschedule = canWrite && ['scheduled', 'confirmed'].includes(appointment.status);
    const formButton = formAction(appointment, canWrite);

    const actions = next
        .map((status) => {
            const destructive = ['cancelled', 'no_show'].includes(status);
            const primary = ['confirmed', 'completed'].includes(status);
            const cls = destructive ? 'btn-danger' : primary ? 'btn-primary' : 'btn-secondary';
            return `<button class="btn ${cls} btn-sm" type="button" data-status="${status}">${STATUS[status].action}</button>`;
        })
        .join('');

    return {
        html: `<header class="appbar">${backButton('/agenda')}<h1>Atendimento</h1></header>

            <section class="banner rise tone-${STATUS[appointment.status].tone}">
                ${statusBadge(appointment.status)}
                <h1>${esc(client.name)}</h1>
                <p class="muted" style="margin-top:4px">${esc(category.name)} · ${esc(service.name)}</p>
                ${appointment.serviceDetails ? `<p style="margin-top:6px">${esc(appointment.serviceDetails)}</p>` : ''}
                <a class="link small" style="display:inline-block;margin-top:8px" href="#/atendidos/${client.id}">Ver ficha do atendido</a>
                ${canWrite && actions ? `<div class="btn-row">${actions}</div>` : ''}
                ${formButton ? `<div class="btn-row"><a class="btn btn-secondary btn-sm" data-form-action href="${esc(formButton.url)}" target="_blank" rel="noopener noreferrer">${esc(formButton.label)}</a></div>` : ''}
            </section>

            <section class="card" style="margin-top:12px">
                <dl class="kv">
                    <div class="wide"><dt>Quando</dt><dd><b>${esc(capitalize(dateLong(appointment.start)))}</b><br><span class="muted">${timeStr(appointment.start)} – ${timeStr(appointment.end)}</span></dd></div>
                    <div><dt>Responsável</dt><dd style="display:flex;align-items:center;gap:8px">${avatar(responsible.name, 'is-sm')}${esc(responsible.name)}</dd></div>
                    <div><dt>Contato</dt><dd>${esc(client.phone ?? client.email ?? '—')}</dd></div>
                    <div class="wide"><dt>Onde</dt><dd>${esc(locationLabel(appointment))}</dd></div>
                    ${appointment.notes ? `<div class="wide"><dt>Observações</dt><dd>${esc(appointment.notes)}</dd></div>` : ''}
                </dl>
                ${canReschedule ? `<div class="btn-row"><button class="btn btn-secondary btn-sm" type="button" id="reschedule">${icon('calendar')}Alterar horário ou responsável</button></div>` : ''}
            </section>

            <section class="card">
                <div class="card-head"><h2>Documentos</h2><span class="muted small doc-count">${total ? `${received} de ${total} entregues` : ''}</span></div>
                ${
                    total
                        ? `<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="${total}" aria-valuenow="${received}"><i style="width:${(received / total) * 100}%"></i></div>
                           <div>${appointment.documents
                               .map(
                                   (d) => `<button type="button" class="doc ${d.receivedAt ? 'is-done' : ''}" data-doc="${d.id}" role="checkbox" aria-checked="${Boolean(d.receivedAt)}" ${canWrite ? '' : 'disabled'}>
                                        <span class="mark">${icon('check')}</span><span class="name">${esc(d.name)}</span><span class="state">${d.receivedAt ? 'Entregue' : 'Pendente'}</span></button>`,
                               )
                               .join('')}</div>`
                        : '<p class="muted">Nenhum documento solicitado para este serviço.</p>'
                }
            </section>

            ${formsCard(appointment, canWrite)}
            ${reminderCard(state, appointment, canWrite)}
            ${googleCard(state, appointment, user)}

            <section class="card">
                <div class="card-head"><h2>Histórico</h2></div>
                <ol class="timeline">${[...appointment.activities]
                    .reverse()
                    .map((activity) => {
                        const { label, detail } = describeActivity(state, activity);
                        const who = activity.userId ? userOf(state, activity.userId)?.name : null;
                        return `<li><div><b>${label}</b>${detail ? `<small>${esc(detail)}</small>` : ''}<small>${dateTime(activity.at)}${who ? ` · por ${esc(who)}` : ''}</small></div></li>`;
                    })
                    .join('')}</ol>
            </section>`,

        mount(root) {
            mountGoogleCard(root);
            root.querySelectorAll('[data-status]').forEach((button) =>
                button.addEventListener('click', () => updateStatus(appointment, button.dataset.status)),
            );

            root.querySelectorAll('[data-doc]').forEach((button) =>
                button.addEventListener('click', async () => {
                    try {
                        const toggled = await store.action('toggleDocument', appointment.id, Number(button.dataset.doc));
                        const done = Boolean(toggled.receivedAt);
                        const count = appointmentOf(store.state, appointment.id).documents.filter((d) => d.receivedAt).length;

                        button.classList.toggle('is-done', done);
                        button.classList.toggle('just-done', done);
                        button.setAttribute('aria-checked', String(done));
                        button.querySelector('.state').textContent = done ? 'Entregue' : 'Pendente';
                        root.querySelector('.progress i').style.width = `${(count / total) * 100}%`;
                        root.querySelector('.progress').setAttribute('aria-valuenow', String(count));
                        root.querySelector('.doc-count').textContent = `${count} de ${total} entregues`;
                    } catch (error) {
                        toast(error.message);
                    }
                }),
            );

            root.querySelector('#reschedule')?.addEventListener('click', () => openReschedule(appointment));

            root.querySelector('#send-reminder')?.addEventListener('click', async () => {
                try {
                    const email = await store.action('sendReminder', appointment.id);
                    toast(`Lembrete enviado para ${email}.`);
                    refresh();
                } catch (error) {
                    if (!(error instanceof ValidationError)) throw error;
                    toast(error.message);
                }
            });
        },
    };
}

async function updateStatus(appointment, status) {
    const ask = CONFIRM[status];
    if (ask && !(await confirmDialog({ ...ask, danger: true }))) return;

    const before = snapshotAppointment(appointment);

    try {
        await store.action('changeStatus', appointment.id, status);
    } catch (error) {
        if (!(error instanceof ValidationError)) throw error;
        toast(error.message);
        return;
    }

    refresh();
    toast(`Status alterado para "${STATUS[status].label}".`, {
        actionLabel: 'Desfazer',
        onAction: async () => {
            try {
                await store.action('restoreAppointment', before);
                refresh();
            } catch (error) {
                toast(error.message);
            }
        },
    });
}

function openReschedule(appointment) {
    const state = store.state;
    const day = dayKey(appointment.start);

    openSheet({
        title: 'Alterar horário ou responsável',
        html: `<form class="form" id="reschedule-form" novalidate>
            <div class="field"><label for="date">Data</label><input class="input" id="date" name="date" type="date" value="${day}" required></div>
            <div class="field-row">
                <div class="field"><label for="start">Início</label><input class="input" id="start" name="start" type="time" step="900" value="${timeStr(appointment.start)}" required></div>
                <div class="field"><label for="end">Fim</label><input class="input" id="end" name="end" type="time" step="900" value="${timeStr(appointment.end)}" required></div>
            </div>
            <div class="field"><label for="who">Responsável</label>
                <select class="select" id="who" name="who">${responsibles(state).map((u) => `<option value="${u.id}" ${u.id === appointment.responsibleId ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
            </div>
            <p class="hint-text" id="busy"></p>
            <p class="error-text" id="problem" role="alert" hidden></p>
            <div class="btn-row" style="margin-top:0">
                <button class="btn btn-ghost" type="button" data-dismiss style="flex:1">Desistir</button>
                <button class="btn btn-primary" type="submit" style="flex:1">Salvar alteração</button>
            </div>
        </form>`,

        mount(sheet, close) {
            const form = sheet.querySelector('#reschedule-form');
            const problem = sheet.querySelector('#problem');
            const busy = sheet.querySelector('#busy');

            const period = () => ({ start: at(form.date.value, form.start.value), end: at(form.date.value, form.end.value), responsibleId: Number(form.who.value) });

            const check = () => {
                const { start, end, responsibleId } = period();
                const others = busyOn(state, responsibleId, form.date.value, appointment.id);
                busy.textContent = others.length ? `Já ocupado neste dia: ${others.map((a) => `${timeStr(a.start)}–${timeStr(a.end)}`).join(', ')}.` : 'Nenhum outro atendimento deste responsável neste dia.';

                const conflict = end > start ? findConflict(state, { responsibleId, start, end, ignoreId: appointment.id }) : null;
                problem.textContent = conflict ? conflictMessage(state, conflict) : end <= start ? 'O horário final precisa ser depois do horário inicial.' : '';
                problem.hidden = !problem.textContent;
            };

            form.addEventListener('input', check);
            check();

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submit = form.querySelector('[type="submit"]');
                if (submit.disabled) return;
                submit.disabled = true;
                try {
                    await store.action('rescheduleAppointment', appointment.id, period());
                    close();
                    toast('Horário e responsável atualizados.');
                    refresh();
                } catch (error) {
                    if (!(error instanceof ValidationError)) throw error;
                    problem.textContent = error.message;
                    problem.hidden = false;
                } finally {
                    submit.disabled = false;
                }
            });
        },
    });
}
