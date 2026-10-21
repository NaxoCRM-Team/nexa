<section class="nexa-chat-privacy" aria-label="Chat privacy">
    <header class="nc-heading"><h3>Chat Privacy</h3><a class="btn btn-default" href="#NexaConversations/chat" aria-label="Back to live chat" title="Back to live chat"><span class="fas fa-arrow-left" aria-hidden="true"></span></a></header>
    <div class="nc-error" role="alert" hidden></div>
    <form class="chat-privacy-policy">
        <label>Retain closed or expired chats for (days)<input class="form-control" name="retentionDays" type="number" min="1" max="3650" required></label>
        <label class="chat-privacy-hold"><input name="legalHold" type="checkbox"> Legal hold</label>
        <label>Legal-hold reason<textarea class="form-control" name="holdReason" rows="2" maxlength="500"></textarea></label>
        <button class="btn btn-primary" type="submit" aria-label="Save privacy policy" title="Save privacy policy"><span class="fas fa-save" aria-hidden="true"></span></button>
    </form>
    <h4>Deleted Chats</h4>
    <div class="chat-privacy-toolbar"><input class="form-control chat-privacy-search" type="search" maxlength="100" aria-label="Search deleted chats" placeholder="Search deleted chats"><span class="chat-privacy-pending" role="status"></span></div>
    <div class="chat-privacy-table" tabindex="0" role="region" aria-label="Deleted chats"><table class="table"><thead><tr><th>Name</th><th>Deleted</th><th>Restore Until</th><th>Actions</th></tr></thead><tbody></tbody></table><button class="btn btn-default chat-privacy-more" type="button" hidden>Load more</button></div>
</section>
