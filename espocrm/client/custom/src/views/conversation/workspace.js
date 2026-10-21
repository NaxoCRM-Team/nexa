define('custom:views/conversation/workspace', ['view', 'email-helper'], (Dep, EmailHelper) => class extends Dep {
    template = 'custom:conversation/workspace';
    events = {
        'input .nc-search': 'search',
        'change .nc-status-filter': 'reset',
        'change .nc-owner-filter': 'reset',
        'change .nc-view-filter': 'reset',
        'click [data-action="unread"]': 'markUnread',
        'click [data-action="refresh"]': 'refresh',
        'click [data-action="more"]': 'more',
        'click [data-action="open"]': 'open',
        'click [data-action="back"]': 'back',
        'change .nc-edit-status': 'save',
        'change .nc-edit-owner': 'save',
        'input .nc-staff-search': 'searchOwners',
        'click [data-action="reply"]': 'reply',
    };
    setup() {
        this.items = []; this.listSequence = 0; this.threadSequence = 0; this.ownerSequence = 0;
        this.on('remove', () => {
            this.disposed = true; clearTimeout(this.timer); clearTimeout(this.ownerTimer);
        });
    }
    afterRender() { this.reset(); this.startAvailability(); }
    startAvailability() {
        clearInterval(this.availabilityTimer);
        const update=async(heartbeat=false)=>{
            if(this.disposed||document.hidden||this.availabilityBusy)return;
            this.availabilityBusy=true;
            try{const state=await Espo.Ajax.postRequest('Nexa/conversation-routing',{op:'presence',...(heartbeat?{heartbeat:true,version:this.availability?.version??0}:{})});if(this.disposed||state.version<(this.availability?.version??0))return;this.availability=state;
                let holder=this.element.querySelector('.nc-availability');
                if(!holder){holder=document.createElement('span');holder.className='nc-availability';holder.style.cssText='display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap';holder.innerHTML='<label style="font-size:12px;margin:0;white-space:normal"><input type="checkbox" aria-label="Available for conversations"> Available for conversations</label>'+(state.canConfigure?'<a class="btn btn-default" href="#NexaConversations/routing" title="Conversation routing" aria-label="Conversation routing"><span class="fas fa-random" aria-hidden="true"></span></a>':'');this.element.querySelector('.nc-heading-actions')?.prepend(holder);holder.querySelector('input').addEventListener('change',async event=>{const value=event.target.checked;event.target.disabled=true;try{this.availability=await Espo.Ajax.postRequest('Nexa/conversation-routing',{op:'presence',available:value,version:this.availability.version});}catch(error){this.error(error);}finally{event.target.checked=Boolean(this.availability?.available);event.target.disabled=false;}});}
                if(state.canConfigure&&!holder.querySelector('.nc-chat-privacy-link')){const link=document.createElement('a');link.className='btn btn-default nc-chat-privacy-link';link.href='#NexaConversations/privacy';link.title='Chat privacy';link.setAttribute('aria-label','Chat privacy');link.innerHTML='<span class="fas fa-shield-alt" aria-hidden="true"></span>';holder.append(link);}
                holder.querySelector('input').checked=state.available;
            }catch(error){if([401,403].includes(error.status))this.element.querySelector('.nc-availability')?.remove();}
            finally{this.availabilityBusy=false;}
        };
        update();this.availabilityTimer=setInterval(()=>update(true),30000);this.once('remove',()=>clearInterval(this.availabilityTimer));
    }
    escape(value) { return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    error(error) {
        const node = this.$el.find('.nc-error')[0]; if (!node) return;
        node.hidden = !error;
        node.textContent = error ? (error.responseJSON?.message || 'Unable to load or update the inbox. Please try again.') : '';
    }
    search() { clearTimeout(this.timer); this.timer = setTimeout(() => this.reset(), 180); }
    refresh() { this.reset(); if (this.current) this.loadThread(this.current); }
    reset() { this.cursor = null; this.items = []; this.loadList(false); }
    more() { if (this.cursor && !this.loading) this.loadList(true); }
    async loadList(append) {
        const seq = ++this.listSequence; this.loading = true; this.error(null);
        const query = {
            q: this.$el.find('.nc-search').val() || '', before: append ? this.cursor : '',
            status: this.$el.find('.nc-status-filter').val() || '', owner: this.$el.find('.nc-owner-filter').val() || '',
            view: this.$el.find('.nc-view-filter').val() || '',
        };
        this.$el.find('.nc-list').attr('aria-busy', 'true');
        try {
            const result = await Espo.Ajax.getRequest('Nexa/conversations', query);
            if (this.disposed || seq !== this.listSequence) return;
            this.items = append ? [...this.items, ...result.list] : result.list;
            this.cursor = result.nextCursor; this.drawList();
            this.$el.find('.nc-mailboxes').prop('hidden', !result.canConfigureMailboxes);
            // ACL-filtered candidate pages may be empty; keep scanning until exhausted.
            if (!result.list.length && this.cursor) { this.loading = false; return this.loadList(true); }
        } catch (error) { if (seq === this.listSequence && !this.disposed) this.error(error); }
        finally { if (seq === this.listSequence) { this.loading = false; this.$el.find('.nc-list').attr('aria-busy', 'false'); } }
    }
    drawList() {
        const e = value => this.escape(value);
        this.$el.find('.nc-list').html(this.items.length ? this.items.map(item => `<button class="nc-conversation ${item.id === this.current ? 'is-selected' : ''} ${item.isUnread ? 'is-unread' : ''}" data-action="open" data-id="${e(item.id)}" aria-pressed="${item.id === this.current}"><span class="nc-subject">${item.isUnread ? '<span class="fas fa-circle nc-unread-dot" title="Unread" aria-label="Unread"></span> ' : ''}${e(item.name || '(No subject)')}</span><span class="nc-sender">${e(item.fromString)}</span>${item.threadLimitReached ? '<span class="nc-sender">Large thread: read/reply state unavailable. Open in Email.</span>' : ''}<span class="nc-meta"><span class="nc-state nc-state-${e(item.conversationStatus.toLowerCase())}">${e(item.conversationStatus)}</span><span>${e(item.assignedUserName || 'Unassigned')}</span></span></button>`).join('') : '<div class="nc-empty">No shared conversations found</div>');
        this.$el.find('.nc-more').prop('hidden', !this.cursor);
    }
    open(event) {
        if (this.markingUnread) return;
        const id = event.currentTarget.dataset.id;
        if (this.items.find(item => item.id === id)?.threadLimitReached) { this.getRouter().navigate('#Email/view/' + id, {trigger: true}); return; }
        this.loadThread(id);
    }
    back() { this.$el.find('.nexa-conversations').removeClass('nc-detail-open'); }
    async loadThread(id) {
        const seq = ++this.threadSequence; ++this.ownerSequence; this.current = id; this.thread = null;
        this.drawList(); this.$el.find('.nexa-conversations').addClass('nc-detail-open');
        this.$el.find('.nc-thread').html('<div class="nc-empty" role="status">Loading conversation...</div>');
        try {
            const result = await Espo.Ajax.getRequest(`Nexa/conversations/${encodeURIComponent(id)}`);
            if (this.disposed || seq !== this.threadSequence) return;
            this.thread = result; this.drawThread();
            if (result.canMarkRead) {
                this.readRequest = Espo.Ajax.postRequest(`Nexa/conversations/${encodeURIComponent(id)}/read`, {});
                await this.readRequest;
                if (this.disposed || seq !== this.threadSequence) return;
                this.items.forEach(item => {if (item.id === result.conversation.id) item.isUnread = false;}); this.drawList();
            }
        } catch (error) {
            if (seq === this.threadSequence && !this.disposed) {
                this.error(error);
                this.$el.find('.nc-thread').html(`<div class="nc-empty"><button class="btn btn-default nc-back" data-action="back" title="Back to conversations" aria-label="Back to conversations"><span class="fas fa-arrow-left" aria-hidden="true"></span></button><p>Conversation could not be opened.</p><a href="#Email/view/${this.escape(id)}">Open email</a></div>`);
            }
        }
    }
    drawThread() {
        const e = value => this.escape(value), data = this.thread, c = data.conversation;
        this.$el.find('.nc-thread').html(`<header class="nc-thread-heading"><button class="btn btn-default nc-back" data-action="back" title="Back to conversations" aria-label="Back to conversations"><span class="fas fa-arrow-left" aria-hidden="true"></span></button><h4>${e(c.name)}</h4>${data.canMarkRead ? '<button class="btn btn-default" data-action="unread" title="Mark unread" aria-label="Mark unread"><span class="fas fa-envelope" aria-hidden="true"></span></button>' : ''}<a class="btn btn-default" href="#Email/view/${e(c.id)}" title="Open email" aria-label="Open email"><span class="fas fa-external-link-alt" aria-hidden="true"></span></a></header>
            ${data.context ? `<div class="nc-context"><span>${e(data.context.type)}</span><a href="#${e(data.context.type)}/view/${e(data.context.id)}">${e(data.context.name)}</a></div>` : ''}
            <div class="nc-triage"><label>Status<select class="form-control nc-edit-status" ${!data.canEdit || !data.canAssign ? 'disabled' : ''}>${['Open','Pending','Closed'].map(s => `<option ${s === c.conversationStatus ? 'selected' : ''}>${s}</option>`).join('')}</select></label>
                <div class="nc-owner-control"><label for="nc-staff-search">Conversation owner</label>
                    <input id="nc-staff-search" class="form-control nc-staff-search" type="search" aria-label="Search staff" placeholder="Search staff" ${!data.canAssign || !data.canEdit ? 'disabled' : ''}>
                    <select class="form-control nc-edit-owner" aria-label="Conversation owner" ${!data.canAssign || !data.canEdit ? 'disabled' : ''}><option value="">Unassigned</option>${c.assignedUserId ? `<option selected value="${e(c.assignedUserId)}">${e(c.assignedUserName || 'Assigned staff member')}</option>` : ''}</select>
                </div>
            </div>
            <div class="nc-messages">${data.messages.map(m => `<article class="nc-message"><header><strong>${e(m.fromString || 'Email')}</strong><time>${e(this.displayDate(m.dateSent))}</time></header><div class="nc-message-body">${e(m.bodyPlain)}</div><footer>${m.canReply ? `<button class="btn btn-default" data-action="reply" data-id="${e(m.id)}"><span class="fas fa-reply" aria-hidden="true"></span> Reply</button>` : ''}<a href="#Email/view/${e(m.id)}">Open email</a></footer></article>`).join('')}</div>`);
        if (data.canAssign && data.canEdit) this.loadOwners('');
    }
    displayDate(value) {
        if (!value) return '';
        const date = new Date(value.replace(' ', 'T') + 'Z');
        return Number.isNaN(date.valueOf()) ? value : date.toLocaleString();
    }
    searchOwners() { clearTimeout(this.ownerTimer); ++this.ownerSequence; this.ownerTimer = setTimeout(() => this.loadOwners(this.$el.find('.nc-staff-search').val() || ''), 180); }
    async loadOwners(query) {
        const seq = ++this.ownerSequence, id = this.current;
        try {
            const result = await Espo.Ajax.getRequest('Nexa/conversation-owners', {q: query});
            if (this.disposed || seq !== this.ownerSequence || id !== this.current || !this.thread) return;
            const c = this.thread.conversation, items = result.list.slice();
            if (c.assignedUserId && !items.some(u => u.id === c.assignedUserId)) items.unshift({id:c.assignedUserId, name:c.assignedUserName || 'Assigned staff member'});
            this.$el.find('.nc-edit-owner').html('<option value="">Unassigned</option>' + items.map(u => `<option value="${this.escape(u.id)}">${this.escape(u.name)}</option>`).join('')).val(c.assignedUserId || '');
        } catch (error) { if (id === this.current && !this.disposed && seq === this.ownerSequence) this.error(error); }
    }
    async save() {
        if (!this.thread || this.saving) return;
        const c = this.thread.conversation, id = this.current, seq = this.threadSequence;
        ++this.ownerSequence; clearTimeout(this.ownerTimer);
        this.saving = true; this.$el.find('.nc-triage input,.nc-triage select').prop('disabled', true); this.error(null);
        try {
            const result = await Espo.Ajax.postRequest(`Nexa/conversations/${encodeURIComponent(c.id)}`, {
                conversationStatus: this.$el.find('.nc-edit-status').val(), assignedUserId: this.$el.find('.nc-edit-owner').val() || null,
                expectedStatus: c.conversationStatus, expectedOwnerId: c.assignedUserId,
                expectedVersion: c.versionNumber,
            });
            if (this.disposed || id !== this.current || seq !== this.threadSequence) return;
            this.thread = result; this.drawThread(); this.reset();
        } catch (error) { if (!this.disposed && id === this.current) { this.error(error); await this.loadThread(id); } }
        finally { this.saving = false; }
    }
    async markUnread() {
        if (!this.thread || this.markingUnread) return;
        const id = this.thread.conversation.id;
        const seq = ++this.threadSequence;
        this.markingUnread = true;
        const controls = this.$el.find('.nc-inbox :input:enabled, .nc-heading :input:enabled, [data-action="unread"]:enabled');
        controls.prop('disabled', true);
        try {
            // Preserve intent if automatic mark-read is still completing from opening the thread.
            if (this.readRequest) await this.readRequest;
            await Espo.Ajax.postRequest(`Nexa/conversations/${encodeURIComponent(id)}/read`, {isRead: false});
            if (this.disposed || seq !== this.threadSequence) return;
            this.current = null; this.thread = null; ++this.threadSequence;
            this.$el.find('.nc-thread').html('<div class="nc-empty">Select a conversation</div>');
            this.back(); this.reset();
        } catch (error) { if (!this.disposed && seq === this.threadSequence) this.error(error); }
        finally { this.markingUnread = false; controls.prop('disabled', false); }
    }
    async reply(event) {
        const id = event.currentTarget.dataset.id, seq = this.threadSequence;
        try {
            const model = await this.getModelFactory().create('Email'); model.id = id; await model.fetch();
            if (this.disposed || seq !== this.threadSequence) return;
            const attributes = new EmailHelper().getReplyAttributes(model);
            this.createView('compose', this.getMetadata().get('clientDefs.Email.modalViews.compose') || 'views/modals/compose-email', {attributes, focusForCreate:true}, view => {
                if (this.disposed) return;
                view.render(); this.listenTo(view, 'after:save', () => this.refresh());
            });
        } catch (error) { this.error(error); }
    }
});
