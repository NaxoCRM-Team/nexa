define(['custom:views/conversation/workspace'], Base => class extends Base {
    template = 'custom:conversation/messaging-inbox';
    events = {
        'change .messaging-kind': 'reset', 'change .messaging-connection': 'reset',
        'click [data-action="refresh"]': 'refresh', 'click [data-action="more"]': 'more',
        'click [data-action="open"]': 'open', 'click [data-action="back"]': 'back',
    };
    api(input) { return Espo.Ajax.postRequest('Nexa/messaging/connections', input); }
    async afterRender() {
        try {
            const data = await this.api({op:'list'});
            if (this.disposed) return;
            const e = value => this.escape(value);
            this.$el.find('.messaging-connection').append(data.list.map(row => `<option value="${e(row.id)}">${e(row.name)}</option>`).join(''));
            this.reset();
        } catch (error) { if (!this.disposed) this.error(error); }
    }
    reset() {
        ++this.threadSequence; this.current = null; this.items = []; this.cursor = null;
        this.$el.find('.nexa-conversations').removeClass('nc-detail-open');
        this.$el.find('.nc-thread').html('<div class="nc-empty">Select a message</div>');
        this.loadList(false);
    }
    async loadList(append) {
        const seq = ++this.listSequence; this.loading = true; this.error(null);
        this.$el.find('.nc-list').attr('aria-busy','true');
        try {
            const data = await this.api({op:'receipts',kind:this.$el.find('.messaging-kind').val(),connectionId:this.$el.find('.messaging-connection').val() || '',before:append ? this.cursor : ''});
            if (this.disposed || seq !== this.listSequence) return;
            this.items = append ? [...this.items,...data.list] : data.list;
            this.cursor = data.nextCursor; this.drawList();
        } catch (error) { if (!this.disposed && seq === this.listSequence) { this.error(error); this.items = []; this.drawList(); } }
        finally { if (seq === this.listSequence) { this.loading = false; this.$el.find('.nc-list').attr('aria-busy','false'); } }
    }
    drawList() {
        const e = value => this.escape(value);
        this.$el.find('.nc-list').html(this.items.length ? this.items.map(row => `<button class="nc-conversation ${row.id === this.current ? 'is-selected' : ''}" data-action="open" data-id="${e(row.id)}" aria-pressed="${row.id === this.current}"><span class="nc-subject">${e(row.from || row.deliveryStatus)}</span><span class="nc-sender">${e(row.connectionName)}</span>${row.kind === 'inbound' ? `<span class="nc-sender">${e(row.body || 'Media message')}</span>` : '<span class="nc-sender">Unmatched delivery receipt</span>'}<time>${e(this.displayDate(row.receivedAt))}</time></button>`).join('') : '<div class="nc-empty">No messages found</div>');
        this.$el.find('.nc-more').prop('hidden',!this.cursor);
    }
    async loadThread(id) {
        const seq = ++this.threadSequence; this.current = id; this.drawList(); this.error(null);
        this.$el.find('.nexa-conversations').addClass('nc-detail-open');
        this.$el.find('.nc-thread').html('<div class="nc-empty" role="status">Loading message...</div>');
        try {
            const row = await this.api({op:'receipt',id});
            if (this.disposed || seq !== this.threadSequence) return;
            const e = value => this.escape(value);
            this.$el.find('.nc-thread').html(`<header class="nc-thread-heading"><button class="btn btn-default nc-back" data-action="back" title="Back to messages" aria-label="Back to messages"><span class="fas fa-arrow-left" aria-hidden="true"></span></button><h4>${e(row.from || 'Delivery receipt')}</h4></header><div class="nc-context">${e(row.connectionName)}</div><div class="nc-messages" tabindex="0" aria-label="Message content"><article class="nc-message"><header><strong>${e(row.kind === 'inbound' ? 'Incoming SMS' : row.deliveryStatus)}</strong><time>${e(this.displayDate(row.receivedAt))}</time></header>${row.kind === 'inbound' ? `<div class="nc-message-body">${e(row.body)}</div><p>To: ${e(row.to)}</p>${row.mediaCount ? `<p><span class="fas fa-paperclip" aria-hidden="true"></span> ${row.mediaCount} media attachment${row.mediaCount === 1 ? '' : 's'}${row.mediaTypes.length ? ` (${row.mediaTypes.map(e).join(', ')})` : ''}</p><p>Media preview unavailable.</p>` : ''}` : `<p>No matching dispatched message.</p>${row.errorCode ? `<p>Provider error: ${e(row.errorCode)}</p>` : ''}`}</article></div>`);
        } catch (error) {
            if (!this.disposed && seq === this.threadSequence) { this.error(error); this.$el.find('.nc-thread').html('<div class="nc-empty">Message unavailable</div>'); }
        }
    }
    refresh() { this.reset(); }
});
