define(['view'], Base => class extends Base {
    templateContent='<section class="nexa-chat-settings"><header class="nc-heading"><h3>Website Chat Widgets</h3><a class="btn btn-default" href="#NexaConversations/chat" title="Back to Live Chat" aria-label="Back to Live Chat"><span class="fas fa-arrow-left" aria-hidden="true"></span></a></header><div class="nc-error" role="alert" hidden></div><div class="chat-widget-editor"></div></section>';
    events={'change .chat-widget-choice':'choose','submit .chat-widget-form':'save','submit .chat-bot-form':'saveBot','click [data-action="copyEmbed"]':'copy'};
    setup() {this.on('remove',()=>{this.disposed=true;});}
    api(data) {return Espo.Ajax.postRequest('Nexa/chat',data);}
    escape(value) {return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    async afterRender() {
        try {const result=await this.api({op:'widgets'}); if(this.disposed)return; this.items=result.list; this.draw();}
        catch(error) {this.error(error);}
    }
    error(error) {this.$el.find('.nc-error').prop('hidden',!error).text(error?.responseJSON?.message||error?.message||'Unable to save the widget.');}
    choose() {this.current=this.$el.find('.chat-widget-choice').val()||null; this.draw();}
    draw() {this.drawWidget();this.drawBot();this.$el.find('.chat-widget-editor').append('<p><a href="#NexaConversations/messaging"><span class="fas fa-plug" aria-hidden="true"></span> Messaging connections</a></p>');}
    drawWidget() {
        const e=v=>this.escape(v), item=this.items.find(w=>w.id===this.current)||{name:'Website support',title:'Chat with our team',welcome:'How can we help?',origins:[],color:'#147d70',position:'right',draggable:1,is_enabled:0,privacy_url:''};
        this.$el.find('.chat-widget-editor').html(`<label>Widget<select class="form-control chat-widget-choice"><option value="">New widget</option>${this.items.map(w=>`<option value="${e(w.id)}" ${w.id===this.current?'selected':''}>${e(w.name)}</option>`).join('')}</select></label><form class="chat-widget-form"><div class="chat-setting-grid"><label>Name<input class="form-control" name="name" value="${e(item.name)}" required maxlength="120"></label><label>Chat heading<input class="form-control" name="title" value="${e(item.title)}" required maxlength="120"></label><label class="chat-setting-wide">Welcome message<textarea class="form-control" name="welcome" maxlength="500" rows="2">${e(item.welcome)}</textarea></label><label class="chat-setting-wide">Approved website origins<textarea class="form-control" name="origins" rows="3" placeholder="https://company.example" required>${e(item.origins.join('\n'))}</textarea></label><label>Brand color<input name="color" type="color" value="${e(item.color)}"></label><label>Position<select name="position" class="form-control"><option ${item.position==='right'?'selected':''}>right</option><option ${item.position==='left'?'selected':''}>left</option></select></label><label class="chat-setting-wide">Privacy notice URL<input type="url" class="form-control" name="privacyUrl" value="${e(item.privacy_url)}" maxlength="1000"></label><label class="chat-setting-checkbox"><input type="checkbox" name="draggable" ${Number(item.draggable)?'checked':''}> Allow visitors to move the widget</label><label class="chat-setting-checkbox"><input type="checkbox" name="enabled" ${Number(item.is_enabled)?'checked':''}> Enabled</label></div><button class="btn btn-primary" type="submit"><span class="fas fa-save" aria-hidden="true"></span> Save widget</button></form>${item.embed?`<div class="chat-embed"><label for="chat-embed-code">Website embed code</label><textarea id="chat-embed-code" class="form-control" readonly rows="4">${e(item.embed)}</textarea><button class="btn btn-default" data-action="copyEmbed" title="Copy embed code" aria-label="Copy embed code"><span class="fas fa-copy" aria-hidden="true"></span></button><span class="chat-copy-status" role="status"></span></div>`:''}`);
    }
    async save(event) {
        event.preventDefault(); if(this.saving)return; this.error(null);
        const form=event.currentTarget, values=new FormData(form), old=this.items.find(w=>w.id===this.current);
        const input={op:'widgetSave',name:values.get('name'),title:values.get('title'),welcome:values.get('welcome'),origins:values.get('origins').split(/\r?\n/).map(v=>v.trim()).filter(Boolean),color:values.get('color'),position:values.get('position'),privacyUrl:values.get('privacyUrl'),draggable:values.has('draggable'),enabled:values.has('enabled')};
        if(old){input.id=old.id;input.version=Number(old.version_number);}
        this.saving=true; this.$el.find('button[type="submit"]').prop('disabled',true);
        try {const saved=await this.api(input), result=await this.api({op:'widgets'}); if(this.disposed)return; this.current=saved.id;this.items=result.list;this.draw(); Espo.Ui.success('Widget saved');}
        catch(error){if(!this.disposed)this.error(error);}
        finally{this.saving=false;this.$el.find('button[type="submit"]').prop('disabled',false);}
    }
    drawBot() {
        const item=this.items.find(w=>w.id===this.current);if(!item)return;
        let nodes=[];try{nodes=JSON.parse(item.bot_definition_json||'null')?.nodes||[];}catch{}
        const node=id=>nodes.find(n=>n.id===id),e=v=>this.escape(v);
        const fields=[['intro','Intro message',node('intro')?.body||'Welcome. How can we help?'],['question','Question',node('question')?.body||'Would you like to speak to our team?'],['staffLabel','Staff help option',node('question')?.options?.[0]?.label||'Speak to our team'],['doneLabel','Finish option',node('question')?.options?.[1]?.label||'Nothing else'],['handoff','Staff handoff message',node('support')?.body||'Your request has been sent to our team.'],['complete','Closing message',node('complete')?.body||'Thank you for visiting.']];
        this.$el.find('.chat-widget-editor').append(`<form class="chat-bot-form"><h4>Automated greeting and handoff</h4><label class="chat-setting-checkbox"><input type="checkbox" name="botEnabled" ${Number(item.bot_enabled)?'checked':''}> Enabled for new conversations</label><div class="chat-setting-grid">${fields.map(([name,label,value])=>`<label>${e(label)}<input name="${e(name)}" class="form-control" value="${e(value)}" maxlength="${name.endsWith('Label')?'80':'1000'}" required></label>`).join('')}</div><button class="btn btn-primary" type="submit"><span class="fas fa-save" aria-hidden="true"></span> Publish chat flow</button></form>`);
    }
    async saveBot(event) {
        event.preventDefault();if(this.saving)return;
        const item=this.items.find(w=>w.id===this.current);if(!item)return;
        const values=new FormData(event.currentTarget),get=name=>values.get(name);
        const definition={schemaVersion:1,entry:'intro',nodes:[
            {id:'intro',type:'message',body:get('intro'),next:'question'},
            {id:'question',type:'choice',body:get('question'),options:[{label:get('staffLabel'),next:'support'},{label:get('doneLabel'),next:'complete'}]},
            {id:'support',type:'handoff',body:get('handoff')},
            {id:'complete',type:'message',body:get('complete'),next:'done'},
            {id:'done',type:'end'},
        ]};
        this.saving=true;this.error(null);this.$el.find('button[type="submit"]').prop('disabled',true);
        try{await this.api({op:'botSave',id:item.id,version:Number(item.version_number),enabled:values.has('botEnabled'),definition});const result=await this.api({op:'widgets'});if(this.disposed)return;this.items=result.list;this.draw();Espo.Ui.success('Chat flow published');}
        catch(error){if(!this.disposed)this.error(error);}
        finally{this.saving=false;this.$el.find('button[type="submit"]').prop('disabled',false);}
    }
    async copy() {
        const text=this.$el.find('#chat-embed-code').val();
        try {await navigator.clipboard.writeText(text);this.$el.find('.chat-copy-status').text('Copied');}
        catch {this.$el.find('#chat-embed-code')[0]?.select();this.$el.find('.chat-copy-status').text('Clipboard unavailable');}
    }
});
