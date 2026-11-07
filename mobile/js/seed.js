import { CATALOG, DEFAULT_PLACE } from './data.js';
import { changeStatus, documentsAreDue, scheduleAppointment, toggleDocument, ValidationError } from './domain.js';
import { addDays, at, dayKey, minutes, pad, startOfDay } from './util.js';

const USERS = [
    { id: 1, name: 'Administrador', email: 'admin@agenda.local', role: 'admin', active: true },
    { id: 2, name: 'Membro da Equipe', email: 'equipe@agenda.local', role: 'team', active: true },
    { id: 3, name: 'Visualizador', email: 'visualizador@agenda.local', role: 'viewer', active: true },
    { id: 4, name: 'Ana Paula Reis', email: 'ana@agenda.local', role: 'team', active: true },
    { id: 5, name: 'Lucas Menezes', email: 'lucas@agenda.local', role: 'team', active: true },
];

const makeCpf = (base) => {
    const d = base.split('').map(Number);
    const check = (len) => {
        const sum = d.slice(0, len).reduce((acc, n, i) => acc + n * (len + 1 - i), 0);
        const rest = (sum * 10) % 11;
        return rest === 10 ? 0 : rest;
    };
    d.push(check(9));
    d.push(check(10));
    return d.join('');
};

const makeCnpj = (base) => {
    const d = base.split('').map(Number);
    const check = (len) => {
        const weights = len === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        const rest = weights.reduce((acc, w, i) => acc + w * d[i], 0) % 11;
        return rest < 2 ? 0 : 11 - rest;
    };
    d.push(check(12));
    d.push(check(13));
    return d.join('');
};

// kind decides which demands the generated history gives each client.
const CLIENTS = [
    { type: 'individual', kind: 'pf', name: 'Joana Lima', phone: '(51) 99812-4410', email: 'joana.lima@example.com', document: makeCpf('123456789') },
    { type: 'individual', kind: 'pf', name: 'Carlos Eduardo Souza', phone: '(51) 99701-2280' },
    { type: 'organization', kind: 'mei', name: 'Teresa Alves Doces', tradeName: 'Doces da Vó Teresa', document: makeCnpj('451239870001'), phone: '(51) 98455-0192', email: 'contato@docesdavo.example.com' },
    { type: 'ngo', kind: 'ong', name: 'Associação Comunitária Vila Nova', tradeName: 'ACVN', phone: '(51) 3595-2211', email: 'acvn@example.org' },
    { type: 'individual', kind: 'pf', name: 'Marcos Pereira', phone: '(51) 99633-8814', email: 'marcos.p@example.com' },
    { type: 'organization', kind: 'mei', name: 'Oficina do Beto Reparos', tradeName: 'Oficina do Beto', phone: '(51) 98122-7765' },
    { type: 'individual', kind: 'pf', name: 'Luciana Ferreira', phone: '(51) 99277-3301', email: 'lu.ferreira@example.com' },
    { type: 'ngo', kind: 'ong', name: 'Instituto Mãos que Ajudam', tradeName: 'Mãos que Ajudam', phone: '(51) 3587-4400', email: 'contato@maos.example.org' },
    { type: 'individual', kind: 'pf', name: 'Roberto Klein', phone: '(51) 99544-1207', email: 'roberto.klein@example.com' },
    { type: 'organization', kind: 'empresa', name: 'Padaria Pão Quente Ltda', tradeName: 'Pão Quente', document: makeCnpj('120987650001'), phone: '(51) 3594-7788', email: 'paoquente@example.com' },
    { type: 'individual', kind: 'pf', name: 'Fernanda Müller', phone: '(51) 99388-6521' },
    { type: 'ngo', kind: 'ong', name: 'Associação de Moradores do Santo Afonso', tradeName: 'Moradores do Santo Afonso', phone: '(51) 3584-1930', email: 'santoafonso@example.org' },
];

const DEMANDS = {
    pf: [['irpf', 3], ['declaracao-de-imposto-de-renda', 2], ['orcamento-familiar-e-pessoal', 2], ['fluxo-de-caixa', 1], ['planejamento-financeiro', 1], ['emissao-de-guias-para-pagamento-de-tributos', 1]],
    mei: [['atendimento-mei', 4], ['emissao-de-guias-para-pagamento-de-tributos', 2], ['fluxo-de-caixa', 1], ['formacao-do-preco-de-venda', 1]],
    empresa: [['formacao-do-preco-de-venda', 2], ['calculo-de-custos', 2], ['orcamento-empresarial', 2], ['mapeamento-de-processos', 1], ['fluxo-de-caixa', 1]],
    ong: [['prestacao-de-contas-para-entidades-do-terceiro-setor', 3], ['elaboracao-de-demonstracoes-contabeis', 2], ['elaboracao-de-relatorio-de-responsabilidade-social', 1]],
};

// Seeded random, so a restored demo always looks the same.
const mulberry32 = (seed) => () => {
    seed |= 0;
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
};

const pickWeighted = (random, entries) => {
    const total = entries.reduce((sum, [, weight]) => sum + weight, 0);
    let roll = random() * total;
    for (const [value, weight] of entries) {
        roll -= weight;
        if (roll < 0) return value;
    }
    return entries[0][0];
};

const buildCatalog = () =>
    CATALOG.flatMap((category) =>
        category.services.map((service) => ({
            slug: service.slug,
            categorySlug: category.slug,
            name: service.name,
            minutes: service.minutes,
            requiresDetails: service.requiresDetails === true,
            active: true,
            documents: [...service.documents],
        })),
    );

export function buildSeed(now = Date.now()) {
    const state = {
        version: 1,
        users: USERS.map((u) => ({ ...u })),
        clients: [],
        services: buildCatalog(),
        appointments: [],
        counters: {},
        meta: { seededOn: dayKey(now), edited: false },
    };

    const admin = state.users[0];
    const byId = (id) => state.users.find((u) => u.id === id);

    CLIENTS.forEach((client, index) => {
        const { kind: _kind, ...fields } = client;
        void _kind;
        state.clients.push({
            id: index + 1,
            tradeName: null,
            document: null,
            phone: null,
            email: null,
            notes: null,
            acceptsReminders: Boolean(client.email),
            consentAt: client.email ? now - 20 * 86_400_000 : null,
            active: true,
            createdAt: now - 60 * 86_400_000,
            ...fields,
        });
    });
    state.counters.client = CLIENTS.length;

    const today = startOfDay(now);
    const roundedHour = Math.round(new Date(now).getHours() + new Date(now).getMinutes() / 60);
    const base = Math.min(16, Math.max(10, roundedHour));

    const place = (startMs, durationMin) => ({ start: startMs, end: startMs + minutes(durationMin) });
    const nextWorkday = (offset) => {
        const ms = addDays(today, offset);
        return new Date(ms).getDay() === 0 ? addDays(ms, 1) : ms; // no Sundays
    };

    // [day, "HH:mm" | hour offset from `base`, minutes, client, service, responsible id, status, where]
    const AGENDA = [
        [0, base - 2, 60, 1, 'irpf', 2, 'completed'],
        [0, base, 60, 0, 'declaracao-de-imposto-de-renda', 1, 'confirmed'],
        [0, base + 1.5, 60, 2, 'atendimento-mei', 4, 'scheduled'],
        [0, base + 3, 90, 7, 'elaboracao-de-demonstracoes-contabeis', 5, 'scheduled'],
        [1, '10:30', 60, 6, 'irpf', 2, 'confirmed'],
        [1, '14:00', 60, 5, 'formacao-do-preco-de-venda', 1, 'scheduled'],
        [2, '09:00', 60, 4, 'emissao-de-guias-para-pagamento-de-tributos', 1, 'scheduled'],
        [2, '09:00', 60, 2, 'atendimento-mei', 2, 'scheduled'],
        [2, '15:00', 90, 3, 'prestacao-de-contas-para-entidades-do-terceiro-setor', 4, 'scheduled'],
        [3, '13:30', 180, 2, 'atendimento-externo', 1, 'confirmed', ['external', 'Sala do Empreendedor, Campo Bom']],
        [4, '09:30', 60, 1, 'irpf', 5, 'scheduled'],
        [5, '14:00', 60, 4, 'planejamento-financeiro', 1, 'scheduled', ['remote', 'Videochamada']],
        [6, '10:00', 60, 11, 'prestacao-de-contas-para-entidades-do-terceiro-setor', 2, 'scheduled'],
        [8, '16:00', 60, 9, 'calculo-de-custos', 2, 'scheduled'],
        [-1, '15:00', 60, 6, 'fluxo-de-caixa', 2, 'completed'],
        [-1, '10:00', 90, 3, 'prestacao-de-contas-para-entidades-do-terceiro-setor', 1, 'completed'],
        [-2, '09:00', 60, 5, 'atendimento-mei', 4, 'no_show'],
        [-3, '14:00', 60, 8, 'irpf', 5, 'completed'],
        [-3, '16:00', 60, 0, 'orcamento-familiar-e-pessoal', 2, 'cancelled'],
        [-4, '09:30', 60, 10, 'declaracao-de-imposto-de-renda', 4, 'completed'],
        [-6, '14:00', 90, 7, 'elaboracao-de-relatorio-de-responsabilidade-social', 1, 'completed'],
    ];

    const STEPS = {
        scheduled: [],
        confirmed: ['confirmed'],
        completed: ['in_progress', 'completed'],
        no_show: ['no_show'],
        cancelled: ['cancelled'],
    };

    const addAppointment = ({ dayMs, when, duration, clientIndex, serviceSlug, responsibleId, status, where }) => {
        const key = dayKey(dayMs);
        const startMs = typeof when === 'string' ? at(key, when) : dayMs + when * 3_600_000;
        const { start, end } = place(startMs, duration);
        const createdAt = Math.min(now, start - 3 * 86_400_000);

        const appointment = scheduleAppointment(
            state,
            admin,
            {
                clientId: clientIndex + 1,
                serviceSlug,
                responsibleId,
                start,
                end,
                locationType: where?.[0] ?? 'on_campus',
                location: where?.[1] ?? (where ? null : DEFAULT_PLACE),
            },
            createdAt,
        );

        const stamps = {
            confirmed: Math.min(now, start - 2 * 86_400_000),
            in_progress: start,
            completed: end,
            no_show: start + minutes(20),
            cancelled: Math.min(now, start - 86_400_000),
        };
        STEPS[status].forEach((step) => changeStatus(state, byId(responsibleId), appointment.id, step, stamps[step]));

        const delivered = status === 'scheduled' || !documentsAreDue(appointment, now) ? 0 : status === 'completed' ? appointment.documents.length : Math.ceil(appointment.documents.length / 2);
        appointment.documents.slice(0, delivered).forEach((doc) => toggleDocument(state, byId(responsibleId), appointment.id, doc.id, Math.min(now, start)));

        const client = state.clients[clientIndex];
        const reminderAt = start - 86_400_000;
        if (client.acceptsReminders && ['scheduled', 'confirmed', 'completed'].includes(status) && reminderAt < now) {
            appointment.reminderSentAt = reminderAt;
        }
    };

    for (const [offset, when, duration, clientIndex, serviceSlug, responsibleId, status, where] of AGENDA) {
        const dayMs = offset > 0 ? nextWorkday(offset) : addDays(today, offset);
        addAppointment({ dayMs, when, duration, clientIndex, serviceSlug, responsibleId, status, where });
    }

        const random = mulberry32(2026);
    const TEAM = [[1, 2], [2, 3], [4, 3], [5, 2]];
    for (let offset = -11; offset >= -165; offset--) {
        const dayMs = addDays(today, offset);
        const weekday = new Date(dayMs).getDay();
        if (weekday === 0) continue;
        const count = weekday === 6 ? (random() < 0.3 ? 1 : 0) : (random() < 0.85 ? 1 : 0) + (random() < 0.4 ? 1 : 0);

        for (let n = 0; n < count; n++) {
            const clientIndex = Math.floor(random() * CLIENTS.length);
            const serviceSlug = pickWeighted(random, DEMANDS[CLIENTS[clientIndex].kind]);
            const service = state.services.find((s) => s.slug === serviceSlug);
            const hour = 9 + Math.floor(random() * 8);
            const roll = random();

            try {
                addAppointment({
                    dayMs,
                    when: `${pad(weekday === 6 ? Math.min(hour, 11) : hour)}:${random() < 0.5 ? '00' : '30'}`,
                    duration: service.minutes,
                    clientIndex,
                    serviceSlug,
                    responsibleId: pickWeighted(random, TEAM),
                    status: roll < 0.8 ? 'completed' : roll < 0.9 ? 'no_show' : 'cancelled',
                });
            } catch (error) {
                // Random slots collided; skip it.
                if (!(error instanceof ValidationError)) throw error;
            }
        }
    }

    state.meta.edited = false;
    return state;
}
