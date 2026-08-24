const {test, expect} = require('@playwright/test');

const baseUrl = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const userName = process.env.NEXA_LIVE_USERNAME || '';
const password = process.env.NEXA_LIVE_PASSWORD || '';

test.setTimeout(120_000);

async function expectAlignedCommentToolbar(form) {
    const host = form.locator('.nexa-comment-editor');
    const toolbar = host.locator('.note-toolbar');
    await expect(toolbar).toBeVisible({timeout: 15_000});
    const layout = await host.evaluate(element => {
        const toolbarElement = element.querySelector('.note-toolbar');
        const toolbarRect = toolbarElement.getBoundingClientRect();
        const buttons = [...toolbarElement.querySelectorAll('button')].filter(button =>
            button.getClientRects().length && getComputedStyle(button).visibility !== 'hidden'
        );
        return {
            hostDisplay: getComputedStyle(element).display,
            toolbarDisplay: getComputedStyle(toolbarElement).display,
            toolbarWidth: toolbarRect.width,
            hostWidth: element.getBoundingClientRect().width,
            buttonsInside: buttons.every(button => {
                const rect = button.getBoundingClientRect();
                return rect.left >= toolbarRect.left - 2 && rect.right <= toolbarRect.right + 2;
            }),
        };
    });
    expect(layout.hostDisplay).toBe('block');
    expect(layout.toolbarDisplay).toBe('flex');
    expect(layout.toolbarWidth).toBeLessThanOrEqual(layout.hostWidth + 1);
    expect(layout.buttonsInside).toBe(true);
}

test('Contact SMS exposes the tenant image library inside the rich editor', async ({page}, testInfo) => {
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form').evaluate(form => form.noValidate = true);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});

    const tenantKey = new URL(page.url()).pathname.match(/\/w\/([^/]+)/)?.[1];
    const contacts = await page.evaluate(() => Espo.Ajax.getRequest('Contact', {
        select: 'id,name', maxSize: 1, orderBy: 'createdAt', order: 'desc',
    }));
    test.skip(!contacts?.list?.length, 'The tenant has no Contact fixture.');

    await page.goto(`${baseUrl}/w/${tenantKey}/Contact/view/${contacts.list[0].id}`);
    await expect(page.locator('[data-nexa-contact-workspace]')).toBeVisible({timeout: 30_000});
    await page.getByRole('button', {name: 'More customer actions'}).click();
    await page.getByRole('button', {name: 'Log SMS'}).click();

    const dialog = page.locator('[data-nexa-interaction-dialog]');
    await expect(dialog).toBeVisible();
    const imageButton = dialog.getByRole('button', {name: 'Insert image'});
    await expect(imageButton).toBeVisible({timeout: 15_000});
    await imageButton.click();

    const library = page.locator('.nexa-tenant-image-dialog');
    await expect(library.getByRole('heading', {name: 'Insert image'})).toBeVisible();
    await expect(library.getByRole('searchbox', {name: 'Search tenant images'})).toBeVisible();
    await expect(library.getByText('Upload image')).toBeVisible();
    await expect(library.locator('[data-nexa-image-grid]')).toBeVisible();
    await expect.poll(() => library.evaluate(element => {
        const rect = element.getBoundingClientRect();
        const x = Math.min(window.innerWidth - 1, Math.max(0, rect.left + rect.width / 2));
        const y = Math.min(window.innerHeight - 1, Math.max(0, rect.top + Math.min(rect.height, window.innerHeight) / 2));
        return element.contains(document.elementFromPoint(x, y));
    })).toBe(true);
    await page.screenshot({path: testInfo.outputPath(`tenant-rich-editor-${testInfo.project.name}.png`)});

    const fileName = `nexa-editor-check-${Date.now()}.png`;
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await library.locator('[data-nexa-image-upload]').setInputFiles({name: fileName, mimeType: 'image/png', buffer: png});
    await expect(library).toHaveCount(0);
    const inserted = dialog.locator('.note-editable img[data-nexa-attachment-id]').last();
    await expect(inserted).toBeVisible({timeout: 20_000});
    await expect.poll(() => inserted.evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
    const attachmentId = await inserted.getAttribute('data-nexa-attachment-id');
    expect(attachmentId).toBeTruthy();

    const resizeHandle = dialog.locator('.nexa-image-resize-handle');
    await expect(resizeHandle).toBeVisible();
    const initialWidth = await inserted.evaluate(image => image.getBoundingClientRect().width);
    await resizeHandle.press('ArrowRight');
    await expect.poll(() => inserted.evaluate(image => image.getBoundingClientRect().width)).toBeGreaterThan(initialWidth);
    const keyboardWidth = await inserted.evaluate(image => image.getBoundingClientRect().width);
    const handleBox = await resizeHandle.boundingBox();
    expect(handleBox).toBeTruthy();
    await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(handleBox.x + handleBox.width / 2 + 32, handleBox.y + handleBox.height / 2, {steps: 4});
    await page.mouse.up();
    await expect.poll(() => inserted.evaluate(image => image.getBoundingClientRect().width)).toBeGreaterThan(keyboardWidth);

    const documentName = `nexa-editor-file-${Date.now()}.txt`;
    await dialog.getByRole('button', {name: 'Attach file'}).click();
    const fileLibrary = page.locator('.nexa-tenant-file-dialog');
    await expect(fileLibrary.getByRole('heading', {name: 'Attach file'})).toBeVisible();
    await expect(fileLibrary.getByText('Images up to 8 MB; documents up to 25 MB')).toBeVisible();
    await fileLibrary.locator('[data-nexa-file-upload]').setInputFiles({
        name: documentName,
        mimeType: 'text/plain',
        buffer: Buffer.from('Nexa tenant attachment check'),
    });
    await expect(fileLibrary).toHaveCount(0);
    const insertedFile = dialog.locator(`.note-editable a[data-nexa-file-name="${documentName}"]`);
    await expect(insertedFile).toBeVisible({timeout: 20_000});
    const fileAttachmentId = await insertedFile.getAttribute('data-nexa-file-id');
    expect(fileAttachmentId).toBeTruthy();
    await dialog.getByRole('button', {name: 'Attach file'}).click();
    await expect(fileLibrary).toBeVisible();
    await fileLibrary.getByRole('searchbox', {name: 'Search tenant files'}).fill(documentName);
    const existingFile = fileLibrary.locator(`[data-nexa-file-id="${fileAttachmentId}"]`);
    await expect(existingFile).toBeVisible({timeout: 20_000});
    await existingFile.click();
    await expect(dialog.locator(`.note-editable a[data-nexa-file-name="${documentName}"]`)).toHaveCount(2);

    const message = `Image timeline check ${Date.now()}`;
    const editable = dialog.locator('.note-editable');
    await editable.click({position: {x: 8, y: 8}});
    await editable.press('End');
    await editable.press('Enter');
    await editable.type(message);
    await dialog.getByRole('button', {name: 'Log interaction'}).click();
    await expect(dialog).toHaveCount(0, {timeout: 30_000});

    const activity = page.locator('.nexa-activity-card').filter({hasText: message}).first();
    await expect(activity).toBeVisible({timeout: 30_000});
    if (await activity.locator('.nexa-activity-details').getAttribute('hidden') !== null) {
        await activity.locator('[data-nexa-activity-toggle]').click();
    }
    await expect(activity.locator('.nexa-rich-activity-content')).toContainText(message);
    await expect(activity.locator('strong').first()).toHaveText('Logged SMS');
    await expect(activity).not.toContainText('[SMS]');
    await expect(activity.locator(`a[data-nexa-file-name="${documentName}"]`).first()).toBeVisible();
    const timelineImage = activity.locator('.nexa-rich-activity-content img[data-nexa-attachment-id]').first();
    await expect(timelineImage).toBeVisible();
    await expect.poll(() => timelineImage.evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
    const stored = await page.evaluate(async name => {
        const response = await Espo.Ajax.getRequest('Nexa/files/images', {search: name, offset: 0, limit: 12});
        return response.list || [];
    }, fileName);
    expect(stored.some(item => item.id === attachmentId)).toBe(true);
    const noteId = await activity.getAttribute('data-nexa-activity-note-id');
    if (noteId) await page.evaluate(id => Espo.Ajax.deleteRequest(`Note/${encodeURIComponent(id)}`), noteId);
    await page.evaluate(id => Espo.Ajax.deleteRequest(`Attachment/${encodeURIComponent(id)}`), attachmentId);
    await page.evaluate(id => Espo.Ajax.deleteRequest(`Attachment/${encodeURIComponent(id)}`), fileAttachmentId);
});

test('Account SMS persists rich images into the company timeline', async ({page}) => {
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form').evaluate(form => form.noValidate = true);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
    const tenantKey = new URL(page.url()).pathname.match(/\/w\/([^/]+)/)?.[1];
    let accounts = await page.evaluate(() => Espo.Ajax.getRequest('Account', {
        select: 'id,name', maxSize: 1, orderBy: 'createdAt', order: 'desc',
    }));
    let temporaryAccountId = null;
    if (!accounts?.list?.length) {
        const created = await page.evaluate(name => Espo.Ajax.postRequest('Account', {name}), `Rich editor check ${Date.now()}`);
        temporaryAccountId = created.id;
        accounts = {list: [created]};
    }

    await page.goto(`${baseUrl}/w/${tenantKey}/Account/view/${accounts.list[0].id}`);
    const workspace = page.locator('[data-nexa-company-workspace]');
    await expect(workspace).toBeVisible({timeout: 30_000});
    await workspace.getByRole('button', {name: 'More company actions'}).click();
    await page.getByRole('button', {name: 'Log SMS'}).click();
    const dialog = page.locator('[data-nexa-account-interaction-dialog]');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', {name: 'Insert image'})).toBeVisible({timeout: 15_000});
    await expect(dialog.locator('[data-nexa-sms-contact-search]')).toBeEnabled();
    await expect(dialog.locator('.note-editable')).toBeEditable();
    const contactOption = dialog.locator('[data-nexa-sms-contact-option]').first();
    if (!await contactOption.count()) {
        if (temporaryAccountId) await page.evaluate(id => Espo.Ajax.deleteRequest(`Account/${encodeURIComponent(id)}`), temporaryAccountId);
        return;
    }
    await dialog.locator('[data-nexa-sms-contact-search]').click();
    await contactOption.click();

    await dialog.getByRole('button', {name: 'Insert image'}).click();
    const library = page.locator('.nexa-tenant-image-dialog');
    const fileName = `nexa-account-editor-check-${Date.now()}.png`;
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await library.locator('[data-nexa-image-upload]').setInputFiles({name: fileName, mimeType: 'image/png', buffer: png});
    const inserted = dialog.locator('.note-editable img[data-nexa-attachment-id]').last();
    await expect(inserted).toBeVisible({timeout: 20_000});
    await expect.poll(() => inserted.evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
    const attachmentId = await inserted.getAttribute('data-nexa-attachment-id');

    const message = `Company timeline image check ${Date.now()}`;
    const editable = dialog.locator('.note-editable');
    await editable.click({position: {x: 8, y: 8}});
    await editable.press('End');
    await editable.press('Enter');
    await editable.type(message);
    await dialog.getByRole('button', {name: 'Log interaction'}).click();
    await expect(dialog).toHaveCount(0, {timeout: 30_000});

    const activity = workspace.locator('.nexa-company-engagement-record').filter({hasText: message}).first();
    await expect(activity).toBeVisible({timeout: 30_000});
    await expect(activity.locator('strong').first()).toHaveText('Logged SMS');
    await expect(activity).not.toContainText('[SMS]');
    if (await activity.locator('.nexa-activity-details').getAttribute('hidden') !== null) {
        await activity.locator('[data-nexa-company-engagement-toggle]').click();
    }
    const richContent = activity.locator('.nexa-company-engagement-preview');
    await expect(richContent).toContainText(message);
    const timelineImage = richContent.locator('img[data-nexa-attachment-id]').first();
    await expect(timelineImage).toBeVisible();
    await expect.poll(() => timelineImage.evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);

    const noteId = await activity.getAttribute('data-nexa-company-engagement-id');
    const commentText = `Company comment ${Date.now()}`;
    await activity.getByRole('button', {name: 'Add comment'}).click();
    const commentForm = activity.locator('[data-nexa-company-comment-form]');
    await expect(commentForm).toBeVisible();
    await expectAlignedCommentToolbar(commentForm);
    await commentForm.locator('.note-editable').fill(commentText);
    await commentForm.getByRole('button', {name: 'Comment', exact: true}).click();
    const activityPanel = workspace.locator('[data-nexa-company-panel="activity"]');
    const savedComment = activityPanel.locator('[data-nexa-company-comment-id]').filter({hasText: commentText}).first();
    await expect(savedComment).toBeVisible({timeout: 30_000});

    const replyText = `Company reply ${Date.now()}`;
    await savedComment.getByRole('button', {name: 'Reply', exact: true}).click();
    const replyForm = savedComment.locator('[data-nexa-company-reply-form]');
    await expect(replyForm).toBeVisible();
    await replyForm.locator('.note-editable').fill(replyText);
    await replyForm.getByRole('button', {name: 'Reply', exact: true}).click();
    await expect(activityPanel.locator('.nexa-task-comment-reply').filter({hasText: replyText}).first()).toBeVisible({timeout: 30_000});

    if (noteId) {
        await page.evaluate(async ({accountId, targetId}) => {
            const payload = await Espo.Ajax.getRequest(`Nexa/account/${encodeURIComponent(accountId)}/timeline`, {tab: 'activity', offset: 0, limit: 25});
            const comments = payload.comments || [];
            const parent = comments.find(record => String(record.post || '').startsWith(`<!-- nexa-engagement-comment:Note:${targetId} -->`));
            const ids = comments.filter(record => record.id === parent?.id || String(record.post || '').startsWith(`<!-- nexa-engagement-reply:${parent?.id} -->`)).map(record => record.id);
            await Promise.all(ids.map(id => Espo.Ajax.deleteRequest(`Note/${encodeURIComponent(id)}`)));
        }, {accountId: accounts.list[0].id, targetId: noteId});
    }
    if (noteId) await page.evaluate(id => Espo.Ajax.deleteRequest(`Note/${encodeURIComponent(id)}`), noteId);
    if (attachmentId) await page.evaluate(id => Espo.Ajax.deleteRequest(`Attachment/${encodeURIComponent(id)}`), attachmentId);
    if (temporaryAccountId) {
        await page.evaluate(id => Espo.Ajax.deleteRequest(`Account/${encodeURIComponent(id)}`), temporaryAccountId);
    }
});

test('Contact and Account logs and notes share the tenant rich editor', async ({page}) => {
    test.skip(!baseUrl || !userName || !password, 'Live Nexa credentials were not provided.');

    await page.goto(`${baseUrl}/login/`);
    await page.locator('#field-userName').fill(userName);
    await page.locator('#field-password').fill(password);
    await page.locator('#login-form').evaluate(form => form.noValidate = true);
    await page.locator('#login-form button[type="submit"]').click();
    await page.waitForURL(/\/w\/[^/]+(?:\/.*)?$/, {timeout: 30_000});
    const tenantKey = new URL(page.url()).pathname.match(/\/w\/([^/]+)/)?.[1];

    const contacts = await page.evaluate(() => Espo.Ajax.getRequest('Contact', {
        select: 'id,name', maxSize: 1, orderBy: 'createdAt', order: 'desc',
    }));
    test.skip(!contacts?.list?.length, 'The tenant has no Contact fixture.');
    await page.goto(`${baseUrl}/w/${tenantKey}/Contact/view/${contacts.list[0].id}`);
    const contactWorkspace = page.locator('[data-nexa-contact-workspace]');
    await expect(contactWorkspace).toBeVisible({timeout: 30_000});

    for (const action of ['whatsapp', 'linkedin', 'call-log', 'email-log', 'postal-mail', 'live-chat']) {
        await contactWorkspace.getByRole('button', {name: 'More customer actions'}).click();
        await page.locator(`[data-nexa-command="${action}"]`).click();
        const dialog = page.locator('[data-nexa-interaction-dialog]');
        await expect(dialog.getByRole('button', {name: 'Insert image'})).toBeVisible({timeout: 15_000});
        await expect(dialog.getByRole('button', {name: 'Attach file'})).toBeVisible();
        await expect(dialog.locator('[name="subject"]')).toHaveCount(0);
        if (action === 'call-log') {
            await expect(dialog.locator('[name="callOutcome"]')).toBeVisible();
            await expect(dialog.locator('[name="direction"]')).toBeVisible();
        } else {
            await expect(dialog.locator('[name="direction"]')).toHaveCount(0);
            await expect(dialog.locator('[name="outcome"]')).toHaveCount(0);
        }
        await dialog.locator('[data-nexa-dialog-close]').first().click();
        await expect(dialog).toHaveCount(0);
    }

    await contactWorkspace.getByRole('button', {name: 'More customer actions'}).click();
    await page.locator('[data-nexa-command="meeting-log"]').click();
    const contactMeeting = page.locator('[data-nexa-interaction-dialog]');
    await expect(contactMeeting.locator('[name="meetingOutcome"]')).toBeVisible();
    await contactMeeting.locator('[data-nexa-duration-search]').fill('8 Hours');
    await contactMeeting.locator('[data-nexa-duration-option][data-value="480"]').click();
    await expect(contactMeeting.locator('[name="duration"]')).toHaveValue('480');
    await contactMeeting.locator('[data-nexa-dialog-close]').first().click();

    await contactWorkspace.locator('[data-nexa-contact-action="note"]').click();
    const contactNote = page.locator('[data-nexa-note-dialog]');
    await expect(contactNote.getByRole('button', {name: 'Insert image'})).toBeVisible({timeout: 15_000});
    await expect(contactNote.getByRole('button', {name: 'Attach file'})).toBeVisible();
    await contactNote.locator('[data-nexa-note-close]').first().click();

    let accounts = await page.evaluate(() => Espo.Ajax.getRequest('Account', {
        select: 'id,name', maxSize: 1, orderBy: 'createdAt', order: 'desc',
    }));
    let temporaryAccountId = null;
    if (!accounts?.list?.length) {
        const account = await page.evaluate(() => Espo.Ajax.postRequest('Account', {name: `Editor contract ${Date.now()}`}));
        temporaryAccountId = account.id;
        accounts = {list: [account]};
    }
    await page.goto(`${baseUrl}/w/${tenantKey}/Account/view/${accounts.list[0].id}`);
    const accountWorkspace = page.locator('[data-nexa-company-workspace]');
    await expect(accountWorkspace).toBeVisible({timeout: 30_000});

    for (const action of ['whatsapp', 'linkedin', 'call', 'email', 'postal-mail']) {
        await accountWorkspace.getByRole('button', {name: 'More company actions'}).click();
        await page.locator(`[data-nexa-account-command="${action}"]`).click();
        const dialog = page.locator('[data-nexa-account-interaction-dialog]');
        await expect(dialog.getByRole('button', {name: 'Insert image'})).toBeVisible({timeout: 15_000});
        await expect(dialog.getByRole('button', {name: 'Attach file'})).toBeVisible();
        await expect(dialog.locator('[name="subject"]')).toHaveCount(0);
        await expect(dialog.locator('[data-nexa-sms-contact-search]')).toBeVisible();
        if (action === 'call') {
            await expect(dialog.locator('[name="callOutcome"]')).toBeVisible();
            await expect(dialog.locator('[name="direction"]')).toBeVisible();
        } else {
            await expect(dialog.locator('[name="direction"]')).toHaveCount(0);
            await expect(dialog.locator('[name="outcome"]')).toHaveCount(0);
        }
        await dialog.locator('[data-close]').first().click();
        await expect(dialog).toHaveCount(0);
    }


    await accountWorkspace.getByRole('button', {name: 'More company actions'}).click();
    await page.locator('[data-nexa-account-command="meeting"]').click();
    const accountMeeting = page.locator('[data-nexa-account-interaction-dialog]');
    await expect(accountMeeting.locator('[name="meetingOutcome"]')).toBeVisible();
    await accountMeeting.locator('[data-nexa-duration-search]').fill('8 Hours');
    await accountMeeting.locator('[data-nexa-duration-option][data-value="480"]').click();
    await expect(accountMeeting.locator('[name="duration"]')).toHaveValue('480');
    await accountMeeting.locator('[data-close]').first().click();
    await accountWorkspace.getByRole('button', {name: 'More company actions'}).click();
    await expect(page.locator('[data-nexa-account-command="live-chat"]')).toHaveCount(0);
    await page.keyboard.press('Escape');

    await accountWorkspace.locator('[data-nexa-company-action="note"]').click();
    const accountNote = page.locator('[data-nexa-account-note-dialog]');
    await expect(accountNote.getByRole('button', {name: 'Insert image'})).toBeVisible({timeout: 15_000});
    await expect(accountNote.getByRole('button', {name: 'Attach file'})).toBeVisible();
    await accountNote.locator('[data-close]').first().click();
    if (temporaryAccountId) await page.evaluate(id => Espo.Ajax.deleteRequest(`Account/${encodeURIComponent(id)}`), temporaryAccountId);
});
