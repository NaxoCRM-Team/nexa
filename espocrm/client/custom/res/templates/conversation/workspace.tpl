<section class="nexa-conversations" aria-label="Shared inbox">
    <header class="nc-heading"><h3>Shared Inbox</h3><div class="nc-heading-actions"><a class="btn btn-default nc-mailboxes" href="#Admin/inboundEmails" title="Configure group mailboxes" aria-label="Configure group mailboxes" hidden><span class="fas fa-cog" aria-hidden="true"></span></a><button class="btn btn-default" data-action="refresh" title="Refresh inbox" aria-label="Refresh inbox"><span class="fas fa-sync-alt" aria-hidden="true"></span></button></div></header>
    <div class="nc-error" role="alert" hidden></div>
    <div class="nc-layout">
        <aside class="nc-inbox" aria-label="Conversations">
            <div class="nc-filters">
                <input class="form-control nc-search" type="search" placeholder="Search conversations" aria-label="Search conversations" maxlength="160">
                <select class="form-control nc-view-filter" aria-label="Conversation view"><option value="">All conversations</option><option value="unread">Unread</option><option value="replied">Replied</option></select>
                <div class="nc-filter-row">
                    <select class="form-control nc-status-filter" aria-label="Filter status"><option value="">All statuses</option><option>Open</option><option>Pending</option><option>Closed</option></select>
                    <select class="form-control nc-owner-filter" aria-label="Filter assignment"><option value="">All assignments</option><option value="mine">Assigned to me</option><option value="unassigned">Unassigned</option></select>
                </div>
            </div>
            <div class="nc-list" aria-live="polite"></div>
            <button class="btn btn-default nc-more" data-action="more" hidden>Load more</button>
        </aside>
        <main class="nc-thread" aria-label="Conversation thread"><div class="nc-empty">Select a conversation</div></main>
    </div>
</section>
