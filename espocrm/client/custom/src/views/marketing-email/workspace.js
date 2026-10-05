define('custom:views/marketing-email/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:marketing-email/workspace';
    events = {
        'click [data-action="new-email"]': 'newEmail',
        'click [data-action="edit-email"]': 'editEmail',
        'click [data-action="close-editor"]': 'closeEditor',
        'click [data-action="reload"]': 'load',
        'click [data-action="transition"]': 'transition',
        'click [data-action="schedule-email"]': 'scheduleEmail',
        'click [data-action="email-results"]': 'openResults',
        'click [data-action="close-results"]': 'closeResults',
        'click [data-action="delivery-setup"]': 'openDelivery',
        'click [data-action="close-delivery"]': 'closeDelivery',
        'click [data-action="verify-domain"]': 'verifyDomain',
        'click [data-action="copy-callback"]': 'copyCallback',
        'click [data-action="rotate-callback"]': 'rotateCallback',
        'input [data-email-search]': 'renderEmails',
        'input [name="bodyHtml"], input [name="subject"], input [name="preheader"]': 'renderContentPreview',
        'change [data-preview-width]': 'renderContentPreview',
        'click [data-action="preview-contact"]': 'choosePreviewContact',
        'click [data-action="preview-design"]': 'clearRecipientPreview',
        'change [data-email-status]': 'renderEmails',
        'change [name="campaignId"]': 'syncCampaign',
        'submit [data-email-form]': 'save',
        'submit [data-provider-form]': 'saveProvider',
        'submit [data-domain-form]': 'saveDomain',
    };

    setup() { this.setPageTitle('Marketing Email'); this.workspace = null; this.current = null; this._delivery = null; Object.defineProperty(this, 'delivery', {get: () => this._delivery, set: value => { this._delivery = value; queueMicrotask(() => this.renderCallback()); }}); }
    afterRender() { super.afterRender(); document.body.classList.add('nexa-marketing-email-page'); this.element.querySelector('[data-email-editor]').style.display='none'; this.element.querySelector('[data-delivery-editor]').style.display='none'; this.installCallbackSetup(); this.once('remove', () => document.body.classList.remove('nexa-marketing-email-page')); this.load(); }
    async load() { this.state('loading'); try { this.workspace = await Espo.Ajax.getRequest('Nexa/marketing-emails/workspace'); this.renderSummary(); this.populateCampaigns(); this.renderEmails(); this.text('[data-delivery-message]', this.workspace.deliveryMessage); this.element.querySelector('[data-action="new-email"]').hidden = !this.workspace.permissions?.create; this.element.querySelector('[data-action="delivery-setup"]').hidden = !this.workspace.permissions?.approve; this.state('ready'); } catch (error) { this.state('error'); Espo.Ui.error(error?.message || 'Marketing email could not be loaded.'); } }
    state(value) { this.element.querySelectorAll('[data-email-state]').forEach(node => { node.hidden = node.dataset.emailState !== value; node.style.display = node.hidden ? 'none' : ''; }); }
    renderSummary() {
        const summary = this.workspace.summary || {};
        ['total','drafts','review','approved'].forEach(key => this.text(`[data-summary-${key}]`, Number(summary[key] || 0)));
        this.text('[data-delivery-title]', this.workspace.deliveryEnabled ? 'Delivery is ready' : 'Delivery setup required');
        const section = this.element.querySelector('[data-email-usage]');
        if (section) {
            section.hidden = !this.workspace.usage;
            if (this.workspace.usage) {
                const usage = this.workspace.usage;
                this.text('[data-usage-month]', new Date(`${usage.month}T00:00:00`).toLocaleDateString(undefined, {month: 'long', year: 'numeric'}));
                this.text('[data-usage-limit]', usage.limit === null ? 'No configured cap' : Number(usage.limit).toLocaleString());
                this.text('[data-usage-reserved]', Number(usage.reserved).toLocaleString());
                this.text('[data-usage-remaining]', usage.remaining === null ? 'No configured cap' : Number(usage.remaining).toLocaleString());
            }
        }
    }
    openResults(event) {
        const item = this.workspace.emails.find(row => row.id === event.currentTarget.dataset.id);
        if (!item) return;
        this.resultsTrigger = event.currentTarget;
        const dialog = this.element.querySelector('[data-email-results]');
        const metrics = item.deliveryMetrics || {};
        this.text('[data-results-title]', item.name);
        this.text('[data-results-status]', this.label(item.lifecycleStatus));
        this.text('[data-results-campaign]', item.campaignName || 'Not linked');
        ['submitted','delivered','deferred','bounced','complained','failed','opened','clicked','unsubscribed'].forEach(key =>
            this.text(`[data-result-${key}]`, Number(metrics[key] || 0).toLocaleString()));
        dialog.hidden = false; dialog.style.display = 'grid';
        document.body.classList.add('nexa-modal-open');
        const close = dialog.querySelector('[data-action="close-results"]');
        close.focus();
        this.resultsKeyHandler = event => {
            if (event.key === 'Escape') { event.preventDefault(); this.closeResults(); }
            if (event.key === 'Tab') { event.preventDefault(); close.focus(); }
        };
        dialog.addEventListener('keydown', this.resultsKeyHandler);
    }
    closeResults() {
        const dialog = this.element.querySelector('[data-email-results]');
        dialog.hidden = true; dialog.style.display = 'none';
        dialog.removeEventListener('keydown', this.resultsKeyHandler);
        document.body.classList.remove('nexa-modal-open');
        if (this.resultsTrigger?.isConnected) this.resultsTrigger.focus();
    }
    populateCampaigns() { const options = (this.workspace.campaigns || []).map(item => `<option value="${this.escape(item.id)}" data-purpose="${this.escape(item.purposeId || '')}">${this.escape(item.name)} (${Number(item.eligibleCount || 0)} eligible)</option>`).join(''); this.element.querySelector('[name="campaignId"]').innerHTML = `<option value="">Choose an email campaign</option>${options}`; }
    renderEmails() {
        const query = (this.element.querySelector('[data-email-search]')?.value || '').trim().toLowerCase(); const status = this.element.querySelector('[data-email-status]')?.value || '';
        const items = (this.workspace?.emails || []).filter(item => (!status || item.lifecycleStatus === status) && (!query || `${item.name} ${item.subject} ${item.campaignName || ''}`.toLowerCase().includes(query)));
        this.element.querySelector('[data-email-list]').innerHTML = items.map(item => `<tr><td><button class="nexa-email-name" data-action="edit-email" data-id="${this.escape(item.id)}"><span class="fas fa-envelope-open-text"></span><span><strong>${this.escape(item.name)}</strong><small>${this.escape(item.subject || 'No subject')}</small></span></button></td><td>${this.escape(item.campaignName || 'Not linked')}</td><td><span class="nexa-email-status is-${this.escape(item.lifecycleStatus)}">${this.escape(this.label(item.lifecycleStatus))}</span></td><td>v${Number(item.version || 1)}</td><td>${this.escape(item.fromAddress || 'Not set')}</td><td>${Number(item.queuedCount || 0)}</td><td>${this.escape(this.dateTime(item.modifiedAt))}</td><td><div class="nexa-email-actions">${this.actions(item)}</div></td></tr>`).join('');
        this.text('[data-email-count]', `${items.length} email${items.length === 1 ? '' : 's'}`); const empty = this.element.querySelector('[data-email-empty]'); empty.hidden = items.length > 0; this.element.querySelector('[data-email-table]').hidden = !items.length;
    }
    actions(item) { const buttons = [`<button class="btn btn-icon" data-action="email-results" data-id="${this.escape(item.id)}" title="Delivery results"><span class="fas fa-chart-bar"></span></button>`]; if (['draft','review','approved'].includes(item.lifecycleStatus) && this.workspace.permissions?.edit) buttons.push(`<button class="btn btn-icon" data-action="edit-email" data-id="${this.escape(item.id)}" title="Edit"><span class="fas fa-pen"></span></button>`); if (item.lifecycleStatus === 'draft') buttons.push(this.transitionButton(item, 'submit', 'Submit for review', 'fa-paper-plane')); if (item.lifecycleStatus === 'review' && this.workspace.permissions?.approve) buttons.push(this.transitionButton(item, 'approve', 'Approve', 'fa-check')); if (item.lifecycleStatus === 'approved' && this.workspace.deliveryEnabled && this.workspace.permissions?.approve) buttons.push(`<button class="btn btn-icon" data-action="schedule-email" data-id="${this.escape(item.id)}" title="Schedule approved email"><span class="fas fa-clock"></span></button>`); if (['review','approved'].includes(item.lifecycleStatus)) buttons.push(this.transitionButton(item, 'return', 'Return to draft', 'fa-undo')); return buttons.join(''); }
    transitionButton(item, action, title, icon) { return `<button class="btn btn-icon" data-action="transition" data-id="${this.escape(item.id)}" data-transition="${action}" title="${title}"><span class="fas ${icon}"></span></button>`; }
    newEmail() { this.previewRequest = (this.previewRequest || 0) + 1; this.recipientPreview = null; this.openEditor(null); }
    renderContentPreview() {
        const form = this.element.querySelector('[data-email-form]');
        let preview = form.querySelector('[data-content-preview]');
        if (!preview) {
            preview = document.createElement('section');
            preview.dataset.contentPreview = '';
            preview.className = 'nexa-email-content-preview';
            preview.innerHTML = '<header><h3>Preview</h3><div class="nexa-preview-controls"><button class="btn btn-default" type="button" data-action="preview-contact"><span class="fas fa-user" aria-hidden="true"></span>Preview as Contact</button><button class="btn btn-icon" type="button" data-action="preview-design" title="Return to design preview" aria-label="Return to design preview" hidden><span class="fas fa-times" aria-hidden="true"></span></button><select class="form-control" data-preview-width aria-label="Preview width"><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></div></header><p data-preview-recipient aria-live="polite"></p><strong data-preview-subject></strong><p data-preview-preheader></p><div class="nexa-email-preview-viewport"><iframe title="Email content preview" sandbox="" referrerpolicy="no-referrer"></iframe></div>';
            form.querySelector('.nexa-marketing-email-grid').append(preview);
        }
        const key = JSON.stringify([form.elements.subject.value, form.elements.bodyHtml.value]);
        const rendered = this.recipientPreview?.key === key ? this.recipientPreview : null;
        preview.querySelector('[data-preview-subject]').textContent = rendered?.subject || form.elements.subject.value || 'No subject';
        preview.querySelector('[data-preview-recipient]').textContent = rendered ? `Preview for ${rendered.name}` : '';
        preview.querySelector('[data-action="preview-design"]').hidden = !rendered;
        preview.querySelector('[data-preview-preheader]').textContent = form.elements.preheader.value;
        const frame = preview.querySelector('iframe');
        frame.classList.toggle('is-mobile', preview.querySelector('select').value === 'mobile');
        // The preview is deliberately inert, including remote images and stylesheets.
        const policy = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";
        frame.srcdoc = `<html><head><meta http-equiv="Content-Security-Policy" content="${policy}"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:20px;color:#17211f;font:14px Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}</style></head><body>${rendered?.bodyHtml ?? form.elements.bodyHtml.value}</body></html>`;
    }
    clearRecipientPreview() { this.previewRequest = (this.previewRequest || 0) + 1; this.recipientPreview = null; this.renderContentPreview(); }
    choosePreviewContact() {
        const editor = this.element.querySelector('[data-email-editor]');
        editor.style.display = 'none';
        this.createView('previewContactSelector', 'views/modals/select-records', {
            scope: 'Contact', multiple: false, createButton: false, headerText: 'Choose a preview Contact',
        }, view => {
            this.listenToOnce(view, 'remove', () => { editor.style.display = 'grid'; });
            this.listenToOnce(view, 'select', model => this.previewContact(model));
            view.render();
        });
    }
    async previewContact(model) {
        const form = this.element.querySelector('[data-email-form]');
        const input = {contactId: model.id, subject: form.elements.subject.value, bodyHtml: form.elements.bodyHtml.value};
        const key = JSON.stringify([input.subject, input.bodyHtml]);
        const request = this.previewRequest = (this.previewRequest || 0) + 1;
        Espo.Ui.notify('Rendering preview...');
        try {
            const result = await Espo.Ajax.postRequest('Nexa/marketing-emails/preview', input);
            Espo.Ui.notify(false);
            if (request !== this.previewRequest || this.element.querySelector('[data-email-editor]').hidden) return;
            this.recipientPreview = {...result, key, name: model.get('name') || 'Contact'};
            Espo.Ui.notify(false); this.renderContentPreview();
        } catch (error) {
            Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The recipient preview could not be rendered.');
        }
    }
    editEmail(event) {
        const item = (this.workspace.emails || []).find(item => item.id === event.currentTarget.dataset.id);
        if (!item) return;
        if (!['draft','review','approved'].includes(item.lifecycleStatus) || !this.workspace.permissions?.edit) return this.openResults(event);
        this.previewRequest = (this.previewRequest || 0) + 1;
        this.recipientPreview = null;
        this.openEditor(item);
    }
    openEditor(item) { this.current = item || null; const form = this.element.querySelector('[data-email-form]'); form.reset(); form.elements.name.value=item?.name||''; form.elements.campaignId.value=item?.campaignId||''; form.elements.subject.value=item?.subject||''; form.elements.preheader.value=item?.preheader||''; form.elements.fromAddress.value=item?.fromAddress||''; form.elements.fromName.value=item?.fromName||''; form.elements.replyToAddress.value=item?.replyToAddress||''; form.elements.replyToName.value=item?.replyToName||''; form.elements.startAt.value=this.inputDateTime(item?.startAt); form.elements.bodyHtml.value=item?.bodyHtml||'<p>Hello {Contact.firstName},</p><p></p><p>Kind regards,<br>{User.name}</p>'; this.syncCampaign(); this.text('[data-editor-title]', item ? `Edit ${item.name}` : 'Create marketing email'); const editor=this.element.querySelector('[data-email-editor]'); editor.hidden=false; editor.style.display='grid'; document.body.classList.add('nexa-modal-open'); setTimeout(()=>form.elements.name.focus(),0); }
    closeEditor() { this.previewRequest = (this.previewRequest || 0) + 1; const editor=this.element.querySelector('[data-email-editor]'); editor.hidden=true; editor.style.display='none'; document.body.classList.remove('nexa-modal-open'); }
    async openDelivery() { const editor=this.element.querySelector('[data-delivery-editor]'); editor.hidden=false; editor.style.display='grid'; document.body.classList.add('nexa-modal-open'); Espo.Ui.notify('Loading delivery settings...'); try { this.delivery=await Espo.Ajax.getRequest('Nexa/marketing-delivery/settings'); Espo.Ui.notify(false); this.renderDelivery(); } catch(error){this.closeDelivery();Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Delivery settings could not be loaded.');} }
    closeDelivery() { const editor=this.element.querySelector('[data-delivery-editor]'); editor.hidden=true; editor.style.display='none'; document.body.classList.remove('nexa-modal-open'); }
    renderDelivery() { const provider=this.delivery?.provider||{}; const form=this.element.querySelector('[data-provider-form]'); form.elements.apiBaseUrl.value=provider.apiBaseUrl||''; form.elements.serverName.value=provider.serverName||''; form.elements.apiKey.value=''; form.elements.apiKey.required=!provider.hasCredential; form.elements.monthlySendLimit.value=Number(provider.monthlySendLimit||0); this.text('[data-credential-help]',provider.hasCredential?'An encrypted API key is configured. Leave blank to keep it.':'The key is encrypted and is never returned to the browser.'); const domains=this.delivery?.domains||[]; this.element.querySelector('[data-domain-list]').innerHTML=domains.length?domains.map(item=>`<article class="nexa-domain-card"><div><strong>${this.escape(item.domainName)}</strong><small>Ownership ${this.escape(item.ownershipStatus)} / SPF ${this.escape(item.spfStatus)} / DKIM ${this.escape(item.dkimStatus)} / DMARC ${this.escape(item.dmarcStatus)} / Return path ${this.escape(item.returnPathStatus)}</small><code>TXT @ nexa-verification=${this.escape(item.verificationToken)}</code></div><button class="btn btn-default" data-action="verify-domain" data-id="${this.escape(item.id)}"><span class="fas fa-sync"></span>Check DNS</button></article>`).join(''):'<p class="text-muted">No sending domain has been added.</p>'; }
    async saveProvider(event) { event.preventDefault(); const form=event.currentTarget; const payload={apiBaseUrl:form.elements.apiBaseUrl.value.trim(),serverName:form.elements.serverName.value.trim(),apiKey:form.elements.apiKey.value,monthlySendLimit:Number(form.elements.monthlySendLimit.value||0)}; Espo.Ui.notify('Saving provider...'); try{this.delivery=await Espo.Ajax.putRequest('Nexa/marketing-delivery/provider',payload);Espo.Ui.success('Postal provider saved securely.');this.renderDelivery();await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Provider settings could not be saved.');} }
    installCallbackSetup() { const section=this.element.querySelector('[data-provider-form] section'); if(!section||section.querySelector('[data-callback-setup]'))return; section.insertAdjacentHTML('beforeend','<div class="nexa-callback-setup" data-callback-setup hidden><strong>Postal webhook URL</strong><p>Copy this URL into Postal webhook settings. Treat it as a secret.</p><div><input class="form-control" data-callback-url readonly><button class="btn btn-default" data-action="copy-callback" type="button" title="Copy webhook URL"><span class="fas fa-copy"></span></button></div></div><div class="nexa-callback-existing" data-callback-existing hidden><span class="fas fa-check-circle"></span><span>A webhook token is configured.</span><button class="btn btn-link" data-action="rotate-callback" type="button">Rotate URL</button></div>'); }
    renderCallback() { const setup=this.element?.querySelector('[data-callback-setup]'); const existing=this.element?.querySelector('[data-callback-existing]'); if(!setup||!existing)return; if(this.delivery?.callbackToken){setup.hidden=false;existing.hidden=true;this.element.querySelector('[data-callback-url]').value=this.callbackUrl(this.delivery.callbackToken);return;} setup.hidden=true;existing.hidden=!this.delivery?.provider?.callbackTokenPrefix; }
    async copyCallback() { const input=this.element.querySelector('[data-callback-url]'); if(!input?.value)return; try{await navigator.clipboard.writeText(input.value);}catch(error){input.select();document.execCommand('copy');} Espo.Ui.success('Webhook URL copied.'); }
    rotateCallback() { this.confirm({message:'Rotate the Postal webhook URL? The current URL will stop working immediately.',confirmText:'Rotate URL'},async()=>{Espo.Ui.notify('Rotating webhook URL...');try{this.delivery=await Espo.Ajax.postRequest('Nexa/marketing-delivery/callback/rotate',{});Espo.Ui.success('Webhook URL rotated. Update it in Postal now.');}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Webhook URL could not be rotated.');}}); }
    callbackUrl(token) { const configured=String(this.getConfig().get('siteUrl')||'').replace(/\/$/,''); const base=configured||`${window.location.origin}${window.location.pathname.split('/w/')[0]}`; return `${base}/api/v1/Nexa/public/marketing-delivery/postal/${encodeURIComponent(token)}`; }
    async saveDomain(event) { event.preventDefault(); const form=event.currentTarget; const payload=Object.fromEntries(['domainName','spfInclude','dkimHost','dkimValue','returnPathHost','returnPathValue'].map(name=>[name,form.elements[name].value.trim()])); Espo.Ui.notify('Saving sending domain...'); try{this.delivery=await Espo.Ajax.postRequest('Nexa/marketing-delivery/domains',payload);Espo.Ui.success('Sending domain saved. Add the DNS records, then check DNS.');form.reset();this.renderDelivery();await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Sending domain could not be saved.');} }
    async verifyDomain(event) { const id=event.currentTarget.dataset.id; Espo.Ui.notify('Checking public DNS...'); try{this.delivery=await Espo.Ajax.postRequest(`Nexa/marketing-delivery/domains/${encodeURIComponent(id)}/verify`,{});Espo.Ui.success(this.delivery.readiness?.ready?'Delivery prerequisites are ready.':'DNS check completed. Missing records remain listed.');this.renderDelivery();await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'DNS could not be checked.');} }
    syncCampaign() { const form=this.element.querySelector('[data-email-form]'); const campaign=(this.workspace?.campaigns||[]).find(item=>item.id===form.elements.campaignId.value); form.elements.purposeId.value=campaign?.purposeId||''; this.text('[data-campaign-purpose]', campaign ? `${campaign.purposeName || 'Email consent'} / ${Number(campaign.eligibleCount || 0)} eligible / ${Number(campaign.suppressedCount || 0)} suppressed` : 'Choose the governed campaign audience for this email.'); this.renderContentPreview(); }
    payload() { const form=this.element.querySelector('[data-email-form]'); return {name:form.elements.name.value.trim(),campaignId:form.elements.campaignId.value,purposeId:form.elements.purposeId.value,subject:form.elements.subject.value.trim(),preheader:form.elements.preheader.value.trim(),fromAddress:form.elements.fromAddress.value.trim(),fromName:form.elements.fromName.value.trim(),replyToAddress:form.elements.replyToAddress.value.trim(),replyToName:form.elements.replyToName.value.trim(),startAt:form.elements.startAt.value||null,deliveryMode:'campaign',bodyHtml:form.elements.bodyHtml.value,bodyText:''}; }
    async save(event) { event.preventDefault(); const payload=this.payload(); if (!payload.name||!payload.campaignId||!payload.subject||!payload.fromAddress||!payload.bodyHtml.trim()) return Espo.Ui.error('Complete the required name, campaign, subject, sender and content fields.'); Espo.Ui.notify('Saving marketing email...'); try { if(this.current) await Espo.Ajax.putRequest(`Nexa/marketing-emails/${encodeURIComponent(this.current.id)}`,payload); else await Espo.Ajax.postRequest('Nexa/marketing-emails',payload); Espo.Ui.success('Marketing email draft saved.'); this.closeEditor(); await this.load(); } catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The marketing email could not be saved.');} }
    transition(event) { const id=event.currentTarget.dataset.id; const action=event.currentTarget.dataset.transition; const labels={submit:'Submit this email for review?',approve:'Approve this email content?',return:'Return this email to draft?'}; this.confirm({message:labels[action],confirmText:this.label(action)},async()=>{Espo.Ui.notify('Updating email...');try{await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/transition`,{action});Espo.Ui.success('Marketing email status updated.');await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The status could not be updated.');}}); }
    scheduleEmail(event) { const id=event.currentTarget.dataset.id; this.confirm({message:'Schedule this approved email for its proposed send time? Eligibility and monthly capacity will be checked again before delivery.',confirmText:'Schedule email'},async()=>{Espo.Ui.notify('Scheduling email...');try{const result=await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/schedule`,{});Espo.Ui.success(`Email scheduled for ${this.dateTime(result.scheduledAt)} with ${Number(result.eligibleCount)} eligible recipients.`);await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The email could not be scheduled.');}}); }
    inputDateTime(value) { if(!value)return''; return String(value).replace(' ','T').slice(0,16); }
    dateTime(value) { if(!value)return'Not updated'; const parsed=new Date(String(value).replace(' ','T')); return Number.isNaN(parsed.getTime())?String(value):parsed.toLocaleString(); }
    label(value) { return String(value||'').replace(/_/g,' ').replace(/\b\w/g,letter=>letter.toUpperCase()); }
    text(selector,value) { const node=this.element.querySelector(selector); if(node)node.textContent=String(value??''); }
    escape(value) { const node=document.createElement('div'); node.textContent=String(value??''); return node.innerHTML; }
});
