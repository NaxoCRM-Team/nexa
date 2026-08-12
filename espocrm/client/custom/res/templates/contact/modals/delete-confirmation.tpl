<div class="nexa-delete-confirmation">
    <p>You're about to delete <strong>{{count}} {{recordLabel}}</strong>. Deleted contacts can be restored by a tenant administrator for two months.</p>
    <label for="nexa-delete-confirmation-count">Type <strong>{{count}}</strong> below to delete</label>
    <input id="nexa-delete-confirmation-count" class="form-control" data-name="confirmationCount" inputmode="numeric" autocomplete="off" aria-describedby="nexa-delete-retention-note">
    <div id="nexa-delete-retention-note" class="nexa-delete-note">
        <span class="fas fa-info-circle" aria-hidden="true"></span>
        <p><strong>Note:</strong> Contacts are soft deleted first. They are permanently removed after two months. Privacy-law erasure requests must follow the dedicated data-privacy workflow.</p>
    </div>
</div>
