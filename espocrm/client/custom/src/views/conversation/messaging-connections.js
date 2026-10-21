define(['custom:views/conversation/widgets'], Base => class extends Base {
    templateContent='<section class="nexa-chat-settings"><header class="nc-heading"><h3>Messaging Connections</h3><a class="btn btn-default" href="#NexaConversations/widgets" title="Back to widget settings" aria-label="Back to widget settings"><span class="fas fa-arrow-left" aria-hidden="true"></span></a></header><div class="nc-error" role="alert" hidden></div><div class="messaging-editor"></div></section>';
    events={'change .messaging-choice':'choose','submit .messaging-form':'save','click [data-action="verifyConnection"]':'verifyConnection','click [data-action="activateSms"]':'activateSms','submit .whatsapp-template-form':'inspectTemplate'};
    api(data){return Espo.Ajax.postRequest('Nexa/messaging/connections',data);}
    async afterRender(){try{const result=await this.api({op:'list'});if(this.disposed)return;this.activationAvailable=result.activationAvailable;this.whatsappActivationAvailable=result.whatsappActivationAvailable;this.items=result.list;this.draw();}catch(error){this.error(error);}}
    choose(){this.current=this.$el.find('.messaging-choice').val()||null;this.error(null);this.draw();}
    draw(){
        if(!this.$el.find('.messaging-inbox-link').length)this.$el.find('.nc-heading').append('<a class="btn btn-default messaging-inbox-link" href="#NexaConversations/messages"><span class="fas fa-comment-alt" aria-hidden="true"></span> Incoming messages</a>');
        this.drawSettings();const item=this.items.find(row=>row.id===this.current);
        if(item?.status==='verified')this.$el.find('[role="status"]').html(`<span class="fas fa-check-circle" aria-hidden="true"></span> ${item.channel==='whatsapp'?'WhatsApp':'SMS'} sender verified`);
        if(item?.channel==='whatsapp'&&item.status!=='disabled')this.$el.find('.messaging-editor').append(`<label class="whatsapp-sender-proof">WhatsApp Sender SID<input class="form-control whatsapp-sender-sid" value="${this.escape(item.provider_resource_sid||'')}" placeholder="XE..." pattern="XE[0-9a-fA-F]{32}" maxlength="34"></label>`);
        if(item&&item.status!=='disabled')this.$el.find('.messaging-editor').append(`<button class="btn btn-default" data-action="verifyConnection"><span class="fas fa-shield-alt" aria-hidden="true"></span> ${item.status==='verified'?'Recheck':'Verify'} ${item.channel==='whatsapp'?'WhatsApp':'SMS'} sender</button>`);
        if(item?.channel==='whatsapp'&&item.status==='verified')this.$el.find('.messaging-editor').append('<form class="whatsapp-template-form"><h4>Approved WhatsApp template</h4><label>Content SID<input class="form-control whatsapp-content-sid" pattern="HX[0-9a-fA-F]{32}" maxlength="34" required placeholder="HX..."></label><button type="submit" class="btn btn-default"><span class="fas fa-search" aria-hidden="true"></span> Check template approval</button><div class="whatsapp-template-preview" role="status"></div></form>');
        if(item&&['sms','whatsapp'].includes(item.channel)&&item.status==='verified'){
            const available=item.channel==='whatsapp'?this.whatsappActivationAvailable:this.activationAvailable,label=item.channel==='whatsapp'?'WhatsApp':'SMS';
            this.$el.find('.messaging-editor').append(`<div class="sms-activation"><label><input type="checkbox" class="sms-dispatch-enabled" ${Number(item.dispatch_enabled)?'checked':''} ${available||Number(item.dispatch_enabled)?'':'disabled'}> Enable ${label} sending</label><button class="btn btn-default" data-action="activateSms" ${available||Number(item.dispatch_enabled)?'':'disabled'}><span class="fas fa-save" aria-hidden="true"></span> Save sending setting</button>${available?'':'<p>Deployment sending approval is not enabled.</p>'}</div>`);
        }
    }
    drawSettings(){
        const e=v=>this.escape(v),item=this.items.find(row=>row.id===this.current),value=item||{name:'',channel:'sms',account_sid:'',sender:'',status:'unverified'};
        this.$el.find('.messaging-editor').html(`<label>Connection<select class="form-control messaging-choice"><option value="">New connection</option>${this.items.map(row=>`<option value="${e(row.id)}" ${row.id===this.current?'selected':''}>${e(row.name)}</option>`).join('')}</select></label><p role="status"><span class="fas fa-shield-alt" aria-hidden="true"></span> ${value.status==='disabled'?'Disabled':'Not verified'}</p><form class="messaging-form"><div class="chat-setting-grid"><label>Name<input name="name" class="form-control" value="${e(value.name)}" maxlength="120" required></label><label>Provider<input class="form-control" value="Twilio" readonly></label><label>Channel<select name="channel" class="form-control" ${item?'disabled':''}><option value="sms" ${value.channel==='sms'?'selected':''}>SMS</option><option value="whatsapp" ${value.channel==='whatsapp'?'selected':''}>WhatsApp</option></select></label><label>Account SID<input name="accountSid" class="form-control" value="${e(value.account_sid)}" pattern="AC[0-9a-fA-F]{32}" required ${item?'readonly':''}></label><label>Sender<input name="sender" class="form-control" value="${e(value.sender)}" maxlength="40" placeholder="+14155550100" required ${item?'readonly':''}></label><label>Auth token<input name="secret" type="password" autocomplete="new-password" class="form-control" maxlength="32" pattern="[0-9a-fA-F]{32}" ${item?'':'required'} placeholder="${item?'Leave unchanged':''}"></label><label class="chat-setting-checkbox"><input type="checkbox" name="disabled" ${value.status==='disabled'?'checked':''}> Disabled</label></div><button type="submit" class="btn btn-primary"><span class="fas fa-save" aria-hidden="true"></span> Save connection</button></form>`);
    }
    async save(event){
        event.preventDefault();if(this.saving)return;this.error(null);
        const values=new FormData(event.currentTarget),old=this.items.find(row=>row.id===this.current);
        const input={op:'save',name:values.get('name'),accountSid:values.get('accountSid'),channel:old?.channel||values.get('channel'),sender:values.get('sender'),disabled:values.has('disabled')};
        if(values.get('secret'))input.secret=values.get('secret');
        if(old){input.id=old.id;input.version=Number(old.version_number);}
        this.saving=true;this.$el.find('button[type="submit"]').prop('disabled',true);
        try{const saved=await this.api(input),result=await this.api({op:'list'});if(this.disposed)return;this.current=saved.id;this.items=result.list;this.draw();Espo.Ui.success('Connection saved');}
        catch(error){if(!this.disposed)this.error(error);}
        finally{this.saving=false;this.$el.find('button[type="submit"]').prop('disabled',false);}
    }
    async verifyConnection(){
        if(this.saving)return;const item=this.items.find(row=>row.id===this.current);if(!item)return;
        this.saving=true;this.error(null);const enabledButtons=this.$el.find('button:not(:disabled)');enabledButtons.prop('disabled',true);
        this.$el.find('[data-action="verifyConnection"]').attr('aria-busy','true').find('.fas').attr('class','fas fa-spinner fa-spin');
        try{await this.api({op:'verify',id:item.id,version:Number(item.version_number),...(item.channel==='whatsapp'?{senderSid:this.$el.find('.whatsapp-sender-sid').val()}: {})});const result=await this.api({op:'list'});if(this.disposed)return;this.items=result.list;this.draw();Espo.Ui.success(`${item.channel==='whatsapp'?'WhatsApp':'SMS'} sender verified`);}
        catch(error){if(!this.disposed){try{const result=await this.api({op:'list'});if(!this.disposed){this.items=result.list;this.draw();}}catch{}this.error(error);}}
        finally{this.saving=false;enabledButtons.prop('disabled',false);this.$el.find('[data-action="verifyConnection"]').attr('aria-busy','false').find('.fas').attr('class','fas fa-shield-alt');}
    }
    async activateSms(){
        if(this.saving)return;const item=this.items.find(row=>row.id===this.current);if(!item)return;this.saving=true;this.error(null);
        try{await this.api({op:'activate',id:item.id,version:Number(item.version_number),enabled:this.$el.find('.sms-dispatch-enabled').prop('checked')});const result=await this.api({op:'list'});if(this.disposed)return;this.items=result.list;this.activationAvailable=result.activationAvailable;this.whatsappActivationAvailable=result.whatsappActivationAvailable;this.draw();Espo.Ui.success(`${item.channel==='whatsapp'?'WhatsApp':'SMS'} sending setting updated`);}
        catch(error){if(!this.disposed)this.error(error);}finally{this.saving=false;}
    }
    async inspectTemplate(event){
        event.preventDefault();if(this.saving)return;const item=this.items.find(row=>row.id===this.current);if(!item)return;this.saving=true;this.error(null);
        this.$el.find('.whatsapp-template-form button').prop('disabled',true);
        try{const result=await this.api({op:'inspectWhatsAppTemplate',id:item.id,version:Number(item.version_number),contentSid:this.$el.find('.whatsapp-content-sid').val()});if(this.disposed||this.current!==item.id)return;const e=v=>this.escape(v);this.$el.find('.whatsapp-template-preview').html(`<p><strong>${e(result.name)}</strong> &middot; ${e(result.language)} &middot; ${e(result.category)}</p><p>${e(result.body)}</p>`);}
        catch(error){if(!this.disposed)this.error(error);}finally{this.saving=false;this.$el.find('.whatsapp-template-form button').prop('disabled',false);}
    }
});
