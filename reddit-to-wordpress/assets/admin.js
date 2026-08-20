(function () {
    function renumberRows(tableSelector, groupName) {
        var table = document.querySelector(tableSelector);
        if (!table) {
            return;
        }

        table.querySelectorAll('tbody tr').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(new RegExp(groupName + '\\[[0-9]+\\]'), groupName + '[' + index + ']');
            });
        });
    }

    function cloneRow(tableSelector, rowSelector) {
        var table = document.querySelector(tableSelector);
        if (!table) {
            return;
        }

        var rows = table.querySelectorAll(rowSelector);
        var source = rows[rows.length - 1];
        if (!source) {
            return;
        }

        var clone = source.cloneNode(true);
        clone.querySelectorAll('input, textarea, select').forEach(function (field) {
            if (/\[id\]$/.test(field.name)) {
                field.value = '';
                return;
            }

            if (field.type === 'checkbox') {
                field.checked = false;
                return;
            }

            if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
                return;
            }

            field.value = '';
        });
        table.querySelector('tbody').appendChild(clone);
        renumberRows(tableSelector, tableSelector.indexOf('template') === -1 ? 'rules' : 'templates');
    }

    document.addEventListener('click', function (event) {
        if (event.target.matches('[data-rtw-add-rule]')) {
            event.preventDefault();
            cloneRow('.rtw-rules-table', 'tr');
        }

        if (event.target.matches('[data-rtw-add-template]')) {
            event.preventDefault();
            cloneRow('.rtw-template-table', 'tr');
        }

        if (event.target.matches('[data-rtw-remove-row]')) {
            event.preventDefault();
            var row = event.target.closest('tr');
            var tbody = row ? row.parentNode : null;

            if (tbody && tbody.querySelectorAll('tr').length > 1) {
                row.remove();
                renumberRows('.rtw-rules-table', 'rules');
                renumberRows('.rtw-template-table', 'templates');
                return;
            }

            if (row) {
                row.querySelectorAll('input, textarea').forEach(function (field) {
                    if (field.type === 'checkbox') {
                        field.checked = false;
                    } else {
                        field.value = '';
                    }
                });
            }
        }
    });
}());
