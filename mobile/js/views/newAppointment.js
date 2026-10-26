import { CATALOG, CLIENT_TYPES, LOCATION_TYPES } from '../data.js';
import {
    can,
    clientOf,
    conflictMessage,
    findConflict,
    responsibles,
    searchClients,
    serviceOf,
    ValidationError,
} from '../domain.js';
import { back, navigate } from '../router.js';
import { store } from '../store.js';
import { backButton, emptyState, icon, toast } from '../ui.js';
import { addDays, at, capitalize, dateFull, dayKey, esc, minutes, plural, weekday } from '../util.js';
import { clientFormBody, mountClientForm } from './clients.js';

const DURATIONS = [30, 60, 90, 120, 180];
const SLOT_FROM = 8 * 60;
const SLOT_TO = 20 * 60;

let draft = null;

const newDraft = (query, state, user) => {
    const [day, time] = (query.inicio ?? '').split('T');
    const owner = responsibles(state).find((u) => u.id === user.id) ?? responsibles(state)[0];
    const client = query.atendido ? clientOf(state, Number(query.atendido)) : null;

    return {
        step: client ? 2 : 1,
        clientId: client?.id ?? null,
        creating: false,
        categorySlug: null,
        serviceSlug: null,
        serviceDetails: '',
        date: /^\d{4}-\d{2}-\d{2}$/.test(day ?? '') ? day : dayKey(Date.now()),
        start: time ?? '',
        end: '',
        duration: 60,
        responsibleId: owner.id,
        locationType: 'on_campus',
        location: '',
        notes: '',
    };
};

const addMinutes = (time, amount) => {
    const total = Number(time.slice(0, 2)) * 60 + Number(time.slice(3)) + amount;
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};

const period = () => ({
    start: draft.start ? at(draft.date, draft.start) : NaN,
    end: draft.end ? at(draft.date, draft.end) : NaN,
});

const conflictFor = (state) => {
    const { start, end } = period();
    if (!(end > start)) return null;
    return findConflict(state, { responsibleId: draft.responsibleId, start, end });
};

const freeSlots = (state) => {
    const now = Date.now();
    const slots = [];
    for (let m = SLOT_FROM; m <= SLOT_TO - 30; m += 30) {
        const label = `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
        const start = at(draft.date, label);
        const busy = findConflict(state, { responsibleId: draft.responsibleId, start, end: start + minutes(draft.duration) });
        slots.push({ label, disabled: Boolean(busy) || start < now });
    }
    return slots;
};

export default function newAppointment({ query, state, user }) {
    if (!can(user, 'appointments.write')) {
        return { html: `<header class="appbar">${backButton('/agenda')}<h1>Novo atendimento</h1></header>${emptyState({ icon: 'info', title: 'Você não pode fazer isso', text: 'Seu perfil só consulta a agenda.' })}` };
    }

    draft = newDraft(query, state, user);

    return {
        html: `<header class="appbar"><button type="button" class="icon-btn" id="prev" aria-label="Voltar">${icon('chev-l')}</button><h1>Novo atendimento</h1></header>
            <div class="steps" aria-hidden="true"><i></i><i></i><i></i></div>
            <div id="step"></div>`,

        mount(root) {
            const box = root.querySelector('#step');
            const bars = root.querySelectorAll('.steps i');

            const show = () => {
                bars.forEach((bar, i) => bar.classList.toggle('is-done', i < draft.step));
                const render = [stepClient, stepService, stepWhen][draft.step - 1];
                box.innerHTML = render(store.state);
                [wireClient, wireService, wireWhen][draft.step - 1](box, show, store.state);
                root.parentElement.scrollTop = 0;
            };

            root.querySelector('#prev').addEventListener('click', () => {
                if (draft.creating) {
                    draft.creating = false;
                    return show();
                }
                if (draft.step <= 1) return back('/agenda');
                draft.step -= 1;
                show();
            });

            show();
        },
    };
}

const stepClient = (state) => {
    if (draft.creating) {
        return `<h2>Cadastrar novo atendido</h2><p class="muted" style="margin:4px 0 16px">Só o essencial: o restante pode ser completado depois na ficha.</p>${clientFormBody(null, { compact: true })}`;
    }

    return `<h2>Quem será atendido?</h2>
        <div class="search" style="margin:14px 0" role="search">
            ${icon('search')}
            <label class="sr-only" for="q">Buscar atendido</label>
            <input class="input" id="q" type="search" autocomplete="off" placeholder="Nome, telefone, e-mail ou CPF/CNPJ">
        </div>
        <div id="found" aria-live="polite">${foundList(state, '')}</div>
        <button class="btn btn-secondary btn-block" type="button" id="create-client" style="margin-top:14px">${icon('plus')}Cadastrar novo atendido</button>`;
};

const foundList = (state, term) => {
    const found = searchClients(state, term, { onlyActive: true }).slice(0, 8);
    if (!found.length) return `<p class="muted" style="padding:8px 2px">Ninguém encontrado com "${esc(term)}".</p>`;
    return `<div class="list">${found
        .map(
            (c) => `<button type="button" class="row is-client" data-client="${c.id}" style="border-left-width:1px">
                <div class="row-main"><span class="row-title">${esc(c.name)}</span><span class="row-sub">${CLIENT_TYPES[c.type]}${c.phone ? ` · ${esc(c.phone)}` : ''}</span></div>
                <span class="row-chevron">${icon('chev-r')}</span></button>`,
        )
        .join('')}</div>`;
};

const wireClient = (box, show, state) => {
    if (draft.creating) {
        mountClientForm(box, null, (saved) => {
            draft.clientId = saved.id;
            draft.creating = false;
            draft.step = 2;
            toast(`${saved.name} cadastrado.`);
            show();
        });
        return;
    }

    const found = box.querySelector('#found');
    box.querySelector('#q').addEventListener('input', (event) => (found.innerHTML = foundList(state, event.target.value.trim())));
    found.addEventListener('click', (event) => {
        const row = event.target.closest('[data-client]');
        if (!row) return;
        draft.clientId = Number(row.dataset.client);
        draft.step = 2;
        show();
    });
    box.querySelector('#create-client').addEventListener('click', () => {
        draft.creating = true;
        show();
    });
};

const stepService = (state) => {
    const client = clientOf(state, draft.clientId);
    const category = CATALOG.find((c) => c.slug === draft.categorySlug);
    const services = category ? state.services.filter((s) => s.categorySlug === category.slug && s.active) : [];
    const chosen = draft.serviceSlug ? serviceOf(state, draft.serviceSlug) : null;

    return `<p class="muted small" style="margin-bottom:4px">Atendido: <b>${esc(client.name)}</b></p>
        <h2>Qual a demanda?</h2>
        <div class="tiles" style="margin:14px 0" role="group" aria-label="Categoria">
            ${CATALOG.map((c) => `<button type="button" class="tile ${c.slug === 'outros' ? 'is-wide' : ''}" data-category="${c.slug}" aria-pressed="${c.slug === draft.categorySlug}"><b>${esc(c.name)}</b><span>${esc(c.hint)}</span></button>`).join('')}
        </div>
        ${
            category
                ? `<div role="radiogroup" aria-label="Serviço">${services
                      .map(
                          (s) => `<button type="button" class="choice" role="radio" aria-checked="${s.slug === draft.serviceSlug}" data-service="${s.slug}">
                              <span class="radio"></span><span class="grow">${esc(s.name)}<small>${s.minutes} min${s.documents.length ? ` · ${plural(s.documents.length, 'documento', 'documentos')}` : ''}</small></span></button>`,
                      )
                      .join('')}</div>`
                : ''
        }
        ${
            chosen?.requiresDetails
                ? `<div class="field" style="margin-top:14px"><label for="details">Descreva a demanda</label><textarea class="textarea" id="details" placeholder="Descreva a demanda em poucas palavras">${esc(draft.serviceDetails)}</textarea></div>`
                : ''
        }
        ${
            chosen
                ? `<section class="card" style="margin-top:14px"><h3>O atendido deverá trazer</h3>${
                      chosen.documents.length
                          ? `<ul style="margin-top:8px">${chosen.documents.map((d) => `<li style="padding:5px 0;border-bottom:1px solid var(--line)">${esc(d)}</li>`).join('')}</ul>`
                          : '<p class="muted" style="margin-top:6px">Este serviço não tem lista de documentos.</p>'
                  }</section>`
                : ''
        }
        <p class="error-text" id="problem" role="alert" hidden style="margin-top:14px"></p>
        <button class="btn btn-primary btn-block" type="button" id="next" style="margin-top:16px">Continuar</button>`;
};

const wireService = (box, show) => {
    box.querySelectorAll('[data-category]').forEach((button) =>
        button.addEventListener('click', () => {
            if (draft.categorySlug !== button.dataset.category) draft.serviceSlug = null;
            draft.categorySlug = button.dataset.category;
            if (draft.categorySlug === 'outros') draft.serviceSlug = 'outros';
            if (draft.serviceSlug) draft.duration = serviceOf(store.state, draft.serviceSlug).minutes;
            show();
        }),
    );

    box.querySelectorAll('[data-service]').forEach((button) =>
        button.addEventListener('click', () => {
            draft.serviceSlug = button.dataset.service;
            draft.duration = serviceOf(store.state, draft.serviceSlug).minutes;
            show();
        }),
    );

    box.querySelector('#details')?.addEventListener('input', (event) => (draft.serviceDetails = event.target.value));

    box.querySelector('#next').addEventListener('click', () => {
        const problem = box.querySelector('#problem');
        const service = draft.serviceSlug ? serviceOf(store.state, draft.serviceSlug) : null;
        const message = !service
            ? 'Escolha uma demanda para continuar.'
            : service.requiresDetails && !draft.serviceDetails.trim()
              ? 'Descreva a demanda para continuar.'
              : '';
        if (message) {
            problem.textContent = message;
            problem.hidden = false;
            return;
        }
        if (!draft.start) pickFirstFreeSlot(store.state);
        if (draft.start && !draft.end) draft.end = addMinutes(draft.start, draft.duration);
        draft.step = 3;
        show();
    });
};

const firstFree = (state) => freeSlots(state).find((s) => !s.disabled)?.label ?? '';

// Nothing left today (late in the day, or a full agenda): move on to the next day with room.
const pickFirstFreeSlot = (state) => {
    for (let tries = 0; tries < 14; tries++) {
        draft.start = firstFree(state);
        if (draft.start) return;
        draft.date = dayKey(addDays(at(draft.date, '12:00'), 1));
    }
};

const summary = (state) => {
    const client = clientOf(state, draft.clientId);
    const service = serviceOf(state, draft.serviceSlug);
    const day = draft.date ? at(draft.date, '12:00') : null;
    const rows = [
        ['Data', day ? dateFull(day) : '—'],
        ['Dia da semana', day ? capitalize(weekday(day)) : '—'],
        ['Horário', draft.start && draft.end ? `${draft.start} às ${draft.end}` : '—'],
        ['Atendimento', CLIENT_TYPES[client.type]],
        ['Demanda', service.requiresDetails && draft.serviceDetails ? `${service.name}: ${draft.serviceDetails}` : service.name],
        ['Contato', client.phone ?? '—'],
        ['E-mail', client.email ?? '—'],
    ];
    return rows.map(([label, value]) => `<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`).join('');
};

const stepWhen = (state) => {
    const client = clientOf(state, draft.clientId);
    const service = serviceOf(state, draft.serviceSlug);
    const conflict = conflictFor(state);
    const { start, end } = period();
    const invalid = draft.start && draft.end && !(end > start);
    const message = conflict ? conflictMessage(state, conflict) : invalid ? 'O horário final precisa ser depois do horário inicial.' : '';

    return `<p class="muted small" style="margin-bottom:4px">${esc(client.name)} · ${esc(service.name)}</p>
        <h2>Quando e com quem?</h2>
        <form class="form" id="when" style="margin-top:14px" novalidate>
            <div class="field">
                <label for="date">Data</label>
                <input class="input" id="date" type="date" value="${draft.date}" required>
                <span class="hint-text" id="weekday">${draft.date ? capitalize(weekday(at(draft.date, '12:00'))) : ''}</span>
            </div>
            <div class="field">
                <label for="who">Responsável</label>
                <select class="select" id="who">${responsibles(state).map((u) => `<option value="${u.id}" ${u.id === draft.responsibleId ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
            </div>
            <div class="field">
                <span class="label">Duração</span>
                <div class="chips" role="group" aria-label="Duração">${DURATIONS.map((d) => `<button type="button" class="chip" data-duration="${d}" aria-pressed="${d === draft.duration}">${d} min</button>`).join('')}</div>
            </div>
            <div class="field">
                <span class="label">Horários livres</span>
                <div class="slots" role="group" aria-label="Horários de início">${freeSlots(state).map((s) => `<button type="button" class="chip" data-slot="${s.label}" aria-pressed="${s.label === draft.start}" ${s.disabled ? 'disabled' : ''}>${s.label}</button>`).join('')}</div>
                <span class="hint-text">Os horários riscados já estão ocupados para este responsável.</span>
            </div>
            <div class="field-row">
                <div class="field"><label for="start">Início</label><input class="input" id="start" type="time" step="900" value="${draft.start}"></div>
                <div class="field"><label for="end">Fim</label><input class="input" id="end" type="time" step="900" value="${draft.end}"></div>
            </div>
            <p class="error-text" id="problem" role="alert" ${message ? '' : 'hidden'}>${esc(message)}</p>
            <div class="field">
                <span class="label">Local</span>
                <div class="seg" role="radiogroup" aria-label="Tipo de local">${Object.entries(LOCATION_TYPES).map(([value, text]) => `<button type="button" role="radio" aria-checked="${draft.locationType === value}" data-location-type="${value}">${text}</button>`).join('')}</div>
                <input class="input" id="location" placeholder="Ex.: Sala 205, prédio Azul" value="${esc(draft.location)}" aria-label="Detalhe do local">
            </div>
            <div class="field"><label for="notes">Observações <span class="opt">(opcional)</span></label><textarea class="textarea" id="notes" placeholder="Algo que a equipe precisa saber antes do atendimento">${esc(draft.notes)}</textarea></div>

            <section class="card"><h3>Resumo da ficha</h3><dl class="summary" id="summary" style="margin-top:6px">${summary(state)}</dl></section>
            <button class="btn btn-primary btn-block" type="submit">Agendar atendimento</button>
        </form>`;
};

const wireWhen = (box, show, state) => {
    const form = box.querySelector('#when');
    const problem = box.querySelector('#problem');

    const refreshProblem = () => {
        const conflict = conflictFor(state);
        const { start, end } = period();
        const invalid = draft.start && draft.end && !(end > start);
        problem.textContent = conflict ? conflictMessage(state, conflict) : invalid ? 'O horário final precisa ser depois do horário inicial.' : '';
        problem.hidden = !problem.textContent;
        box.querySelector('#summary').innerHTML = summary(state);
    };

    form.querySelector('#date').addEventListener('change', (event) => {
        draft.date = event.target.value;
        draft.start = firstFree(state);
        draft.end = draft.start ? addMinutes(draft.start, draft.duration) : '';
        show();
    });

    form.querySelector('#who').addEventListener('change', (event) => {
        draft.responsibleId = Number(event.target.value);
        show();
    });

    box.querySelectorAll('[data-duration]').forEach((button) =>
        button.addEventListener('click', () => {
            draft.duration = Number(button.dataset.duration);
            if (draft.start) draft.end = addMinutes(draft.start, draft.duration);
            show();
        }),
    );

    box.querySelectorAll('[data-slot]').forEach((button) =>
        button.addEventListener('click', () => {
            draft.start = button.dataset.slot;
            draft.end = addMinutes(draft.start, draft.duration);
            show();
        }),
    );

    form.querySelector('#start').addEventListener('input', (event) => {
        draft.start = event.target.value;
        if (draft.start) {
            draft.end = addMinutes(draft.start, draft.duration);
            form.querySelector('#end').value = draft.end;
        }
        refreshProblem();
    });

    form.querySelector('#end').addEventListener('input', (event) => {
        draft.end = event.target.value;
        refreshProblem();
    });

    box.querySelectorAll('[data-location-type]').forEach((button) =>
        button.addEventListener('click', () => {
            draft.locationType = button.dataset.locationType;
            box.querySelectorAll('[data-location-type]').forEach((b) => b.setAttribute('aria-checked', String(b === button)));
        }),
    );

    form.querySelector('#location').addEventListener('input', (event) => (draft.location = event.target.value));
    form.querySelector('#notes').addEventListener('input', (event) => (draft.notes = event.target.value));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        if (submit.disabled) return;
        submit.disabled = true;
        const { start, end } = period();

        try {
            const created = await store.action('scheduleAppointment', {
                clientId: draft.clientId,
                serviceSlug: draft.serviceSlug,
                serviceDetails: draft.serviceDetails,
                responsibleId: draft.responsibleId,
                start,
                end,
                locationType: draft.locationType,
                location: draft.location,
                notes: draft.notes,
            });
            toast('Atendimento agendado.');
            navigate(`/atendimentos/${created.id}`, { replace: true });
        } catch (error) {
            if (!(error instanceof ValidationError)) throw error;
            problem.textContent = error.message;
            problem.hidden = false;
            problem.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } finally {
            submit.disabled = false;
        }
    });
};
