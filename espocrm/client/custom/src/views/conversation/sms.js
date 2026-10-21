define(['custom:views/conversation/chat'], Base => class extends Base {
    template = 'custom:conversation/sms';
    events = {
        'input .chat-search':'search','change .chat-filter':'reset','click [data-action="refresh"]':'refresh',
        'click [data-action="more"]':'more','click [data-action="open"]':'open','click [data-action="back"]':'back',
        'click [data-action="saveState"]':'saveState','input .chat-owner-search':'searchOwners','click [data-action="earlier"]':'earlier',
        'click [data-action="toggleContact"]':'toggleContact','input .sms-contact-search':'searchContacts',
        'change .sms-contact-choice':'enableContactLink','submit .sms-contact-form':'linkContact','click [data-action="unlinkContact"]':'unlinkContact',
        'submit .sms-compose':'prepareSms','input .sms-body':'rememberSms','change .sms-purpose':'rememberSms',
        'click [data-action="queueSms"]':'changeSms','click [data-action="cancelSms"]':'changeSms',
        'submit .sms-opt-in-review':'releaseSuppression','click [data-action="openIncomingMedia"]':'openIncomingMedia',
    };
    async api(data) { const result=await Espo.Ajax.postRequest('Nexa/messaging/conversations',data);if(data.op==='list')this.canConfigure=result.canConfigure;return result; }
    setup() { super.setup(); clearInterval(this.interval);this.contactSequence=0;this.smsDrafts=new Map();this.incomingMediaUrls=new Map();this.smsPoll=setInterval(()=>{if(this.current&&!this.saving&&!document.hidden){this.refreshOutbox();this.refreshIncomingMedia();}},15000);this.on('remove',()=>{clearTimeout(this.contactTimer);clearInterval(this.smsPoll);this.clearIncomingMedia();}); }
    clearIncomingMedia(){this.incomingMediaUrls.forEach(file=>URL.revokeObjectURL(file.url));this.incomingMediaUrls.clear();}
    async loadThread(id){this.clearIncomingMedia();return super.loadThread(id);}
    incomingMediaHtml(message){
        const e=value=>this.escape(value);
        if(!message.media?.length)return message.mediaCount?`<p role="status">${message.mediaError?'Attachments are unavailable.':'Attachments are being processed. Maximum 8 MB per file.'}</p>`:'';
        return message.media.map(file=>{
            const preview=this.incomingMediaUrls.get(file.id);
            const controls=file.status==='ready'?`<button class="btn btn-default" type="button" data-action="openIncomingMedia" data-id="${e(file.id)}"><span class="fas fa-paperclip" aria-hidden="true"></span> ${e(file.name||'Open attachment')}</button>${preview?`<a class="btn btn-default" href="${e(preview.url)}" download="${e(preview.name)}">Download</a>`:''}`:`<span role="status">${e(file.mimeType)} - ${file.status==='failed'?'unavailable':'processing'}</span>`;
            const image=preview?.mimeType.startsWith('image/')?`<div class="incoming-media-preview"><img src="${e(preview.url)}" alt="Received attachment"></div>`:'';
            return `<div class="incoming-channel-media" data-media-id="${e(file.id)}">${controls}${image}</div>`;
        }).join('');
    }
    async openIncomingMedia(event){
        const button=event.currentTarget,id=this.current,seq=this.threadSequence,mediaId=button.dataset.id;button.disabled=true;
        try{const file=await this.api({op:'media',id,mediaId});if(this.disposed||id!==this.current||seq!==this.threadSequence)return;
            const bytes=Uint8Array.from(atob(file.data),c=>c.charCodeAt(0));
            if(this.incomingMediaUrls.has(mediaId))URL.revokeObjectURL(this.incomingMediaUrls.get(mediaId).url);
            while(this.incomingMediaUrls.size>=8){const oldest=this.incomingMediaUrls.keys().next().value;URL.revokeObjectURL(this.incomingMediaUrls.get(oldest).url);this.incomingMediaUrls.delete(oldest);}
            const url=URL.createObjectURL(new Blob([bytes],{type:file.mimeType}));this.incomingMediaUrls.set(mediaId,{url,name:file.name,mimeType:file.mimeType});this.drawMessages();
            if(!file.mimeType.startsWith('image/')){const a=document.createElement('a');a.href=url;a.download=file.name;a.click();}
        }catch(error){this.error(error);}finally{button.disabled=false;}
    }
    async refreshIncomingMedia(){
        if(this.mediaPolling||!this.messages?.some(m=>m.mediaCount&&((!m.media?.length&&!m.mediaError)||m.media?.some(f=>['pending','downloading'].includes(f.status)))))return;
        const id=this.current,seq=this.threadSequence;this.mediaPolling=true;
        try{const result=await this.api({op:'thread',id});if(this.disposed||seq!==this.threadSequence)return;let changed=false;
            for(const message of this.messages){const updated=result.messages.find(m=>m.id===message.id);if(updated&&(JSON.stringify(updated.media)!==JSON.stringify(message.media)||updated.mediaError!==message.mediaError)){message.media=updated.media;message.mediaError=updated.mediaError;changed=true;}}
            if(changed){const top=this.$el.find('.nc-messages').scrollTop();this.drawMessages();this.$el.find('.nc-messages').scrollTop(top);}
        }catch(error){if(!this.disposed&&seq===this.threadSequence)this.error(error);}finally{this.mediaPolling=false;}
    }
    async loadList(append) {
        await super.loadList(append);
        if(!this.disposed)this.$el.find('.sms-settings').prop('hidden',!this.canConfigure);
    }
    drawList() {
        const e = value => this.escape(value);
        this.$el.find('.nc-list').html(this.items.length ? this.items.map(row=>`<button class="nc-conversation ${row.id===this.current?'is-selected':''}" data-action="open" data-id="${e(row.id)}" aria-pressed="${row.id===this.current}"><span class="nc-subject">${e(row.name)}</span><span class="nc-sender">${e(row.connectionName)}</span><span class="nc-state nc-state-${e(row.status.toLowerCase())}">${e(row.status)}</span><time>${e(this.displayDate(row.createdAt))}</time></button>`).join('') : '<div class="nc-empty">No SMS conversations found</div>');
        this.$el.find('.chat-more').prop('hidden',!this.cursor);
    }
    drawThread() {
        const data=this.thread,c=data.conversation,e=value=>this.escape(value);
        this.$el.find('.nc-thread').html(`<header class="nc-thread-heading"><button class="btn btn-default nc-back" data-action="back" title="Back to conversations" aria-label="Back to conversations"><span class="fas fa-arrow-left" aria-hidden="true"></span></button><h4>${e(c.name)}</h4></header><div class="nc-context">${e(c.connectionName)}</div><div class="nc-triage"><label>Status<select class="form-control chat-status" ${data.canAssign?'':'disabled'}>${['Open','Pending','Closed'].map(s=>`<option ${s===c.status?'selected':''}>${s}</option>`).join('')}</select></label><div class="nc-owner-control"><label for="sms-owner-search">Conversation owner</label><input id="sms-owner-search" type="search" class="form-control chat-owner-search" aria-label="Search SMS staff" placeholder="Search staff" ${data.canAssign?'':'disabled'}><select class="form-control chat-owner" aria-label="Conversation owner" ${data.canAssign?'':'disabled'}><option value="">Unassigned</option>${c.assignedUserId?`<option selected value="${e(c.assignedUserId)}">${e(c.ownerName||'Assigned staff')}</option>`:''}</select></div><button class="btn btn-default" data-action="saveState" title="Update conversation" aria-label="Update conversation" ${data.canAssign?'':'disabled'}><span class="fas fa-check" aria-hidden="true"></span></button></div><div class="nc-messages" tabindex="0" aria-label="SMS message history"></div>`);
        this.$el.find('.nc-messages').attr('role','region');
        this.drawMessages();this.drawContact();this.drawPreference();this.drawComposer(); if(data.canAssign)this.loadOwners('');
    }
    rememberSms(){if(!this.current)return;const previous=this.smsDrafts.get(this.current)||{},body=this.$el.find('.sms-body').val()||'',purposeId=this.$el.find('.sms-purpose').val()||'';if(body!==previous.body||purposeId!==previous.purposeId)delete previous.requestKey;this.smsDrafts.set(this.current,{...previous,body,purposeId});}
    drawComposer(){
        this.$el.find('.sms-compose,.sms-compose-unavailable').remove();const data=this.thread.smsOutbox||{},e=value=>this.escape(value);
        this.thread.smsPreference=data.preference||this.thread.smsPreference;this.$el.find('.sms-preference-status').remove();this.drawPreference();
        this.drawOptInReview();
        if(!data.canCompose){this.$el.find('.nc-triage').after(`<p class="sms-compose-unavailable" role="status">${e(data.reason||'SMS preparation is unavailable.')}</p>`);return;}
        const draft=this.smsDrafts.get(this.current)||{};
        this.$el.find('.nc-triage').after(`<form class="sms-compose"><label>Purpose<select class="form-control sms-purpose" required><option value="">Choose a purpose</option>${(data.purposes||[]).map(p=>`<option value="${e(p.id)}" ${p.id===draft.purposeId?'selected':''}>${e(p.name)}</option>`).join('')}</select></label><label>Message<textarea class="form-control sms-body" rows="3" maxlength="1600" required>${e(draft.body||'')}</textarea></label><div class="sms-compose-actions"><button class="btn btn-default" type="submit" data-queue="false"><span class="fas fa-save" aria-hidden="true"></span> Save draft</button><button class="btn btn-primary" type="submit" data-queue="true" ${data.dispatchEnabled?'':'disabled'}><span class="fas fa-paper-plane" aria-hidden="true"></span> Queue SMS</button></div>${data.reason?`<p role="status">${e(data.reason)}</p>`:''}<p class="sms-compose-error" role="alert" hidden></p></form>`);
    }
    drawOptInReview(){
        this.$el.find('.sms-opt-in-review,.sms-review-status').remove();const review=this.thread.smsOutbox?.optInReview,e=value=>this.escape(value);
        if(!review?.canReview)return;
        const pending=(review.purposes||[]).filter(p=>!p.released),released=(review.purposes||[]).filter(p=>p.released);
        const status=review.pending?'Preference recovery must finish before opt-in review.':released.length?`Suppression released for: ${released.map(p=>p.name).join(', ')}. Other purposes remain blocked.`:'Release requires a signed START followed by a new explicit consent grant. Do-not-contact remains enforced.';
        this.$el.find('.nc-context').after(`<section class="sms-review-status" role="status">${e(status)}</section>`);
        if(!pending.length)return;
        this.$el.find('.sms-review-status').after(`<form class="sms-opt-in-review"><h5>Review SMS opt-in</h5><label>Consent purpose<select class="form-control sms-review-purpose" required>${pending.map(p=>`<option value="${e(p.id)}">${e(p.name)}</option>`).join('')}</select></label><label>Review reason<textarea class="form-control sms-review-reason" required minlength="10" maxlength="1000" rows="2"></textarea></label><button type="submit" class="btn btn-default"><span class="fas fa-shield-alt" aria-hidden="true"></span> Release suppression</button><p class="sms-review-error" role="alert" hidden></p></form>`);
    }
    async releaseSuppression(event){
        event.preventDefault();if(this.saving)return;const id=this.current,seq=this.threadSequence,review=this.thread.smsOutbox?.optInReview;
        const purpose=review?.purposes.find(p=>p.id===this.$el.find('.sms-review-purpose').val());if(!purpose)return;
        this.saving=true;this.$el.find('.sms-opt-in-review button').prop('disabled',true);
        try{await this.api({op:'reviewSmsOptIn',id,purposeId:purpose.id,consentEventId:purpose.consentEventId,snapshot:review.snapshot,startReceiptId:review.startReceiptId,reason:this.$el.find('.sms-review-reason').val()});
            if(this.disposed||seq!==this.threadSequence)return;Espo.Ui.success('SMS suppression reviewed for this purpose');
        }catch(error){if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-review-error').text('Review could not be completed. Refresh and check current opt-in evidence, consent and restrictions.').prop('hidden',false);this.error(error);}}
        finally{this.saving=false;if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-opt-in-review button').prop('disabled',false);await this.refreshOutbox();}}
    }
    async refreshOutbox(){
        if(this.outboxLoading||!this.thread)return;this.outboxLoading=true;const id=this.current,seq=this.threadSequence;
        try{const data=await this.api({op:'outbox',id});if(this.disposed||id!==this.current||seq!==this.threadSequence)return;
            if(JSON.stringify(data)!==JSON.stringify(this.thread.smsOutbox)){
                const focused=this.$el.find('.sms-body').is(':focus'),position=focused?this.$el.find('.sms-body')[0].selectionStart:null;
                this.rememberSms();this.thread.smsOutbox=data;const history=this.$el.find('.nc-messages'),top=history.scrollTop();this.drawMessages();history.scrollTop(top);this.drawComposer();
                if(focused&&data.canCompose){const input=this.$el.find('.sms-body')[0];input.focus({preventScroll:true});input.setSelectionRange(position,position);}
            }
        }catch(error){if(!this.disposed&&seq===this.threadSequence)this.error(error);}finally{this.outboxLoading=false;}
    }
    async prepareSms(event){
        event.preventDefault();if(this.saving)return;this.rememberSms();const id=this.current,seq=this.threadSequence,draft=this.smsDrafts.get(id);
        const queue=event.originalEvent?.submitter?.dataset.queue==='true';if(draft.queue!==queue)delete draft.requestKey;draft.queue=queue;draft.requestKey=draft.requestKey||crypto.randomUUID();
        this.saving=true;this.$el.find('.sms-compose button').prop('disabled',true);
        try{await this.api({op:'prepareSms',id,version:this.thread.conversation.version,requestKey:draft.requestKey,purposeId:draft.purposeId,body:draft.body,queue});
            if(this.disposed||seq!==this.threadSequence)return;this.smsDrafts.delete(id);this.$el.find('.sms-body').val('');this.thread.smsOutbox=await this.api({op:'outbox',id});if(this.disposed||seq!==this.threadSequence)return;this.drawMessages();this.drawComposer();Espo.Ui.success(queue?'SMS queued':'SMS draft saved');
        }catch(error){if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-compose-error').text('SMS could not be prepared. Check current consent, recipient and connection settings, then refresh.').prop('hidden',false);this.error(error);}}
        finally{this.saving=false;if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-compose button').prop('disabled',false);this.$el.find('[data-queue="true"]').prop('disabled',!this.thread.smsOutbox?.dispatchEnabled);}}
    }
    async changeSms(event){
        if(this.saving)return;const button=event.currentTarget,id=this.current,seq=this.threadSequence;this.saving=true;
        try{await this.api({op:button.dataset.action==='queueSms'?'releaseSms':'cancelSms',id,outboxId:button.dataset.id,version:Number(button.dataset.version)});}
        catch(error){if(!this.disposed&&seq===this.threadSequence)this.error(error);}
        finally{this.saving=false;if(!this.disposed&&seq===this.threadSequence)await this.refreshOutbox();}
    }
    drawPreference() {
        const state=this.thread.smsPreference||{};
        const released=state.blocked&&(this.thread.smsOutbox?.purposes||[]).length>0;
        const message=state.pending?'SMS preferences are being recovered. Message preparation is blocked.':state.blocked?(released?'Historical SMS opt-out retained. Only reviewed purposes are available.':'SMS opted out. Suppression release requires administrator review.'):'';
        const review=state.optInReceived?(released?'Opt-in reviewed for available purposes. Current consent and channel restrictions remain enforced.':'Opt-in response received. Consent and suppression release require review.'):'';
        if(message||review)this.$el.find('.nc-context').after(`<div class="sms-preference-status ${state.blocked&&!released?'is-blocked':''}" role="status">${message?`<strong>${this.escape(message)}</strong>`:''}${review?`<p>${this.escape(review)}</p>`:''}</div>`);
    }
    drawContact() {
        ++this.contactSequence;clearTimeout(this.contactTimer);
        this.$el.find('.sms-contact-context').remove();
        const e=value=>this.escape(value),data=this.thread;
        if(!data.canReadContact)return;
        this.$el.find('.nc-context').after(`<section class="sms-contact-context" aria-label="Linked Contact"><div class="sms-contact-heading"><strong>Contact</strong>${data.contact?`<a href="#Contact/view/${e(data.contact.id)}">${e(data.contact.name)}</a>`:'<span>No linked Contact</span>'}${data.canLinkContact?'<button class="btn btn-default" data-action="toggleContact" title="Choose Contact" aria-label="Choose Contact"><span class="fas fa-link" aria-hidden="true"></span></button>':''}${data.canLinkContact&&data.contact?'<button class="btn btn-default" data-action="unlinkContact" title="Unlink Contact" aria-label="Unlink Contact"><span class="fas fa-unlink" aria-hidden="true"></span></button>':''}</div>${data.canLinkContact?'<form class="sms-contact-form" hidden><label>Search Contacts<input class="form-control sms-contact-search" type="search" maxlength="100" autocomplete="off"></label><label>Contact<select class="form-control sms-contact-choice" required><option value="">Choose a Contact</option></select></label><button class="btn btn-default sms-contact-submit" type="submit" disabled>Link Contact</button><span class="sms-contact-search-status" role="status"></span></form>':''}</section>`);
        // Re-rendering during a save must not expose controls whose clicks are ignored.
        if(this.saving)this.$el.find('.sms-contact-context :input').prop('disabled',true);
    }
    toggleContact() {
        if(this.saving)return;
        const form=this.$el.find('.sms-contact-form'),hidden=!form.prop('hidden');form.prop('hidden',hidden);
        if(!hidden)this.$el.find('.sms-contact-search').trigger('focus');
        else{++this.contactSequence;clearTimeout(this.contactTimer);}
    }
    searchContacts() {
        clearTimeout(this.contactTimer);const seq=++this.contactSequence,id=this.current;
        this.$el.find('.sms-contact-choice').html('<option value="">Choose a Contact</option>');this.enableContactLink();
        const q=this.$el.find('.sms-contact-search').val()||'';
        this.$el.find('.sms-contact-search-status').text('');if(q.trim().length<2)return;
        this.contactTimer=setTimeout(async()=>{
            try {
                const result=await this.api({op:'contacts',id,q});
                if(this.disposed||seq!==this.contactSequence||id!==this.current)return;
                const e=value=>this.escape(value);
                this.$el.find('.sms-contact-choice').append(result.list.map(row=>`<option value="${e(row.id)}">${e(row.name)}</option>`).join(''));
                this.$el.find('.sms-contact-search-status').text(result.list.length?`${result.list.length} Contacts`:'No matching Contacts');
            }catch(error){if(!this.disposed&&seq===this.contactSequence)this.error(error);}
        },180);
    }
    enableContactLink() {this.$el.find('.sms-contact-submit').prop('disabled',!this.$el.find('.sms-contact-choice').val()||Boolean(this.saving));}
    linkContact(event) {event.preventDefault();const id=this.$el.find('.sms-contact-choice').val();if(id)this.persistContact(id);}
    unlinkContact() {this.persistContact(null);}
    async persistContact(contactId) {
        if(this.saving||!this.thread?.canLinkContact)return;
        const id=this.current,seq=this.threadSequence,version=this.thread.conversation.version;this.saving=true;this.error(null);
        this.$el.find('.sms-contact-context :input').prop('disabled',true);
        try {
            const saved=await this.api({op:'linkContact',id,version,contactId});
            if(this.disposed||seq!==this.threadSequence)return;
            this.thread.contact=saved.contact;this.thread.conversation.version=saved.version;this.drawContact();await this.refreshOutbox();
        }catch(error){if(!this.disposed&&seq===this.threadSequence)this.error(error);}
        finally{this.saving=false;if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-contact-context :input').prop('disabled',false);this.enableContactLink();}}
    }
    drawMessages() {
        const e=value=>this.escape(value);
        const incoming=this.messages.map(message=>({date:message.createdAt,html:`<article class="nc-message"><header><strong>Incoming SMS</strong><time>${e(this.displayDate(message.createdAt))}</time></header><div class="nc-message-body">${e(message.body)}</div>${this.incomingMediaHtml(message)}</article>`}));
        const outgoing=(this.thread.smsOutbox?.outgoing||[]).map(message=>({date:message.createdAt,html:`<article class="nc-message sms-outgoing" data-outbox-id="${e(message.id)}"><header><strong>Outgoing SMS</strong><time>${e(this.displayDate(message.createdAt))}</time></header><div class="nc-message-body">${e(message.body)}</div><p class="sms-delivery-status" role="status">${e(message.deliveryStatus||message.status)}${message.status==='uncertain'?' - Awaiting provider confirmation. Do not resend.':''}${message.error==='quota_wait'?' - Waiting for workspace allowance.':''}${message.error==='policy_changed'?' - Blocked by current permissions or communication policy.':''}</p><footer>${message.canRelease?`<button class="btn btn-default" data-action="queueSms" data-id="${e(message.id)}" data-version="${message.version}"><span class="fas fa-paper-plane" aria-hidden="true"></span> Queue SMS</button>`:''}${message.canCancel?`<button class="btn btn-default" data-action="cancelSms" data-id="${e(message.id)}" data-version="${message.version}"><span class="fas fa-times" aria-hidden="true"></span> Cancel SMS</button>`:''}</footer></article>`}));
        this.$el.find('.nc-messages').html(`${this.thread.hasMore?'<button class="btn btn-default" data-action="earlier">Earlier messages</button>':''}`+[...incoming,...outgoing].sort((a,b)=>a.date.localeCompare(b.date)).map(row=>row.html).join(''));
    }
    async earlier() {
        if(this.loadingEarlier||!this.current||!this.messages.length)return;
        const seq=this.threadSequence,id=this.current;this.loadingEarlier=true;
        try {
            const data=await this.api({op:'thread',id,before:this.messages[0].id});
            if(this.disposed||seq!==this.threadSequence)return;
            const known=new Set(this.messages.map(row=>row.id));this.messages=[...data.messages.filter(row=>!known.has(row.id)),...this.messages];
            this.thread.hasMore=data.hasMore;this.drawMessages();
        }catch(error){if(!this.disposed&&seq===this.threadSequence)this.error(error);}
        finally{this.loadingEarlier=false;}
    }
    async saveState() {
        if(this.saving||!this.thread?.canAssign)return;
        const id=this.current,seq=this.threadSequence;this.saving=true;this.error(null);
        const controls=this.$el.find('.nc-triage :input');
        const input={op:'update',id,version:this.thread.conversation.version,status:this.$el.find('.chat-status').val(),ownerId:this.$el.find('.chat-owner').val()||null};
        const ownerName=this.$el.find('.chat-owner option:selected').text();
        controls.prop('disabled',true);
        try {
            const saved=await this.api(input);
            if(!this.disposed&&seq===this.threadSequence){
                if(saved.accessLost){this.current=null;this.thread=null;this.messages=[];this.$el.find('.nc-thread').html('<div class="nc-empty">Conversation is no longer available.</div>');this.back();}
                else{this.thread.conversation={...this.thread.conversation,...saved,ownerName:input.ownerId?ownerName:null};this.drawThread();}
                this.loadList(false);
            }
        }
        catch(error){if(!this.disposed&&seq===this.threadSequence)this.error(error);}
        finally{this.saving=false;if(!this.disposed&&seq===this.threadSequence)controls.prop('disabled',false);}
    }
});
