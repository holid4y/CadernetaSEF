import { test, expect } from '@playwright/test';

test('a booking link can not inject markup through the start time', async ({ page }) => {
    const dialogs = [];
    page.on('dialog', (dialog) => {
        dialogs.push(dialog.message());
        dialog.dismiss();
    });
    await page.goto('/');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('#profile')).toBeVisible();

    await page.evaluate(() => {
        location.hash = '/atendimentos/novo?atendido=1&inicio=2026-12-01T"><img id="injected" src=x onerror=alert(1)>';
    });
    await page.locator('[data-category="fiscal"]').click();
    await page.locator('[data-service="irpf"]').click();
    await page.locator('#next').click();

    await expect(page.locator('#start')).toBeVisible();
    await expect(page.locator('#injected')).toHaveCount(0);
    expect(dialogs).toEqual([]);
});

test('a booking link keeps a valid start time', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.locator('#profile')).toBeVisible();

    await page.evaluate(() => {
        location.hash = '/atendimentos/novo?atendido=1&inicio=2026-12-01T14:30';
    });
    await page.locator('[data-category="fiscal"]').click();
    await page.locator('[data-service="irpf"]').click();
    await page.locator('#next').click();

    await expect(page.locator('#start')).toHaveValue('14:30');
});
