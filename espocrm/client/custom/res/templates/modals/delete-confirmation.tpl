<div class="nexa-delete-confirmation">
    <p>You're about to delete <strong>{{count}} {{recordLabel}}</strong> from {{entityLabel}}.</p>
    <label for="nexa-record-delete-confirmation-count">Type <strong>{{count}}</strong> below to delete</label>
    <input id="nexa-record-delete-confirmation-count" class="form-control" data-name="confirmationCount" inputmode="numeric" autocomplete="off" aria-describedby="nexa-record-delete-retention-note">
    <div id="nexa-record-delete-retention-note" class="nexa-delete-note">
        <span class="fas fa-info-circle" aria-hidden="true"></span>
        <p><strong>Note:</strong> Records are soft deleted first and retained for two months under the platform retention policy.</p>
    </div>
</div>
