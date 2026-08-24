define('custom:views/account/import', ['view'], Dep => {
    const MAX_FILE_BYTES = 65 * 1024 * 1024;
    const MAX_ROW_LIMIT = 100000;

    return class extends Dep {
        template = 'custom:account/import';

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
            this.setPageTitle('Import Accounts');
        }

        data() {
            return {accountUrl: '#Account', defaultRowLimit: 5000, maxRowLimit: MAX_ROW_LIMIT.toLocaleString()};
        }

        actionCancel() {
            this.getRouter().navigate('#Account', {trigger: true});
        }

        actionChooseFile() {
            this.$el.find('[data-name="file"]').trigger('click');
        }

        actionPreviousPreviewPage() {
            if (this.previewPage > 1) this.loadPreviewPage(this.previewPage - 1);
        }

        actionNextPreviewPage() {
            if (this.previewPage < this.previewTotalPages) this.loadPreviewPage(this.previewPage + 1);
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
            if (file) this.setFile(file);
        }

        handleFileChange(event) {
            const file = event.currentTarget.files?.[0];
            if (file) this.setFile(file);
        }

        setFile(file) {
            this.file = file;
            this.attachmentId = null;
            this.validation = null;
            this.previewPage = 1;
            this.previewTotalPages = 1;
            this.clearResults();

            const extension = file.name.toLowerCase().split('.').pop();
            const $name = this.$el.find('[data-name="fileName"]');
            const $validate = this.$el.find('[data-action="validate"]');
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
                const csv = await Espo.Ajax.getRequest('Nexa/account-import/template', null, {dataType: 'text'});
                const url = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
                const link = document.createElement('a');
                link.href = url;
                link.download = 'nexa-account-import-template.csv';
                document.body.append(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
            } catch (error) {
                Espo.Ui.error('The Account template could not be downloaded.');
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
            if (!rowLimit) return;

            this.setBusy(true, 'Checking every account row...');
            this.clearResults();
            try {
                const contents = await this.file.arrayBuffer();
                const result = await Espo.Ajax.request(
                    `Nexa/account-import/preview?rowLimit=${rowLimit}`,
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
                this.showInlineError(this.getRequestError(xhr, 'The Account file could not be validated.'));
            } finally {
                this.setBusy(false);
            }
        }

        async loadPreviewPage(page) {
            if (!this.attachmentId) return;
            this.setPreviewBusy(true);
            try {
                const result = await Espo.Ajax.postRequest('Nexa/account-import/preview-page', {
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
                this.showInlineError('Validate the file before importing it.');
                return;
            }
            const rowLimit = this.getRowLimit();
            if (!rowLimit) return;

            this.setBusy(true, 'Importing accounts...');
            try {
                const result = await Espo.Ajax.postRequest('Nexa/account-import/confirm', {
                    attachmentId: this.attachmentId,
                    rowLimit,
                }, {timeout: 300000});
                const hasErrors = result.errors > 0;
                this.$el.find('[data-name="result"]')
                    .removeClass('hidden alert-danger alert-success')
                    .addClass(hasErrors ? 'alert-danger' : 'alert-success')
                    .text(`${result.created} accounts created, ${result.duplicates} duplicates skipped, ${result.errors} errors.`);
                this.renderErrors(result.errorDetails || []);
                this.$el.find('[data-action="import"]').prop('disabled', true);
                this.$el.find('[data-name="stepImport"]').addClass('is-complete');
                hasErrors ? Espo.Ui.warning('Account import completed with errors.') : Espo.Ui.success('Account import completed.');
            } catch (xhr) {
                this.showInlineError(this.getRequestError(xhr, 'The Account import could not be completed.'));
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
            this.$el.find('[data-name="summary"]').removeClass('hidden').text(
                `${result.rowCount.toLocaleString()} account rows checked against a ${result.rowLimit.toLocaleString()} row limit.`
            );
            this.renderExistingMatch(result.existingMatch);
            if (!result.valid) {
                this.$el.find('[data-name="result"]').removeClass('hidden alert-success').addClass('alert-danger')
                    .text('The file needs attention before it can be imported.');
                this.renderErrors(result.errors || []);
                return;
            }

            this.$el.find('[data-name="result"]').removeClass('hidden alert-danger').addClass('alert-success')
                .text('Validation passed. Review the preview, then import the accounts.');
            this.$el.find('[data-name="errors"]').addClass('hidden').empty();
            this.renderPreview(result.preview || []);
            this.updatePreviewPagination();
            this.$el.find('[data-action="import"]').prop('disabled', false);
            this.$el.find('[data-name="stepValidate"]').addClass('is-complete');
        }

        renderExistingMatch(match) {
            const $element = this.$el.find('[data-name="existingMatch"]');
            if (!match?.matched) {
                $element.addClass('hidden').empty();
                return;
            }
            const examples = (match.examples || []).join(', ');
            $element.removeClass('hidden').addClass('alert-warning')
                .text(`${match.matched} company names already exist in this workspace and will be checked as duplicates${examples ? `: ${examples}` : '.'}`);
        }

        renderErrors(errors) {
            const container = this.$el.find('[data-name="errors"]')[0];
            container.replaceChildren();
            container.classList.toggle('hidden', !errors.length);
            errors.forEach(error => {
                const item = document.createElement('li');
                item.textContent = (error.row ? `Row ${error.row}, ${error.field}: ` : '') + error.message;
                container.append(item);
            });
        }

        renderPreview(rows) {
            const tbody = this.$el.find('[data-name="previewBody"]')[0];
            tbody.replaceChildren();
            rows.forEach(row => {
                const tr = document.createElement('tr');
                [row.company_name, row.website, row.industry, row.account_type, row.annual_revenue, row.address_country]
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
            const $pagination = this.$el.find('[data-name="previewPagination"]');
            $pagination.toggleClass('hidden', this.previewTotalPages <= 1);
            this.$el.find('[data-name="previewPageStatus"]')
                .text(`Page ${this.previewPage.toLocaleString()} of ${this.previewTotalPages.toLocaleString()}`);
            this.$el.find('[data-action="previousPreviewPage"]').prop('disabled', this.previewPage <= 1);
            this.$el.find('[data-action="nextPreviewPage"]').prop('disabled', this.previewPage >= this.previewTotalPages);
        }

        setPreviewBusy(isBusy) {
            this.$el.find('[data-name="previewTable"]').toggleClass('is-loading', isBusy).attr('aria-busy', String(isBusy));
            this.$el.find('[data-action="previousPreviewPage"], [data-action="nextPreviewPage"]').prop('disabled', isBusy);
        }

        clearResults() {
            this.$el.find('[data-name="result"], [data-name="summary"], [data-name="existingMatch"], [data-name="errors"], [data-name="preview"]')
                .addClass('hidden');
            this.$el.find('[data-name="errors"], [data-name="previewBody"]').empty();
            this.$el.find('[data-name="previewPagination"]').addClass('hidden');
            this.$el.find('[data-action="import"]').prop('disabled', true);
            this.$el.find('[data-name="stepValidate"], [data-name="stepImport"]').removeClass('is-complete');
        }

        showInlineError(message) {
            this.$el.find('[data-name="result"]').removeClass('hidden alert-success').addClass('alert-danger').text(message);
        }

        setBusy(isBusy, message = '') {
            this.$el.attr('aria-busy', String(isBusy));
            this.$el.find('[data-name="busy"]')[isBusy ? 'removeClass' : 'addClass']('hidden')
                .find('[data-name="busyText"]').text(message);
            this.$el.find('[data-action="validate"], [data-action="import"], [data-action="chooseFile"]').prop('disabled', isBusy);
            if (!isBusy) {
                this.$el.find('[data-action="validate"]').prop('disabled', !this.file);
                this.$el.find('[data-action="import"]').prop('disabled', !this.validation?.valid || !this.attachmentId);
            }
        }

        getRequestError(xhr, fallback) {
            try {
                return JSON.parse(xhr.responseText || '{}').message || fallback;
            } catch (error) {
                return fallback;
            }
        }

        formatBytes(bytes) {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        }
    };
});
