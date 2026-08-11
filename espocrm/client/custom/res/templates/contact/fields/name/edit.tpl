<div class="row nexa-contact-name">
    <div class="{{#if hideSalutation}}hidden{{else}}col-sm-2 col-xs-12{{/if}}">
        <select data-name="salutation{{ucName}}" class="form-control" aria-label="{{translate 'salutationName' category='fields' scope=scope}}">
            {{options salutationOptions salutationValue field='salutationName' scope=scope}}
        </select>
    </div>
    <div class="{{#if hideSalutation}}col-sm-6{{else}}col-sm-5{{/if}} col-xs-12">
        <input type="text" class="form-control" data-name="first{{ucName}}" value="{{firstValue}}" placeholder="{{translate 'First Name'}}"{{#if firstMaxLength}} maxlength="{{firstMaxLength}}"{{/if}} autocomplete="espo-first{{ucName}}">
    </div>
    <div class="{{#if hideSalutation}}col-sm-6{{else}}col-sm-5{{/if}} col-xs-12">
        <input type="text" class="form-control" data-name="last{{ucName}}" value="{{lastValue}}" placeholder="{{translate 'Last Name'}}"{{#if lastMaxLength}} maxlength="{{lastMaxLength}}"{{/if}} autocomplete="espo-last{{ucName}}">
    </div>
</div>
