// Stock EspoCRM bug (reproduces on a fully vanilla page, e.g. #Case/create,
// with none of Nexa's custom code involved): views/record/list's setup()
// conditionally adds `this.events['click .list a.link'] = ...` for
// selectable mode (used by every "Select <Entity>" picker modal, e.g.
// choosing an Assigned User), but that mutation happens too late - the
// view's Backbone events hash has already been delegated to the DOM from
// the static prototype value at construction time, before setup() runs.
// The row-select handler is therefore silently never bound.
//
// On top of that, the app's own global router already claims every click
// on an `<a href="#...">` in the capture phase, before any listener a
// custom script could add gets a chance to run (confirmed live:
// event.defaultPrevented is already true by the time our own capture
// listener sees the event) - so this can't be fixed by racing another
// click listener ahead of it. Instead, since the row's link is only ever
// meant to select a record inside a picker modal (never to navigate
// there), the fix strips the href off these specific links right after
// render so the router has nothing to recognize as an internal route,
// then binds a plain click listener that does what the native selectable
// handler was supposed to do.
require(['views/record/list'], ListView => {
    const originalAfterRender = ListView.prototype.afterRender;

    ListView.prototype.afterRender = function (...args) {
        const result = originalAfterRender.apply(this, args);

        if (this.selectable) {
            this.element?.querySelectorAll('.list a.link[data-id]').forEach(link => {
                link.removeAttribute('href');
            });

            if (this.element && !this.element.dataset.nexaSelectableRowFixBound) {
                this.element.dataset.nexaSelectableRowFixBound = 'true';
                this.element.addEventListener('click', event => {
                    if (event.ctrlKey || event.metaKey || event.shiftKey) return;

                    const link = event.target.closest('a.link[data-id]');
                    if (!link) return;

                    const id = link.getAttribute('data-id');
                    if (id) this.selectModel(id);
                });
            }
        }

        return result;
    };
});
