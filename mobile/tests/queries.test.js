import assert from 'node:assert/strict';
import { test } from 'node:test';

import { scheduleAppointment } from '../js/domain.js';
import {
    attendanceRate,
    byClientType,
    byResponsible,
    countByStatus,
    indicators,
    indicatorsCsv,
    meiCount,
    peopleByMonth,
    thirdSectorCount,
    toCsv,
    topDemands,
    uniqueClientsServed,
} from '../js/queries.js';
import { buildSeed } from '../js/seed.js';
import { at } from '../js/util.js';
import { changeStatus } from '../js/domain.js';

// A tiny known data set: everything below is counted by hand.
const known = () => {
    const state = buildSeed(at('2026-10-14', '12:00'));
    state.appointments = [];
    const admin = state.users[0];
    const book = (clientId, serviceSlug, day, from, status, responsibleId = 2) => {
        const a = scheduleAppointment(state, admin, { clientId, serviceSlug, responsibleId, start: at(day, from), end: at(day, from.replace(/^(\d+)/, (h) => String(Number(h) + 1).padStart(2, '0'))) });
        if (status !== 'scheduled') {
            if (status === 'completed') {
                changeStatus(state, admin, a.id, 'in_progress');
            }
            changeStatus(state, admin, a.id, status);
        }
        return a;
    };

    // clients: 1 Joana (PF), 3 Teresa (PJ, MEI), 4 ACVN (PJ, ONG)
    book(1, 'irpf', '2026-09-10', '09:00', 'completed');
    book(1, 'irpf', '2026-09-24', '09:00', 'completed'); // same person, same month
    book(3, 'atendimento-mei', '2026-09-11', '10:00', 'completed');
    book(4, 'prestacao-de-contas-para-entidades-do-terceiro-setor', '2026-10-02', '10:00', 'completed', 1);
    book(3, 'atendimento-mei', '2026-10-05', '14:00', 'no_show');
    book(1, 'fluxo-de-caixa', '2026-10-06', '14:00', 'cancelled');
    book(4, 'elaboracao-de-demonstracoes-contabeis', '2026-10-20', '09:00', 'scheduled', 1);
    return state;
};

const PERIOD = { from: at('2026-09-01', '00:00'), to: at('2026-11-01', '00:00') };

test('counts by status and attendance rate', () => {
    const state = known();
    const list = state.appointments;

    assert.deepEqual(countByStatus(list), { scheduled: 1, confirmed: 0, in_progress: 0, completed: 4, cancelled: 1, no_show: 1 });
    // 4 showed up, 1 did not: 4 / 5
    assert.equal(attendanceRate(list), 0.8);
    assert.equal(attendanceRate([]), null);
});

test('unique people served counts each person once', () => {
    assert.equal(uniqueClientsServed(known().appointments), 3);
});

test('composition indicators ignore cancelled appointments', () => {
    const state = known();
    const list = state.appointments;

    // Not cancelled: Joana x2, Teresa x2, ACVN x2.
    assert.deepEqual(byClientType(state, list), { individual: 2, organization: 4 });
    assert.equal(meiCount(list), 2);
    assert.equal(thirdSectorCount(state, list), 2);
});

test('top demands and responsibles are ranked', () => {
    const state = known();

    assert.deepEqual(
        topDemands(state, state.appointments, 2).map((d) => [d.slug, d.count]),
        [['atendimento-mei', 2], ['irpf', 2]],
    );
    assert.deepEqual(byResponsible(state, state.appointments).map((r) => [r.name, r.count]), [['Membro da Equipe', 4], ['Administrador', 2]]);
});

test('people per month lists each month of the period', () => {
    const state = known();
    const months = peopleByMonth(state.appointments, PERIOD);

    assert.deepEqual(months.map((m) => [m.key, m.count]), [['2026-09', 2], ['2026-10', 1]]);
});

test('the indicators bundle respects the period filter', () => {
    const state = known();
    const october = indicators(state, { from: at('2026-10-01', '00:00'), to: PERIOD.to });
    const all = indicators(state, PERIOD);

    assert.equal(all.total, 7);
    assert.equal(october.total, 4);
    assert.equal(october.status.completed, 1);
});

test('CSV export has the headline numbers and escapes quotes', () => {
    const state = known();
    const csv = indicatorsCsv(state, PERIOD, {
        period: 'set–out/2026',
        status: { scheduled: 'Agendado', confirmed: 'Confirmado', in_progress: 'Em atendimento', completed: 'Concluído', cancelled: 'Cancelado', no_show: 'Não compareceu' },
    });

    assert.match(csv, /"Total de atendimentos";"7"/);
    assert.match(csv, /"Concluído";"4"/);
    assert.match(csv, /"Taxa de comparecimento";"80%"/);
});

test('CSV cells that look like spreadsheet formulas are kept as text', () => {
    assert.equal(toCsv([['=HYPERLINK("x")', '+1', '-2', '@SUM(A1)', 'Normal', 5]]), `"'=HYPERLINK(""x"")";"'+1";"'-2";"'@SUM(A1)";"Normal";"5"`);
});
