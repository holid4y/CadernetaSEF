import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    addServiceDocument,
    appointmentOf,
    can,
    changeStatus,
    clientAppointments,
    findConflict,
    rescheduleAppointment,
    saveClient,
    scheduleAppointment,
    searchClients,
    sendReminder,
    toggleDocument,
    ValidationError,
} from '../js/domain.js';
import { buildSeed } from '../js/seed.js';
import { at } from '../js/util.js';

const NOW = at('2026-10-14', '12:00');

const fresh = () => {
    const state = buildSeed(NOW);
    const [admin, team, viewer] = state.users;
    return { state, admin, team, viewer };
};

// A day with no seeded appointments, far from "today".
const DAY = '2027-03-02';
const slot = (from, to) => ({ start: at(DAY, from), end: at(DAY, to) });
const input = (state, extra = {}) => ({
    clientId: 1,
    serviceSlug: 'irpf',
    responsibleId: 2,
    ...slot('09:00', '10:00'),
    ...extra,
});

test('schedules an appointment and copies the checklist', () => {
    const { state, admin } = fresh();
    const appointment = scheduleAppointment(state, admin, input(state), NOW);

    assert.equal(appointment.status, 'scheduled');
    assert.equal(appointment.documents.length, 7);
    assert.ok(appointment.documents.every((d) => d.receivedAt === null));
    assert.equal(appointment.activities[0].type, 'created');
});

test('changing the service checklist later does not rewrite existing appointments', () => {
    const { state, admin } = fresh();
    const appointment = scheduleAppointment(state, admin, input(state), NOW);
    addServiceDocument(state, admin, 'irpf', 'Documento novo');

    assert.equal(appointment.documents.length, 7);
    const next = scheduleAppointment(state, admin, input(state, { ...slot('11:00', '12:00') }), NOW);
    assert.equal(next.documents.length, 8);
});

test('blocks overlapping appointments for the same responsible', () => {
    const { state, admin } = fresh();
    scheduleAppointment(state, admin, input(state), NOW);

    const attempts = {
        partial: slot('09:30', '10:30'),
        inside: slot('09:15', '09:45'),
        containing: slot('08:00', '11:00'),
        same: slot('09:00', '10:00'),
    };

    for (const [name, period] of Object.entries(attempts)) {
        assert.throws(() => scheduleAppointment(state, admin, input(state, period), NOW), ValidationError, name);
    }
});

test('conflict message names the person and the busy period', () => {
    const { state, admin } = fresh();
    scheduleAppointment(state, admin, input(state), NOW);

    assert.throws(
        () => scheduleAppointment(state, admin, input(state, slot('09:30', '10:30')), NOW),
        /Membro da Equipe já tem atendimento das 09:00 às 10:00\. Escolha outro horário ou outro responsável\./,
    );
});

test('allows adjacent periods and different responsibles', () => {
    const { state, admin } = fresh();
    scheduleAppointment(state, admin, input(state), NOW);

    scheduleAppointment(state, admin, input(state, slot('10:00', '11:00')), NOW);
    scheduleAppointment(state, admin, input(state, { responsibleId: 4 }), NOW);
    assert.equal(state.appointments.filter((a) => a.start === at(DAY, '09:00')).length, 2);
});

test('cancelled and no-show appointments do not block the slot', () => {
    const { state, admin } = fresh();
    const first = scheduleAppointment(state, admin, input(state), NOW);
    changeStatus(state, admin, first.id, 'cancelled', NOW);

    assert.equal(findConflict(state, { responsibleId: 2, ...slot('09:00', '10:00') }), null);
    scheduleAppointment(state, admin, input(state), NOW);
});

test('rejects an end before the start and absurdly long appointments', () => {
    const { state, admin } = fresh();

    assert.throws(() => scheduleAppointment(state, admin, input(state, slot('10:00', '09:00')), NOW), /horário final/);
    assert.throws(() => scheduleAppointment(state, admin, input(state, slot('08:00', '17:00')), NOW), /no máximo 8 horas/);
});

test('"Outros" requires a description and inactive services are refused', () => {
    const { state, admin } = fresh();

    assert.throws(() => scheduleAppointment(state, admin, input(state, { serviceSlug: 'outros' }), NOW), /Descreva a demanda/);
    scheduleAppointment(state, admin, input(state, { serviceSlug: 'outros', serviceDetails: 'Dúvida sobre ITCMD' }), NOW);

    state.services.find((s) => s.slug === 'irpf').active = false;
    assert.throws(() => scheduleAppointment(state, admin, input(state, slot('13:00', '14:00')), NOW), /desativado/);
});

test('viewers cannot create or change anything', () => {
    const { state, viewer, admin } = fresh();
    const appointment = scheduleAppointment(state, admin, input(state), NOW);

    assert.throws(() => scheduleAppointment(state, viewer, input(state, slot('13:00', '14:00')), NOW), /permissão/);
    assert.throws(() => changeStatus(state, viewer, appointment.id, 'cancelled', NOW), /permissão/);
    assert.throws(() => toggleDocument(state, viewer, appointment.id, appointment.documents[0].id, NOW), /permissão/);
    assert.equal(can(viewer, 'read'), true);
    assert.equal(can(viewer, 'appointments.write'), false);
});

test('only admins manage the service catalog', () => {
    const { state, team } = fresh();
    assert.throws(() => addServiceDocument(state, team, 'irpf', 'Algo'), /permissão/);
});

test('a viewer cannot be the responsible', () => {
    const { state, admin } = fresh();
    assert.throws(() => scheduleAppointment(state, admin, input(state, { responsibleId: 3 }), NOW), /somente de visualização/);
});

test('status follows the allowed transitions and cancelling keeps the record', () => {
    const { state, admin } = fresh();
    const appointment = scheduleAppointment(state, admin, input(state), NOW);

    changeStatus(state, admin, appointment.id, 'confirmed', NOW);
    changeStatus(state, admin, appointment.id, 'in_progress', NOW);
    assert.throws(() => changeStatus(state, admin, appointment.id, 'no_show', NOW), /não pode passar/);
    changeStatus(state, admin, appointment.id, 'completed', NOW);
    assert.throws(() => changeStatus(state, admin, appointment.id, 'scheduled', NOW), /não pode passar/);

    const other = scheduleAppointment(state, admin, input(state, slot('11:00', '12:00')), NOW);
    changeStatus(state, admin, other.id, 'cancelled', NOW);
    assert.ok(appointmentOf(state, other.id), 'cancelled appointments stay in the data');
    assert.equal(other.activities.at(-1).type, 'cancelled');
});

test('rescheduling keeps history and validates conflicts against others only', () => {
    const { state, admin } = fresh();
    const first = scheduleAppointment(state, admin, input(state), NOW);
    const second = scheduleAppointment(state, admin, input(state, slot('11:00', '12:00')), NOW);

    // Moving onto itself is not a conflict.
    rescheduleAppointment(state, admin, first.id, { ...slot('09:30', '10:30'), responsibleId: 2 }, NOW);
    assert.throws(() => rescheduleAppointment(state, admin, first.id, { ...slot('11:30', '12:30'), responsibleId: 2 }, NOW), /já tem atendimento/);

    rescheduleAppointment(state, admin, second.id, { ...slot('11:00', '12:00'), responsibleId: 4 }, NOW);
    assert.deepEqual(first.activities.map((a) => a.type), ['created', 'rescheduled']);
    assert.deepEqual(second.activities.map((a) => a.type), ['created', 'responsible_changed']);

    changeStatus(state, admin, first.id, 'in_progress', NOW);
    assert.throws(() => rescheduleAppointment(state, admin, first.id, { ...slot('14:00', '15:00'), responsibleId: 2 }, NOW), /agendados ou confirmados/);
});

test('marking a document records when it was delivered', () => {
    const { state, admin } = fresh();
    const appointment = scheduleAppointment(state, admin, input(state), NOW);
    const [document] = appointment.documents;

    toggleDocument(state, admin, appointment.id, document.id, NOW);
    assert.equal(document.receivedAt, NOW);
    toggleDocument(state, admin, appointment.id, document.id, NOW);
    assert.equal(document.receivedAt, null);
});

test('reminders need consent and an e-mail', () => {
    const { state, admin } = fresh();
    const withConsent = scheduleAppointment(state, admin, input(state), NOW); // Joana
    const noEmail = scheduleAppointment(state, admin, input(state, { clientId: 2, ...slot('11:00', '12:00') }), NOW); // Carlos

    assert.equal(sendReminder(state, admin, withConsent.id, NOW), 'joana.lima@example.com');
    assert.equal(withConsent.reminderSentAt, NOW);
    assert.throws(() => sendReminder(state, admin, noEmail.id, NOW), /não autorizou/);

    state.clients[1].acceptsReminders = true;
    assert.throws(() => sendReminder(state, admin, noEmail.id, NOW), /Sem e-mail/);
});

test('client history lists only that client, newest first, with disabled services', () => {
    const { state } = fresh();
    state.services.find((s) => s.slug === 'irpf').active = false;
    const history = clientAppointments(state, 2); // Carlos has seeded visits
    assert.ok(history.length > 0);
    assert.ok(history.every((a) => a.clientId === 2));
    assert.deepEqual(history.map((a) => a.start), [...history.map((a) => a.start)].sort((a, b) => b - a));
    assert.ok(history.some((a) => a.serviceSlug === 'irpf'));
});

test('a CNPJ with letters is accepted, compared and found like a numeric one', () => {
    const { state, admin } = fresh();
    assert.throws(() => saveClient(state, admin, { type: 'organization', name: 'Cooperativa', document: '12.ABC.345/01DE-36' }), /CNPJ/);

    const created = saveClient(state, admin, { type: 'organization', name: 'Cooperativa Nova', document: '12.abc.345/01de-35' });
    assert.equal(created.document, '12ABC34501DE35');
    assert.throws(() => saveClient(state, admin, { type: 'organization', name: 'Outra', document: '12ABC34501DE35' }), /já está cadastrado/);
    assert.deepEqual(searchClients(state, '12.abc.345').map((c) => c.name), ['Cooperativa Nova']);
});

test('client registration validates and de-duplicates', () => {
    const { state, admin } = fresh();

    assert.throws(() => saveClient(state, admin, { type: 'individual', name: '  ' }), /nome/);
    assert.throws(() => saveClient(state, admin, { type: 'individual', name: 'Ana', email: 'ana@' }), /e-mail/);
    assert.throws(() => saveClient(state, admin, { type: 'individual', name: 'Ana', phone: '999' }), /telefone/);
    assert.throws(() => saveClient(state, admin, { type: 'individual', name: 'Ana', document: '111.111.111-11' }), /CPF/);
    assert.throws(() => saveClient(state, admin, { type: 'individual', name: 'Ana', acceptsReminders: true }), /informe o e-mail/);

    const created = saveClient(state, admin, { type: 'individual', name: 'Ana Teste', document: '529.982.247-25', acceptsReminders: false });
    assert.equal(created.acceptsReminders, false);
    assert.equal(created.consentAt, null);
    assert.throws(() => saveClient(state, admin, { type: 'individual', name: 'Outra', document: '52998224725' }), /já está cadastrado/);
});

test('search finds by name, trade name, phone digits and e-mail without accents', () => {
    const { state } = fresh();

    assert.deepEqual(searchClients(state, 'joana').map((c) => c.name), ['Joana Lima']);
    assert.deepEqual(searchClients(state, 'doces da vo').map((c) => c.name), ['Teresa Alves Doces']);
    assert.deepEqual(searchClients(state, '99701').map((c) => c.name), ['Carlos Eduardo Souza']);
    assert.deepEqual(searchClients(state, 'maos.example').map((c) => c.name), ['Instituto Mãos que Ajudam']);
    assert.deepEqual(searchClients(state, 'fernanda muller').map((c) => c.name), ['Fernanda Müller']);
});

test('seed has no overlapping appointments and a populated agenda today', () => {
    const { state } = fresh();
    const live = state.appointments.filter((a) => ['scheduled', 'confirmed', 'in_progress'].includes(a.status));

    for (const a of live) {
        const clash = live.find((b) => b.id !== a.id && b.responsibleId === a.responsibleId && a.start < b.end && a.end > b.start);
        assert.equal(clash, undefined);
    }
    const today = state.appointments.filter((a) => new Date(a.start).toDateString() === new Date(NOW).toDateString());
    assert.ok(today.length >= 3);
    assert.ok(state.appointments.length > 60);
});
