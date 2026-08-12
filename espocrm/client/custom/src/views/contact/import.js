define('custom:views/contact/import', ['view'], Dep => {
    const MAX_FILE_BYTES = 65 * 1024 * 1024;
    const MAX_ROW_LIMIT = 100000;

    return class extends Dep {
        template = 'custom:contact/import';

        events = {
            'click [data-action="downloadTemplate"]': 'actionDownloadTemplate',
            'click [data-action="chooseFile"]': 'actionChooseFile',
            'click [data-action="validate"]': 'actionValidate',
            'click [data-action="import"]': 'actionImport',
            'click [data-action="cancel"]': 'actionCancel',
            'click [data-action="previousPreviewPage"]': 'actionPreviousPreviewPage',
            'click [data-action="nextPreviewPage"]': 'actionNextPreviewPage',
            'change [data-name="file"]': 'handleFileChange',
            'dragover [data-name="dropZone"]': 'handleDragOver',
            'dragleave [data-name="dropZone"]': 'handleDragLeave',
            'drop [data-name="dropZone"]': 'handleDrop',
        };

        setup() {
            this.file = null;
            this.attachmentId = null;
            this.validation = null;
            this.previewPage = 1;
            this.previewTotalPages = 1;
            this.setPageTitle('Import Contacts');
        }

        data() {
            return {
                contactUrl: '#Contact',
                defaultRowLimit: 5000,
                maxRowLimit: MAX_ROW_LIMIT.toLocaleString(),
            };
        }

        actionCancel() {
            this.getRouter().navigate('#Contact', {trigger: true});
        }

        actionPreviousPreviewPage() {
            if (this.previewPage > 1) {
                this.loadPreviewPage(this.previewPage - 1);
            }
        }

        actionNextPreviewPage() {
            if (this.previewPage < this.previewTotalPages) {
                this.loadPreviewPage(this.previewPage + 1);
            }
        }

        actionChooseFile() {
            this.$el.find('[data-name="file"]').trigger('click');
        }

        handleDragOver(event) {
            event.preventDefault();
            event.currentTarget.classList.add('is-dragging');
        }

        handleDragLeave(event) {
            event.currentTarget.classList.remove('is-dragging');
        }

        handleDrop(event) {
            event.preventDefault();
            event.currentTarget.classList.remove('is-dragging');

            const file = event.originalEvent?.dataTransfer?.files?.[0];

            if (file) {
                this.setFile(file);
            }
        }

        handleFileChange(event) {
            const file = event.currentTarget.files?.[0];

            if (file) {
                this.setFile(file);
            }
        }

        setFile(file) {
            this.file = file;
            this.attachmentId = null;
            this.validation = null;
            this.previewPage = 1;
            this.previewTotalPages = 1;
            this.clearResults();

            const $name = this.$el.find('[data-name="fileName"]');
            const $validate = this.$el.find('[data-action="validate"]');

            const extension = file.name.toLowerCase().split('.').pop();

            if (!['csv', 'xlsx', 'xls'].includes(extension)) {
                $name.text('Choose a .csv, .xlsx or .xls file.').addClass('text-danger');
                $validate.prop('disabled', true);
                return;
            }

            if (file.size > MAX_FILE_BYTES) {
                $name.text('This file is larger than 65 MB.').addClass('text-danger');
                $validate.prop('disabled', true);
                return;
            }

            $name.text(`${file.name} (${this.formatBytes(file.size)})`).removeClass('text-danger');
            $validate.prop('disabled', false);
        }

        async actionDownloadTemplate() {
            const button = this.$el.find('[data-action="downloadTemplate"]');
            button.prop('disabled', true);

            try {
                const csv = await Espo.Ajax.getRequest(
                    'Nexa/contact-import/template',
                    null,
                    {dataType: 'text'}
                );
                const url = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
                const link = document.createElement('a');
                link.href = url;
                link.download = 'nexa-contact-import-template.csv';
                document.body.append(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
            } catch (error) {
                Espo.Ui.error('The Contact template could not be downloaded.');
            } finally {
                button.prop('disabled', false);
            }
        }

        async actionValidate() {
            if (!this.file) {
                this.showInlineError('Choose a .csv, .xlsx or .xls file first.');
                return;
            }

            const rowLimit = this.getRowLimit();

            if (!rowLimit) {
                return;
            }

            this.setBusy(true, 'Checking every row...');
            this.clearResults();

            try {
                const contents = await this.file.arrayBuffer();
                const result = await Espo.Ajax.request(
                    `Nexa/contact-import/preview?rowLimit=${rowLimit}`,
                    'POST',
                    contents,
                    {
                        contentType: this.file.type || 'application/octet-stream',
                        processData: false,
                        headers: {'X-Nexa-File-Name': encodeURIComponent(this.file.name)},
                        timeout: 180000,
                    }
                );

                this.validation = result;
                this.attachmentId = result.attachmentId || null;
                this.previewPage = result.previewPage || 1;
                this.previewTotalPages = result.previewTotalPages || 1;
                this.renderValidation(result);
            } catch (xhr) {
                this.showInlineError(this.getRequestError(xhr, 'The import file could not be validated.'));
            } finally {
                this.setBusy(false);
            }
        }

        async loadPreviewPage(page) {
            if (!this.attachmentId) {
                return;
            }

            this.setPreviewBusy(true);

            try {
                const result = await Espo.Ajax.postRequest('Nexa/contact-import/preview-page', {
                    attachmentId: this.attachmentId,
                    page,
                    pageSize: 20,
                }, {timeout: 180000});

                this.previewPage = result.page;
                this.previewTotalPages = result.totalPages;
                this.renderPreview(result.preview || []);
                this.updatePreviewPagination();
            } catch (xhr) {
                this.showInlineError(this.getRequestError(xhr, 'The requested preview page could not be loaded.'));
            } finally {
                this.setPreviewBusy(false);
            }
        }

        async actionImport() {
            if (!this.validation?.valid || !this.attachmentId) {
                this.showInlineError('Validate the CSV before importing it.');
                return;
            }

            const rowLimit = this.getRowLimit();

            if (!rowLimit) {
                return;
            }

            this.setBusy(true, 'Importing contacts...');

            try {
                const result = await Espo.Ajax.postRequest('Nexa/contact-import/confirm', {
                    attachmentId: this.attachmentId,
                    rowLimit,
                    createMissingAccounts: this.$el.find('[data-name="createMissingAccounts"]').prop('checked'),
                }, {timeout: 300000});

                const hasErrors = result.errors > 0;
                this.$el.find('[data-name="result"]')
                    .removeClass('hidden alert-danger alert-success')
                    .addClass(hasErrors ? 'alert-danger' : 'alert-success')
                    .text([
                        `${result.created} contacts created`,
                        `${result.accountsMatched} existing accounts matched`,
                        `${result.accountsCreated} accounts created`,
                        `${result.accountsUnlinked} company names left unlinked`,
                        `${result.duplicates} duplicates skipped`,
                        `${result.errors} errors`,
                    ].join(', ') + '.');
                this.renderErrors(result.errorDetails || []);
                this.$el.find('[data-action="import"]').prop('disabled', true);
                this.$el.find('[data-name="stepImport"]').addClass('is-complete');
                if (hasErrors) {
                    Espo.Ui.warning('Contact import completed with errors.');
                } else {
                    Espo.Ui.success('Contact import completed.');
                }
            } catch (xhr) {
                this.showInlineError(this.getRequestError(xhr, 'The Contact import could not be completed.'));
            } finally {
                this.setBusy(false);
            }
        }

        getRowLimit() {
            const input = this.$el.find('[data-name="rowLimit"]');
            const value = Number.parseInt(input.val(), 10);

            if (!Number.isInteger(value) || value < 1 || value > MAX_ROW_LIMIT) {
                input.attr('aria-invalid', 'true').focus();
                this.showInlineError('Row limit must be between 1 and 100,000.');
                return null;
            }

            input.removeAttr('aria-invalid');
            return value;
        }

        renderValidation(result) {
            const $result = this.$el.find('[data-name="result"]');
            const $summary = this.$el.find('[data-name="summary"]');
            const $errors = this.$el.find('[data-name="errors"]');
            const $accountMatch = this.$el.find('[data-name="accountMatch"]');

            $summary.removeClass('hidden').text(
                `${result.rowCount.toLocaleString()} contact rows checked against a ${result.rowLimit.toLocaleString()} row limit.`
            );

            this.renderAccountMatch(result.accountMatch);

            if (!result.valid) {
                $result.removeClass('hidden alert-success').addClass('alert-danger')
                    .text('The file needs attention before it can be imported.');
                this.renderErrors(result.errors || []);
                this.$el.find('[data-action="import"]').prop('disabled', true);
                return;
            }

            $result.removeClass('hidden alert-danger').addClass('alert-success')
                .text('Validation passed. Review the preview, then import the contacts.');
            $errors.addClass('hidden').empty();
            this.renderPreview(result.preview || []);
            this.updatePreviewPagination();
            this.$el.find('[data-action="import"]').prop('disabled', false);
            this.$el.find('[data-name="stepValidate"]').addClass('is-complete');
        }

        renderAccountMatch(match) {
            const $element = this.$el.find('[data-name="accountMatch"]');

            if (!match?.requested) {
                $element.addClass('hidden').empty();
                return;
            }

            if (!match.unmatchedCount) {
                $element.removeClass('hidden alert-warning').addClass('alert-success')
                    .text(`${match.matched} account names matched in this workspace.`);
                return;
            }

            const examples = (match.unmatched || []).join(', ');
            $element.removeClass('hidden alert-success').addClass('alert-warning')
                .text(`${match.matched} account names matched. ${match.unmatchedCount} missing accounts will be created if the option below remains selected: ${examples}`);
        }

        renderErrors(errors) {
            const container = this.$el.find('[data-name="errors"]')[0];
            container.replaceChildren();
            container.classList.remove('hidden');

            errors.forEach(error => {
                const item = document.createElement('li');
                const location = error.row ? `Row ${error.row}, ${error.field}: ` : '';
                item.textContent = location + error.message;
                container.append(item);
            });
        }

        renderPreview(rows) {
            const tbody = this.$el.find('[data-name="previewBody"]')[0];
            tbody.replaceChildren();

            rows.forEach(row => {
                const tr = document.createElement('tr');

                [row.first_name, row.last_name, row.email, row.phone, row.account_name, row.contact_source]
                    .forEach(value => {
                        const td = document.createElement('td');
                        td.textContent = value || '-';
                        tr.append(td);
                    });

                tbody.append(tr);
            });

            this.$el.find('[data-name="preview"]')[rows.length ? 'removeClass' : 'addClass']('hidden');
        }

        updatePreviewPagination() {
            const hasMultiplePages = this.previewTotalPages > 1;
            const $pagination = this.$el.find('[data-name="previewPagination"]');

            $pagination.toggleClass('hidden', !hasMultiplePages);
            this.$el.find('[data-name="previewPageStatus"]')
                .text(`Page ${this.previewPage.toLocaleString()} of ${this.previewTotalPages.toLocaleString()}`);
            this.$el.find('[data-action="previousPreviewPage"]').prop('disabled', this.previewPage <= 1);
            this.$el.find('[data-action="nextPreviewPage"]').prop('disabled', this.previewPage >= this.previewTotalPages);
        }

        setPreviewBusy(isBusy) {
            this.$el.find('[data-name="previewTable"]')
                .toggleClass('is-loading', isBusy)
                .attr('aria-busy', isBusy ? 'true' : 'false');
            this.$el.find('[data-action="previousPreviewPage"], [data-action="nextPreviewPage"]')
                .prop('disabled', isBusy);
        }

        clearResults() {
            this.$el.find('[data-name="result"], [data-name="summary"], [data-name="accountMatch"], [data-name="errors"], [data-name="preview"]')
                .addClass('hidden');
            this.$el.find('[data-name="errors"], [data-name="previewBody"]').empty();
            this.$el.find('[data-name="previewPagination"]').addClass('hidden');
            this.$el.find('[data-action="import"]').prop('disabled', true);
            this.$el.find('[data-name="stepValidate"], [data-name="stepImport"]').removeClass('is-complete');
        }

        showInlineError(message) {
            this.$el.find('[data-name="result"]')
                .removeClass('hidden alert-success')
                .addClass('alert-danger')
                .text(message);
        }

        setBusy(isBusy, message = '') {
            this.$el.attr('aria-busy', isBusy ? 'true' : 'false');
            this.$el.find('[data-name="busy"]')[isBusy ? 'removeClass' : 'addClass']('hidden')
                .find('[data-name="busyText"]').text(message);
            this.$el.find('[data-action="validate"], [data-action="import"], [data-action="chooseFile"]')
                .prop('disabled', isBusy);

            if (!isBusy) {
                this.$el.find('[data-action="validate"]').prop('disabled', !this.file);
                this.$el.find('[data-action="import"]').prop('disabled', !this.validation?.valid || !this.attachmentId);
            }
        }

        getRequestError(xhr, fallback) {
            try {
                const body = JSON.parse(xhr.responseText || '{}');
                return body.message || fallback;
            } catch (error) {
                return fallback;
            }
        }

        formatBytes(bytes) {
            if (bytes < 1024) {
                return `${bytes} B`;
            }

            if (bytes < 1024 * 1024) {
                return `${(bytes / 1024).toFixed(1)} KB`;
            }

            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        }
    };
});
