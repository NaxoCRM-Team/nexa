<div class="nexa-file-library">
    <div class="nexa-image-library-toolbar">
        <label class="nexa-image-library-search">
            <span class="fas fa-search" aria-hidden="true"></span>
            <span class="sr-only">Search tenant files</span>
            <input type="search" class="form-control" data-nexa-file-search placeholder="Search files" autocomplete="off">
        </label>
        <label class="btn btn-primary nexa-image-library-upload">
            <span class="fas fa-upload" aria-hidden="true"></span><span>Upload file</span>
            <input type="file" data-nexa-file-upload accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.rtf,.zip,.png,.jpg,.jpeg,.gif,.webp" hidden>
        </label>
    </div>
    <div class="nexa-image-library-meta"><strong data-nexa-file-count>0 files</strong><span>Images up to 8 MB; documents up to 25 MB</span></div>
    <p class="nexa-image-library-error" data-nexa-file-error role="alert" hidden></p>
    <div class="nexa-file-library-list" data-nexa-file-list aria-live="polite"></div>
    <footer class="nexa-image-library-pagination">
        <button type="button" class="btn btn-default" data-nexa-file-previous aria-label="Previous files"><span class="fas fa-chevron-left" aria-hidden="true"></span></button>
        <span data-nexa-file-page>0 of 0</span>
        <button type="button" class="btn btn-default" data-nexa-file-next aria-label="Next files"><span class="fas fa-chevron-right" aria-hidden="true"></span></button>
    </footer>
</div>
