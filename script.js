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
            if (row && confirm('Direktive entfernen?')) {
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
                removeBtn.title = 'Entfernen';
                removeBtn.textContent = '\u00D7';
                btnCell.parentNode.replaceChild(removeBtn, btnCell);
            }

            // Assign datalist to value input if applicable
            assignDatalist(currentRow, keyInput.value.trim());

            // Create a new empty row
            var newRow = document.createElement('tr');
            newRow.className = 'new-directive-row';
            newRow.innerHTML =
                '<td><input type="text" name="keys[]" value="" class="input-key" placeholder="Neue Direktive" list="dl-directives"></td>' +
                '<td><input type="text" name="values[]" value="" class="input-value" placeholder="Wert"></td>' +
                '<td><button type="button" class="btn-add" title="Weitere hinzufügen">+</button></td>';
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
