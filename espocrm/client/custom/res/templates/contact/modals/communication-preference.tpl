<div class="nexa-communication-preference-form">
    <p class="nexa-preference-intro">
        This change applies to <strong>{{count}} {{#if isSingle}}contact{{else}}contacts{{/if}}</strong>
        and is recorded in the tenant compliance history.
    </p>
    <div class="form-group">
        <label for="nexa-preference-channel">Channel scope</label>
        <select id="nexa-preference-channel" class="form-control" data-name="channel">
            {{#each channelOptions}}<option value="{{value}}">{{label}}</option>{{/each}}
        </select>
    </div>
    <div class="form-group">
        <label for="nexa-preference-reason">Reason <span aria-hidden="true">*</span></label>
        <select id="nexa-preference-reason" class="form-control" data-name="reason" required aria-required="true">
            <option value="">Select a reason</option>
            {{#if isBlocking}}
            <option value="contact_request">Contact requested no communication</option>
            <option value="unsubscribed">Unsubscribed</option>
            <option value="invalid_details">Invalid contact details</option>
            <option value="legal_compliance">Legal or compliance requirement</option>
            <option value="complaint">Complaint</option>
            {{else}}
            <option value="consent_restored">Consent restored</option>
            <option value="correction">Previous restriction was incorrect</option>
            {{/if}}
            <option value="other">Other</option>
        </select>
        <p class="help-block text-danger" data-name="reasonError" hidden>Select a reason before continuing.</p>
    </div>
    <div class="form-group">
        <label for="nexa-preference-note">Internal note <span class="text-muted">(optional)</span></label>
        <textarea id="nexa-preference-note" class="form-control" data-name="note" rows="3" maxlength="1000" placeholder="Add useful compliance context"></textarea>
    </div>
    {{#if isBlocking}}
    <div class="nexa-preference-warning" role="note">
        <span class="fas fa-ban" aria-hidden="true"></span>
        Restricted channels must be checked before any manual or automated outreach.
    </div>
    {{/if}}
</div>
