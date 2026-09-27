const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test('tenant administrator can use the consent governance workspace', async ({page}) => {
    test.setTimeout(60_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
    const workspaceBase = page.url().match(/^(.*\/w\/[^/]+)/)?.[1];
    expect(workspaceBase).toBeTruthy();

    await page.goto(`${workspaceBase}/NexaConsent`);
    await expect(page.getByRole('heading', {name: 'Consent & Privacy'})).toBeVisible();
    await expect(page.locator('[data-consent-state="ready"]')).toBeVisible();
    await expect(page.locator('.nexa-consent-summary article')).toHaveCount(5);
    await expect(page.locator('[data-purpose-list] .nexa-purpose-card')).toHaveCount(3);
    await expect(page.locator('[data-contact-search]')).toBeVisible();
    await expect(page.locator('[data-selected-contact]')).toBeHidden();
    await expect(page.locator('[data-decision-form]')).toBeHidden();

    await page.getByRole('button', {name: 'Add purpose'}).click();
    const dialog = page.getByRole('dialog', {name: 'Add communication purpose'});
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[name="name"]')).toBeFocused();
    await expect(dialog.locator('[name="channels"]')).toHaveCount(7);
    await dialog.getByRole('button', {name: 'Cancel'}).click();
    await expect(dialog).toBeHidden();

    await expect(page.locator('[data-consent-history] tr').first()).toBeVisible();
    await page.locator('[data-contact-search]').fill('Freya King');
    await expect(page.locator('[data-contact-results]')).toBeVisible({timeout: 10_000});
    await expect(page.locator('[data-contact-results]')).not.toContainText('Contacts could not be searched.');
    const freya = page.locator('[data-contact-results] [data-action="select-contact"]', {hasText: 'Freya King'}).first();
    await expect(freya).toBeVisible();
    await expect(freya.locator('[data-contact-avatar] img')).toBeVisible({timeout: 10_000});
    await freya.click();
    await expect(page.locator('[data-selected-contact] [data-contact-avatar] img')).toBeVisible({timeout: 10_000});
    const decisionForm = page.locator('[data-decision-form]');
    await decisionForm.locator('[name="purposeId"]').selectOption({label: 'Sales outreach'});
    await decisionForm.locator('[name="status"]').selectOption('granted');
    await decisionForm.locator('[name="source"]').selectOption('manual');
    await decisionForm.locator('[name="evidenceNote"]').fill('');
    await decisionForm.getByRole('button', {name: 'Record decision'}).click();
    await expect(decisionForm.locator('[data-field-error="evidenceNote"]')).toHaveText('This field is required.');

    await expect(page.locator('[data-history-search]')).toBeVisible();
    await expect(page.locator('[data-history-purpose]')).toBeVisible();
    await expect(page.locator('[data-history-channel]')).toBeVisible();
    await expect(page.locator('[data-history-status]')).toBeVisible();
    await page.locator('[data-history-search]').fill('Freya');
    await expect(page.locator('[data-history-count]')).toContainText(/decision/);
    await page.locator('[data-history-sort="contactName"]').click();
    await page.getByRole('button', {name: 'Cancel'}).first().click();
    const correct = page.locator('[data-action="correct-decision"]').first();
    if (await correct.count()) {
        await correct.click();
        await expect(page.getByRole('heading', {name: 'Correct consent decision'})).toBeVisible();
        await page.getByRole('button', {name: 'Cancel'}).first().click();
        await page.locator('[data-action="void-decision"]').first().click();
        await expect(page.getByRole('dialog', {name: 'Void consent decision?'})).toBeVisible();
        await page.getByRole('dialog', {name: 'Void consent decision?'}).getByRole('button', {name: 'Cancel'}).click();
    }

    await page.getByRole('button', {name: 'Website cookies'}).click();
    await expect(page.locator('[data-consent-view="cookies"]')).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Cookie banner'})).toBeVisible();
    await expect(page.locator('[data-cookie-categories] [data-cookie-category-row]')).toHaveCount(4);
    await expect(page.locator('[data-cookie-categories] .fa-lock')).toHaveCount(1);
    await expect(page.locator('[data-cookie-preview]')).toContainText('Your privacy choices');
    await expect(page.locator('[data-cookie-embed]')).toHaveValue(/data-nexa-cookie-key/);
    const cookieForm = page.locator('[data-cookie-form]');
    await cookieForm.locator('[name="integrationMode"]').selectOption('existing_banner');
    await expect(page.locator('[data-cookie-sync-example]')).toBeVisible();
    await expect(page.locator('[data-cookie-mode-help]')).toContainText('does not show another banner');
    await cookieForm.locator('[name="integrationMode"]').selectOption('managed');
    const originalMessage = await cookieForm.locator('[name="message"]').inputValue();
    await cookieForm.locator('[name="message"]').fill('');
    await cookieForm.getByRole('button', {name: 'Save cookie settings'}).click();
    await expect(cookieForm.locator('[data-cookie-error="message"]')).toHaveText('This field is required.');
    await cookieForm.locator('[name="message"]').fill(originalMessage);
    await expect(page.locator('[data-cookie-preview]')).toContainText(originalMessage);
    await page.getByRole('button', {name: 'Communication consent'}).click();
    await expect(page.locator('[data-consent-view="communications"]')).toBeVisible();
});
