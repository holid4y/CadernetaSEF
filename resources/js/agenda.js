import { Calendar } from '@fullcalendar/core';
import ptBrLocale from '@fullcalendar/core/locales/pt-br';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import timeGridPlugin from '@fullcalendar/timegrid';

// The calendar runs in "UTC" so the browser never shifts São Paulo times.
const wallTime = (date) => date.toISOString().slice(0, 19);

const layouts = [
    {
        query: window.matchMedia('(max-width: 639px)'),
        view: 'listWeek',
        header: { left: 'title', right: 'today prev,next' },
        footer: { center: 'listWeek,timeGridDay,dayGridMonth' },
    },
    {
        query: window.matchMedia('(max-width: 1023px)'),
        view: 'timeGridThreeDay',
        header: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridThreeDay,listWeek' },
        footer: false,
    },
    {
        query: window.matchMedia('all'),
        view: 'timeGridWeek',
        header: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        footer: false,
    },
];
const currentLayout = () => layouts.find((layout) => layout.query.matches);

function node(tag, className, text = '', children = []) {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;
    element.append(...children);

    return element;
}

function timeNode(timeText) {
    const [start, end] = timeText.split(' - ');

    return node('div', 'agenda-event__time', start, end ? [node('span', 'agenda-event__end', ` – ${end}`)] : []);
}

const renderers = {
    dayGrid: ({ event, timeText }) => [
        timeNode(timeText),
        node('div', 'agenda-event__title', event.title),
    ],
    timeGrid: ({ event, timeText }) => [
        timeNode(timeText),
        node('div', 'agenda-event__title', event.title),
        node('div', 'agenda-event__meta', event.extendedProps.service),
    ],
    list: ({ event }) => [
        node('div', 'agenda-event__row', '', [
            node('div', 'min-w-0', '', [
                node('div', 'agenda-event__title', event.title),
                node('div', 'agenda-event__meta', `${event.extendedProps.service} · ${event.extendedProps.responsible}`),
            ]),
            node('span', 'agenda-event__status', event.extendedProps.status),
        ]),
    ],
};

const viewFamily = (viewType) => viewType.replace(/(Month|Week|ThreeDay|Day)$/, '');

const renderEvent = (arg) => ({ domNodes: renderers[viewFamily(arg.view.type)](arg) });

export function mountAgenda(element, navigate) {
    const message = document.querySelector('[data-agenda-message]');
    const filters = {
        responsible: document.querySelector('[data-agenda-filter="responsible"]'),
        cancelled: document.querySelector('[data-agenda-filter="cancelled"]'),
    };
    const { eventsUrl, createUrl, rescheduleUrl, textSaving, textSaved, textFailed, timezone } = element.dataset;
    const canCreate = createUrl !== '';

    // "Now" as São Paulo wall time, in the same UTC-labelled form as the events.
    const now = () => new Date(`${new Date().toLocaleString('sv-SE', { timeZone: timezone }).replace(' ', 'T')}Z`);

    const announce = (text, tone) => {
        message.textContent = text;
        message.className = `rise-in rounded-soft px-4 py-3 text-sm ${tone === 'error' ? 'bg-danger-soft text-danger' : 'bg-moss-soft text-moss'}`;
    };

    async function reschedule(info) {
        announce(textSaving, 'info');

        const response = await fetch(rescheduleUrl.replace('__ID__', info.event.id), {
            method: 'PATCH',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                starts_at: wallTime(info.event.start),
                ends_at: wallTime(info.event.end),
            }),
        });

        if (response.ok) {
            announce(textSaved, 'success');

            return;
        }

        info.revert();

        const body = await response.json().catch(() => ({}));
        const firstError = Object.values(body.errors ?? {})[0]?.[0];

        announce(firstError ?? textFailed, 'error');
    }

    function openCreateForm(info) {
        // Month cells have no time of day.
        const start = info.allDay ? `${info.startStr}T09:00:00` : wallTime(info.start);
        const params = new URLSearchParams({ inicio: start });

        if (! info.allDay) {
            params.set('fim', wallTime(info.end));
        }

        navigate(`${createUrl}?${params}`);
    }

    const calendar = new Calendar(element, {
        plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
        locale: ptBrLocale,
        timeZone: 'UTC',
        now,
        firstDay: 1,
        initialView: currentLayout().view,
        headerToolbar: currentLayout().header,
        footerToolbar: currentLayout().footer,
        height: 'auto',
        eventTimeFormat: { hour: '2-digit', minute: '2-digit' },
        slotLabelFormat: { hour: '2-digit', minute: '2-digit' },
        listDayFormat: { weekday: 'long', day: 'numeric' },
        listDaySideFormat: false,
        slotEventOverlap: false,
        eventDisplay: 'block',
        eventMinHeight: 28,
        eventShortHeight: 72,
        allDaySlot: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '22:00:00',
        slotDuration: '00:30:00',
        snapDuration: '00:15:00',
        nowIndicator: true,
        views: {
            timeGridWeek: { hiddenDays: [0] },
            timeGridThreeDay: { type: 'timeGrid', duration: { days: 3 }, buttonText: '3 dias' },
            dayGridMonth: { dayMaxEvents: 3 },
        },
        noEventsContent: element.dataset.textEmpty,
        businessHours: { daysOfWeek: [1, 2, 3, 4, 5, 6], startTime: '08:00', endTime: '21:30' },
        selectable: canCreate,
        selectMirror: true,
        editable: false,
        eventDurationEditable: true,
        eventContent: renderEvent,
        eventDidMount: ({ event, el }) => {
            el.title = [event.title, event.extendedProps.service, event.extendedProps.responsible, event.extendedProps.status].join(' · ');
        },
        select: openCreateForm,
        eventClick: (info) => {
            info.jsEvent.preventDefault();

            if (info.event.url) {
                navigate(info.event.url);
            }
        },
        eventDrop: reschedule,
        eventResize: reschedule,
        events: {
            url: eventsUrl,
            extraParams: () => ({
                responsible: filters.responsible.value,
                cancelled: filters.cancelled.checked ? 1 : 0,
            }),
        },
    });

    calendar.render();

    let activeLayout = currentLayout();

    const onResize = () => {
        const layout = currentLayout();

        if (layout === activeLayout) {
            return;
        }

        activeLayout = layout;
        calendar.setOption('headerToolbar', layout.header);
        calendar.setOption('footerToolbar', layout.footer);
        calendar.changeView(layout.view);
    };
    const refetch = () => calendar.refetchEvents();

    window.addEventListener('resize', onResize);
    Object.values(filters).forEach((filter) => filter.addEventListener('change', refetch));

    return () => {
        window.removeEventListener('resize', onResize);
        calendar.destroy();
    };
}
