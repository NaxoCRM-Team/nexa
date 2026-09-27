define([], () => class WorkspaceTable {
    constructor(view) {
        this.view = view;
    }

    enhance(table, key) {
        if (!table) return;
        const stateKey = `nexaWorkspaceTable:${key}`;
        const state = this.view.getStorage().get('state', stateKey) || {};
        this.applyOrder(table, state.order || []);
        this.applyWidths(table, state.widths || {});

        table.querySelectorAll('thead th[data-column]').forEach(th => {
            if (th.dataset.tableReady === 'true') return;
            th.dataset.tableReady = 'true';
            const column = th.dataset.column;
            if (column === 'actions') return;
            th.draggable = true;
            th.addEventListener('dragstart', event => {
                if (event.target.closest('.nexa-col-resizer')) return event.preventDefault();
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', column);
                th.classList.add('is-dragging');
            });
            th.addEventListener('dragend', () => th.classList.remove('is-dragging'));
            th.addEventListener('dragover', event => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            });
            th.addEventListener('drop', event => {
                event.preventDefault();
                const source = event.dataTransfer.getData('text/plain');
                if (!source || source === column) return;
                const order = [...table.querySelectorAll('thead th[data-column]')].map(node => node.dataset.column);
                const sourceIndex = order.indexOf(source);
                let targetIndex = order.indexOf(column);
                if (sourceIndex < 0 || targetIndex < 0) return;
                order.splice(sourceIndex, 1);
                if (sourceIndex < targetIndex) targetIndex -= 1;
                const after = event.clientX > th.getBoundingClientRect().left + th.getBoundingClientRect().width / 2;
                order.splice(targetIndex + (after ? 1 : 0), 0, source);
                state.order = order;
                this.view.getStorage().set('state', stateKey, state);
                this.applyOrder(table, order);
            });

            const handle = document.createElement('span');
            handle.className = 'nexa-col-resizer';
            handle.setAttribute('aria-hidden', 'true');
            th.append(handle);
            handle.addEventListener('mousedown', event => {
                event.preventDefault();
                event.stopPropagation();
                const startX = event.pageX;
                const startWidth = th.getBoundingClientRect().width;
                const move = moveEvent => {
                    const width = Math.max(72, startWidth + moveEvent.pageX - startX);
                    this.setWidth(table, column, width);
                };
                const up = () => {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', up);
                    state.widths = state.widths || {};
                    state.widths[column] = Math.round(th.getBoundingClientRect().width);
                    this.view.getStorage().set('state', stateKey, state);
                };
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', up);
            });
        });
    }

    applyOrder(table, order) {
        if (!order.length) return;
        table.querySelectorAll('tr').forEach(row => {
            const cells = new Map([...row.children].map(cell => [cell.dataset.column, cell]));
            order.forEach(column => { if (cells.has(column)) row.append(cells.get(column)); });
            [...row.children].forEach(cell => { if (!order.includes(cell.dataset.column)) row.append(cell); });
        });
    }

    applyWidths(table, widths) {
        Object.entries(widths).forEach(([column, width]) => this.setWidth(table, column, width));
    }

    setWidth(table, column, width) {
        table.querySelectorAll(`[data-column="${column}"]`).forEach(cell => {
            cell.style.width = `${Math.round(width)}px`;
            cell.style.minWidth = `${Math.round(width)}px`;
        });
    }
});
