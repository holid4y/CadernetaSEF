import { test } from 'node:test';
import assert from 'node:assert/strict';
import { calendarFile, googleCalendarUrl } from '../js/calendar.js';
import { phoneNumber, emailLink, emailAddress, whatsappLink } from '../js/contacts.js';

const now = Date.parse('2026-10-22T12:00:00Z');

test('validates Brazilian and explicit international numbers without inventing a country code', () => {
    assert.equal(phoneNumber('(51) 99125-1538'), '5551991251538');
    assert.equal(phoneNumber('+55 (51) 99125-1538'), '5551991251538');
    assert.equal(phoneNumber('(51) 3595-2211'), '555135952211');
    assert.equal(phoneNumber('+1 415 555 2671'), '14155552671');
    for (const invalid of [
        '',
        '1234',
        '00000000000',
        '(20) 99999-9999',
        '(51) 12345-6789',
        '+55 912345678',
        'abc51991251538',
        '+55+51991251538',
    ]) {
        assert.equal(phoneNumber(invalid), null, invalid);
        assert.equal(whatsappLink(invalid), null);
    }
});

test('email links encode message content and reject malformed addresses and header injection', () => {
    assert.equal(emailAddress(' equipe+teste@example.com '), 'equipe+teste@example.com');
    for (const invalid of [
        '',
        'sem-arroba',
        'a@-example.com',
        'a..b@example.com',
        'a@example.com?bcc=outro@example.com',
        'a@example.com\r\nBcc:evil@example.com',
    ])
        assert.equal(emailLink(invalid), null);
    const link = emailLink('ana@example.com', 'Olá & teste', 'Linha 1\nLinha 2');
    assert.ok(link.startsWith('mailto:ana%40example.com?'));
    assert.equal(new URLSearchParams(link.split('?')[1]).get('body'), 'Linha 1\nLinha 2');
});

test('exports UTC timestamps, escaped text and folded lines', () => {
    const input = {
        uid: 'agenda-test',
        title: 'João, organização; teste',
        description: 'Primeira linha\n' + 'ç'.repeat(100),
        location: 'Sala 205',
        start: now + 86400000,
        end: now + 90000000,
    };
    const text = calendarFile(input);
    assert.ok(text.split('\r\n').every((line) => new TextEncoder().encode(line).length <= 75));
    const unfolded = text.replace(/\r\n /g, '');
    assert.match(unfolded, /\r\nDTSTART:20261023T120000Z\r\n/);
    assert.match(unfolded, /\r\nDTEND:20261023T130000Z\r\n/);
    assert.ok(unfolded.includes('SUMMARY:João\\, organização\\; teste\r\n'));
    assert.ok(unfolded.includes(`DESCRIPTION:Primeira linha\\n${'ç'.repeat(100)}\r\n`));
    const google = new URL(googleCalendarUrl(input));
    assert.equal(google.hostname, 'calendar.google.com');
    assert.equal(google.searchParams.get('text'), input.title);
    assert.equal(google.searchParams.get('dates'), '20261023T120000Z/20261023T130000Z');
});
