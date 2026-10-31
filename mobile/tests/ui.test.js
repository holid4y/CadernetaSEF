import assert from 'node:assert/strict';
import { test } from 'node:test';

import { backButton } from '../js/ui.js';

test('back links keep route parameters inside the HTML attribute', () => {
    const html = backButton('/atendidos/"><img id="injected" src=x>');

    assert.ok(!html.includes('<img'));
    assert.ok(html.includes('data-back="/atendidos/&quot;&gt;&lt;img id=&quot;injected&quot; src=x&gt;"'));
});

test('back links preserve ordinary routes', () => {
    assert.ok(backButton('/atendidos/42').includes('data-back="/atendidos/42"'));
});
