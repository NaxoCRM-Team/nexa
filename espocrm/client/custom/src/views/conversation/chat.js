define(['custom:views/conversation/workspace'], Base => class extends Base {
    template = 'custom:conversation/chat';
    events = {
        'input .chat-search': 'search', 'change .chat-filter': 'reset', 'click [data-action="refresh"]': 'refresh',
        'click [data-action="more"]': 'more', 'click [data-action="open"]': 'open', 'click [data-action="back"]': 'back',
        'submit .chat-compose': 'send', 'click [data-action="saveState"]': 'saveState', 'input .chat-owner-search': 'searchOwners',
        'click [data-action="earlier"]': 'earlier', 'input .chat-reply': 'cacheDraft',
        'change .chat-owner': 'markTriageDirty', 'change .chat-status': 'markTriageDirty',
        'click [data-action="deleteChat"]': 'deleteChat',
    };
    setup() {
        super.setup(); this.drafts={}; this.interval = setInterval(() => this.poll(), 6000);
        this.on('remove', () => clearInterval(this.interval));
    }
    api(data) { return Espo.Ajax.postRequest('Nexa/chat', data); }
    async loadList(append) {
        const seq = ++this.listSequence; this.loading = true;
        this.$el.find('.nc-list').attr('aria-busy','true');
        try {
            const result = await this.api({op:'list', q:this.$el.find('.chat-search').val() || '', status:this.$el.find('.chat-filter').val() || '', before:append ? this.cursor : ''});
            if (this.disposed || seq !== this.listSequence) return;
            this.items = append ? [...this.items,...result.list] : result.list; this.cursor = result.nextCursor;
            this.$el.find('.chat-settings').prop('hidden',!result.canConfigure); this.drawList();
            if (!result.list.length && this.cursor) return this.loadList(true);
        } catch(error) { if (!this.disposed && seq === this.listSequence) {this.error(error); if([401,403].includes(error.status))this.accessDenied=true;} }
        finally { if (seq===this.listSequence) {this.loading=false; this.$el.find('.nc-list').attr('aria-busy','false');} }
    }
    drawList() {
        const e = value => this.escape(value), scroll=this.$el.find('.nc-list').scrollTop();
        this.$el.find('.nc-list').html(this.items.length ? this.items.map(item=>`<button class="nc-conversation ${item.id===this.current?'is-selected':''}" data-action="open" data-id="${e(item.id)}"><span class="nc-subject">${e(item.name || 'Website visitor')}</span><span class="nc-state nc-state-${e(item.status.toLowerCase())}">${e(item.status)}</span><time>${e(this.displayDate(item.createdAt))}</time></button>`).join('') : '<div class="nc-empty">No chats found</div>');
        this.$el.find('.chat-more').prop('hidden',!this.cursor);
        this.items.forEach(item=>{
            if(item.handoff?.requestedAt&&!item.handoff?.answeredAt)this.$el.find('.nc-conversation').filter((i,el)=>el.dataset.id===item.id).find('.nc-subject').append(' - Staff help requested');
        });
        this.$el.find('.nc-list').scrollTop(scroll);
    }
    open(event) { this.loadThread(event.currentTarget.dataset.id); }
    async loadThread(id) {
        const seq=++this.threadSequence; ++this.ownerSequence; this.current=id; this.thread=null; this.messages=[];
        this.drawList(); this.$el.find('.nexa-conversations').addClass('nc-detail-open'); this.$el.find('.nc-thread').html('<div class="nc-empty" role="status">Loading chat...</div>');
        try { const data=await this.api({op:'thread',id}); if (this.disposed||seq!==this.threadSequence) return;
            this.thread=data; this.messages=data.messages; this.drawThread();
        } catch(error) { if (!this.disposed&&seq===this.threadSequence) {this.error(error); this.$el.find('.nc-thread').html('<div class="nc-empty">Chat could not be opened.</div>');} }
    }
    drawThread() {
        this.triageDirty=false;
        const e=value=>this.escape(value), data=this.thread, c=data.conversation;
        this.$el.find('.nc-thread').html(`<header class="nc-thread-heading"><button class="btn btn-default nc-back" data-action="back" aria-label="Back to conversations" title="Back to conversations"><span class="fas fa-arrow-left" aria-hidden="true"></span></button><h4>${e(c.name || 'Website visitor')}</h4></header>${data.contact?`<div class="nc-context"><span>Verified contact</span><a href="#Contact/view/${e(data.contact.id)}">${e(data.contact.name)}</a></div>`:''}<div class="nc-triage"><label>Status<select class="form-control chat-status" ${data.canAssign?'':'disabled'}>${['Open','Pending','Closed'].map(s=>`<option ${s===c.status?'selected':''}>${s}</option>`).join('')}</select></label><div class="nc-owner-control"><label for="chat-owner-search">Conversation owner</label><input id="chat-owner-search" class="form-control chat-owner-search" type="search" aria-label="Search chat staff" placeholder="Search staff" ${data.canAssign?'':'disabled'}><select class="form-control chat-owner" aria-label="Chat owner" ${data.canAssign?'':'disabled'}><option value="">Unassigned</option>${c.assignedUserId?`<option selected value="${e(c.assignedUserId)}">${e(c.ownerName || 'Assigned staff')}</option>`:''}</select></div><button class="btn btn-default" data-action="saveState" title="Update chat state" aria-label="Update chat state" ${data.canAssign?'':'disabled'}><span class="fas fa-check" aria-hidden="true"></span></button></div><div class="nc-messages" aria-live="polite"></div><form class="chat-compose"><label for="chat-reply">Reply</label><textarea id="chat-reply" class="form-control chat-reply" maxlength="4000" rows="3" required ${data.canReply?'':'disabled'}></textarea><button class="btn btn-primary" type="submit" ${data.canReply?'':'disabled'}><span class="fas fa-paper-plane" aria-hidden="true"></span> Send</button></form>`);
        this.$el.find('.chat-reply').val(this.drafts[c.id]||'');
        if(data.canDelete)this.$el.find('.nc-thread-heading').append('<button class="btn btn-default" type="button" data-action="deleteChat" title="Delete chat" aria-label="Delete chat"><span class="fas fa-trash-alt" aria-hidden="true"></span></button>');
        this.$el.find('.nc-thread-heading').after('<div class="nc-context chat-handoff-status" role="status" hidden></div>');
        this.drawHandoff(c.handoff);
        this.$el.find('.nc-messages').attr({tabindex:0,role:'log','aria-label':'Conversation messages'});
        if(data.communicationBlocked)this.$el.find('.chat-compose').prepend('<p class="chat-restriction" role="status">Live-chat replies are restricted for this contact.</p>');
        else if(!data.sessionActive)this.$el.find('.chat-compose').prepend('<p class="chat-restriction" role="status">The visitor session has ended.</p>');
        this.drawMessages(); if (data.canAssign) this.loadOwners('');
    }
    drawMessages() {
        const e=value=>this.escape(value);
        this.$el.find('.nc-messages').html(`${this.thread.hasMore?'<button class="btn btn-default" data-action="earlier">Earlier messages</button>':''}`+this.messages.map(m=>`<article class="nc-message chat-message-${e(m.author)}"><header><strong>${e(m.authorLabel||(m.author==='bot'?'Automated assistant':m.author==='staff'?'Support':'Visitor'))}</strong><time>${e(this.displayDate(m.createdAt))}</time></header><div class="nc-message-body">${e(m.body)}</div></article>`).join(''));
    }
    drawHandoff(state) {
        this.$el.find('.chat-handoff-status').prop('hidden',!state?.requestedAt).text(state?.answeredAt?'Staff help request answered':state?.state==='assigned'?'Agent assigned; awaiting reply':'Waiting for an available agent');
    }
    async poll() {
        if (document.hidden||this.disposed||this.polling||this.accessDenied) return;
        if(!this.loading&&!this.cursor)this.loadList(false);
        if(!this.current||!this.thread)return;
        const id=this.current, seq=this.threadSequence; this.polling=true;
        try {
            const data=await this.api({op:'thread',id,after:this.messages.at(-1)?.id || ''});
            if (this.disposed||id!==this.current||seq!==this.threadSequence) return;
            const known=new Set(this.messages.map(m=>m.id)), incoming=data.messages.filter(m=>!known.has(m.id));
            this.thread.canReply=data.canReply;
            if(!this.triageDirty&&!this.saving){
                this.thread.conversation=data.conversation;
                this.thread.canAssign=data.canAssign;
                this.$el.find('.chat-status').val(data.conversation.status);
                const owners=this.$el.find('.chat-owner'),ownerId=data.conversation.assignedUserId||'';
                if(ownerId&&!owners.find('option').toArray().some(option=>option.value===ownerId))owners.append(new Option(data.conversation.ownerName||'Assigned staff',ownerId));
                owners.val(ownerId);this.$el.find('.nc-triage :input').prop('disabled',!data.canAssign);
            }
            this.drawHandoff(data.conversation.handoff);
            if(!this.sending)this.$el.find('.chat-compose :input').prop('disabled',!data.canReply);
            this.messages.push(...incoming); if (this.messages.length>1000) this.messages=this.messages.slice(-1000);
            if (incoming.length) this.drawMessages();
            // Do not overwrite pending status/owner edits or a draft during polling.
        } catch(error) { if (!this.disposed&&seq===this.threadSequence) {this.error(error); if([401,403,404].includes(error.status)){this.thread=null;this.current=null;this.messages=[];this.$el.find('.nc-thread').html('<div class="nc-empty">Chat is no longer available.</div>');}} }
        finally {this.polling=false;}
    }
    async send(event) {
        event.preventDefault(); if (this.sending||!this.thread?.canReply) return;
        const input=this.$el.find('.chat-reply'), body=input.val().trim(), id=this.current;
        if (!body) return; const seq=this.threadSequence;
        if (!this.pending || this.pending.body!==body || this.pending.id!==id) this.pending={op:'send',id,body,nonce:crypto.randomUUID()};
        this.sending=true; this.$el.find('.chat-compose :input').prop('disabled',true); this.error(null);
        try {await this.api(this.pending); delete this.drafts[id]; if (this.disposed||seq!==this.threadSequence) return; this.pending=null; input.val(''); await this.poll();}
        catch(error) {if (!this.disposed&&seq===this.threadSequence) this.error(error);}
        finally {this.sending=false; if (!this.disposed&&seq===this.threadSequence) this.$el.find('.chat-compose :input').prop('disabled',false);}
    }
    cacheDraft() {if(this.current)this.drafts[this.current]=this.$el.find('.chat-reply').val();}
    markTriageDirty() {this.triageDirty=true;}
    deleteChat(){const id=this.current,version=this.thread?.conversation.version;if(!this.thread?.canDelete)return;this.createView('deleteChat','custom:views/conversation/privacy-confirmation',{},view=>{view.render();this.listenToOnce(view,'confirm',async()=>{try{await Espo.Ajax.postRequest('Nexa/chat-privacy',{op:'delete',id,version});delete this.drafts[id];if(this.pending?.id===id)this.pending=null;if(this.current===id){this.current=null;this.thread=null;this.messages=[];this.$el.find('.nc-thread').html('<div class="nc-empty">Select a conversation</div>');}this.reset();Espo.Ui.success('Chat deleted');}catch(error){this.error(error);}});});}
    searchOwners() {clearTimeout(this.ownerTimer); ++this.ownerSequence; this.ownerTimer=setTimeout(()=>this.loadOwners(this.$el.find('.chat-owner-search').val()||''),180);}
    async loadOwners(q) {
        const seq=++this.ownerSequence, id=this.current;
        try {const data=await this.api({op:'owners',q}); if (this.disposed||seq!==this.ownerSequence||id!==this.current||!this.thread) return;
            const c=this.thread.conversation, e=v=>this.escape(v), list=data.list;
            if (c.assignedUserId&&!list.some(u=>u.id===c.assignedUserId)) list.unshift({id:c.assignedUserId,name:c.ownerName||'Assigned staff'});
            this.$el.find('.chat-owner').html('<option value="">Unassigned</option>'+list.map(u=>`<option value="${e(u.id)}">${e(u.name)}</option>`).join('')).val(c.assignedUserId||'');
        } catch(error) {if (!this.disposed&&seq===this.ownerSequence) this.error(error);}
    }
    async saveState() {
        if (this.saving||!this.thread?.canAssign) return; const id=this.current, seq=this.threadSequence;
        this.saving=true; this.error(null);
        try {await this.api({op:'update',id,version:this.thread.conversation.version,status:this.$el.find('.chat-status').val(),ownerId:this.$el.find('.chat-owner').val()||null}); if (!this.disposed&&seq===this.threadSequence) {await this.loadThread(id); this.reset();}}
        catch(error) {if (!this.disposed&&seq===this.threadSequence) this.error(error);}
        finally {this.saving=false;}
    }
    async earlier() {
        const id=this.current, seq=this.threadSequence;
        try {const data=await this.api({op:'thread',id,before:this.messages[0]?.id||''}); if (this.disposed||seq!==this.threadSequence) return;
            this.messages=[...data.messages,...this.messages]; this.thread.hasMore=data.hasMore; this.drawMessages();
        } catch(error) {if (!this.disposed&&seq===this.threadSequence) this.error(error);}
    }
    refresh() {this.reset(); if (this.current) this.loadThread(this.current);}
});
