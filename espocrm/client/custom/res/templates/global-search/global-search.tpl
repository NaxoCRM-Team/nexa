<div class="input-group has-feedback nexa-global-search" role="search">
    <label class="sr-only" for="nexa-global-search-input">Search across this workspace</label>
    <input
        id="nexa-global-search-input"
        type="search"
        class="form-control global-search-input"
        placeholder="Search customers, deals and more"
        autocomplete="off"
        spellcheck="false"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="nexa-search-suggestions"
        aria-haspopup="listbox"
    >
    {{#if hasSearchButton}}
    <div class="input-group-btn">
        <button type="button" class="btn btn-link global-search-button" data-action="search" title="Search workspace" aria-label="Search workspace">
            <span class="fas fa-search icon" aria-hidden="true"></span>
        </button>
    </div>
    {{/if}}
</div>
<section class="nexa-search-suggestion-panel" aria-label="Search suggestions" hidden>
    <header>
        <strong class="nexa-search-suggestions-heading">Recent searches</strong>
        <button type="button" class="btn btn-link btn-xs" data-action="clearRecentSearches" hidden>Clear</button>
    </header>
    <div id="nexa-search-suggestions" class="nexa-search-suggestions" role="listbox"></div>
    <p class="nexa-search-shortcut"><kbd>Ctrl</kbd> + <kbd>K</kbd> to search from anywhere</p>
</section>
<div class="global-search-panel-container"></div>
