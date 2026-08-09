require([
    'views/list',
    'views/detail',
    'views/edit',
    'views/record/list',
    'views/record/list-expanded',
    'views/record/detail',
    'views/record/edit',
], (ListView, DetailView, EditView, RecordListView, ExpandedListView, RecordDetailView, RecordEditView) => {
    const viewTypes = [ListView, DetailView, EditView, RecordListView, ExpandedListView, RecordDetailView, RecordEditView];

    const visible = element => element && !element.classList.contains('hidden') && element.offsetParent !== null;
    const scopeLabel = view => {
        const scope = view.scope || view.entityType || view.collection?.name || view.model?.entityType || 'CRM';
        return view.translate?.(scope, 'scopeNamesPlural') || scope;
    };

    const ensureLiveStatus = (root, className) => {
        let status = root.querySelector(`.${className}`);

        if (!status) {
            status = document.createElement('p');
            status.className = `${className} sr-only`;
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            root.prepend(status);
        }

        return status;
    };

    const enhancePagination = root => {
        root.querySelectorAll('.pagination').forEach(pagination => {
            pagination.setAttribute('role', 'navigation');
            pagination.setAttribute('aria-label', 'Record pages');
            pagination.querySelectorAll('[data-page]').forEach(control => {
                const disabled = control.classList.contains('disabled');
                control.setAttribute('aria-disabled', String(disabled));
                if (disabled) control.setAttribute('tabindex', '-1');
            });
            const pageInput = pagination.querySelector('.page-input');
            pageInput?.setAttribute('aria-label', 'Go to page');
        });
    };

    const enhanceSelection = (view, root) => {
        const toolbar = root.querySelector('.list-buttons-container');
        if (!toolbar) return;

        toolbar.classList.add('nexa-list-toolbar');
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', `${scopeLabel(view)} list actions`);
        const status = ensureLiveStatus(toolbar, 'nexa-list-selection-status');

        const update = () => {
            const selected = [...root.querySelectorAll('tbody input[type="checkbox"]')]
                .filter(input => input.checked).length;
            const message = selected ? `${selected} record${selected === 1 ? '' : 's'} selected.` : '';
            if (status.textContent !== message) status.textContent = message;
            root.classList.toggle('nexa-has-selection', selected > 0);
            root.querySelectorAll('.actions-button').forEach(button => {
                button.setAttribute('aria-label', selected ? `Bulk actions for ${selected} selected records` : 'Bulk actions');
            });
        };

        if (root.dataset.nexaSelectionBound !== 'true') {
            root.dataset.nexaSelectionBound = 'true';
            root.addEventListener('change', event => {
                if (event.target.matches('input[type="checkbox"]')) window.setTimeout(update, 0);
            });
        }
        update();
    };

    const enhanceList = view => {
        const root = view.element;
        if (!root) return;

        root.classList.add('nexa-crm-list-workflow');
        const search = root.closest('.list-container')?.parentElement?.querySelector('.search-container') ||
            document.querySelector('.search-container');
        search?.setAttribute('role', 'search');
        search?.setAttribute('aria-label', `Filter ${scopeLabel(view)}`);

        root.querySelectorAll('.list').forEach((list, listIndex) => {
            list.classList.add('nexa-record-list');
            list.setAttribute('role', 'region');
            list.setAttribute('aria-label', `${scopeLabel(view)} records${listIndex ? ` section ${listIndex + 1}` : ''}`);
            list.setAttribute('tabindex', '0');
        });

        root.querySelectorAll('table.table').forEach((table, tableIndex) => {
            table.classList.add('nexa-crm-table');
            if (!table.querySelector('caption')) {
                const caption = document.createElement('caption');
                caption.className = 'sr-only';
                caption.textContent = `${scopeLabel(view)} record list ${tableIndex + 1}`;
                table.prepend(caption);
            }

            table.querySelectorAll('th.field-header-cell').forEach(header => {
                const sort = header.querySelector('.sort');
                if (!sort) return;
                const sorted = Boolean(header.querySelector('.fa-chevron-down, .fa-chevron-up'));
                const descending = Boolean(header.querySelector('.fa-chevron-up'));
                header.setAttribute('aria-sort', sorted ? (descending ? 'descending' : 'ascending') : 'none');
                sort.setAttribute('aria-label', `Sort by ${sort.textContent.trim()}`);
            });

            const selectAll = table.querySelector('.select-all');
            selectAll?.setAttribute('aria-label', `Select all visible ${scopeLabel(view)} records`);
            table.querySelectorAll('tbody input[type="checkbox"]').forEach((checkbox, index) => {
                checkbox.setAttribute('aria-label', `Select record ${index + 1}`);
            });
        });

        root.querySelectorAll('.no-data').forEach(empty => {
            empty.classList.add('nexa-list-empty');
            empty.setAttribute('role', 'status');
            empty.setAttribute('aria-live', 'polite');
        });
        root.querySelectorAll('.show-more [data-action="showMore"]').forEach(button => {
            button.setAttribute('aria-label', `Load more ${scopeLabel(view)} records`);
        });

        enhanceSelection(view, root);
        enhancePagination(root);
    };

    const setSaveStatus = (root, state, message) => {
        const status = ensureLiveStatus(root, 'nexa-form-status');
        root.dataset.saveState = state;
        root.classList.toggle('nexa-is-saving', state === 'saving');
        status.classList.toggle('sr-only', !message);
        status.textContent = message;
        root.querySelectorAll('[data-action="save"], [data-name="save"]').forEach(button => {
            button.setAttribute('aria-busy', String(state === 'saving'));
        });
        if (state === 'success') window.setTimeout(() => setSaveStatus(root, 'idle', ''), 5000);
    };

    const enhanceForm = (view, root) => {
        root.classList.add('nexa-record-form');
        root.querySelectorAll('.field, .form-group').forEach(field => {
            const required = Boolean(field.querySelector('.required-sign, .required')) || field.classList.contains('required');
            const invalid = field.classList.contains('has-error');
            field.querySelectorAll('input, select, textarea').forEach(input => {
                if (required) input.setAttribute('aria-required', 'true');
                input.setAttribute('aria-invalid', String(invalid));
                const message = field.querySelector('.error, .help-block.text-danger');
                if (invalid && message) {
                    message.id ||= `nexa-field-error-${Math.random().toString(36).slice(2, 10)}`;
                    input.setAttribute('aria-describedby', message.id);
                }
            });
        });

        if (root.dataset.nexaSaveBound === 'true') return;
        root.dataset.nexaSaveBound = 'true';
        root.addEventListener('click', event => {
            const save = event.target.closest('[data-action="save"], [data-name="save"]');
            if (!save || save.disabled) return;

            setSaveStatus(root, 'saving', 'Saving changes…');
            window.setTimeout(() => {
                const firstInvalid = root.querySelector('.has-error input, .has-error select, .has-error textarea');
                if (!firstInvalid) return;
                setSaveStatus(root, 'invalid', 'Review the highlighted required or invalid fields.');
                firstInvalid.focus();
                enhanceForm(view, root);
            }, 80);

            const model = view.model;
            if (!model?.once) return;
            model.once('sync', () => setSaveStatus(root, 'success', 'Changes saved successfully.'));
            model.once('error', (record, response) => {
                const conflict = response?.status === 409 || response?.status === 412;
                setSaveStatus(root, conflict ? 'conflict' : 'error', conflict ?
                    'This record changed elsewhere. Refresh it before saving again.' :
                    'Changes could not be saved. Review the form and try again.');
            });
        });
    };

    const enhanceRecord = view => {
        const root = view.element;
        if (!root) return;

        root.querySelectorAll('.detail, .edit').forEach(record => {
            record.classList.add('nexa-crm-record-workflow');
            record.setAttribute('aria-label', `${scopeLabel(view)} record`);
            record.querySelectorAll('.record-buttons .sub-container').forEach(toolbar => {
                toolbar.setAttribute('role', 'toolbar');
                toolbar.setAttribute('aria-label', `${scopeLabel(view)} record actions`);
            });

            record.querySelectorAll('.panel').forEach((panel, index) => {
                panel.classList.add('nexa-record-panel');
                const title = panel.querySelector('.panel-title');
                if (title) {
                    title.id ||= `nexa-record-panel-${index}-${Math.random().toString(36).slice(2, 7)}`;
                    panel.setAttribute('role', 'region');
                    panel.setAttribute('aria-labelledby', title.id);
                }
                if (panel.querySelector('.list-container')) panel.classList.add('nexa-relationship-panel');
                if (panel.matches('.panel-stream, [data-name="stream"]')) panel.classList.add('nexa-activity-panel');
            });

            record.querySelectorAll('.tabs').forEach(tabs => {
                tabs.setAttribute('role', 'tablist');
                tabs.querySelectorAll('button[data-tab]').forEach(button => {
                    button.setAttribute('role', 'tab');
                    button.setAttribute('aria-selected', String(button.classList.contains('active')));
                });
            });

            if (record.classList.contains('edit') || record.querySelector('.edit-buttons:not(.hidden)')) {
                enhanceForm(view, record);
            }
        });
    };

    const enhance = view => {
        enhanceList(view);
        enhanceRecord(view);
    };

    const install = ViewClass => {
        if (!ViewClass?.prototype || ViewClass.prototype.__nexaWorkflowInstalled) return;
        ViewClass.prototype.__nexaWorkflowInstalled = true;
        const originalAfterRender = ViewClass.prototype.afterRender;

        ViewClass.prototype.afterRender = function () {
            const result = originalAfterRender?.apply(this, arguments);
            const run = () => enhance(this);
            run();
            window.requestAnimationFrame(run);

            if (!this.__nexaWorkflowObserver && this.element) {
                this.__nexaWorkflowObserver = new MutationObserver(run);
                this.__nexaWorkflowObserver.observe(this.element, {childList: true, subtree: true});
                this.once?.('remove', () => this.__nexaWorkflowObserver?.disconnect());
            }

            return result;
        };
    };

    viewTypes.forEach(install);
});
