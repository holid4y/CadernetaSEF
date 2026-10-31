import assert from 'node:assert/strict';
import { test } from 'node:test';

import { googleCard, googleEventRow, googleEventsOnDay, googleLink } from '../js/google.js';

const day = Date.parse('2026-10-06T13:00:00Z');
const users = [
    { id: 1, name: 'Ana', role: 'team', active: true },
    { id: 2, name: 'Bruno', role: 'viewer', active: true },
];

test('only Google Calendar links over HTTPS are kept', () => {
    assert.equal(googleLink('https://www.google.com/calendar/event?eid=abc'), 'https://www.google.com/calendar/event?eid=abc');
    assert.equal(googleLink('https://calendar.google.com/calendar/r'), 'https://calendar.google.com/calendar/r');
    for (const bad of ['javascript:alert(1)', 'http://www.google.com/calendar', 'https://evil.example/calendar', 'https://user:pass@www.google.com/', 'https://www.google.com:8443/', null, 42]) {
        assert.equal(googleLink(bad), null, String(bad));
    }
});

test('events waiting in Google are listed by day and by whose calendar they came from', () => {
    const state = {
        googleEvents: [
            { id: 3, title: 'Depois', start: day + 3_600_000, end: day + 7_200_000, ownerId: 1 },
            { id: 2, title: 'Antes', start: day, end: day + 3_600_000, ownerId: 1 },
            { id: 4, title: 'Outro dia', start: day + 86_400_000 * 2, end: day + 86_400_000 * 2 + 3_600_000, ownerId: 1 },
            { id: 5, title: 'De outra pessoa', start: day, end: day + 3_600_000, ownerId: 9 },
        ],
    };

    assert.deepEqual(googleEventsOnDay(state, day, { responsibleId: 1 }).map((event) => event.title), ['Antes', 'Depois']);
    assert.equal(googleEventsOnDay({}, day).length, 0);
});

test('a title from Google is shown as text and only people who schedule can open it', () => {
    const event = { id: 7, title: '<img src=x onerror=alert(1)>', start: day, end: day + 3_600_000, ownerId: 1 };
    const state = { users, googleEvents: [event] };

    const forTeam = googleEventRow(state, event, users[0]);
    assert.ok(!forTeam.includes('<img'));
    assert.ok(forTeam.includes('&lt;img src=x onerror=alert(1)&gt;'));
    assert.ok(forTeam.includes('href="#/atendimentos/novo?google=7"'));

    assert.ok(!googleEventRow(state, event, users[1]).includes('href='));
});

test('the appointment card links to the copy or offers to connect', () => {
    const user = users[0];
    const appointment = { id: 1, status: 'scheduled', responsibleId: 1, google: { link: 'https://www.google.com/calendar/event?eid=abc' } };
    const connected = { users, google: { available: true, connected: true, needsReconnect: false } };

    assert.equal(googleCard({ users }, appointment, user), '');
    assert.ok(googleCard(connected, appointment, user).includes('href="https://www.google.com/calendar/event?eid=abc"'));
    assert.ok(!googleCard(connected, { ...appointment, google: { link: 'javascript:alert(1)' } }, user).includes('javascript:'));
    assert.ok(googleCard({ users, google: { available: true, connected: false } }, { ...appointment, google: {} }, user).includes('data-google-connect'));
    assert.ok(googleCard(connected, { ...appointment, responsibleId: 2, google: {} }, user).includes('de quem é responsável'));
    assert.ok(googleCard(connected, { ...appointment, google: { fromGoogle: true, removed: true } }, user).includes('foi apagado no Google'));
});
