const utc = (value) =>
    new Date(value)
        .toISOString()
        .replace(/[-:]/g, '')
        .replace(/\.\d{3}Z$/, 'Z');
const escapeText = (value) =>
    String(value ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/\r\n|\r|\n/g, '\\n')
        .replace(/,/g, '\\,')
        .replace(/;/g, '\\;');

export function googleCalendarUrl(event) {
    if (!Number.isFinite(event.start) || !Number.isFinite(event.end) || event.end <= event.start)
        throw new Error('Horário inválido para exportar.');
    const query = new URLSearchParams({
        action: 'TEMPLATE',
        text: event.title,
        dates: `${utc(event.start)}/${utc(event.end)}`,
        details: event.description ?? '',
        location: event.location ?? '',
        ctz: 'America/Sao_Paulo',
    });
    return `https://calendar.google.com/calendar/render?${query}`;
}

export function calendarFile(event) {
    googleCalendarUrl(event);
    const lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Agenda Contabil//PT-BR',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        `UID:${escapeText(event.uid)}`,
        `DTSTAMP:${utc(Date.now())}`,
        `DTSTART:${utc(event.start)}`,
        `DTEND:${utc(event.end)}`,
        `SUMMARY:${escapeText(event.title)}`,
        `DESCRIPTION:${escapeText(event.description)}`,
        `LOCATION:${escapeText(event.location)}`,
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return (
        lines
            .map((line) => {
                const parts = [];
                let current = '',
                    bytes = 0;
                for (const char of line) {
                    const length = new TextEncoder().encode(char).length;
                    if (bytes + length > 73) {
                        parts.push(current);
                        current = ' ';
                        bytes = 1;
                    }
                    current += char;
                    bytes += length;
                }
                return [...parts, current].join('\r\n');
            })
            .join('\r\n') + '\r\n'
    );
}

export function appointmentEvent(state, appointment) {
    const client = state.clients.find((item) => item.id === appointment.clientId);
    const service = state.services.find((item) => item.slug === appointment.serviceSlug);
    return {
        uid: `atendimento-${appointment.id}@agenda-contabil`,
        title: `${service.name} · ${client.name}`,
        start: appointment.start,
        end: appointment.end,
        location: appointment.location ?? '',
        description: 'Atendimento da Agenda Contábil. Confira os detalhes e documentos na plataforma.',
    };
}
