import { test } from 'node:test';
import assert from 'node:assert/strict';
import { phoneNumber, emailLink, emailAddress, whatsappLink } from '../js/contacts.js';

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
    assert.ok(link.startsWith('mailto:ana@example.com?'));
    assert.equal(new URLSearchParams(link.split('?')[1]).get('body'), 'Linha 1\nLinha 2');
});
