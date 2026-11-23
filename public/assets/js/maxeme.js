/*
 * Maxeme Auto admin behaviours, on top of app.js (loaded after it; uses window.WC).
 *
 * Row ⋯ menus: app.js copies a row's .row-action-dropdown into one body-level dropdown, so a
 * button in it is NOT inside its row. Name the row by selector instead (data-remove-row).
 *
 *   data-modal-open="#id"   opens that .admin-modal-overlay (app.js's .js-modal-close / backdrop closes it)
 *       data-form-action    also point the modal's <form> at this URL
 *       data-form-values    and fill its fields from this JSON object ({ field name: value })
 *
 *   .js-post-action         POSTs { _token } to data-url, then toasts the JSON { message }
 *       data-url, data-token                 endpoint and CSRF token
 *       data-params='{"status":"booked"}'     more fields to post
 *       data-remove-row="#row-id"            fade that row out on success
 *       data-reload                          reload the page on success (the toast shows after it)
 *       data-redirect="url"                  go to that page on success (the toast shows there)
 *       data-confirm-title (+ data-confirm-before, data-item, data-confirm-after)
 *                                            ask first, with app.js's #delete-modal. A .danger
 *                                            button (delete, de-activate, decline) always asks,
 *                                            with a generic text when it names none.
 *
 *   .mx-actions             an action group (row or toolbar): the first two actions stay buttons,
 *                           the rest move into a ⋯ menu after them, the way wholesale core does it
 *
 *   input[type=color][data-colour-for="#id"]   a colour picker writing its #rrggbb into that text
 *                           input (which may be left blank); a valid text value moves the picker
 *   [data-insert-tag="{{x}}"][data-insert-into="#id"]   inserts the tag at that textarea's cursor
 *
 *   .js-sortable-config[data-reload-after-sort]   app.js's drag-to-reorder rows: reload once the new
 *                           order is saved, so row numbers and Move up / Move down match it
 */
(function ($) {
    'use strict';

    $(function () {
        $('.mx-actions').each(function () {
            var $group = $(this);
            // The buttons beside a page title stay in a row, however many there are (mockup P11).
            if ($group.parent().hasClass('mx-title-actions')) { $group.addClass('is-grouped'); return; }
            var $extra = $group.children().slice(2);
            if ($extra.length) {
                var inRow = $group.closest('td').length > 0;
                var $toggle = $('<button type="button" class="table-action row-action-toggle" aria-expanded="false" aria-haspopup="true" aria-label="More actions"></button>');
                if (!inRow) {
                    $toggle.addClass('load-endpoint-toggle').text('More');
                }
                // A menu item is a plain row; .danger keeps it red.
                $extra.removeClass('button mx-btn-sm primary outline mx-success mx-warning');
                $('<div class="row-action-menu"></div>')
                    .append($toggle, $('<div class="row-action-dropdown"></div>').append($extra))
                    .appendTo($group);
            }
            $group.addClass('is-grouped');
        });
    });

    $(document).on('input change', 'input[type=color][data-colour-for]', function () {
        $($(this).data('colour-for')).val(this.value);
    });
    $('input[type=color][data-colour-for]').each(function () {
        var $picker = $(this);
        $($picker.data('colour-for')).on('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) { $picker.val(this.value.toLowerCase()); }
        });
    });

    $(document).on('click', '[data-insert-tag]', function () {
        var field = $($(this).data('insert-into'))[0];
        var tag = $(this).data('insert-tag');
        if (!field) { return; }
        var start = field.selectionStart ?? field.value.length;
        var end = field.selectionEnd ?? start;
        field.value = field.value.slice(0, start) + tag + field.value.slice(end);
        field.focus();
        field.selectionStart = field.selectionEnd = start + tag.length;
    });

    $(document).ajaxSuccess(function (event, xhr, settings) {
        $('.js-sortable-config[data-reload-after-sort]').each(function () {
            if (settings.url === $(this).data('reorder-url')) {
                window.location.reload();
            }
        });
    });

    $(document).on('click', '[data-modal-open]', function (event) {
        event.preventDefault();
        var $trigger = $(this);
        var $modal = $($trigger.attr('data-modal-open'));
        var $form = $modal.find('form').first();

        if ($trigger.is('[data-form-action]')) {
            $form.attr('action', $trigger.attr('data-form-action'));
        }
        if ($trigger.is('[data-form-values]')) {
            $form.trigger('reset');
            $.each($trigger.data('form-values') || {}, function (name, value) {
                var $field = $form.find('[name="' + name + '"]');
                if ($field.is(':checkbox')) {
                    // A list (name="rates[]") ticks the boxes whose value it holds.
                    $field.each(function () {
                        $(this).prop('checked', $.isArray(value) ? value.indexOf(this.value) !== -1 : value === '1');
                    });
                } else {
                    $field.val(value === null ? '' : value);
                }
            });
            // A colour picker follows the text field it writes into (left as reset when that is blank).
            $form.find('input[type=color][data-colour-for]').each(function () {
                var text = $($(this).data('colour-for')).val() || '';
                if (/^#[0-9a-fA-F]{6}$/.test(text)) { $(this).val(text.toLowerCase()); }
            });
        }

        $modal.addClass('is-visible');
    });

    function post($btn) {
        $btn.prop('disabled', true);
        $.post($btn.data('url'), $.extend({}, $btn.data('params') || {}, { _token: $btn.data('token') }))
            .done(function (response) {
                var message = response && response.message ? response.message : 'Done.';
                if ($btn.is('[data-reload]') || $btn.attr('data-redirect')) {
                    try { window.sessionStorage.setItem('wcFlashMessage', JSON.stringify({ message: message, type: 'success' })); } catch (e) { /* no storage */ }
                    if ($btn.attr('data-redirect')) { window.location.href = $btn.attr('data-redirect'); } else { window.location.reload(); }
                    return;
                }
                var row = $btn.attr('data-remove-row');
                if (row) {
                    $(row).fadeOut(180, function () { $(this).remove(); });
                }
                window.WC.showToast(message, 'success');
            })
            .fail(function (xhr) {
                $btn.prop('disabled', false);
                window.WC.showToast(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Something went wrong. Please try again.', 'error');
            });
    }

    /*
     * Click-to-edit cells (the Services list): <tr data-inline-url> holds <td data-inline-field="name">.
     * Enter POSTs { field, value, _token } and shows the JSON { value } it answers; Esc or leaving
     * the cell cancels. data-inline-options='{"value":"Label"}' edits the cell with a select, and
     * data-inline-value is the value it starts from when the text shown is not (the select's key,
     * a price without its thousands separators); the answer's { raw } replaces it.
     */
    function closeInline($cell, text) {
        $cell.removeClass('is-editing').text(text);
    }

    $(document).on('click', 'td[data-inline-field]:not(.is-editing)', function () {
        var $cell = $(this);
        var original = $cell.text().trim();
        var initial = $cell.is('[data-inline-value]') ? String($cell.attr('data-inline-value')) : original;
        var options = $cell.data('inline-options');
        var $input;

        if (options) {
            $input = $('<select></select>');
            $.each(options, function (value, label) {
                $input.append($('<option></option>').val(value).text(label));
            });
        } else {
            $input = $('<input type="text">');
        }

        $cell.addClass('is-editing').empty().append($input.val(initial));
        $input.trigger('focus');

        function save() {
            $input.prop('disabled', true);
            $.post($cell.closest('tr').data('inline-url'), {
                field: $cell.data('inline-field'),
                value: $input.val(),
                _token: $('meta[name="csrf-token"]').attr('content')
            }).done(function (response) {
                if (response.raw !== undefined) { $cell.attr('data-inline-value', response.raw === null ? '' : response.raw); }
                closeInline($cell, response.value === null ? '' : response.value);
                window.WC.showToast(response.message || 'Saved.', 'success');
            }).fail(function (xhr) {
                closeInline($cell, original);
                window.WC.showToast(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not save.', 'error');
            });
        }

        $input.on('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); save(); }
            if (event.key === 'Escape') { closeInline($cell, original); }
        });
        if (options) { $input.on('change', save); }
        $input.on('blur', function () {
            if (!$input.prop('disabled')) { closeInline($cell, original); }
        });
    });

    /* Email PDF modals (legacy validation): required, and every comma-separated entry an address. */
    $(document).on('submit', 'form.js-email-form', function (event) {
        var $form = $(this);
        var entries = String($form.find('[name="emails"]').val() || '').split(',').map(function (e) { return e.trim(); });
        var message = '';
        if (entries.join('') === '') {
            message = 'This field is required.';
        } else if (entries.some(function (e) { return !/^\S+@\S+$/.test(e); })) {
            message = 'Please provide valid email-Ids.';
        }
        $form.find('.mx-email-error').text(message);
        if (message) {
            event.preventDefault();
            return;
        }
        $form.find('button').prop('disabled', true);
    });

    $(document).on('click', '.js-post-action', function () {
        var $btn = $(this);

        if (!$btn.data('confirm-title') && !$btn.hasClass('danger')) {
            post($btn);
            return;
        }

        // data-item="" names nothing (the text says it all); no data-item at all: "this item".
        window.MxConfirm(
            $btn.data('confirm-title') || 'Please confirm',
            $btn.data('confirm-before') || 'Are you sure about ',
            $btn.is('[data-item]') ? String($btn.data('item')) : 'this item',
            $btn.data('confirm-after') || '?',
            function () { post($btn); },
            true
        );
    });
}(jQuery));

/*
 * The sidebar "+" (MaxemeAdminMenuProvider) links to a list with the add modal's id as its fragment,
 * e.g. /admin/services#manageAddModal: arriving that way opens the modal through its own button, so
 * the form is set up exactly as a click would set it up. Clicking the "+" while already on that
 * list only changes the fragment (no page load), so the fragment change opens it too.
 */
(function () {
    'use strict';

    function openFromHash() {
        var id = window.location.hash.slice(1);
        if (!/^[A-Za-z][\w-]*$/.test(id) || !document.getElementById(id)) { return; }

        var trigger = document.querySelector('[data-modal-open="#' + id + '"]');
        if (trigger) {
            trigger.click();
            // Drop the fragment, so the next "+" click changes it again.
            if (window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        }
    }

    openFromHash();
    window.addEventListener('hashchange', openFromHash);
}());

/*
 * An item autocomplete under a text input inside a .mx-name-group (the invoice builder's items, the
 * service page's lines): MxAutocomplete.attach(root, selector, options), options being
 *   url(input, term)  the JSON URL to ask, or null for no menu; it answers [{ label, value, price,
 *                     category }], a category heading its group when items have one
 *   pick(input, item) what choosing an item does
 *   empty             the text shown when nothing matches
 * Answers are cached per URL for the page.
 */
(function () {
    'use strict';

    var menu = null;
    var anchor = null;
    var cache = {};

    function close() { if (menu) { menu.remove(); menu = null; anchor = null; } }

    /*
     * The menu floats on the page (fixed, in <body>) at its input, so a scrolling table around the
     * input (.wide-table-wrap on the service lines, the repair order jobs, the invoice items) cannot
     * cut it off. Below the input, or above it when there is more room there.
     */
    function place() {
        if (!menu || !anchor) { return; }
        var box = anchor.closest('.mx-name-group').getBoundingClientRect();
        var below = window.innerHeight - box.bottom;
        menu.style.left = box.left + 'px';
        menu.style.width = box.width + 'px';
        if (below < 200 && box.top > below) {
            menu.style.top = 'auto';
            menu.style.bottom = (window.innerHeight - box.top + 2) + 'px';
            menu.style.maxHeight = Math.min(260, box.top - 8) + 'px';
        } else {
            menu.style.bottom = 'auto';
            menu.style.top = (box.bottom + 2) + 'px';
            menu.style.maxHeight = Math.min(260, Math.max(120, below - 8)) + 'px';
        }
    }

    window.addEventListener('resize', place);
    // A scroll of the page or of the table moves the input: the menu follows it (its own scroll aside).
    document.addEventListener('scroll', function (event) { if (menu && event.target !== menu) { place(); } }, true);

    function load(url) {
        if (cache[url]) { return Promise.resolve(cache[url]); }
        return fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (items) { cache[url] = items; return items; });
    }

    function highlight(label, term) {
        var span = document.createElement('span');
        var at = term ? label.toLowerCase().indexOf(term.toLowerCase()) : -1;
        if (at < 0) { span.textContent = label; return span; }
        span.appendChild(document.createTextNode(label.slice(0, at)));
        var mark = document.createElement('mark');
        mark.textContent = label.slice(at, at + term.length);
        span.appendChild(mark);
        span.appendChild(document.createTextNode(label.slice(at + term.length)));
        return span;
    }

    function open(input, items, term, options) {
        close();
        menu = document.createElement('div');
        menu.className = 'mx-ac-menu';
        var category = null;
        items.forEach(function (item) {
            if (item.category && item.category !== category) {
                category = item.category;
                var heading = document.createElement('div');
                heading.className = 'cat';
                heading.textContent = category;
                menu.appendChild(heading);
            }
            var link = document.createElement('a');
            link.href = '#';
            link.appendChild(highlight(item.label, term));
            var price = parseFloat(item.price);
            if (!isNaN(price)) {
                var small = document.createElement('small');
                small.textContent = '$' + price.toFixed(2);
                link.appendChild(small);
            }
            link.addEventListener('mousedown', function (event) { event.preventDefault(); options.pick(input, item); close(); });
            menu.appendChild(link);
        });
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'mx-ac-empty';
            empty.textContent = options.empty;
            menu.appendChild(empty);
        }
        menu.classList.add('is-floating');
        anchor = input;
        document.body.appendChild(menu);
        place();
    }

    function suggest(input, options) {
        var term = input.value.trim();
        var url = options.url(input, term);
        if (!url) { close(); return; }
        load(url).then(function (items) {
            // Only the answer for what the box holds now: an earlier, slower answer (the empty box on
            // focus, a shorter word) arriving last would otherwise replace the right list.
            if (document.activeElement === input && input.value.trim() === term) { open(input, items, term, options); }
        });
    }

    window.MxAutocomplete = {
        attach: function (root, selector, options) {
            ['focusin', 'input'].forEach(function (type) {
                root.addEventListener(type, function (event) { if (event.target.matches(selector)) { suggest(event.target, options); } });
            });
            root.addEventListener('focusout', function () { setTimeout(close, 150); });
        }
    };
}());

/*
 * Growing lists of form rows without a page script of their own:
 *   <button data-row-add="#template-id" data-row-into="#tbody-id" data-row-token="__ROW__">
 * copies the <template>, its token replaced by the next number (data-next on the container), and
 *   .js-row-remove inside a row removes it.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-row-add]');
        if (add) {
            var into = document.querySelector(add.getAttribute('data-row-into'));
            var index = parseInt(into.getAttribute('data-next'), 10) || into.children.length;
            into.setAttribute('data-next', String(index + 1));
            var holder = document.createElement('tbody');
            holder.innerHTML = document.querySelector(add.getAttribute('data-row-add')).innerHTML.split(add.getAttribute('data-row-token') || '__ROW__').join(String(index)).trim();
            into.appendChild(holder.firstElementChild);
            return;
        }
        var remove = event.target.closest('.js-row-remove');
        if (remove) { remove.closest('tr').remove(); }
    });
}());

/*
 * A report's period presets (templates/maxeme/report/_period.html.twig): choosing Today, Last Week…
 * only fills in the form's From and To; the report runs on those. Typing a date sets it to Custom.
 */
(function () {
    'use strict';

    function iso(date) {
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return date.getFullYear() + '-' + month + '-' + day;
    }

    /* [from, to] for a preset, as in the mockup: weeks start on Sunday; This Week and This Month run
       up to today; Last Week is Sunday to Saturday. */
    function range(preset, shopToday) {
        // The shop's date when the page gives it (data-today), else the browser's.
        var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(shopToday || '');
        var today = parts ? new Date(+parts[1], +parts[2] - 1, +parts[3]) : new Date();
        today.setHours(0, 0, 0, 0);
        var day = today.getDay();
        var add = function (base, days) { var d = new Date(base); d.setDate(d.getDate() + days); return d; };
        switch (preset) {
            case 'today': return [today, today];
            case 'yesterday': return [add(today, -1), add(today, -1)];
            case 'this_week': return [add(today, -day), today];
            case 'last_week': return [add(today, -day - 7), add(today, -day - 1)];
            case 'this_month': return [new Date(today.getFullYear(), today.getMonth(), 1), today];
            case 'last_month': return [new Date(today.getFullYear(), today.getMonth() - 1, 1), new Date(today.getFullYear(), today.getMonth(), 0)];
            default: return null;
        }
    }

    document.querySelectorAll('select[data-period-preset]').forEach(function (select) {
        var form = select.form;
        var from = form.querySelector('input[name=from]');
        var to = form.querySelector('input[name=to]');
        // Show which preset the period already is, if any.
        ['today', 'yesterday', 'this_week', 'this_month', 'last_week', 'last_month'].some(function (preset) {
            var r = range(preset, select.dataset.today);
            if (iso(r[0]) === from.value && iso(r[1]) === to.value) { select.value = preset; return true; }
            return false;
        });
        select.addEventListener('change', function () {
            var r = range(select.value, select.dataset.today);
            if (r) { from.value = iso(r[0]); to.value = iso(r[1]); }
        });
        [from, to].forEach(function (input) { input.addEventListener('input', function () { select.value = 'custom'; }); });
    });
}());

/*
 * Browser errors into Logs › Error Log (ClientErrorController): script errors and unhandled promise
 * rejections on any admin page. At most 5 reports per page load, each distinct message once.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta || !window.fetch) { return; }

    var URL = '/admin/logs/client-error';
    var seen = {};
    var left = 5;

    function report(details) {
        var key = details.message + '|' + details.source + '|' + details.line;
        if (left <= 0 || seen[key]) { return; }
        seen[key] = true;
        left -= 1;

        details.page = window.location.pathname + window.location.search;
        try {
            window.fetch(URL, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': meta.getAttribute('content') },
                body: JSON.stringify(details)
            }).catch(function () {});
        } catch (ignored) { /* reporting must never raise an error of its own */ }
    }

    window.addEventListener('error', function (event) {
        // A failed <img>/<script> load also fires "error", without a message; only script errors count.
        if (!event.message) { return; }
        report({
            message: String(event.message),
            source: String(event.filename || ''),
            line: String(event.lineno || ''),
            column: String(event.colno || ''),
            stack: event.error && event.error.stack ? String(event.error.stack) : ''
        });
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason;
        report({
            message: 'Unhandled promise rejection: ' + (reason && reason.message ? reason.message : String(reason)),
            source: '',
            line: '',
            column: '',
            stack: reason && reason.stack ? String(reason.stack) : ''
        });
    });
}());

/*
 * Export CSV of a list that shows every row on the page (mockup a38e7a2):
 *   <a data-csv-of="#table" data-csv="name.csv" data-csv-area="service" data-csv-entity="ServiceCategory"
 *      data-log-url="…" data-token="…">Export CSV</a>
 * The table's header row and body rows, without the filter row and the Actions column; the export is
 * then written to the Activity Log (ExportLogController).
 */
(function () {
    'use strict';

    function text(cell) { return (cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim(); }
    function quote(value) { return /[",\r\n]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value; }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-csv-of]');
        if (!link) { return; }
        event.preventDefault();
        var table = document.querySelector(link.getAttribute('data-csv-of'));
        if (!table) { return; }
        var head = table.querySelector('thead tr:not(.filter-row)');
        var headers = head ? Array.prototype.map.call(head.cells, text) : [];
        var keep = headers.map(function (label) { return label !== '' && label.toLowerCase() !== 'actions'; });
        var lines = [headers.filter(function (label, i) { return keep[i]; }).map(quote).join(',')];
        var count = 0;
        Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
            if (row.querySelector('.empty-table-cell, .mx-empty-line') || row.cells.length < 2) { return; }
            var cells = Array.prototype.filter.call(row.cells, function (cell, i) { return keep[i] !== false; });
            lines.push(cells.map(function (cell) { return quote(text(cell)); }).join(','));
            count++;
        });
        var filename = link.getAttribute('data-csv');
        var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var anchor = document.createElement('a');
        anchor.href = URL.createObjectURL(blob);
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        var body = new FormData();
        body.append('area', link.getAttribute('data-csv-area') || '');
        body.append('entity', link.getAttribute('data-csv-entity') || '');
        body.append('filename', filename);
        body.append('rows', String(count));
        fetch(link.getAttribute('data-log-url'), { method: 'POST', body: body, headers: { 'X-CSRF-TOKEN': link.getAttribute('data-token') || '', 'Accept': 'application/json' } }).catch(function () {});
        if (window.WC && window.WC.showToast) { window.WC.showToast(filename + ' downloaded (' + count + ' rows).', 'success'); }
    });
}());

/*
 * window.MxConfirm(title, before, item, after, onConfirm, danger): app.js's #delete-modal (WC.confirm)
 * with two changes. No item (empty) leaves out the "this item" app.js puts in its place. And a question
 * that is not a delete (danger false: "Issue the invoice now?", "Confirm appointment") shows a question
 * mark and the primary OK, not the bin and the red button; the modal is put back when it closes.
 */
(function () {
    'use strict';

    var QUESTION = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

    window.MxConfirm = function (title, before, item, after, onConfirm, danger) {
        window.WC.confirm(title, before, item, after, onConfirm);
        var overlay = document.getElementById('delete-modal');
        if (!overlay) { return; }
        if (!item) {
            var strong = overlay.querySelector('#delete-modal-item');
            if (strong) { strong.remove(); }
        }
        if (danger) { return; }
        var icon = overlay.querySelector('.delete-modal-icon');
        var bin = icon ? icon.innerHTML : '';
        overlay.classList.add('mx-confirm');
        if (icon) { icon.innerHTML = QUESTION; }
        var watch = new MutationObserver(function () {
            if (overlay.classList.contains('is-open')) { return; }
            overlay.classList.remove('mx-confirm');
            if (icon) { icon.innerHTML = bin; }
            watch.disconnect();
        });
        watch.observe(overlay, { attributes: true, attributeFilter: ['class'] });
    };
}());

/*
 * <a href="#" data-toast="…">: a link the mockup keeps for something the shop does not use; it says
 * so in a toast instead of opening a page (vendor page: purchase orders, bills, AP aging).
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-toast]');
        if (!link) { return; }
        event.preventDefault();
        if (window.WC && window.WC.showToast) { window.WC.showToast(link.getAttribute('data-toast'), 'info'); }
    });
}());

/*
 * <form data-mx-confirm="Title" data-mx-confirm-text="Question?">: asks first, then submits (mockup:
 * Sync Repair Order, "Replace the lines, fees and totals of INV-… ?").
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-mx-confirm]');
        if (!form || !window.MxConfirm) { return; }
        event.preventDefault();
        window.MxConfirm(form.getAttribute('data-mx-confirm'), form.getAttribute('data-mx-confirm-text') || '', '', '', function () { form.submit(); });
    });
}());
