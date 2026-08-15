define('custom:views/contact/modals/communication-preference', ['views/modal'], Dep => class extends Dep {
    template = 'custom:contact/modals/communication-preference';
    className = 'dialog nexa-contact-communication-dialog';
    noFullHeight = true;

    setup() {
        this.count = Number(this.options.count) || 0;
        this.status = this.options.status === 'allowed' ? 'allowed' : 'blocked';
        this.activeChannels = Array.isArray(this.options.channels) ? this.options.channels : [];
        this.headerText = this.status === 'blocked'
            ? `Set do not contact for ${this.count} ${this.count === 1 ? 'record' : 'records'}`
            : `Remove do not contact for ${this.count} ${this.count === 1 ? 'record' : 'records'}`;
        this.addButton({
            name: 'update',
            label: this.status === 'blocked' ? 'Set do not contact' : 'Remove restriction',
            style: this.status === 'blocked' ? 'danger' : 'primary',
        });
        this.addButton({name: 'cancel', label: 'Cancel'});
    }

    data() {
        return {
            count: this.count,
            isSingle: this.count === 1,
            isBlocking: this.status === 'blocked',
            channelOptions: this.channelOptions(),
        };
    }

    channelOptions() {
        const labels = {
            all: 'All channels', email: 'Email', phone: 'Phone calls',
            sms: 'SMS', whatsapp: 'WhatsApp', postal: 'Postal mail',
        };
        const channels = this.status === 'blocked'
            ? ['all', 'email', 'phone', 'sms', 'whatsapp', 'postal']
            : this.activeChannels.filter(channel => labels[channel] && channel !== 'all');

        return [...new Set(channels)].map(value => ({value, label: labels[value]}));
    }

    afterRender() {
        super.afterRender();
        this.disableButton('update');
        this.element.querySelector('[data-name="reason"]')?.addEventListener('change', event => {
            const hasReason = Boolean(event.currentTarget.value);
            this.element.querySelector('[data-name="reasonError"]').hidden = true;
            hasReason ? this.enableButton('update') : this.disableButton('update');
        });
    }

    actionUpdate() {
        const reason = this.element.querySelector('[data-name="reason"]')?.value || '';
        if (!reason) {
            this.element.querySelector('[data-name="reasonError"]').hidden = false;
            this.element.querySelector('[data-name="reason"]')?.focus();
            return;
        }

        this.trigger('confirm', {
            status: this.status,
            channels: [this.element.querySelector('[data-name="channel"]')?.value || ''],
            reason,
            note: this.element.querySelector('[data-name="note"]')?.value?.trim() || null,
        });
        this.close();
    }
});
