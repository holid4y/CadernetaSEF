import { STATUS } from '../data.js';
import { appointmentsOnDay, can, clientOf, responsibles, serviceOf } from '../domain.js';
import { googleEventRow, googleEventsOnDay } from '../google.js';
import { navigate, refresh } from '../router.js';
import { appointmentRow, emptyState, icon, openSheet } from '../ui.js';
import {
    addDays,
    addMonths,
    capitalize,
    dateLong,
    dayKey,
    dayMonth,
    esc,
    fromDayKey,
    isSameDay,
    monthShort,
    monthYear,
    startOfDay,
    startOfMonth,
    startOfWeek,
    timeStr,
    weekdayShort,
} from '../util.js';
import { openProfile } from '../profile.js';
import { avatar } from '../ui.js';

// Kept between visits so coming back from an appointment lands on the same day.
const view = { mode: 'week', selected: startOfDay(Date.now()), responsibleId: null, showClosed: false };

// Moving a month lands on today when it is the current month.
const MOVES = {
    week: (selected, step) => addDays(selected, step * 7),
    day: (selected, step) => addDays(selected, step),
    month: (selected, step) => {
        const month = addMonths(selected, step);
        return isSameDay(startOfMonth(month), startOfMonth(Date.now())) ? startOfDay(Date.now()) : month;
    },
};

const FIRST_HOUR = 7;
const LAST_HOUR = 21;
const HOUR_PX = 64;

const filtersActive = () => view.responsibleId !== null || view.showClosed;

const rangeLabel = (from, to) => {
    const a = new Date(from);
    const b = new Date(to);
    const sameMonth = a.getMonth() === b.getMonth();
    return `${a.getDate()}${sameMonth ? '' : ` ${monthShort(from)}`} – ${b.getDate()} ${monthShort(to)} ${b.getFullYear()}`;
};

const dotsFor = (state, ms) => {
    const open = appointmentsOnDay(state, ms, { responsibleId: view.responsibleId, showClosed: view.showClosed }).filter((a) => !['cancelled', 'no_show'].includes(a.status) || view.showClosed);
    const waiting = googleEventsOnDay(state, ms, { responsibleId: view.responsibleId });
    return `<span class="dots">${[...open.map(() => '<i></i>'), ...waiting.map(() => '<i class="is-google"></i>')].slice(0, 3).join('')}</span>`;
};

const dayButton = (state, ms, { out = false } = {}) => {
    const today = isSameDay(ms, Date.now());
    const selected = isSameDay(ms, view.selected);
    return `<button type="button" class="day-btn ${today ? 'is-today' : ''} ${out ? 'is-out' : ''}" data-day="${dayKey(ms)}" aria-pressed="${selected}"
        aria-label="${esc(dateLong(ms))}${today ? ', hoje' : ''}">
        <span class="dow">${weekdayShort(ms)}</span><span class="num">${new Date(ms).getDate()}</span>${dotsFor(state, ms)}
    </button>`;
};

const weekView = (state) => {
    const monday = startOfWeek(view.selected);
    const days = Array.from({ length: 7 }, (_, i) => addDays(monday, i));
    return `<div class="weekstrip" role="group" aria-label="Dias da semana">${days.map((d) => dayButton(state, d)).join('')}</div>`;
};

const monthView = (state) => {
    const first = startOfMonth(view.selected);
    const gridStart = startOfWeek(first);
    const weeks = Math.ceil(((new Date(first).getDay() + 6) % 7 + new Date(new Date(first).getFullYear(), new Date(first).getMonth() + 1, 0).getDate()) / 7);
    const days = Array.from({ length: weeks * 7 }, (_, i) => addDays(gridStart, i));
    const headers = days.slice(0, 7).map((d) => `<span class="dow">${weekdayShort(d)}</span>`).join('');
    return `<div class="month" role="group" aria-label="${esc(monthYear(first))}">${headers}${days
        .map((d) => dayButton(state, d, { out: new Date(d).getMonth() !== new Date(first).getMonth() }).replace(/<span class="dow">[^<]*<\/span>/, ''))
        .join('')}</div>`;
};

// Side-by-side columns for appointments that overlap in time.
const layoutColumns = (items) => {
    const placed = [];
    let cluster = [];
    let clusterEnd = 0;

    const flush = () => {
        const columns = [];
        cluster.forEach((item) => {
            let col = columns.findIndex((end) => end <= item.a.start);
            if (col === -1) col = columns.length;
            columns[col] = item.a.end;
            item.col = col;
        });
        cluster.forEach((item) => placed.push({ ...item, cols: columns.length }));
        cluster = [];
    };

    items.forEach((a) => {
        if (cluster.length && a.start >= clusterEnd) flush();
        cluster.push({ a });
        clusterEnd = Math.max(clusterEnd, a.end);
    });
    if (cluster.length) flush();
    return placed;
};

const minutesOfDay = (ms) => new Date(ms).getHours() * 60 + new Date(ms).getMinutes();

const dayTimeline = (state, user) => {
    const items = appointmentsOnDay(state, view.selected, { responsibleId: view.responsibleId, showClosed: view.showClosed });
    const canCreate = can(user, 'appointments.write');
    const hours = Array.from({ length: LAST_HOUR - FIRST_HOUR }, (_, i) => FIRST_HOUR + i);
    const px = HOUR_PX / 60;

    const rows = hours
        .map((h) => {
            const label = `${String(h).padStart(2, '0')}:00`;
            const slot = (time) => (canCreate ? `<button type="button" class="hour-free" data-slot="${dayKey(view.selected)}T${time}" aria-label="Agendar às ${time}" style="${time.endsWith('30') ? 'top:50%;height:50%' : 'height:50%'}"></button>` : '');
            return `<div class="hour"><span>${label}</span>${slot(label)}${slot(`${String(h).padStart(2, '0')}:30`)}<i class="hour-half"></i></div>`;
        })
        .join('');

    const waiting = googleEventsOnDay(state, view.selected, { responsibleId: view.responsibleId }).map((event) => ({ ...event, fromGoogle: true }));
    const events = layoutColumns([...items, ...waiting].sort((a, b) => a.start - b.start))
        .map(({ a, col, cols }) => {
            const top = Math.max(0, (minutesOfDay(a.start) - FIRST_HOUR * 60) * px);
            const height = Math.max(34, (Math.min(minutesOfDay(a.end), LAST_HOUR * 60) - Math.max(minutesOfDay(a.start), FIRST_HOUR * 60)) * px - 2);
            const width = 100 / cols;
            const position = `top:${top}px;height:${height}px;left:calc(${col * width}% + 2px);width:calc(${width}% - 4px)`;
            if (a.fromGoogle) {
                return `<a class="event is-google" ${canCreate ? `href="#/atendimentos/novo?google=${encodeURIComponent(a.id)}"` : ''} style="${position}"
                    aria-label="Evento do Google: ${esc(a.title)}, ${timeStr(a.start)} às ${timeStr(a.end)}, a completar">
                    <b>${esc(a.title)}</b>
                    <span>${timeStr(a.start)} · a completar</span>
                </a>`;
            }
            return `<a class="event tone-${STATUS[a.status].tone}" href="#/atendimentos/${a.id}" style="${position}"
                aria-label="${esc(clientOf(state, a.clientId).name)}, ${timeStr(a.start)} às ${timeStr(a.end)}, ${STATUS[a.status].label}">
                <b>${esc(clientOf(state, a.clientId).name)}</b>
                <span>${timeStr(a.start)} · ${esc(serviceOf(state, a.serviceSlug).name)}</span>
            </a>`;
        })
        .join('');

    const now = Date.now();
    const nowLine = isSameDay(view.selected, now) && minutesOfDay(now) >= FIRST_HOUR * 60 && minutesOfDay(now) < LAST_HOUR * 60
        ? `<div class="now-line" style="top:${(minutesOfDay(now) - FIRST_HOUR * 60) * px}px"></div>`
        : '';

    return `<div class="timeline-day" aria-label="Horários do dia">${rows}<div class="hour" style="height:0"></div>
        <div style="position:absolute;inset:0;pointer-events:none">${events}${nowLine}</div></div>
        ${canCreate ? '<p class="hint-text" style="margin-top:12px">Toque em um horário livre para agendar.</p>' : ''}`;
};

const dayList = (state, user) => {
    const items = appointmentsOnDay(state, view.selected, { responsibleId: view.responsibleId, showClosed: view.showClosed });
    const waiting = googleEventsOnDay(state, view.selected, { responsibleId: view.responsibleId });
    return `<div class="day-heading"><span>${esc(capitalize(dateLong(view.selected)))}</span><span>${items.length} ${items.length === 1 ? 'atendimento' : 'atendimentos'}</span></div>
        ${
            items.length
                ? `<div class="list">${items.map((a) => appointmentRow(state, a)).join('')}</div>`
                : waiting.length ? '' : emptyState({ title: 'Dia livre', text: 'Nenhum atendimento neste dia.' })
        }
        ${waiting.length ? `<div class="day-heading"><span>Do Google, para completar</span><span>${waiting.length}</span></div><div class="list">${waiting.map((event) => googleEventRow(state, event, user)).join('')}</div>` : ''}`;
};

export default function agenda({ state, user }) {
    const monday = startOfWeek(view.selected);
    const label = {
        week: rangeLabel(monday, addDays(monday, 6)),
        day: `${capitalize(weekdayShort(view.selected))}, ${dayMonth(view.selected)}`,
        month: monthYear(view.selected),
    }[view.mode];

    const body = {
        week: () => `${weekView(state)}${dayList(state, user)}`,
        day: () => dayTimeline(state, user),
        month: () => `${monthView(state)}${dayList(state, user)}`,
    }[view.mode]();

    const canCreate = can(user, 'appointments.write');

    return {
        html: `<header class="appbar">
                <h1>Agenda</h1>
                <button class="icon-btn is-boxed" type="button" id="filters" aria-label="Filtros${filtersActive() ? ' (ativos)' : ''}" ${filtersActive() ? 'style="border-color:var(--primary);color:var(--primary)"' : ''}>${icon('sliders')}</button>
                <button class="icon-btn" type="button" id="profile" aria-label="Perfil e configurações" style="width:auto">${avatar(user.name)}</button>
            </header>

            <div class="seg" role="group" aria-label="Visão da agenda">
                ${[['week', 'Semana'], ['day', 'Dia'], ['month', 'Mês']].map(([mode, text]) => `<button type="button" data-mode="${mode}" aria-pressed="${view.mode === mode}">${text}</button>`).join('')}
            </div>

            <div class="cal-head">
                <button class="icon-btn is-boxed" type="button" data-step="-1" aria-label="Anterior">${icon('chev-l')}</button>
                <h2>${esc(label)}</h2>
                <button class="icon-btn is-boxed" type="button" data-step="1" aria-label="Próximo">${icon('chev-r')}</button>
            </div>
            <div style="display:flex;justify-content:center;margin-bottom:12px"><button class="chip" type="button" id="today">Ir para hoje</button></div>

            ${body}
            ${canCreate && view.mode !== 'day' ? `<a class="btn btn-secondary btn-block" style="margin-top:16px" href="#/atendimentos/novo?inicio=${dayKey(view.selected)}">${icon('plus')}Agendar neste dia</a>` : ''}`,

        mount(root) {
            const go = () => refresh();

            root.querySelector('#profile').addEventListener('click', openProfile);
            root.querySelector('#today').addEventListener('click', () => {
                view.selected = startOfDay(Date.now());
                go();
            });

            root.querySelectorAll('[data-mode]').forEach((button) =>
                button.addEventListener('click', () => {
                    view.mode = button.dataset.mode;
                    go();
                }),
            );

            root.querySelectorAll('[data-step]').forEach((button) =>
                button.addEventListener('click', () => {
                    view.selected = MOVES[view.mode](view.selected, Number(button.dataset.step));
                    go();
                }),
            );

            root.querySelectorAll('[data-day]').forEach((button) =>
                button.addEventListener('click', () => {
                    view.selected = fromDayKey(button.dataset.day);
                    go();
                }),
            );

            root.querySelectorAll('[data-slot]').forEach((button) =>
                button.addEventListener('click', () => {
                    const [day, time] = button.dataset.slot.split('T');
                    navigate(`/atendimentos/novo?inicio=${day}T${time}`);
                }),
            );

            root.querySelector('#filters').addEventListener('click', () => openFilters(state));
        },
    };
}

function openFilters(state) {
    openSheet({
        title: 'Filtros da agenda',
        html: `<form class="form" id="filter-form">
                <div class="field">
                    <label for="who">Responsável</label>
                    <select class="select" id="who" name="who">
                        <option value="">Toda a equipe</option>
                        ${responsibles(state).map((u) => `<option value="${u.id}" ${view.responsibleId === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}
                    </select>
                </div>
                <label class="toggle-row"><input type="checkbox" name="closed" ${view.showClosed ? 'checked' : ''}><span>Mostrar cancelados e faltas<small>Por padrão eles ficam escondidos da agenda.</small></span></label>
                <div class="btn-row" style="margin-top:0">
                    <button class="btn btn-secondary" type="button" id="clear" style="flex:1">Limpar</button>
                    <button class="btn btn-primary" type="submit" style="flex:1">Aplicar</button>
                </div>
            </form>`,
        mount(sheet, close) {
            const form = sheet.querySelector('#filter-form');
            const apply = (responsibleId, showClosed) => {
                view.responsibleId = responsibleId;
                view.showClosed = showClosed;
                close();
                refresh();
            };
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                apply(form.who.value ? Number(form.who.value) : null, form.closed.checked);
            });
            sheet.querySelector('#clear').addEventListener('click', () => apply(null, false));
        },
    });
}
