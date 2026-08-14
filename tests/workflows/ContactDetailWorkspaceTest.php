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

if (($clientDefs['recordViews']['detail'] ?? '') !== 'custom:views/contact/record/detail-workspace') {
    throw new RuntimeException('Contact detail must use the customer workspace record view.');
}

$mustContain("['crm:views/contact/record/detail']", $view, 'The workspace must extend the native Contact detail record.');
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
$mustContain('nexa-contact-meta', $view, 'Title, company and email must share a full-width row below the Contact identity.');
$mustContain('.nexa-customer-identity .nexa-contact-meta', $styles, 'Contact identity metadata must have a dedicated responsive layout rule.');
$mustContain('grid-column: 1 / -1', $styles, 'Contact identity metadata must span the complete card width below the avatar.');
$mustContain('data-nexa-edit-contact', $view, 'The top toolbar must retain the complete Contact edit action.');
$mustContain('data-nexa-more-actions', $view, 'Contact actions must expose a familiar More command control.');
$mustContain('Search customer actions', $view, 'The expanded customer-action catalogue must be searchable.');
$mustContain('aria-label="More customer actions"', $view, 'The More control and command palette must have an accessible name.');
foreach (['Log SMS', 'Log WhatsApp message', 'Log LinkedIn message', 'Log call', 'Log meeting', 'Log email', 'Log postal mail'] as $actionLabel) {
    $mustContain($actionLabel, $view, "The Contact command palette is missing {$actionLabel}.");
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
$mustContain('data-nexa-activity-search', $view, 'Activities must provide live search across native records and Nexa notes.');
$mustContain('data-nexa-activity-type', $view, 'Activities must provide a type filter for notes, timeline, upcoming work, history and tasks.');
$mustContain('data-nexa-activity-period', $view, 'Activities must provide the same date-range choices as Notes.');
$mustContain('observeContactActivity', $view, 'Native activity rows loaded asynchronously must be included in the active filters.');
$mustContain('contactActivityRows', $view, 'Activity filtering must target individual native records rather than only whole panels.');
$mustContain("this.contactNoteCard(note, this.contactNoteComments?.get(note.id) || [], false, 'activity')", $view, 'Nexa notes in Activities must use the same interactive card as the Notes tab.');
$mustContain('const editorId = `${context}-${noteId}`', $view, 'Note comment editors must remain isolated between Notes and Activities surfaces.');
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
$mustContain("['accounts', 'opportunities', 'cases', 'documents', 'targetLists']", $view, 'CRM associations must remain available in the customer workspace.');

foreach (['overview', 'activity'] as $domain) {
    $mustContain("data-nexa-tab-panel=\"{$domain}\"", $view, "The {$domain} customer workspace domain is missing.");
}
foreach (['sales', 'marketing', 'service'] as $domain) {
    $mustContain("domainPanel('{$domain}'", $view, "The {$domain} customer workspace domain is missing.");
}

$mustContain('Marketing engagement', $view, 'Contact detail must expose marketing context.');
$mustContain('Preferences & activity', $view, 'Contact preferences, consent and website activity must share one concise card.');
$mustContain("['Legal basis', this.optionLabel('legalBasis', this.model.get('legalBasis')), false, 'legalBasis']", $view, 'The combined card must retain the editable recorded legal basis.');
$mustContain("['Last website visit', this.model.get('lastWebsiteVisitAt')]", $view, 'The combined card must retain identified website activity.');
foreach (['Communication & consent', 'Website activity', 'Email opt-out', 'Marketing contact'] as $duplicateField) {
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
$mustContain('.nexa-note-dialog', $styles, 'The Contact Note action must use a responsive standalone popup.');
$mustContain('.nexa-native-rich-editor', $styles, 'The Contact note editor must use a full-width native WYSIWYG surface.');
$mustContain('.nexa-native-rich-editor .note-toolbar', $styles, 'The complete native formatting toolbar must remain visible and responsive.');
$mustContain('.nexa-contact-notes', $styles, 'Saved Contact notes must have a dedicated Notes surface.');
$mustContain('.nexa-activity-toolbar', $styles, 'The Activity workspace must provide a structured search and filter toolbar.');
$mustContain('.nexa-native-activity .stream-date-container', $styles, 'Native activity date and time values must remain visibly styled.');
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
