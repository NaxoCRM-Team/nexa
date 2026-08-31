const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_EMAIL || process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

async function login(page) {
    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
}

test('live tenant activity workspace and authoritative native modules load', async ({page}, testInfo) => {
    test.setTimeout(90_000);
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');
    const clientErrors = [];
    page.on('pageerror', error => clientErrors.push(error.message));
    await login(page);

    for (const section of ['agenda', 'projects']) {
        await page.goto(`${baseUrl}/w/isolation-alpha/NexaActivity/${section}`);
        await expect(page.locator('.nexa-activity-workspace')).toBeVisible({timeout: 30_000});
        await expect(page.locator('[data-activity-state="ready"]')).toBeVisible({timeout: 30_000});
        await expect(page.locator('[data-activity-state="error"]')).toBeHidden();
        await expect(page.locator('.nexa-activity-metrics article')).toHaveCount(4);
    }

    const temporaryProjectName = `Playwright collaboration ${testInfo.project.name} ${Date.now()}`;
    const projectResult = await page.evaluate(async projectName => {
        const created = await Espo.Ajax.postRequest('Nexa/projects', {
            name: projectName,
            status: 'Active',
            priority: 'Normal',
            description: 'Temporary live verification project.',
        });
        try {
            const tasks = await Espo.Ajax.getRequest('Task', {maxSize: 1, select: 'id,name'});
            const task = tasks?.list?.[0];
            if (task) {
                await Espo.Ajax.postRequest(`Nexa/projects/${created.id}/links`, {type: 'Task', recordId: task.id});
            }
            await Espo.Ajax.postRequest(`Nexa/projects/${created.id}/updates`, {body: 'Live collaboration verification.'});
            const detail = await Espo.Ajax.getRequest(`Nexa/projects/${created.id}`);
            if (task) {
                await Espo.Ajax.deleteRequest(`Nexa/projects/${created.id}/links/Task/${task.id}`);
            }
            return {name: detail.name, taskCount: detail.tasks.length, updateCount: detail.updates.length, hadTask: Boolean(task)};
        } finally {
            await Espo.Ajax.deleteRequest(`Nexa/projects/${created.id}`);
        }
    }, temporaryProjectName);
    expect(projectResult.name).toContain('Playwright collaboration');
    expect(projectResult.updateCount).toBe(1);
    if (projectResult.hadTask) expect(projectResult.taskCount).toBe(1);

    await page.goto(`${baseUrl}/w/isolation-alpha/Calendar`);
    await expect(page.locator('.nexa-native-calendar-page .calendar-container')).toBeVisible({timeout: 30_000});
    await page.screenshot({path: testInfo.outputPath('native-calendar.png'), animations: 'disabled', fullPage: true});
    await page.goto(`${baseUrl}/w/isolation-alpha/Document`);
    await expect(page.locator('.nexa-document-list-page')).toBeVisible({timeout: 30_000});
    await expect(page.locator('.nexa-document-list-page .list-container')).toBeVisible({timeout: 30_000});
    await page.screenshot({path: testInfo.outputPath('native-documents.png'), animations: 'disabled', fullPage: true});

    await page.goto(`${baseUrl}/w/isolation-alpha/NexaActivity/calendar`);
    await expect(page).toHaveURL(/\/w\/isolation-alpha\/Calendar/);
    expect(clientErrors).toEqual([]);
});
