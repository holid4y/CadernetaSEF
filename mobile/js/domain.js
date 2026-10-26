import {
    BLOCKING,
    CATALOG,
    CLIENT_TYPES,
    LOCATION_TYPES,
    MAX_APPOINTMENT_HOURS,
    ROLES,
    STATUS,
    TRANSITIONS,
} from './data.js';
import { addDays, dayKey, digits, dialNumber, documentChars, isEmail, isValidCnpj, isValidCpf, normalize, startOfDay, timeStr } from './util.js';

export class ValidationError extends Error {
    constructor(message, field = null) {
        super(message);
        this.name = 'ValidationError';
        this.field = field;
    }
}

const fail = (message, field) => {
    throw new ValidationError(message, field);
};

export const userOf = (state, id) => state.users.find((u) => u.id === id) ?? null;
export const clientOf = (state, id) => state.clients.find((c) => c.id === id) ?? null;
export const serviceOf = (state, slug) => state.services.find((s) => s.slug === slug) ?? null;
export const appointmentOf = (state, id) => state.appointments.find((a) => a.id === id) ?? null;
export const categoryOf = (slug) => CATALOG.find((c) => c.slug === slug) ?? null;

export const responsibles = (state) => state.users.filter((u) => u.active && u.role !== 'viewer');

const ABILITIES = {
    admin: ['read', 'appointments.write', 'clients.write', 'services.manage', 'reports.view'],
    team: ['read', 'appointments.write', 'clients.write', 'reports.view'],
    viewer: ['read', 'reports.view'],
};

export const can = (user, ability) => Boolean(user) && ABILITIES[user.role]?.includes(ability) === true;

const authorize = (actor, ability) => {
    if (!can(actor, ability)) fail('Seu perfil não tem permissão para fazer isso.');
};

const nextId = (state, key) => {
    state.counters[key] = (state.counters[key] ?? 0) + 1;
    return state.counters[key];
};

const log = (state, appointment, type, actor, at, old = null, next = null) => {
    appointment.activities.push({ id: nextId(state, 'activity'), type, userId: actor?.id ?? null, at, old, new: next });
    appointment.updatedAt = at;
};

export const isBlocking = (appointment) => BLOCKING.includes(appointment.status);

/** Two periods overlap when new_start < existing_end AND new_end > existing_start. */
export const findConflict = (state, { responsibleId, start, end, ignoreId = null }) =>
    state.appointments.find(
        (a) =>
            a.id !== ignoreId &&
            a.responsibleId === responsibleId &&
            isBlocking(a) &&
            start < a.end &&
            end > a.start,
    ) ?? null;

export const conflictMessage = (state, conflict) =>
    `${userOf(state, conflict.responsibleId).name} já tem atendimento das ${timeStr(conflict.start)} às ${timeStr(conflict.end)}. Escolha outro horário ou outro responsável.`;

export const busyOn = (state, responsibleId, key, ignoreId = null) =>
    state.appointments
        .filter((a) => a.id !== ignoreId && a.responsibleId === responsibleId && isBlocking(a) && dayKey(a.start) === key)
        .sort((a, b) => a.start - b.start);

const validatePeriod = (start, end) => {
    if (!Number.isFinite(start) || !Number.isFinite(end)) fail('Escolha a data e os horários do atendimento.', 'start');
    if (end <= start) fail('O horário final precisa ser depois do horário inicial.', 'end');
    if (end - start > MAX_APPOINTMENT_HOURS * 3_600_000) {
        fail(`Um atendimento pode durar no máximo ${MAX_APPOINTMENT_HOURS} horas.`, 'end');
    }
};

const validateResponsible = (state, responsibleId) => {
    const user = userOf(state, responsibleId);
    if (!user) fail('Escolha quem será o responsável.', 'responsibleId');
    if (!user.active || user.role === 'viewer') {
        fail(`${user.name} não pode receber atendimentos: a conta está desativada ou é somente de visualização.`, 'responsibleId');
    }
    return user;
};

export function scheduleAppointment(state, actor, input, now = Date.now()) {
    authorize(actor, 'appointments.write');

    const client = clientOf(state, input.clientId);
    if (!client) fail('Escolha quem será atendido.', 'clientId');

    const service = serviceOf(state, input.serviceSlug);
    if (!service) fail('Escolha a demanda do atendimento.', 'serviceSlug');
    if (!service.active) fail('Este serviço foi desativado e não pode ser usado em novos atendimentos.', 'serviceSlug');

    const details = (input.serviceDetails ?? '').trim();
    if (service.requiresDetails && !details) fail(`Descreva a demanda para o serviço "${service.name}".`, 'serviceDetails');

    validatePeriod(input.start, input.end);
    validateResponsible(state, input.responsibleId);

    const conflict = findConflict(state, { responsibleId: input.responsibleId, start: input.start, end: input.end });
    if (conflict) fail(conflictMessage(state, conflict), 'start');


    const appointment = {
        id: nextId(state, 'appointment'),
        clientId: client.id,
        serviceSlug: service.slug,
        serviceDetails: details || null,
        responsibleId: input.responsibleId,
        start: input.start,
        end: input.end,
        status: 'scheduled',
        locationType: input.locationType ?? 'on_campus',
        location: (input.location ?? '').trim() || null,
        notes: (input.notes ?? '').trim() || null,
        createdBy: actor.id,
        createdAt: now,
        updatedAt: now,
        reminderSentAt: null,
        // Copied, so editing the service later does not change this appointment.
        documents: service.documents.map((name) => ({ id: nextId(state, 'document'), name, receivedAt: null })),
        activities: [],
    };

    log(state, appointment, 'created', actor, now);
    state.appointments.push(appointment);
    state.meta.edited = true;

    return appointment;
}

export function rescheduleAppointment(state, actor, id, input, now = Date.now()) {
    authorize(actor, 'appointments.write');

    const appointment = appointmentOf(state, id);
    if (!appointment) fail('Atendimento não encontrado.');
    if (!['scheduled', 'confirmed'].includes(appointment.status)) {
        fail('Só é possível alterar horário ou responsável de atendimentos agendados ou confirmados.');
    }

    validatePeriod(input.start, input.end);
    validateResponsible(state, input.responsibleId);

    const conflict = findConflict(state, { responsibleId: input.responsibleId, start: input.start, end: input.end, ignoreId: id });
    if (conflict) fail(conflictMessage(state, conflict), 'start');

    const timeChanged = appointment.start !== input.start || appointment.end !== input.end;
    const responsibleChanged = appointment.responsibleId !== input.responsibleId;

    if (timeChanged) {
        log(state, appointment, 'rescheduled', actor, now, { start: appointment.start, end: appointment.end }, { start: input.start, end: input.end });
        appointment.reminderSentAt = null;
    }
    if (responsibleChanged) {
        log(state, appointment, 'responsible_changed', actor, now, { userId: appointment.responsibleId }, { userId: input.responsibleId });
    }

    appointment.start = input.start;
    appointment.end = input.end;
    appointment.responsibleId = input.responsibleId;
    state.meta.edited = true;

    return appointment;
}

export function changeStatus(state, actor, id, next, now = Date.now()) {
    authorize(actor, 'appointments.write');

    const appointment = appointmentOf(state, id);
    if (!appointment) fail('Atendimento não encontrado.');

    if (!TRANSITIONS[appointment.status].includes(next)) {
        fail(`Um atendimento "${STATUS[appointment.status].label}" não pode passar para "${STATUS[next].label}".`);
    }

    const from = appointment.status;
    appointment.status = next;
    log(state, appointment, next === 'cancelled' ? 'cancelled' : 'status_changed', actor, now, { status: from }, { status: next });
    state.meta.edited = true;

    return appointment;
}

export function toggleDocument(state, actor, appointmentId, documentId, now = Date.now()) {
    authorize(actor, 'appointments.write');

    const appointment = appointmentOf(state, appointmentId);
    const document = appointment?.documents.find((d) => d.id === documentId);
    if (!document) fail('Documento não encontrado.');

    // Only the delivery is recorded, never the file.
    document.receivedAt = document.receivedAt ? null : now;
    appointment.updatedAt = now;
    state.meta.edited = true;

    return document;
}

/** Why a reminder cannot go out by e-mail, or null when it can. */
export const reminderBlocker = (client) => {
    if (!client.active) return 'Este atendido está inativo.';
    if (!client.acceptsReminders) return 'O atendido não autorizou lembretes.';
    if (!client.email) return 'Sem e-mail cadastrado; use o WhatsApp.';
    if (!isEmail(client.email)) return 'Cadastre um e-mail válido para enviar o lembrete.';
    return null;
};

export function sendReminder(state, actor, id, now = Date.now()) {
    authorize(actor, 'appointments.write');

    const appointment = appointmentOf(state, id);
    if (!appointment) fail('Atendimento não encontrado.');

    const client = clientOf(state, appointment.clientId);
    const blocker = reminderBlocker(client);
    if (blocker) fail(blocker);

    appointment.reminderSentAt = now;
    appointment.updatedAt = now;
    state.meta.edited = true;

    return client.email;
}

export const snapshotAppointment = (appointment) => JSON.parse(JSON.stringify(appointment));

export function restoreAppointment(state, snapshot) {
    const index = state.appointments.findIndex((a) => a.id === snapshot.id);
    if (index === -1) return;
    state.appointments[index] = snapshot;
}

export function saveClient(state, actor, input, id = null, now = Date.now()) {
    authorize(actor, 'clients.write');

    const type = input.type;
    if (!CLIENT_TYPES[type]) fail('Escolha se é pessoa física ou jurídica.', 'type');

    const name = (input.name ?? '').trim();
    if (!name) fail(type === 'organization' ? 'Informe a razão social.' : 'Informe o nome do atendido.', 'name');

    const email = (input.email ?? '').trim();
    if (email && !isEmail(email)) fail('Esse e-mail parece incompleto. Confira se tem @ e o final (.com, .br…).', 'email');

    const phone = digits(input.phone);
    if (phone && !dialNumber(input.phone)) fail('O telefone precisa ter DDD e número. Ex.: (51) 99999-9999.', 'phone');

    const document = type === 'organization' ? documentChars(input.document) : digits(input.document);
    if (document) {
        const valid = type === 'organization' ? isValidCnpj(document) : isValidCpf(document);
        if (!valid) fail(type === 'organization' ? 'Esse CNPJ não confere. Confira os números.' : 'Esse CPF não confere. Confira os números.', 'document');

        const duplicate = state.clients.find((c) => c.id !== id && c.document && documentChars(c.document) === document);
        if (duplicate) fail(`Esse documento já está cadastrado para ${duplicate.name}.`, 'document');
    }

    const acceptsReminders = Boolean(input.acceptsReminders);
    if (acceptsReminders && !email) fail('Para receber lembretes por e-mail, informe o e-mail do atendido.', 'email');

    const values = {
        type,
        name,
        tradeName: type === 'organization' ? (input.tradeName ?? '').trim() || null : null,
        document: document || null,
        phone: input.phone ? input.phone.trim() : null,
        email: email || null,
        notes: (input.notes ?? '').trim() || null,
        acceptsReminders,
        active: input.active ?? true,
    };

    state.meta.edited = true;

    if (id === null) {
        const client = { id: nextId(state, 'client'), ...values, consentAt: acceptsReminders ? now : null, createdAt: now };
        state.clients.push(client);
        return client;
    }

    const client = clientOf(state, id);
    if (!client) fail('Atendido não encontrado.');
    const consentAt = acceptsReminders ? client.consentAt ?? now : null;
    Object.assign(client, values, { consentAt });
    return client;
}

export function searchClients(state, term = '', { type = null, onlyActive = false } = {}) {
    const q = normalize(term);
    const qDigits = digits(term);
    const qDocument = /\d/.test(term) ? documentChars(term) : '';

    return state.clients
        .filter((c) => (type ? c.type === type : true))
        .filter((c) => (onlyActive ? c.active : true))
        .filter((c) => {
            if (!q) return true;
            const text = normalize(`${c.name} ${c.tradeName ?? ''} ${c.email ?? ''}`);
            if (text.includes(q)) return true;
            if (qDigits.length >= 3 && digits(c.phone).includes(qDigits)) return true;
            return qDocument.length >= 3 && documentChars(c.document).includes(qDocument);
        })
        .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
}

export const clientAppointments = (state, clientId) =>
    state.appointments.filter((a) => a.clientId === clientId).sort((a, b) => b.start - a.start);

export function appointmentsInRange(state, from, to, { responsibleId = null, showClosed = true } = {}) {
    return state.appointments
        .filter((a) => a.start >= from && a.start < to)
        .filter((a) => (responsibleId ? a.responsibleId === responsibleId : true))
        .filter((a) => (showClosed ? true : !['cancelled', 'no_show'].includes(a.status)))
        .sort((a, b) => a.start - b.start || a.id - b.id);
}

export const appointmentsOnDay = (state, ms, filters = {}) =>
    appointmentsInRange(state, startOfDay(ms), addDays(startOfDay(ms), 1), filters);

export const pendingDocuments = (appointment) => appointment.documents.filter((d) => !d.receivedAt).length;

export function setServiceActive(state, actor, slug, active) {
    authorize(actor, 'services.manage');
    const service = serviceOf(state, slug);
    if (!service) fail('Serviço não encontrado.');
    service.active = active;
    state.meta.edited = true;
}

export function addServiceDocument(state, actor, slug, name) {
    authorize(actor, 'services.manage');
    const service = serviceOf(state, slug);
    if (!service) fail('Serviço não encontrado.');
    const clean = name.trim();
    if (!clean) fail('Escreva o nome do documento.', 'name');
    service.documents.push(clean);
    state.meta.edited = true;
}

export function removeServiceDocument(state, actor, slug, index) {
    authorize(actor, 'services.manage');
    const service = serviceOf(state, slug);
    if (!service) fail('Serviço não encontrado.');
    service.documents.splice(index, 1);
    state.meta.edited = true;
}

export const describeActivity = (state, activity) => {
    const label = {
        created: 'Atendimento criado',
        rescheduled: 'Horário alterado',
        status_changed: 'Status alterado',
        responsible_changed: 'Responsável alterado',
        cancelled: 'Atendimento cancelado',
    }[activity.type];

    const detail = (() => {
        if (activity.type === 'status_changed') return `${STATUS[activity.old.status].label} → ${STATUS[activity.new.status].label}`;
        if (activity.type === 'rescheduled') return `${shortSlot(activity.old)} → ${shortSlot(activity.new)}`;
        if (activity.type === 'responsible_changed') return `${userOf(state, activity.old.userId)?.name} → ${userOf(state, activity.new.userId)?.name}`;
        return null;
    })();

    return { label, detail };
};

const shortSlot = ({ start }) => `${dayKey(start).slice(8, 10)}/${dayKey(start).slice(5, 7)} ${timeStr(start)}`;

export const locationLabel = (appointment) => {
    const type = LOCATION_TYPES[appointment.locationType];
    return appointment.location ? `${type} · ${appointment.location}` : type;
};

export const roleLabel = (user) => ROLES[user.role];
