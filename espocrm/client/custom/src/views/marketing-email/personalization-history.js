define('custom:views/marketing-email/personalization-history',['view','custom:workspace-table'],(Dep,WorkspaceTable)=>class extends Dep{
    template='custom:marketing-email/personalization-history';
    events={'input [data-search]':'search','change [data-source]':'reset','change [data-from]':'reset','change [data-to]':'reset','click [data-refresh]':'reset','click [data-more]':'more','click [data-sort]':'sort','click [data-contact]':'chooseContact'};
    setup(){this.setPageTitle('Personalization');this.table=new WorkspaceTable(this);this.rows=[];this.generation=0;this.sortKey='occurredAt';this.sortDirection=-1;this.scanned=0;}
    afterRender(){super.afterRender();if(!document.getElementById('nexa-personalization-history-style')){const link=document.createElement('link');link.id='nexa-personalization-history-style';link.rel='stylesheet';link.href='client/custom/css/personalization-history.css';document.head.append(link);}this.element.querySelector('[data-from]').value=new Date(Date.now()-89*86400000).toISOString().slice(0,10);this.element.querySelector('[data-to]').value=new Date().toISOString().slice(0,10);this.element.querySelector('[data-scroll]').addEventListener('scroll',()=>{const box=this.element.querySelector('[data-scroll]');if(box.scrollTop+box.clientHeight>=box.scrollHeight-80)this.more();});this.once('remove',()=>{clearTimeout(this.timer);this.generation++;});this.reset();}
    search(){clearTimeout(this.timer);this.generation++;this.loading=false;this.rows=[];this.cursor=null;this.scanned=0;this.renderRows();this.element.querySelector('[data-more]').hidden=true;this.timer=setTimeout(()=>this.reset(),250);}
    reset(){clearTimeout(this.timer);this.generation++;this.rows=[];this.cursor=null;this.scanned=0;this.loading=false;this.renderRows();this.fetch(false);}
    more(){if(!this.loading&&this.cursor&&this.scanned<2000)this.fetch(true);}
    async fetch(append){
        const generation=this.generation;this.loading=true;const error=this.element.querySelector('[data-error]');error.hidden=true;
        const params={search:this.element.querySelector('[data-search]').value,source:this.element.querySelector('[data-source]').value,from:this.element.querySelector('[data-from]').value,to:this.element.querySelector('[data-to]').value};
        if(append)params.cursor=this.cursor;
        this.element.querySelector('[data-state]').textContent='Loading decisions';
        try{const result=await Espo.Ajax.getRequest('Nexa/personalization/history',params);if(generation!==this.generation)return;this.rows=append?[...this.rows,...result.list]:result.list;this.cursor=result.cursor;this.scanned+=result.scannedCount??result.list.length;this.renderRows();this.element.querySelector('[data-state]').textContent=this.scanned>=2000&&this.cursor?'2,000-row display limit reached':this.cursor?'More decisions available':'Up to date';}
        catch(failure){if(generation!==this.generation)return;error.textContent=failure?.message||'Decision history could not be loaded.';error.hidden=false;this.element.querySelector('[data-state]').textContent='';}
        finally{if(generation===this.generation){this.loading=false;this.element.querySelector('[data-more]').hidden=!this.cursor||this.scanned>=2000;}}
    }
    escape(value){const node=document.createElement('span');node.textContent=String(value??'');return node.innerHTML;}
    sort(event){const key=event.currentTarget.dataset.sort;this.sortDirection=this.sortKey===key?-this.sortDirection:1;this.sortKey=key;this.renderRows();}
    renderRows(){
        const rows=[...this.rows].sort((a,b)=>String(a[this.sortKey]??'').localeCompare(String(b[this.sortKey]??''))*this.sortDirection);
        this.element.querySelector('[data-count]').textContent=`Loaded ${rows.length} decision${rows.length===1?'':'s'}`;
        this.element.querySelector('[data-rows]').innerHTML=rows.map(row=>{
            const record=row.recordId?`<a href="#${row.recordType}/view/${encodeURIComponent(row.recordId)}">${this.escape(row.recordName)}</a>`:this.escape(row.recordName);
            const details=row.details.length?`<details><summary>View decision</summary><ul>${row.details.map(item=>`<li><strong>${this.escape(item.label)}</strong>: ${item.matched?'Matched':'Did not match'}<small>${this.escape(item.operator)} ${this.escape(item.expected)}</small>${item.heading?`<small>${this.escape(item.heading)}</small>`:''}${item.privacyControl?'<small>Privacy control applied</small>':''}</li>`).join('')}</ul></details>`:'';
            return `<tr><td data-column="occurredAt">${this.escape(String(row.occurredAt).slice(0,19))}<small>${this.escape(row.clock)}</small><small>${this.escape(row.id)}</small></td><td data-column="source">${this.escape(row.source)}</td><td data-column="recordName">${record}</td><td data-column="actor">${this.escape(row.actor)}</td><td data-column="outcome">${this.escape(row.outcome)}</td><td data-column="verification">${this.escape(row.verification)}${details}</td></tr>`;
        }).join('')||'<tr><td colspan="6" class="np-empty">No decisions found</td></tr>';
        this.table.enhance(this.element.querySelector('table'),`personalization-history:${location.pathname}`);
    }
    chooseContact(){this.createView('historyContact','views/modals/select-records',{scope:'Contact',multiple:false,createButton:false},view=>{view.render();this.listenToOnce(view,'select',contact=>{this.element.querySelector('[data-search]').value=contact.id;this.element.querySelector('[data-source]').value='email';this.reset();});});}
});
