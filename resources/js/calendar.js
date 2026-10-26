import { calendarFile } from '../../mobile/js/calendar.js';

export function mountCalendarTools() {
    document.querySelectorAll('[data-calendar-event]').forEach((root) => {
        if (root.dataset.ready) return;
        root.dataset.ready = 'true';

        root.querySelector('[data-calendar-download]').addEventListener('click', () => {
            const event = JSON.parse(root.dataset.calendarEvent);
            const url = URL.createObjectURL(new Blob([calendarFile(event)], { type: 'text/calendar;charset=utf-8' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = 'atendimento.ics';
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
    });
}
