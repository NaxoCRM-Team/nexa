<a href="#{{scope}}/view/{{model.id}}" class="link nexa-contact-name-link" data-id="{{model.id}}" title="{{displayName}}">
    <span class="nexa-contact-list-avatar" aria-hidden="true"{{#if profileImageId}} data-profile-image-id="{{profileImageId}}"{{/if}}>{{initial}}</span>
    {{#if doNotContact}}<span class="nexa-do-not-contact-badge" role="img" aria-label="{{doNotContactTitle}}" title="{{doNotContactTitle}}"><span class="fas fa-ban" aria-hidden="true"></span><span class="far fa-envelope" aria-hidden="true"></span></span>{{/if}}
    <span class="nexa-contact-list-name">{{displayName}}</span>
</a>
