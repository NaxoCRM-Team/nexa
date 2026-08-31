define('custom:controllers/nexa-activity', ['controller'], Dep => class extends Dep {
    actionIndex() { this.actionAgenda(); }
    actionAgenda() { this.open('agenda'); }
    actionProjects() { this.open('projects'); }
    // Preserve old bookmarks while keeping one authoritative native workspace.
    actionCalendar() { this.getRouter().navigate('#Calendar', {trigger: true}); }
    actionDocuments() { this.getRouter().navigate('#Document', {trigger: true}); }
    open(section) { this.main('custom:views/activity/workspace', {section}, view => view.render()); }
});
