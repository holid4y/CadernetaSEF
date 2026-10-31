import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formAction, formUrl, formsCard } from '../js/forms.js';

const hosts = ['forms.example.test'];
const settings = { mode: 'server', formsHosts: hosts };
const appointment = {
    integrationId: 'b30acc5c-b024-4eb0-955a-8e76f9c3a610',
    formsEnabled: true,
    status: 'completed',
    formCreateUrl: 'https://forms.example.test/new?appointment_id=b30acc5c-b024-4eb0-955a-8e76f9c3a610',
    forms: [{ title: 'Ficha do atendimento', status: 'completed', url: 'https://forms.example.test/one' }],
};

test('form links require HTTPS, an exact configured hostname and no URL credentials', () => {
    assert.equal(formUrl('https://forms.example.test/one', hosts), 'https://forms.example.test/one');
    for (const value of ['http://forms.example.test/one', 'https://forms.example.test.evil.test/one', 'https://evil.test/one', 'https://user:password@forms.example.test/one', 'https://forms.example.test:444/one', 'javascript:alert(1)', '//forms.example.test/one', 'https://forms.example.test\\@evil.test/one', undefined, 'https://forms.example.test/one\n']) {
        assert.equal(formUrl(value, hosts), null, value);
    }
    assert.equal(formUrl('https://forms.example.test/one', []), null);
});

test('completed appointments show linked forms and their stable reference', () => {
    const html = formsCard(appointment, true, settings);
    assert.match(html, /Ver formulário feito/);
    assert.match(html, /Concluído/);
    assert.match(html, new RegExp(appointment.integrationId));
    assert.match(html, /Preencher formulário/);
    assert.match(html, /rel="noopener noreferrer"/);
    assert.doesNotMatch(formsCard({ ...appointment, forms: [] }, false, settings), /Preencher formulário/);
});

test('unconfigured integration and demonstration builds never generate form links', () => {
    for (const html of [formsCard(appointment, true, { mode: 'demo', formsHosts: hosts }), formsCard({}, true, settings)]) {
        assert.match(html, /ainda não foi configurada/);
        assert.doesNotMatch(html, /href=/);
    }
});

test('deleted forms, unknown statuses and untrusted URLs do not create links', () => {
    for (const form of [{ status: 'deleted', url: appointment.forms[0].url }, { status: '<script>', url: appointment.forms[0].url }, { status: 'pending', url: 'https://evil.test/a' }]) {
        const html = formsCard({ ...appointment, formCreateUrl: null, forms: [{ title: '<script>alert(1)</script>', ...form }] }, true, settings);
        assert.doesNotMatch(html, /href=|<script>/);
        assert.match(html, /&lt;script&gt;/);
    }
});

test('each appointment gets one form button, for the form done or still to do', () => {
    assert.deepEqual(formAction(appointment, true, settings), { label: 'Ver formulário feito', url: 'https://forms.example.test/one' });
    const empty = { ...appointment, forms: [] };
    assert.equal(formAction(empty, true, settings).label, 'Preencher formulário');
    assert.equal(formAction(empty, false, settings), null);
    assert.equal(formAction(appointment, true, { mode: 'demo', formsHosts: hosts }), null);
});
