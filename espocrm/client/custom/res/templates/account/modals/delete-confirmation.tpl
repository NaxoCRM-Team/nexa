<div class="nexa-delete-confirmation">
    <p>You're about to delete <strong>{{count}} {{recordLabel}}</strong>. Deleted accounts can be restored by a tenant administrator for two months.</p>
    <label for="nexa-account-delete-confirmation-count">Type <strong>{{count}}</strong> below to delete</label>
    <input id="nexa-account-delete-confirmation-count" class="form-control" data-name="confirmationCount" inputmode="numeric" autocomplete="off" aria-describedby="nexa-account-delete-retention-note">
    <div id="nexa-account-delete-retention-note" class="nexa-delete-note">
        <span class="fas fa-info-circle" aria-hidden="true"></span>
        <p><strong>Note:</strong> Accounts are soft deleted first and permanently removed after two months. Related records are not automatically reassigned.</p>
    </div>
</div>
