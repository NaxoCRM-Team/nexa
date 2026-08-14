<div class="nexa-bulk-assign-form">
    <label id="nexa-contact-owner-label">Contact owner</label>
    <div class="nexa-owner-picker">
        <button class="form-control nexa-owner-trigger" type="button" data-action="toggleOwners" aria-labelledby="nexa-contact-owner-label" aria-haspopup="listbox" aria-expanded="false">
            <span data-name="selectedOwner">No owner</span>
            <span class="fas fa-chevron-down" aria-hidden="true"></span>
        </button>
        <div class="nexa-owner-panel" data-name="ownerPanel" hidden>
            <div class="nexa-owner-search-wrap">
                <span class="fas fa-search" aria-hidden="true"></span>
                <input class="form-control" type="search" data-name="ownerSearch" placeholder="Search tenant users" autocomplete="off" aria-label="Search tenant users">
            </div>
            <ul class="nexa-owner-options" data-name="ownerOptions" role="listbox" aria-labelledby="nexa-contact-owner-label">
                <li class="nexa-owner-option-state">Loading owners...</li>
            </ul>
        </div>
    </div>
</div>
