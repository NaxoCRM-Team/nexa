/* Nexa website chat: no CRM credentials, tracking cookies or HTML message rendering. */
(() => {
    'use strict';
    const script=document.currentScript, api=script?.dataset.nexaChatApi;
    if(!api||!window.crypto?.getRandomValues)return;
    let endpoint;try{endpoint=new URL(api);if(endpoint.protocol!=='https:'&&!(['localhost','127.0.0.1','[::1]'].includes(endpoint.hostname)&&endpoint.protocol==='http:'))return;}catch{return;}
    const host=document.createElement('div');host.setAttribute('data-nexa-chat-widget','');document.body.append(host);
    const shadow=host.attachShadow({mode:'open'}), style=document.createElement('style');
    style.textContent=`:host{all:initial;position:fixed;z-index:2147483000;bottom:24px;right:24px;font:14px Arial,sans-serif;color:#18342d;letter-spacing:0}*{box-sizing:border-box}button,input,textarea{font:inherit}button{cursor:pointer}button:focus-visible,textarea:focus-visible{outline:2px solid #126bce;outline-offset:3px}.launcher{width:64px;height:52px;border:0;border-radius:26px;background:var(--brand,#147d70);color:#fff;font-weight:bold;touch-action:none;box-shadow:0 3px 14px #0002}.panel{position:fixed;width:min(370px,calc(100vw - 24px));height:min(540px,calc(100dvh - 90px));background:#fff;border:1px solid #cbd8d2;border-radius:8px;box-shadow:0 8px 32px #0003;display:flex;flex-direction:column;overflow:hidden}.panel[hidden]{display:none}.heading{display:flex;align-items:center;gap:12px;padding:16px;border-bottom:1px solid #dce5df;background:#f3f7f5}.heading strong{flex:1;overflow-wrap:anywhere;font-size:16px}.close{border:0;background:transparent;color:#344c43;padding:6px}.messages{padding:16px;overflow:auto;flex:1;min-height:0;scrollbar-color:#829d91 #edf3ef}.message{padding:10px 12px;margin:10px 0;border-radius:6px;background:#f0f4f2;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.5}.message.visitor{background:#e6f1f8}.message small{display:block;font-size:11px;color:#4e6559;margin-top:6px}.welcome{line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}.error{padding:10px 14px;color:#9b2222;background:#fff0ee;font-size:13px}.error[hidden]{display:none}form{padding:12px;border-top:1px solid #dce5df;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}label{grid-column:1/-1;font-size:12px;font-weight:bold}textarea{width:100%;border:1px solid #879f92;border-radius:4px;padding:8px;resize:none;min-height:64px;color:#18342d;background:#fff}form button{background:var(--brand,#147d70);color:#fff;border:0;border-radius:4px;padding:0 12px}button:disabled{opacity:.55;cursor:wait}.privacy{padding:0 12px 10px;font-size:12px}.privacy a{color:#12634d}.earlier{display:block;margin:auto;border:1px solid #b3c7ba;background:#fff;border-radius:4px;padding:6px 10px}`;
    style.textContent+='.launcher,form button{color:var(--brand-text,#fff)}';
    function brand(value){host.style.setProperty('--brand',value);const rgb=value.slice(1).match(/../g).map(c=>parseInt(c,16)/255).map(c=>c<=.04045?c/12.92:((c+.055)/1.055)**2.4);host.style.setProperty('--brand-text',rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722>.179?'#000':'#fff');}
    shadow.append(style);
    const launcher=document.createElement('button');launcher.className='launcher';launcher.textContent='Chat';launcher.setAttribute('aria-label','Open chat');launcher.setAttribute('aria-expanded','false');launcher.title='Chat';
    const panel=document.createElement('section');panel.className='panel';panel.hidden=true;panel.setAttribute('role','dialog');panel.setAttribute('aria-label','Website chat');
    panel.innerHTML='<header class="heading"><strong>Chat</strong><button class="close" type="button" aria-label="Close chat">Close</button></header><div class="messages" role="log" aria-live="polite"></div><div class="error" role="alert" hidden></div><form><label for="nexa-chat-text">Message</label><textarea id="nexa-chat-text" maxlength="4000" rows="2" required></textarea><button type="submit">Send</button></form><div class="privacy" hidden></div>';
    shadow.append(launcher,panel);
    const messages=panel.querySelector('.messages'),form=panel.querySelector('form'),input=panel.querySelector('textarea'),error=panel.querySelector('.error');
    const handoff=document.createElement('button'),handoffStatus=document.createElement('div');
    handoff.type='button';handoff.className='earlier';handoff.textContent='Request staff help';handoff.hidden=true;
    handoffStatus.setAttribute('role','status');handoffStatus.style.cssText='padding:8px 12px;font-size:12px';
    form.before(handoff,handoffStatus);
    const botChoices=document.createElement('div');botChoices.setAttribute('role','group');botChoices.setAttribute('aria-label','Chat choices');botChoices.style.cssText='display:flex;flex-wrap:wrap;gap:8px;padding:8px 12px';form.before(botChoices);
    let choosing=false,botIntent,botRevision=0;
    function updateBot(bot){
        if(bot&&bot.revision<botRevision)return;
        if(bot)botRevision=bot.revision;else botRevision=0;
        botChoices.replaceChildren();
        if(bot?.state!=='waiting')return;
        bot.choices.forEach((label,index)=>{
            const button=document.createElement('button');button.type='button';button.className='earlier';button.textContent=label;button.disabled=choosing;
            button.addEventListener('click',()=>chooseBot(bot.step,index));botChoices.append(button);
        });
    }
    async function chooseBot(step,choice){
        if(choosing||!session?.id)return;choosing=true;const ticket=epoch;
        botChoices.querySelectorAll('button').forEach(b=>b.disabled=true);
        try{
            if(!botIntent)botIntent={op:'choose',id:session.id,step,choice,nonce:crypto.randomUUID()};
            const result=await request(botIntent);if(ticket!==epoch)return;
            botIntent=null;history=result.messages;hasEarlier=result.hasMore;updateHandoff(result.handoff);draw();
            choosing=false;updateBot(result.bot);showError('');
        }catch(e){if(ticket===epoch){showError(e.message);botChoices.querySelectorAll('button').forEach(b=>b.disabled=false);}}
        finally{choosing=false;}
    }
    let requestingStaff=false;
    function updateHandoff(state){
        handoff.hidden=!session?.id;
        handoff.disabled=requestingStaff||!!state?.requestedAt;
        handoffStatus.textContent=state?.answeredAt?'A staff member has replied.':state?.state==='assigned'?'An agent is assigned. Waiting for their reply.':state?.requestedAt?'You are waiting for an available agent. You can continue writing here.':'';
    }
    handoff.addEventListener('click',async()=>{
        if(!session?.id||requestingStaff)return;requestingStaff=true;handoff.disabled=true;const ticket=epoch;
        try{const result=await request({op:'handoff',id:session.id});if(ticket===epoch){updateHandoff(result.handoff);showError('');}}
        catch(e){if(ticket===epoch){showError(e.message);handoff.disabled=false;}}
        finally{requestingStaff=false;}
    });
    messages.setAttribute('tabindex','0');messages.setAttribute('aria-label','Chat messages');style.textContent+='.messages:focus-visible{outline:2px solid #126bce;outline-offset:-2px}';
    let config,session,history=[],pending,polling=false,sending=false,opened=false,position,drag,ignoreClick=false,hasEarlier=false,epoch=0;
    const key='nexa-chat:'+endpoint.href;
    const randomToken=()=>Array.from(crypto.getRandomValues(new Uint8Array(32)),n=>n.toString(16).padStart(2,'0')).join('');
    try{session=JSON.parse(sessionStorage.getItem(key)||'null');if(session&&(!/^[a-f0-9]{64}$/.test(session.token)||Date.now()-session.started>86400000))session=null;}catch{}
    const persist=()=>{try{sessionStorage.setItem(key,JSON.stringify(session));}catch{}};
    const showError=text=>{error.hidden=!text;error.textContent=text||'';};
    async function request(data){
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000),requestToken=session?.token;
        try{const response=await fetch(endpoint,{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json',...(session?.token?{'X-Nexa-Chat-Token':session.token}:{})},body:JSON.stringify(data),signal:controller.signal});
            if(!response.ok){if([401,403,404].includes(response.status)&&data.op!=='config'&&requestToken===session?.token){epoch++;session=null;pending=null;botIntent=null;history=[];hasEarlier=false;updateBot(null);updateHandoff(null);input.value='';draw();try{sessionStorage.removeItem(key);}catch{}}throw new Error(response.status===429?'Too many requests. Please wait and try again.':'Chat is unavailable. Please try again.');}
            const result=await response.json();
            if(data.op!=='config'&&requestToken===session?.token)updateBot(result.bot);
            return result;
        }finally{clearTimeout(timer);}
    }
    function layout(){
        const r=launcher.getBoundingClientRect(),width=Math.min(370,innerWidth-24),height=Math.min(540,innerHeight-90);
        panel.style.left=Math.max(12,Math.min(innerWidth-width-12,r.right-width))+'px';
        panel.style.top=Math.max(12,Math.min(innerHeight-height-12,r.top-height-10))+'px';
    }
    function draw(){
        const nearBottom=messages.scrollHeight-messages.scrollTop-messages.clientHeight<80;messages.replaceChildren();
        if(hasEarlier){const button=document.createElement('button');button.className='earlier';button.textContent='Earlier messages';button.type='button';button.addEventListener('click',()=>earlier(button));messages.append(button);}
        if(!history.length){const welcome=document.createElement('p');welcome.className='welcome';welcome.textContent=config?.welcome||'How can we help?';messages.append(welcome);}
        history.forEach(item=>{const node=document.createElement('div');node.className='message '+item.author;node.textContent=item.body;const time=document.createElement('small');time.textContent=(item.author==='bot'?'Automated assistant':item.author==='staff'?'Support':'You')+' - '+new Date(item.createdAt.replace(' ','T')+'Z').toLocaleString();node.append(time);messages.append(node);});
        if(nearBottom||sending)messages.scrollTop=messages.scrollHeight;
    }
    async function poll(){
        if(!opened||document.hidden||!session?.id||polling||sending)return;polling=true;const ticket=epoch;
        try{const result=await request({op:'read',id:session.id,after:history.at(-1)?.id||''});if(ticket!==epoch)return;updateHandoff(result.handoff);const known=new Set(history.map(m=>m.id)),fresh=result.messages.filter(m=>!known.has(m.id));if(fresh.length){history.push(...fresh);draw();}showError('');}
        catch(e){showError(e.name==='AbortError'?'Connection timed out. Please try again.':e.message);}
        finally{polling=false;}
    }
    async function earlier(button){
        if(!session?.id||polling)return;polling=true;button.disabled=true;const ticket=epoch;
        try{const result=await request({op:'read',id:session.id,before:history[0]?.id||''});if(ticket!==epoch)return;history=[...result.messages,...history];hasEarlier=result.hasMore;draw();}
        catch(e){showError(e.message);button.disabled=false;}finally{polling=false;}
    }
    async function open(){
        if(opened)return;opened=true;panel.hidden=false;launcher.setAttribute('aria-expanded','true');layout();showError('');const ticket=epoch;
        form.querySelector('button').disabled=true;
        try{config=await request({op:'config'});if(ticket!==epoch)return;panel.querySelector('.heading strong').textContent=config.title;brand(config.color);
            if(!position){host.style.right=config.position==='left'?'auto':'24px';host.style.left=config.position==='left'?'24px':'auto';}
            if(config.privacy_url){const privacy=panel.querySelector('.privacy'),link=document.createElement('a');link.textContent='Privacy notice';link.href=config.privacy_url;link.target='_blank';link.rel='noopener noreferrer';privacy.replaceChildren(link);privacy.hidden=false;}
            if(session?.token){const result=await request(session.id?{op:'read',id:session.id}:{op:'resume',nonce:session.nonce});if(ticket!==epoch)return;if(result.id){session.id=result.id;persist();document.dispatchEvent(new CustomEvent('nexa:chat-session',{detail:{id:session.id,visitorKey:session.visitorKey}}));}history=result.messages;hasEarlier=result.hasMore;updateHandoff(result.handoff);updateBot(result.bot);}
            else updateHandoff(null);
            draw();layout();input.focus();
        }catch(e){showError(e.message);}finally{form.querySelector('button').disabled=!config;}
    }
    function close(){opened=false;panel.hidden=true;launcher.setAttribute('aria-expanded','false');launcher.focus();}
    launcher.addEventListener('click',()=>{if(ignoreClick){ignoreClick=false;return;}opened?close():open();});
    panel.querySelector('.close').addEventListener('click',close);
    shadow.addEventListener('keydown',event=>{if(event.key==='Escape'&&opened){event.preventDefault();close();}});
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(sending||!config||!input.value.trim())return;
        const body=input.value.trim(),ticket=epoch;
        if(!session){session={token:randomToken(),visitorKey:crypto.randomUUID(),nonce:crypto.randomUUID(),started:Date.now()};persist();}
        sending=true;input.disabled=true;form.querySelector('button').disabled=true;showError('');
        try{
            if(pending&&pending.body!==body){const recovered=await request(pending);if(ticket!==epoch)return;session.id=recovered.id;persist();history=recovered.messages;pending=null;}
            if(!pending)pending={op:session.id?'send':'start',...(session.id?{id:session.id}:{visitorKey:session.visitorKey}),nonce:session.id?crypto.randomUUID():session.nonce,body};
            const result=await request(pending);if(ticket!==epoch)return;session.id=result.id;persist();document.dispatchEvent(new CustomEvent('nexa:chat-session',{detail:{id:session.id,visitorKey:session.visitorKey}}));pending=null;input.value='';history=result.messages;hasEarlier=result.hasMore;updateHandoff(result.handoff);draw();}
        catch(e){showError(e.name==='AbortError'?'Connection timed out. Your message is ready to retry.':e.message);}
        finally{sending=false;input.disabled=false;form.querySelector('button').disabled=false;input.focus();}
    });
    function move(x,y){position={x:Math.max(8,Math.min(innerWidth-72,x)),y:Math.max(8,Math.min(innerHeight-60,y))};host.style.left=position.x+'px';host.style.top=position.y+'px';host.style.right='auto';host.style.bottom='auto';layout();}
    launcher.addEventListener('pointerdown',event=>{if(!Number(config?.draggable)||event.button!==0)return;const r=launcher.getBoundingClientRect();drag={x:event.clientX,y:event.clientY,left:r.left,top:r.top};launcher.setPointerCapture(event.pointerId);});
    launcher.addEventListener('pointermove',event=>{if(!drag)return;const dx=event.clientX-drag.x,dy=event.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)>5){ignoreClick=true;move(drag.left+dx,drag.top+dy);}});
    launcher.addEventListener('pointerup',()=>{drag=null;});launcher.addEventListener('pointercancel',()=>{drag=null;});
    launcher.addEventListener('keydown',event=>{if(!Number(config?.draggable)||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();const r=launcher.getBoundingClientRect();move(r.left+(event.key==='ArrowLeft'?-12:event.key==='ArrowRight'?12:0),r.top+(event.key==='ArrowUp'?-12:event.key==='ArrowDown'?12:0));});
    addEventListener('resize',()=>{if(position)move(position.x,position.y);else layout();});
    document.addEventListener('nexa:chat-reset',()=>{if(session?.id)request({op:'end',id:session.id}).catch(()=>{});epoch++;session=null;pending=null;botIntent=null;history=[];hasEarlier=false;try{sessionStorage.removeItem(key);}catch{}input.value='';updateHandoff(null);updateBot(null);draw();showError('');close();});
    request({op:'config'}).then(value=>{config=value;brand(config.color);if(config.position==='left'){host.style.right='auto';host.style.left='24px';}}).catch(()=>{});
    setInterval(()=>{if(!host.isConnected)return;poll();},5000);
})();
