// Impact panel indicators. PF/PJ, MEI, ONG, demands and per-responsible counts
// skip cancelled appointments; "people served" counts only completed ones.

import { CLIENT_TYPES, MEI_SERVICE, STATUS_ORDER, THIRD_SECTOR_CATEGORY } from './data.js';
import { clientOf, serviceOf, userOf } from './domain.js';
import { addMonths, monthKey, monthShort, startOfMonth } from './util.js';

export const inPeriod = (state, { from, to }) => state.appointments.filter((a) => a.start >= from && a.start < to);

const active = (list) => list.filter((a) => a.status !== 'cancelled');

export const total = (list) => list.length;

export const countByStatus = (list) =>
    Object.fromEntries(STATUS_ORDER.map((status) => [status, list.filter((a) => a.status === status).length]));

/** Share of people who showed up among those who were expected (completed / (completed + no_show)). */
export const attendanceRate = (list) => {
    const shown = list.filter((a) => a.status === 'completed').length;
    const missed = list.filter((a) => a.status === 'no_show').length;
    return shown + missed === 0 ? null : shown / (shown + missed);
};

export const uniqueClientsServed = (list) => new Set(list.filter((a) => a.status === 'completed').map((a) => a.clientId)).size;

export const byClientType = (state, list) => {
    const counts = Object.fromEntries(Object.keys(CLIENT_TYPES).map((type) => [type, 0]));
    active(list).forEach((a) => (counts[clientOf(state, a.clientId).type] += 1));
    return counts;
};

export const meiCount = (list) => active(list).filter((a) => a.serviceSlug === MEI_SERVICE).length;

export const thirdSectorCount = (state, list) =>
    active(list).filter((a) => serviceOf(state, a.serviceSlug).categorySlug === THIRD_SECTOR_CATEGORY).length;

export const topDemands = (state, list, limit = 5) => {
    const counts = new Map();
    active(list).forEach((a) => counts.set(a.serviceSlug, (counts.get(a.serviceSlug) ?? 0) + 1));

    return [...counts.entries()]
        .map(([slug, count]) => ({ slug, name: serviceOf(state, slug).name, count }))
        .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'pt-BR'))
        .slice(0, limit);
};

export const byResponsible = (state, list) => {
    const counts = new Map();
    active(list).forEach((a) => counts.set(a.responsibleId, (counts.get(a.responsibleId) ?? 0) + 1));

    return [...counts.entries()]
        .map(([id, count]) => ({ id, name: userOf(state, id).name, count }))
        .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'pt-BR'));
};

/** Distinct people served per calendar month, oldest first, for the months the period touches. */
export const peopleByMonth = (list, { from, to }) => {
    const months = [];
    for (let m = startOfMonth(from); m < to; m = addMonths(m, 1)) months.push(m);

    return months.map((month) => {
        const key = monthKey(month);
        const people = new Set(list.filter((a) => a.status === 'completed' && monthKey(a.start) === key).map((a) => a.clientId));
        return { key, label: monthShort(month), count: people.size };
    });
};

export const indicators = (state, period) => {
    const list = inPeriod(state, period);
    const types = byClientType(state, list);

    return {
        total: total(list),
        status: countByStatus(list),
        attendanceRate: attendanceRate(list),
        uniqueClients: uniqueClientsServed(list),
        individuals: types.individual,
        organizations: types.organization,
        mei: meiCount(list),
        thirdSector: thirdSectorCount(state, list),
        topDemands: topDemands(state, list),
        byResponsible: byResponsible(state, list),
        peopleByMonth: peopleByMonth(list, period),
    };
};

// A cell starting with = + - @ would run as a formula in Excel.
const cell = (value) => {
    const text = String(value ?? '');
    const safe = /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
    return `"${safe.replaceAll('"', '""')}"`;
};

export const toCsv = (rows) => rows.map((row) => row.map(cell).join(';')).join('\r\n');

export const indicatorsCsv = (state, period, labels) => {
    const data = indicators(state, period);

    return toCsv([
        ['Indicador', 'Valor'],
        ['Período', labels.period],
        ['Total de atendimentos', data.total],
        ...STATUS_ORDER.map((status) => [labels.status[status], data.status[status]]),
        ['Taxa de comparecimento', data.attendanceRate === null ? '' : `${Math.round(data.attendanceRate * 100)}%`],
        ['Pessoas atendidas (únicas)', data.uniqueClients],
        ['Pessoa física', data.individuals],
        ['Pessoa jurídica', data.organizations],
        ['MEI', data.mei],
        ['ONGs e terceiro setor', data.thirdSector],
        [],
        ['Demandas mais frequentes', 'Atendimentos'],
        ...topDemands(state, inPeriod(state, period), 50).map((d) => [d.name, d.count]),
        [],
        ['Responsável', 'Atendimentos'],
        ...data.byResponsible.map((r) => [r.name, r.count]),
        [],
        ['Mês', 'Pessoas atendidas'],
        ...data.peopleByMonth.map((m) => [m.key, m.count]),
    ]);
};
