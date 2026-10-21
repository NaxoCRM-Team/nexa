define('custom:controllers/nexa-conversations', ['controller'], Dep => class extends Dep {
    actionIndex() {
        this.main('custom:views/conversation/workspace', {}, view => view.render());
    }
    actionChat() { this.main('custom:views/conversation/chat', {}, view => view.render()); }
    actionRouting() { this.main('custom:views/conversation/routing', {}, view => view.render()); }
    actionPrivacy() { this.main('custom:views/conversation/privacy', {}, view => view.render()); }
    actionWidgets() { this.main('custom:views/conversation/widgets', {}, view => view.render()); }
    actionMessaging() { this.main('custom:views/conversation/messaging-connections', {}, view => view.render()); }
    actionMessages() { this.main('custom:views/conversation/messaging-inbox', {}, view => view.render()); }
    actionSms() { this.main('custom:views/conversation/sms', {}, view => view.render()); }
    actionWhatsapp() { this.main('custom:views/conversation/whatsapp', {}, view => view.render()); }
    actionMessenger() { this.main('custom:views/conversation/messenger', {}, view => view.render()); }
    actionMessengerSettings() { this.main('custom:views/conversation/messenger-settings', {}, view => view.render()); }
});
