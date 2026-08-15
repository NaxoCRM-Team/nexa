<span class="nexa-contact-name-cell">
    {{#if doNotContact}}<button type="button" class="nexa-do-not-contact-badge" data-action="removeCommunicationRestriction" aria-label="{{doNotContactTitle}}. Remove restriction" title="{{doNotContactTitle}}. Click to remove"><span class="fas fa-ban" aria-hidden="true"></span></button>{{/if}}
    <a href="#{{scope}}/view/{{model.id}}" class="link nexa-contact-name-link" data-id="{{model.id}}" title="{{displayName}}">
        <span class="nexa-contact-list-avatar" aria-hidden="true"{{#if profileImageId}} data-profile-image-id="{{profileImageId}}"{{/if}}>{{initial}}</span>
        <span class="nexa-contact-list-name">{{displayName}}</span>
    </a>
</span>
