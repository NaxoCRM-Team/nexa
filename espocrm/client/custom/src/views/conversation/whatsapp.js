define(['custom:views/conversation/sms'], Base => class extends Base {
    template = 'custom:conversation/whatsapp';
    setup() {
        this.events={...this.events,'change .wa-template':'changeTemplate','input .wa-variable':'rememberSms'};
        super.setup();
    }
    async api(data) {
        const result=await Espo.Ajax.postRequest('Nexa/whatsapp/conversations',data);
        if(data.op==='list')this.canConfigure=result.canConfigure;
        return result;
    }
    drawList() {
        super.drawList();
        this.$el.find('.nc-list > .nc-empty').text('No WhatsApp conversations found');
    }
    drawThread() {
        super.drawThread();
        this.$el.find('.nc-messages').attr('aria-label','WhatsApp message history');
        this.$el.find('.chat-owner-search').attr('aria-label','Search WhatsApp staff');
    }
    drawOptInReview() {}
    drawPreference() {
        const state=this.thread.smsOutbox?.preference||this.thread.smsPreference||{};
        if(state.pending||state.blocked)this.$el.find('.nc-context').after(`<div class="sms-preference-status is-blocked" role="status"><strong>${state.pending?'WhatsApp preferences are being recovered.':'This recipient has opted out of WhatsApp from this sender.'}</strong></div>`);
    }
    rememberSms() {
        if(!this.current)return;
        const previous=this.smsDrafts.get(this.current)||{},variables={};
        this.$el.find('.wa-variable').each((_,input)=>{variables[input.dataset.key]=input.value;});
        const next={body:this.$el.find('.sms-body').val()||'',purposeId:this.$el.find('.sms-purpose').val()||'',contentSid:this.$el.find('.wa-template').val()||'',variables};
        const same=['body','purposeId','contentSid'].every(key=>previous[key]===next[key])&&JSON.stringify(previous.variables||{})===JSON.stringify(variables);
        this.smsDrafts.set(this.current,{...next,queue:previous.queue,...(same&&previous.requestKey?{requestKey:previous.requestKey}:{})});
    }
    changeTemplate() { this.rememberSms();this.drawComposer(); }
    drawComposer() {
        this.$el.find('.sms-compose,.sms-compose-unavailable,.sms-preference-status').remove();this.drawPreference();
        const data=this.thread.smsOutbox||{},e=value=>this.escape(value);
        if(!data.canCompose){this.$el.find('.nc-triage').after('<p class="sms-compose-unavailable" role="status">Link a readable Contact with a matching primary number and current WhatsApp consent to prepare messages.</p>');return;}
        const draft=this.smsDrafts.get(this.current)||{},templates=data.templates||[],selected=templates.find(t=>t.contentSid===draft.contentSid);
        this.$el.find('.nc-triage').after(`<form class="sms-compose"><label>Purpose<select class="form-control sms-purpose" required><option value="">Choose a purpose</option>${(data.purposes||[]).map(p=>`<option value="${e(p.id)}" ${p.id===draft.purposeId?'selected':''}>${e(p.name)}</option>`).join('')}</select></label><label>Message type<select class="form-control wa-template"><option value="">Customer-service reply</option>${templates.map(t=>`<option value="${e(t.contentSid)}" ${selected===t?'selected':''}>${e(t.name||t.contentSid)} - ${e(t.language)} - ${e(t.category)}</option>`).join('')}</select></label>${selected?`<p class="wa-template-preview">${e(selected.body)}</p>${selected.variableKeys.map(key=>`<label>Value ${e(key)}<input class="form-control wa-variable" data-key="${e(key)}" value="${e(draft.variables?.[key]||'')}" maxlength="500" required></label>`).join('')}`:''}<label ${selected?'hidden':''}>Message<textarea class="form-control sms-body" rows="3" maxlength="1600" ${selected?'':'required'}>${e(draft.body||'')}</textarea></label><div class="sms-compose-actions"><button class="btn btn-default" type="submit" data-queue="false"><span class="fas fa-save" aria-hidden="true"></span> Save draft</button><button class="btn btn-primary" type="submit" data-queue="true" ${data.dispatchEnabled?'':'disabled'}><span class="fas fa-paper-plane" aria-hidden="true"></span> Queue WhatsApp</button></div>${!data.dispatchEnabled?'<p role="status">Sending is not activated. Drafts remain held.</p>':''}<p class="sms-compose-error" role="alert" hidden></p></form>`);
    }
    async prepareSms(event) {
        event.preventDefault();if(this.saving)return;
        this.rememberSms();const id=this.current,seq=this.threadSequence,draft=this.smsDrafts.get(id),queue=event.originalEvent?.submitter?.dataset.queue==='true';
        if(draft.queue!==queue)delete draft.requestKey;draft.queue=queue;draft.requestKey||=crypto.randomUUID();this.saving=true;this.$el.find('.sms-compose button').prop('disabled',true);
        try{
            await this.api({op:'prepareSms',id,version:this.thread.conversation.version,requestKey:draft.requestKey,purposeId:draft.purposeId,body:draft.body,contentSid:draft.contentSid,variables:draft.variables,queue});
            if(this.disposed||seq!==this.threadSequence)return;
            this.smsDrafts.delete(id);this.thread.smsOutbox=await this.api({op:'outbox',id});
            if(this.disposed||seq!==this.threadSequence)return;
            this.drawMessages();this.drawComposer();Espo.Ui.success(queue?'WhatsApp queued':'WhatsApp draft saved');
        }catch(error){if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-compose-error').text(error?.responseJSON?.message||'Message could not be prepared. Check consent, session window and template approval.').prop('hidden',false);this.error(error);}}
        finally{this.saving=false;if(!this.disposed&&seq===this.threadSequence){this.$el.find('.sms-compose button').prop('disabled',false);this.$el.find('[data-queue="true"]').prop('disabled',!this.thread.smsOutbox?.dispatchEnabled);}}
    }
    drawMessages() {
        super.drawMessages();
        this.$el.find('.nc-message > header > strong').each((_,node)=>{node.textContent=node.textContent==='Incoming SMS'?'Incoming WhatsApp':'Outgoing WhatsApp';});
        this.$el.find('.nc-message > footer button').each((_,node)=>{
            const icon=node.querySelector('span');node.replaceChildren();if(icon)node.append(icon);
            node.append(document.createTextNode(node.dataset.action==='queueSms'?' Queue WhatsApp':' Cancel WhatsApp'));
        });
    }
});
