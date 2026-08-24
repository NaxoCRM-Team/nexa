<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if ($content === false) throw new RuntimeException("Unable to read {$relative}.");
    return $content;
};
$mustContain = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message);
};

$clientDefs = json_decode(
    $read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Contact.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$view = $read('espocrm/client/custom/src/views/contact/record/detail-workspace.js');
$styles = $read('espocrm/client/custom/css/crm-workflows.css');
$navigation = $read('espocrm/client/custom/tenant-workspace.js');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$richEditorView = 'custom:views/fields/nexa-rich-text';
$richEditorFields = [
    'Email' => 'body',
    'Task' => 'description',
    'Meeting' => 'description',
    'Call' => 'description',
];

foreach ($richEditorFields as $entityType => $field) {
    $entityDefs = json_decode(
        $read("espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/{$entityType}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (($entityDefs['fields'][$field]['view'] ?? '') !== $richEditorView) {
        throw new RuntimeException("{$entityType}.{$field} must use the tenant-aware Nexa rich editor.");
    }
}

if (($clientDefs['recordViews']['detail'] ?? '') !== 'custom:views/contact/record/detail-workspace') {
    throw new RuntimeException('Contact detail must use the customer workspace record view.');
}

$mustContain("['crm:views/contact/record/detail'", $view, 'The workspace must extend the native Contact detail record.');
$mustContain('super.afterRender()', $view, 'Native Contact rendering must complete before the workspace is added.');
$mustContain('await this.model.fetch()', $view, 'The customer summary must refresh the Contact before displaying saved values.');
$mustContain('workspaceFetchPending', $view, 'Contact refreshes must not issue duplicate concurrent requests.');
$mustContain('Nexa/contact-profile-image/', $view, 'Profile images must use the tenant and ACL protected endpoint.');
$mustContain("this.once('remove'", $view, 'Protected image object URLs must be released with the view.');
$mustContain("document.body.classList.add('nexa-contact-detail-page')", $view, 'Contact detail must activate its fixed desktop shell state.');
$mustContain("document.body.classList.remove('nexa-contact-detail-page')", $view, 'The Contact shell state must be removed when navigating away.');
$mustContain('role="tab"', $view, 'Workspace sections must expose accessible tabs.');
$mustContain('nexa-customer-left', $view, 'The workspace must provide a customer identity and property rail.');
$mustContain('nexa-customer-centre', $view, 'The workspace must provide a central interaction area.');
$mustContain('nexa-customer-right', $view, 'The workspace must provide an association rail.');
$mustContain('placeNativePanels', $view, 'Native activity, relationship and action panels must be preserved and repositioned.');
$mustContain('data-nexa-record-actions', $view, 'The native Contact action toolbar must remain available at the top.');
$mustContain('actions.append(nativeRecord)', $view, 'The native action menu must remain inside its owning record view.');
$mustContain('data-nexa-actions-toggle', $view, 'The toolbar must provide a stable Nexa Actions button.');
$mustContain("classList.remove('record-buttons'", $view, 'The native sticky controller must not move the embedded action source while scrolling.');
$mustContain("group.classList.add('open')", $view, 'The repositioned native Actions menu must open without relying on Bootstrap delegation.');
$mustContain("event.key === 'Escape'", $view, 'The Actions menu must support keyboard dismissal.');
$mustContain('navigate(`#Contact/edit/${this.model.id}`', $view, 'Contact edit controls must navigate directly to the supported edit route.');
$mustContain('nexa-contact-primary-email', $view, 'The customer identity card must expose the primary email address.');
$mustContain('data-nexa-compose-email', $view, 'The displayed Contact email must open the composer directly.');
$mustContain('openContactEmailComposer', $view, 'The Contact Email action must have an explicit composer integration.');
$mustContain("checkScope('Email', 'create')", $view, 'Email composition must enforce the current user Email-create permission.');
$mustContain("checkField('Contact', 'emailAddress', 'read')", $view, 'Email composition must enforce Contact email field access.');
$mustContain("guardOutboundCommunication('email')", $view, 'Email composition must respect tenant communication restrictions before opening.');
$mustContain("guardOutboundCommunication('call')", $view, 'Live and scheduled calls must respect Contact phone restrictions.');
$mustContain("guardOutboundCommunication('meeting')", $view, 'Meeting outreach must respect Contact email restrictions.');
$mustContain("'views/modals/compose-email'", $view, 'Contact messages must use the native authenticated Espo email composer.');
$mustContain('to: emailAddress', $view, 'The Contact email composer must pre-address the active Contact.');
$mustContain('nameHash: {[emailAddress]: contactName}', $view, 'The composer must preserve the Contact display name for its recipient.');
$mustContain("this.model.trigger('update-related:emails')", $view, 'Sent or saved emails must refresh the Contact email relationship.');
$mustContain('nexa-contact-meta', $view, 'Title, company and email must share a full-width row below the Contact identity.');
$mustContain('.nexa-customer-identity .nexa-contact-meta', $styles, 'Contact identity metadata must have a dedicated responsive layout rule.');
$mustContain('.nexa-contact-primary-email:focus-visible', $styles, 'The clickable Contact email must expose a keyboard focus state.');
$mustContain('grid-column: 1 / -1', $styles, 'Contact identity metadata must span the complete card width below the avatar.');
$mustContain('data-nexa-edit-contact', $view, 'The top toolbar must retain the complete Contact edit action.');
$mustContain('data-nexa-more-actions', $view, 'Contact actions must expose a familiar More command control.');
$mustContain('Search customer actions', $view, 'The expanded customer-action catalogue must be searchable.');
$mustContain('aria-label="More customer actions"', $view, 'The More control and command palette must have an accessible name.');
foreach (['Log SMS', 'Log WhatsApp message', 'Log LinkedIn message', 'Log call', 'Log meeting', 'Log email', 'Log postal mail'] as $actionLabel) {
    $mustContain($actionLabel, $view, "The Contact command palette is missing {$actionLabel}.");
}
$mustContain("interactionContactField(label = 'Contacted')", $view, 'Contact communication logs must display the current Contact as a fixed recipient.');
$mustContain("callOutcomeOptions()", $view, 'Contact call logs must expose the approved outcome catalogue.');
$mustContain("'Left voicemail'", $view, 'Contact call outcomes must include voicemail handling.');
$mustContain("meetingOutcomeOptions()", $view, 'Contact meeting logs must expose dedicated meeting outcomes.');
$mustContain('data-nexa-duration-search', $view, 'Contact meeting duration must provide live search.');
$mustContain('for (let minutes = 15; minutes <= 480; minutes += 15)', $view, 'Contact meeting duration must cover 15 minutes through 8 hours.');
$mustContain('data-nexa-interaction-notes-editor', $view, 'Every Contact log must provide a native rich-text notes editor.');
$mustContain("'custom:views/fields/nexa-rich-text'", $view, 'Contact logs must use the reusable Nexa rich editor with tenant files and images.');
$mustContain("this.taskNotesEditorView = await this.createView('nexaTaskNotesEditor', 'custom:views/fields/nexa-rich-text'", $view, 'Contact task notes must use the tenant file and image library.');
$mustContain("formData.set('notes'", $view, 'Every Contact log must persist its rich-text notes.');
$mustContain('`Contacted: ${contactedName}`', $view, 'Contact communication logs must record the contacted person in the activity audit.');
if (str_contains($view, '<label><span>Subject</span><input class="form-control" type="text" name="subject"')) {
    throw new RuntimeException('Contact interaction popups must not request a Subject.');
}
$mustContain("'whatsapp.svg'", $view, 'WhatsApp logging must use a reliable local brand asset.');
$mustContain("'linkedin.svg'", $view, 'LinkedIn logging must use a reliable local brand asset.');
$mustContain('nexa-command-brand-icon', $styles, 'Customer communication brand assets must fit the command controls.');
$mustContain('.nexa-command-group button[hidden]', $styles, 'Live command search must visibly remove actions that do not match.');
if (str_contains($view, "label: 'Follow up'")) {
    throw new RuntimeException('The More menu must not repeat the direct Contact follow-up actions.');
}
$mustContain("this.getModelFactory().create('Note')", $view, 'Manual interactions must persist as native Contact timeline records.');
$mustContain("parentType: this.model.entityType", $view, 'Manual interactions must be related to the active Contact type.');
$mustContain("parentId: this.model.id", $view, 'Manual interactions must be related to the active Contact record.');
$mustContain("await note.save(null)", $view, 'Manual interactions must use authenticated model persistence.');
$mustContain('handleCustomerCommandKeys', $view, 'The customer-action palette must support keyboard navigation.');
$mustContain('handleInteractionDialogKeys', $view, 'The interaction dialog must trap focus and support keyboard dismissal.');
$mustContain("this.tabButton('notes', 'Notes')", $view, 'Notes must have a dedicated tab beside Contact activities.');
$mustContain('data-nexa-activity-search', $view, 'Activities must provide live search across customer interactions.');
$mustContain('data-nexa-activity-type', $view, 'Activities must provide a type filter for calls, meetings, emails, tasks and communication preferences.');
$mustContain('data-nexa-activity-period', $view, 'Activities must provide the same date-range choices as Notes.');
$mustContain('observeContactActivity', $view, 'Native activity rows loaded asynchronously must be included in the active filters.');
$mustContain('contactActivityRows', $view, 'Activity filtering must target individual native records rather than only whole panels.');
$mustContain('data-nexa-activity-list', $view, 'Activities must render into a dedicated customer activity list.');
$mustContain('data-nexa-collapse-activities', $view, 'Activities must support collapsing and expanding all visible records.');
$mustContain('collectContactActivities', $view, 'Activities must be collected from the tenant-scoped native record sources.');
$mustContain('renderContactActivities', $view, 'Activity search and filters must rerender the standalone card collection.');
$mustContain('renderContactActivitySummary', $view, 'The Contact overview must calculate activity summaries from the unified timeline.');
foreach (['first', 'last', 'email', 'next'] as $summaryKey) {
    $mustContain("activitySummaryHighlight('", $view, 'The Contact activity summary helper is missing.');
    $mustContain("'{$summaryKey}',", $view, "The Contact activity summary is missing {$summaryKey}.");
}
$mustContain("...(this.contactMarketingActivities || [])", $view, 'Campaign activity must participate in the unified Contact timeline.');
$mustContain("type: 'website'", $view, 'An authoritative identified website visit must participate in the unified Contact timeline.');
$mustContain('loadContactMarketingContext', $view, 'Contact marketing membership must load from authoritative relationships.');
$mustContain('/targetLists`', $view, 'Contact segment and list membership must use the tenant-scoped relationship API.');
$mustContain("Espo.Ajax.getRequest('CampaignLogRecord'", $view, 'Contact campaign history must use campaign log records.');
$mustContain('/campaigns`', $view, 'Contact campaign membership must be resolved from related target lists.');
$mustContain('Marketing events', $view, 'Unified activity filters must include marketing events.');
$mustContain('Website visits', $view, 'Unified activity filters must include identified website visits.');
$mustContain('nexa-marketing-membership', $styles, 'Live marketing membership must have a compact association presentation.');
$mustContain("if (source === 'stream') return", $view, 'The legacy Stream must not leak generic posts into standalone Activities.');
$mustContain('loadContactCommunicationActivities', $view, 'Communication preferences must load from their authoritative audit history.');
$mustContain('/communication-preferences`', $view, 'Communication audit reads must use the protected Contact endpoint.');
$mustContain('changedByName', $view, 'Communication activities must identify the user who made the change.');
$mustContain('Internal note', $view, 'Communication activities must display a supplied internal note.');
foreach (['Calls', 'Meetings', 'Emails', 'Tasks', 'Communication preferences'] as $activityLabel) {
    $mustContain($activityLabel, $view, "The Activity type filter is missing {$activityLabel}.");
}
if (preg_match('/activityPanel\(\).*?notesPanel\(\)/s', $view, $activityPanel)) {
    if (str_contains($activityPanel[0], 'data-nexa-activity-notes') || str_contains($activityPanel[0], '>Stream<')) {
        throw new RuntimeException('Activities must not expose the legacy Stream or duplicate the dedicated Notes tab.');
    }
}
$mustContain('data-nexa-activity-count', $view, 'Activities must announce the filtered result count.');
$mustContain('data-nexa-tab-panel="notes"', $view, 'The Contact workspace must expose a separate Notes panel.');
$mustContain('openNoteDialog()', $view, 'The direct Contact Note action must open the standalone note editor.');
$mustContain('overlay.dataset.nexaNoteDialog', $view, 'The note editor must expose a stable standalone popup.');
$mustContain('data-nexa-note-editor-host', $view, 'The note popup must provide a native rich-note editor host.');
$mustContain('data-nexa-note-close', $view, 'The standalone note popup must provide close and cancel actions.');
$mustContain('handleNoteDialogKeys', $view, 'The note popup must trap focus and support keyboard dismissal.');
if (preg_match('/notesPanel\(\).*?salesPanel\(\)/s', $view, $notesPanel) && str_contains($notesPanel[0], 'data-nexa-note-form')) {
    throw new RuntimeException('The Notes tab must contain threads only and no note form.');
}
$mustContain("'views/fields/wysiwyg'", $view, 'Notes and comments must use the native EspoCRM WYSIWYG field.');
$mustContain("fullSelector: '[data-nexa-note-editor-host]'", $view, 'The standalone note dialog must mount the native editor into its external host.');
$mustContain('nexa-note-recipient', $view, 'The note editor must identify the active Contact.');
$mustContain('<!-- nexa-contact-note -->', $view, 'Contact notes must be distinguishable from other stream activity.');
$mustContain('<!-- nexa-note-comment:', $view, 'Note comments must retain their parent-note identity.');
$mustContain('createContactStreamNote', $view, 'Notes and comments must share authenticated Contact-stream persistence.');
$mustContain('data-nexa-note-list', $view, 'The Notes tab must expose saved notes in the middle workspace column.');
$mustContain('data-nexa-notes-search', $view, 'The Notes tab must provide live note search.');
$mustContain('data-nexa-create-note', $view, 'The Notes tab must expose the standalone Create a note action.');
$mustContain('data-nexa-collapse-notes', $view, 'The Notes tab must support collapsing and expanding all visible notes.');
$mustContain('data-nexa-period-filter', $view, 'The Notes tab must provide date-range filtering.');
$mustContain('data-nexa-owner-filter', $view, 'The Notes tab must provide owner filtering.');
foreach (['Today', 'Yesterday', 'This week', 'Last week', 'Last 7 days', 'This month', 'Last month', 'Last 30 days', 'Last 90 days', 'Last quarter', 'This quarter', 'This year', 'Last year'] as $periodLabel) {
    $mustContain($periodLabel, $view, "The Notes date filter is missing {$periodLabel}.");
}
$mustContain("Espo.Ajax.getRequest('User'", $view, 'Owner choices must come from the tenant-scoped accessible User API.');
$mustContain('Deactivated and removed owners', $view, 'The owner filter must support inactive and removed note owners.');
$mustContain('data-nexa-owner-search', $view, 'The note-owner dropdown must provide live owner search.');
$mustContain('data-nexa-note-toggle', $view, 'Every note thread must provide an independent expand and collapse control.');
$mustContain('this.collapsedContactNoteIds?.add(note.id)', $view, 'Newly loaded notes must be collapsed by default.');
$mustContain('nexa-note-preview', $view, 'Collapsed notes must retain a one-line content preview.');
$mustContain('contactNotePreview', $view, 'Collapsed note previews must remove formatting syntax safely.');
$mustContain('nexa-note-month', $view, 'Notes must be grouped into readable month sections.');
$mustContain("groups.set('pinned'", $view, 'Pinned notes must remain in a dedicated group above chronological notes.');
$mustContain('data-nexa-note-actions-toggle', $view, 'Each note must expose a bold action menu beside its timestamp.');
$mustContain('data-nexa-note-actions${collapsed ?', $view, 'Note actions must be hidden while their note is collapsed.');
$mustContain("Espo.Ajax.postRequest(path)", $view, 'Pinning must use EspoCRM native Note pin persistence.');
$mustContain("Espo.Ajax.deleteRequest(path)", $view, 'Unpinning must use EspoCRM native Note pin persistence.');
$mustContain("this.getAcl().checkModel(model, 'delete')", $view, 'Note deletion controls must derive from authenticated record ACL and ownership.');
$mustContain("You don't have permission to delete this note", $view, 'Disabled note deletion must explain how to obtain access.');
$mustContain('overlay.dataset.nexaNoteDeleteDialog', $view, 'Note deletion must require a dedicated confirmation dialog.');
$mustContain('Espo.Ajax.deleteRequest(`Note/${encodeURIComponent(noteId)}`)', $view, 'Confirmed note deletion must use the authenticated native Note API.');
$mustContain('data-nexa-comment-form', $view, 'Saved notes must support team comments.');
$mustContain('data-nexa-comment-editor-host=', $view, 'Comments must provide a native rich-text editor host.');
$mustContain('mountContactCommentEditor', $view, 'Each expanded note must mount a managed native comment editor.');
$mustContain("const view = await this.createView(key, 'custom:views/fields/nexa-rich-text'", $view, 'Contact comments and replies must use the aligned tenant-aware rich editor.');
$mustContain('fetchToModel()', $view, 'Native rich-text values must be fetched into their Note model before saving.');
$mustContain('richTextIsEmpty', $view, 'Native rich-text submissions must reject visually empty HTML.');
$mustContain('this.getHelper().sanitizeHtml', $view, 'Stored rich-text note content must be sanitized before display.');
if (
    str_contains($view, 'data-nexa-note-command') ||
    str_contains($view, 'data-nexa-comment-command') ||
    str_contains($view, 'applyMarkdownFormat')
) {
    throw new RuntimeException('The obsolete Markdown editor must not remain in the Contact workspace.');
}
$mustContain("filter: 'posts'", $view, 'The Notes surface must fetch only Contact stream posts.');
$mustContain("classList.add('nexa-contact-detail-workspace', 'nexa-customer-workspace-loading')", $view, 'The native record must be concealed before the refreshed workspace is ready.');
$mustContain('finishWorkspaceLoading', $view, 'The workspace must clear its loading state after rendering.');
$mustContain("propertyCard('Key information'", $view, 'The left rail must retain the Key information card.');
$mustContain('data-nexa-inline-detail', $view, 'Displayed Contact properties must expose lightweight inline-edit controls.');
$mustContain("this.getAcl().checkModel(this.model, 'edit')", $view, 'Inline detail edits must enforce record permission.');
$mustContain("this.getAcl().checkField('Contact', name, 'edit')", $view, 'Inline detail edits must enforce field permission.');
$mustContain("await this.model.save(attributes, {patch: true})", $view, 'Inline detail edits must use authenticated patch-only model saves.');
$mustContain("keyEvent.key === 'Escape'", $view, 'Inline detail edits must support keyboard cancellation.');
$mustContain('nexa-inline-address-editor', $view, 'The combined address must remain editable without leaving the Contact page.');
if (str_contains($view, 'All contact properties') || str_contains($view, 'data-nexa-property-panels')) {
    throw new RuntimeException('The legacy All contact properties panel must not remain in the customer workspace.');
}
$mustContain("['Address', this.formatAddress(), false, 'address']", $view, 'Address components must render as one readable and inline-editable value.');
$mustContain("['Contact owner', this.ownerLink(), true]", $view, 'Key information must expose the linked Contact owner.');
$mustContain("['Lead status', this.leadStatusBadge(), true, 'leadStatus']", $view, 'Key information must use the shared editable Lead status badge presentation.');
$mustContain("['Created date', this.createdAudit(), true]", $view, 'Created date and creator must follow Lead status in Key information.');
$mustContain("this.getDateTime().toDisplay(value)", $view, 'Created date must use the configured local date-time formatter.');
$mustContain("this.model.get('createdById')", $view, 'The Contact audit summary must resolve the creating user.');
$mustContain('href="#User/view/${encodeURIComponent(id)}"', $view, 'The creating staff member must link to their user profile.');
$mustContain('.nexa-created-audit', $styles, 'Created date and staff must use the compact audit layout.');
$mustContain("this.getConfig().get('timeZone')", $view, 'An empty Contact timezone override must resolve to the configured default.');
$mustContain("['Website', this.externalLink(this.model.get('website')), true, 'website']", $view, 'Key information must expose a clean editable Website link.');
$mustContain('nexa-contact-owner-link', $view, 'The Contact owner must link to the user profile.');
$mustContain('socialProfilesCard()', $view, 'The left rail must expose recorded social profiles.');
$mustContain('profiles.filter(Boolean)', $view, 'Empty social profiles must not consume space in the compact card.');
$mustContain('target="_blank" rel="noopener noreferrer" role="listitem"', $view, 'Social profile links must safely open in a new tab.');
$mustContain('data-tooltip=', $view, 'Social profile icons must reveal their destination on hover and focus.');
$mustContain("replace(/^www\\./i, '')", $view, 'External profile labels must omit the www prefix.');
// Target Lists was intentionally replaced by a Payments placeholder in the
// same panel slot - see appendPaymentsPlaceholderPanel().
$mustContain("['accounts', 'opportunities', 'cases', 'documents']", $view, 'CRM associations must remain available in the customer workspace.');
$mustContain('loadContactRelationshipOverview', $view, 'Contact associations must use the dedicated relationship overview.');
$mustContain("{key: 'accounts', label: 'Accounts'", $view, 'Contact associations must load Account names from the authoritative relationship API.');
$mustContain('Contact/${encodeURIComponent(this.model.id)}/${definition.key}', $view, 'Contact relationship cards must use tenant-scoped relationship endpoints.');
$mustContain('maxSize: 6', $view, 'Contact relationship cards must keep a compact six-record preview.');
$mustContain('contactRelationshipCard', $view, 'Contact associations must use the same structured relationship-card experience as Accounts.');
$mustContain("'helpers/record/create-related'", $view, 'Contact association creation must use the native related-record helper.');
$mustContain('data-contact-relationship-create', $view, 'Opportunity, Case and Document cards must expose accessible create controls.');
$mustContain('await helper.process(this.model, definition.key', $view, 'New related records must be linked through the active Contact relationship.');
$mustContain("billingAddressCountry,shippingAddressCountry", $view, 'Account associations must load a country beside the Account name.');
$mustContain('nexa-contact-account-country', $styles, 'Account country labels must have a compact association-card presentation.');
$mustContain('nexa-native-association-source', $styles, 'Legacy native association tables must remain hidden behind the redesigned relationship rail.');
$mustContain('appendPaymentsPlaceholderPanel', $view, 'The Payments placeholder must occupy the slot Target Lists used to.');

// Sales/Marketing/Service were intentionally removed in favour of the
// redesigned Overview "customer 360" hub - see renderContactOverviewInsights.
foreach (['overview', 'activity', 'notes', 'tasks', 'meetings', 'calls'] as $domain) {
    $mustContain("data-nexa-tab-panel=\"{$domain}\"", $view, "The {$domain} customer workspace domain is missing.");
}

$mustContain('Marketing status', $view, 'Contact detail must expose marketing context.');
$mustContain('Preferences & activity', $view, 'Contact preferences, consent and website activity must share one concise card.');
$mustContain("['Legal basis', this.optionLabel('legalBasis', this.model.get('legalBasis')), false, 'legalBasis']", $view, 'The combined card must retain the editable recorded legal basis.');
$mustContain("['Last website visit', this.model.get('lastWebsiteVisitAt')]", $view, 'The combined card must retain identified website activity.');
foreach (['Communication & consent', 'Email opt-out', 'Marketing contact'] as $duplicateField) {
    if (str_contains($view, $duplicateField)) {
        throw new RuntimeException("Contact detail must not retain the duplicated {$duplicateField} summary.");
    }
}
$mustContain('.nexa-contact-detail-workspace', $styles, 'The responsive customer workspace styling is missing.');
$mustContain('grid-template-columns:', $styles, 'The customer workspace must provide a structured multi-column desktop layout.');
$mustContain('.record-buttons .actions-btn-group > .detail-action-item', $styles, 'Only top-level native action buttons may be hidden.');
$mustContain('.dropdown-menu .detail-action-item', $styles, 'Native dropdown actions must remain visible.');
$mustContain('.nexa-native-action-source .dropdown-menu', $styles, 'The native action menu must be anchored to the stable Nexa toolbar.');
$mustContain('.nexa-social-profile-grid', $styles, 'Social profiles must use the compact icon grid.');
$mustContain('.nexa-inline-detail-trigger', $styles, 'Inline detail fields must expose a compact hover and keyboard edit affordance.');
$mustContain('.nexa-inline-detail.is-error', $styles, 'Rejected inline updates must expose an error state.');
$mustContain('.nexa-customer-command-menu', $styles, 'The customer-action palette must have a stable unclipped presentation.');
$mustContain('.nexa-interaction-overlay', $styles, 'Manual interaction entry must use a centred modal surface.');
$mustContain('data-nexa-interaction-notes-editor', $view, 'Every Contact interaction channel must provide the shared rich-text notes editor.');
$mustContain("this.interactionNotesEditor = await this.createView('nexaInteractionNotesEditor', 'custom:views/fields/nexa-rich-text'", $view, 'Contact interaction notes must use the tenant-aware rich editor.');
$mustContain("this.noteEditorView = await this.createView('nexaNoteEditor', 'custom:views/fields/nexa-rich-text'", $view, 'Contact create-note must use the tenant-aware rich editor.');
$mustContain("a[data-nexa-file-id]", $view, 'Contact note validation must accept a securely attached file as content.');
if (str_contains($view, 'data-nexa-sms-notes-editor') || str_contains($view, '<textarea class="form-control" name="notes"')) {
    throw new RuntimeException('Contact interaction channels must not retain the SMS-only or plain-text notes editor.');
}
$mustContain('.nexa-note-dialog', $styles, 'The Contact Note action must use a responsive standalone popup.');
$mustContain('.nexa-native-rich-editor', $styles, 'The Contact note editor must use a full-width native WYSIWYG surface.');
$mustContain('.nexa-native-rich-editor .note-toolbar', $styles, 'The complete native formatting toolbar must remain visible and responsive.');
$mustContain('.nexa-contact-notes', $styles, 'Saved Contact notes must have a dedicated Notes surface.');
$mustContain('.nexa-activity-toolbar', $styles, 'The Activity workspace must provide a structured search and filter toolbar.');
$mustContain('.nexa-native-activity-source', $styles, 'Native activity panels must remain a hidden data source rather than a visible Stream.');
$mustContain('.nexa-activity-card', $styles, 'Each customer activity must use a stable standalone card.');
$mustContain('.nexa-activity-month > h4', $styles, 'Activities must be grouped under clear chronological headings.');
$mustContain('.nexa-activity-preview', $styles, 'Collapsed activities must retain a useful one-line summary.');
$mustContain('.nexa-activity-audit-details', $styles, 'Communication audit details must use a readable structured layout.');
$mustContain('.nexa-activity-empty', $styles, 'The Activity workspace must provide a clear empty result state.');
$mustContain('.nexa-notes-toolbar', $styles, 'The Notes workspace must provide a responsive search and action toolbar.');
$mustContain('.nexa-note-filter-menu', $styles, 'Notes filters must use stable accessible dropdown surfaces.');
$mustContain('.nexa-note-details[hidden]', $styles, 'Collapsed notes must remove their details from the visual layout.');
$mustContain('.nexa-note-preview', $styles, 'Collapsed notes must present a stable single-line preview.');
$mustContain('.nexa-note-actions-menu', $styles, 'Note Pin and Delete actions must use a stable compact menu.');
$mustContain('.nexa-note-card.is-pinned', $styles, 'Pinned notes must have a distinct visual treatment.');
$mustContain('background: #fff7d6', $styles, 'Pinned notes must use the warning-state background treatment.');
$mustContain('.nexa-note-action-disabled::after', $styles, 'Disabled Note deletion must provide an explanatory hover and focus tooltip.');
$mustContain('.nexa-note-delete-overlay', $styles, 'Note deletion confirmation must be centred over the application.');
$mustContain('.nexa-comment-editor', $styles, 'Comment entry must use a full-width rich editor.');
$mustContain('.nexa-comment-editor .note-editable', $styles, 'The native comment editing surface must fill the comment form.');
$mustContain('.nexa-note-comment-form > div:not(.nexa-native-rich-editor)', $styles, 'Comment action-row layout must not turn the editor host into a flex row.');
$mustContain('.nexa-comment-editor .note-toolbar > .note-btn-group', $styles, 'Comment toolbar controls must remain aligned in compact button groups.');
$mustContain('min-height: 120px', $styles, 'The rich comment editor must retain a usable writing area.');
$mustContain('.nexa-note-month > h4', $styles, 'Month groups must have a clear chronological heading.');
$mustContain('position: fixed', $styles, 'Customer-action overlays must not be clipped by independently scrolling Contact columns.');
$mustContain('@media (min-width: 901px)', $styles, 'Independent Contact column scrolling must be limited to the three-column desktop layout.');
$mustContain('grid-template-rows: 58px minmax(0, 1fr)', $styles, 'The Contact toolbar and scrollable column row must have stable dimensions.');
$mustContain('overscroll-behavior-y: contain', $styles, 'Contact columns must contain their independent scroll positions.');
$mustContain('scrollbar-gutter: stable', $styles, 'Contact column scrollbars must not shift the three-column layout.');
$mustContain('.nexa-contact-detail-workspace .nexa-customer-tabs', $styles, 'The centre workspace tabs must remain available while its column scrolls.');
$mustContain('body.nexa-contact-detail-page', $styles, 'The Contact detail workspace must retain its fixed desktop shell state.');
if (str_contains($styles, 'body.nexa-contact-detail-page > footer')) {
    throw new RuntimeException('Contact detail must not position a footer inside the body workspace.');
}
$mustContain('client/custom/img/social/${asset}', $view, 'Social profiles must use portable local brand-image URLs.');
$mustContain('.nexa-social-profile-link img', $styles, 'Social brand images must fit the compact icon controls.');
$mustContain('tiktok.svg', $view, 'TikTok must use its real brand asset rather than a generic music glyph.');
$mustContain('@media (max-width: 600px)', $styles, 'The customer workspace must provide a mobile layout.');

if (str_contains($navigation, "['nexa-customer-360', 'Customer 360']") ||
    str_contains($registry, "['nexa-customer-360', 'Customer 360']")) {
    throw new RuntimeException('Customer 360 must not remain as a duplicate CRM navigation destination.');
}
if (str_contains($view, "propertyCard('CRM details'")) {
    throw new RuntimeException('CRM details must not duplicate lifecycle and ownership information in the left rail.');
}

fwrite(STDOUT, "Contact customer workspace contracts passed.\n");
