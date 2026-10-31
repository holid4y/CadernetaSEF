import { PROJECT } from '../data.js';
import { appointmentsInRange, appointmentsOnDay, can, pendingDocuments } from '../domain.js';
import { GOOGLE_MARK } from '../google.js';
import { openProfile } from '../profile.js';
import { appointmentRow, avatar, emptyState, icon, logo } from '../ui.js';
import { addDays, capitalize, dateLong, esc, firstName, relativeDay, startOfDay } from '../util.js';

export default function home({ state, user }) {
    const now = Date.now();
    const today = startOfDay(now);
    const todays = appointmentsOnDay(state, now);
    const open = (a) => ['scheduled', 'confirmed', 'in_progress'].includes(a.status);
    const week = appointmentsInRange(state, addDays(today, 1), addDays(today, 8)).filter(open);
    const stats = {
        today: todays.filter(open).length,
        week: week.length,
        confirmed: [...todays, ...week].filter((a) => a.status === 'confirmed').length,
        docs: [...todays, ...week].filter(open).reduce((sum, a) => sum + pendingDocuments(a), 0),
    };

    const upcoming = week.slice(0, 5);
    const grouped = upcoming.reduce((acc, a) => {
        const key = relativeDay(a.start, now);
        (acc[key] ??= []).push(a);
        return acc;
    }, {});

    const canCreate = can(user, 'appointments.write');
    const waiting = canCreate ? (state.googleEvents ?? []).filter((event) => event.end > now).length : 0;

    return {
        html: `<header class="appbar">
                <span class="brand">${logo()}<span class="brand-text"><span class="brand-kicker">${PROJECT.kicker}</span><span class="brand-name">${PROJECT.name}</span></span></span>
                <button class="icon-btn" type="button" id="profile" aria-label="Perfil e configurações" style="width:auto">${avatar(user.name)}</button>
            </header>

            <section class="hero rise">
                <h1>Olá, ${esc(firstName(user.name))}</h1>
                <p>Hoje é ${esc(dateLong(now))}.</p>
            </section>

            <section class="stats rise" aria-label="Resumo">
                <a class="stat tone-primary" href="#/agenda"><b>${stats.today}</b><span>${stats.today === 1 ? 'atendimento hoje' : 'atendimentos hoje'}</span></a>
                <a class="stat tone-ochre" href="#/agenda"><b>${stats.week}</b><span>nos próximos 7 dias</span></a>
                <a class="stat tone-moss" href="#/agenda"><b>${stats.confirmed}</b><span>${stats.confirmed === 1 ? 'presença confirmada' : 'presenças confirmadas'}</span></a>
                <a class="stat tone-clay" href="#/agenda"><b>${stats.docs}</b><span>${stats.docs === 1 ? 'documento pendente' : 'documentos pendentes'}</span></a>
            </section>

            ${canCreate ? `<a class="btn btn-primary btn-block rise" style="margin-top:14px" href="#/atendimentos/novo">${icon('plus')}Novo atendimento</a>` : ''}
            ${waiting ? `<a class="notice-google rise" href="#/agenda">${GOOGLE_MARK}<span>${waiting === 1 ? '1 evento do Google espera' : `${waiting} eventos do Google esperam`} atendido e serviço. Abra a agenda para completar.</span></a>` : ''}

            <div class="section-title"><h2>Atendimentos de hoje</h2></div>
            ${
                todays.length
                    ? `<div class="list">${todays.map((a) => appointmentRow(state, a)).join('')}</div>`
                    : emptyState({ title: 'Nenhum atendimento para hoje', text: 'Aproveite para organizar a agenda dos próximos dias.' })
            }

            <div class="section-title"><h2>Próximos 7 dias</h2><a class="link small" href="#/agenda">Abrir agenda</a></div>
            ${
                upcoming.length
                    ? Object.entries(grouped)
                          .map(([label, items]) => `<div class="day-heading">${esc(capitalize(label))}</div><div class="list">${items.map((a) => appointmentRow(state, a)).join('')}</div>`)
                          .join('')
                    : emptyState({ title: 'Nada agendado para os próximos dias' })
            }`,

        mount(root) {
            root.querySelector('#profile').addEventListener('click', openProfile);
        },
    };
}
