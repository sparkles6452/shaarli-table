/**
 * Shaarli Table View Plugin — JavaScript
 * Handles column sorting and row filtering.
 */
(function () {
    'use strict';

    var table, tbody, searchInput, tagFilterInput, countEl;
    var rows = [];
    var sortState = { col: null, dir: 'asc' };

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------

    function init() {
        table = document.getElementById('st-table');
        if (!table) return;

        tbody          = table.querySelector('tbody');
        searchInput    = document.getElementById('st-search');
        tagFilterInput = document.getElementById('st-tag-filter');
        countEl        = document.getElementById('st-count');
        rows           = Array.from(tbody.querySelectorAll('tr.st-row'));

        updateCount();
        bindSortHeaders();
        bindSearch();
        bindCopyButton();
    }

    // -----------------------------------------------------------------------
    // Sort
    // -----------------------------------------------------------------------

    function bindSortHeaders() {
        var headers = table.querySelectorAll('th.sortable');
        headers.forEach(function (th) {
            th.addEventListener('click', function () {
                var colIdx = parseInt(th.getAttribute('data-col'), 10);

                if (sortState.col === colIdx) {
                    sortState.dir = (sortState.dir === 'asc') ? 'desc' : 'asc';
                } else {
                    sortState.col = colIdx;
                    sortState.dir = 'asc';
                }

                sortRows(colIdx, sortState.dir);
                highlightHeader(th, sortState.dir);
            });
        });
    }

    function sortRows(colIdx, dir) {
        rows.sort(function (a, b) {
            var aCell = a.cells[colIdx];
            var bCell = b.cells[colIdx];
            if (!aCell || !bCell) return 0;

            // Prefer data-sort attribute (allows numeric / date sorting by key).
            var aVal = aCell.getAttribute('data-sort') || aCell.textContent.trim();
            var bVal = bCell.getAttribute('data-sort') || bCell.textContent.trim();

            var cmp = aVal.localeCompare(bVal, undefined, {
                numeric: true,
                sensitivity: 'base'
            });
            return (dir === 'asc') ? cmp : -cmp;
        });

        // Re-append rows in sorted order.
        rows.forEach(function (row) {
            tbody.appendChild(row);
        });

        // Renumber the # column.
        var numCells = tbody.querySelectorAll('td.col-num');
        numCells.forEach(function (cell, i) {
            cell.textContent = i + 1;
        });
    }

    function highlightHeader(activeHeader, dir) {
        table.querySelectorAll('th.sortable').forEach(function (th) {
            th.classList.remove('sort-asc', 'sort-desc');
        });
        activeHeader.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
    }

    // -----------------------------------------------------------------------
    // Filter / search
    // -----------------------------------------------------------------------

    function bindSearch() {
        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (tagFilterInput) {
            tagFilterInput.addEventListener('input', applyFilters);
        }
    }

    function applyFilters() {
        var searchQ = searchInput    ? searchInput.value.toLowerCase().trim()    : '';
        var tagQ    = tagFilterInput ? tagFilterInput.value.toLowerCase().trim() : '';

        rows.forEach(function (row) {
            var passSearch = true;
            var passTag    = true;

            if (searchQ !== '') {
                passSearch = row.textContent.toLowerCase().indexOf(searchQ) !== -1;
            }

            if (tagQ !== '') {
                var tagCell = row.querySelector('td.col-tags');
                // data-sort holds the plain space-separated tag names — fastest to match against
                var tagText = tagCell
                    ? (tagCell.getAttribute('data-sort') || tagCell.textContent).toLowerCase()
                    : '';
                passTag = tagText.indexOf(tagQ) !== -1;
            }

            if (passSearch && passTag) {
                row.classList.remove('st-hidden');
            } else {
                row.classList.add('st-hidden');
            }
        });

        updateCount();
    }

    // -----------------------------------------------------------------------
    // Copy to clipboard
    // -----------------------------------------------------------------------

    // Columns to skip when building the TSV (row number, private badge, actions)
    var SKIP_COLS = ['col-num', 'col-priv', 'col-actions'];

    function bindCopyButton() {
        var btn = document.getElementById('st-copy-btn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var tsv = buildTSV();

            function onCopied() {
                btn.textContent = '✓ Copied!';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.innerHTML = '&#128203; Copy';
                    btn.classList.remove('copied');
                }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(tsv).then(onCopied).catch(fallbackCopy.bind(null, tsv, onCopied));
            } else {
                fallbackCopy(tsv, onCopied);
            }
        });
    }

    function fallbackCopy(text, onCopied) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); onCopied(); } catch (e) {}
        document.body.removeChild(ta);
    }

    function shouldSkipCell(cell) {
        for (var i = 0; i < SKIP_COLS.length; i++) {
            if (cell.className.indexOf(SKIP_COLS[i]) !== -1) return true;
        }
        return false;
    }

    function buildTSV() {
        // Header row — strip sort-icon characters, skip unwanted columns
        var headerCells = Array.from(table.querySelectorAll('thead th'));
        var headers = headerCells
            .filter(function (th) { return !shouldSkipCell(th); })
            .map(function (th) {
                return th.textContent.replace(/[⇅▲▼]/g, '').trim();
            });

        var lines = [headers.join('\t')];

        // Visible data rows only
        var visibleRows = rows.filter(function (r) {
            return !r.classList.contains('st-hidden');
        });

        visibleRows.forEach(function (row) {
            var cols = Array.from(row.cells)
                .filter(function (cell) { return !shouldSkipCell(cell); })
                .map(function (cell) {
                    var val;
                    if (cell.className.indexOf('col-tags') !== -1) {
                        // data-sort holds plain space-separated tag names
                        val = cell.getAttribute('data-sort') || '';
                    } else if (cell.className.indexOf('col-url') !== -1) {
                        // data-sort holds the full URL (cell text is truncated)
                        val = cell.getAttribute('data-sort') || cell.textContent.trim();
                    } else {
                        val = cell.textContent.trim();
                    }
                    // Sanitise for TSV: collapse whitespace, remove tabs
                    return val.replace(/[\t]/g, ' ').replace(/\s+/g, ' ').trim();
                });
            lines.push(cols.join('\t'));
        });

        return lines.join('\n');
    }

    // -----------------------------------------------------------------------
    // Row count
    // -----------------------------------------------------------------------

    function updateCount() {
        if (!countEl) return;
        var visible = rows.filter(function (r) {
            return !r.classList.contains('st-hidden');
        }).length;
        countEl.textContent = visible + '\u202f/\u202f' + rows.length + ' rows';
    }

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
