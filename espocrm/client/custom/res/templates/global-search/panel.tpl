<section class="panel panel-default nexa-search-results" aria-labelledby="nexa-search-results-title">
    <div class="panel-heading">
        <div class="link-group">
            <button type="button" class="btn btn-link close-link" data-action="closePanel" aria-label="Close search results"><span class="fas fa-times" aria-hidden="true"></span></button>
        </div>
        <strong id="nexa-search-results-title">Search results</strong>
        <span class="text-soft">for “{{query}}”</span>
    </div>
    <div class="panel-body">
        <div class="nexa-search-result-status" role="status" aria-live="polite"></div>
        <button type="button" class="btn btn-default" data-action="retrySearch" hidden>Try again</button>
        <div class="list-container" hidden></div>
    </div>
</section>
