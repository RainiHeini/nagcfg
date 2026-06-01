/* ── NagCFG JavaScript ─────────────────────────────────────────────── */

(function () {
    'use strict';

    /* ── Theme Toggle ──────────────────────────────────────────────── */
    var toggle = document.getElementById('theme-toggle');
    if (toggle) {
        var stored = localStorage.getItem('nagcfg-theme');
        if (stored) {
            document.documentElement.setAttribute('data-theme', stored);
        }
        updateToggleIcon();

        toggle.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme');
            var next;
            if (current === 'dark') {
                next = 'light';
            } else if (current === 'light') {
                next = 'dark';
            } else {
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                next = prefersDark ? 'light' : 'dark';
            }
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('nagcfg-theme', next);
            updateToggleIcon();
        });
    }

    function updateToggleIcon() {
        if (!toggle) return;
        var theme = document.documentElement.getAttribute('data-theme');
        if (theme === 'dark') {
            toggle.textContent = '\u2600';
        } else if (theme === 'light') {
            toggle.textContent = '\u263E';
        } else {
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            toggle.textContent = prefersDark ? '\u2600' : '\u263E';
        }
    }

    /* ── Live Search ───────────────────────────────────────────────── */
    var searchInput = document.getElementById('search');
    var table = document.getElementById('obj-table');

    if (searchInput && table) {
        var tbody = table.querySelector('tbody');
        var rows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];

        searchInput.addEventListener('input', function () {
            var query = this.value.toLowerCase();
            for (var i = 0; i < rows.length; i++) {
                var text = rows[i].textContent.toLowerCase();
                rows[i].style.display = text.indexOf(query) !== -1 ? '' : 'none';
            }
        });
    }

    /* ── Table Sort ────────────────────────────────────────────────── */
    if (table) {
        var headers = table.querySelectorAll('th[data-sort]');
        for (var h = 0; h < headers.length; h++) {
            headers[h].addEventListener('click', handleSort);
        }
    }

    function handleSort() {
        var th = this;
        var sortTbody = table.querySelector('tbody');
        if (!sortTbody) return;

        var sortRows = Array.prototype.slice.call(sortTbody.querySelectorAll('tr'));
        var colIndex = Array.prototype.indexOf.call(th.parentNode.children, th);
        var asc = !th.classList.contains('sort-asc');

        var allTh = th.parentNode.querySelectorAll('th');
        for (var i = 0; i < allTh.length; i++) {
            allTh[i].classList.remove('sort-asc', 'sort-desc');
        }
        th.classList.add(asc ? 'sort-asc' : 'sort-desc');

        sortRows.sort(function (a, b) {
            var aCell = a.children[colIndex];
            var bCell = b.children[colIndex];
            var aText = (aCell ? aCell.textContent : '').trim().toLowerCase();
            var bText = (bCell ? bCell.textContent : '').trim().toLowerCase();
            if (aText < bText) return asc ? -1 : 1;
            if (aText > bText) return asc ? 1 : -1;
            return 0;
        });

        for (var j = 0; j < sortRows.length; j++) {
            sortTbody.appendChild(sortRows[j]);
        }
    }

    /* ── Remove Directive Button ───────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-remove')) {
            var row = e.target.closest('tr');
            if (row && confirm('Remove directive?')) {
                row.remove();
            }
        }
    });

    /* ── Add Directive Row ─────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-add')) {
            var currentRow = e.target.closest('tr');
            if (!currentRow) return;
            var tbodyEl = currentRow.closest('tbody');
            if (!tbodyEl) return;

            // Read values from current new-directive row
            var keyInput = currentRow.querySelector('.input-key');
            var valInput = currentRow.querySelector('.input-value');
            if (!keyInput.value.trim()) return;

            // Convert current row to a regular directive row
            currentRow.classList.remove('new-directive-row');
            keyInput.setAttribute('readonly', 'readonly');
            keyInput.removeAttribute('list');
            keyInput.removeAttribute('placeholder');
            valInput.removeAttribute('placeholder');

            // Replace + button with x button
            var btnCell = currentRow.querySelector('.btn-add');
            if (btnCell) {
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn-remove';
                removeBtn.title = 'Remove';
                removeBtn.textContent = '\u00D7';
                btnCell.parentNode.replaceChild(removeBtn, btnCell);
            }

            // Assign datalist to value input if applicable
            assignDatalist(currentRow, keyInput.value.trim());

            // Create a new empty row
            var newRow = document.createElement('tr');
            newRow.className = 'new-directive-row';
            newRow.innerHTML =
                '<td><input type="text" name="keys[]" value="" class="input-key" placeholder="New directive" list="dl-directives"></td>' +
                '<td><input type="text" name="values[]" value="" class="input-value" placeholder="Value"></td>' +
                '<td><button type="button" class="btn-add" title="Add another">+</button></td>';
            tbodyEl.appendChild(newRow);
        }
    });

    /* ── Dynamic Datalist Assignment ───────────────────────────────── */
    function assignDatalist(row, directive) {
        if (typeof NagCFG === 'undefined') return;

        var valInput = row.querySelector('.input-value');
        if (!valInput) return;

        var refType = NagCFG.refMap[directive];
        if (!refType) return;

        var dlId;
        if (refType === '__templates__') {
            dlId = 'dl-tpl_' + NagCFG.objectType;
        } else {
            dlId = 'dl-' + refType;
        }

        // Ensure datalist exists
        if (!document.getElementById(dlId)) {
            var data = [];
            if (refType === '__templates__') {
                data = NagCFG.refData['tpl_' + NagCFG.objectType] || [];
            } else {
                data = NagCFG.refData[refType] || [];
            }
            if (data.length > 0) {
                var dl = document.createElement('datalist');
                dl.id = dlId;
                for (var i = 0; i < data.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = data[i];
                    dl.appendChild(opt);
                }
                document.body.appendChild(dl);
            }
        }

        if (document.getElementById(dlId)) {
            valInput.setAttribute('list', dlId);
        }
    }

    /* ── Duplicate Name Warning on Submit ─────────────────────────── */
    var editForm = document.getElementById('edit-form');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            if (typeof NagCFG === 'undefined' || !NagCFG.refData) return;

            var type = NagCFG.objectType;
            var keyField = NagCFG.keyField;
            if (!keyField && type !== 'service') return;

            var isCreate = editForm.action.indexOf('action=create') !== -1;

            // Collect form directive keys and values
            var keys = editForm.querySelectorAll('input[name="keys[]"]');
            var vals = editForm.querySelectorAll('input[name="values[]"]');
            var nameValue = '';
            var svcHost = '';
            var svcDesc = '';

            for (var i = 0; i < keys.length; i++) {
                var k = keys[i].value.trim();
                var v = (vals[i] ? vals[i].value : '').trim();
                if (type === 'service') {
                    if (k === 'host_name') svcHost = v;
                    if (k === 'service_description') svcDesc = v;
                } else if (k === keyField) {
                    nameValue = v;
                }
            }

            // Check for existing names
            var existing = NagCFG.refData[type] || [];
            var duplicate = false;

            if (type === 'service') {
                if (svcHost && svcDesc) {
                    var svcExisting = NagCFG.existingServices || [];
                    duplicate = svcExisting.indexOf(svcHost + '/' + svcDesc) !== -1;
                }
            } else if (nameValue) {
                duplicate = existing.indexOf(nameValue) !== -1;
            }

            // On edit: only warn if the name was changed to an existing one
            if (!isCreate && duplicate) {
                var origName = NagCFG.originalName || '';
                if (type === 'service') {
                    var origSvcKey = NagCFG.originalSvcKey || '';
                    if (svcHost + '/' + svcDesc === origSvcKey) duplicate = false;
                } else {
                    if (nameValue === origName) duplicate = false;
                }
            }

            if (duplicate) {
                var displayName = type === 'service' ? svcHost + ' / ' + svcDesc : nameValue;
                if (!confirm('Warning: "' + displayName + '" already exists. Continue anyway?')) {
                    e.preventDefault();
                }
            }
        });
    }

    /* ── Auto-assign datalist when directive name changes ──────────── */
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('input-key') && !e.target.hasAttribute('readonly')) {
            var row = e.target.closest('tr');
            if (row) {
                assignDatalist(row, e.target.value.trim());
            }
        }
    });

})();
