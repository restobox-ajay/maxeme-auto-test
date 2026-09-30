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
 *       data-confirm-title (+ data-confirm-before, data-item, data-confirm-after)
 *                                            ask first, with app.js's #delete-modal. A .danger
 *                                            button (delete, de-activate, decline) always asks,
 *                                            with a generic text when it names none.
 *
 *   .mx-actions             an action group (row or toolbar): the first two actions stay buttons,
 *                           the rest move into a ⋯ menu after them, the way wholesale core does it
 *
 *   .js-sortable-config[data-reload-after-sort]   app.js's drag-to-reorder rows: reload once the new
 *                           order is saved, so row numbers and Move up / Move down match it
 */
(function ($) {
    'use strict';

    $(function () {
        $('.mx-actions').each(function () {
            var $group = $(this);
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
                $form.find('[name="' + name + '"]').val(value === null ? '' : value);
            });
        }

        $modal.addClass('is-visible');
    });

    function post($btn) {
        $btn.prop('disabled', true);
        $.post($btn.data('url'), $.extend({}, $btn.data('params') || {}, { _token: $btn.data('token') }))
            .done(function (response) {
                var message = response && response.message ? response.message : 'Done.';
                if ($btn.is('[data-reload]')) {
                    try { window.sessionStorage.setItem('wcFlashMessage', JSON.stringify({ message: message, type: 'success' })); } catch (e) { /* no storage */ }
                    window.location.reload();
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
     * Click-to-edit cells (Parts Inventory): <tr data-inline-url> holds <td data-inline-field="name">.
     * Enter POSTs { field, value, _token } and shows the JSON { value } it answers; Esc or leaving
     * the cell cancels. data-inline-options='{"value":"Label"}' edits the cell with a select.
     */
    function closeInline($cell, text) {
        $cell.removeClass('is-editing').text(text);
    }

    $(document).on('click', 'td[data-inline-field]:not(.is-editing)', function () {
        var $cell = $(this);
        var original = $cell.text().trim();
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

        $cell.addClass('is-editing').empty().append($input.val(original));
        $input.trigger('focus');

        function save() {
            $input.prop('disabled', true);
            $.post($cell.closest('tr').data('inline-url'), {
                field: $cell.data('inline-field'),
                value: $input.val(),
                _token: $('meta[name="csrf-token"]').attr('content')
            }).done(function (response) {
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

        window.WC.confirm(
            $btn.data('confirm-title') || 'Please confirm',
            $btn.data('confirm-before') || 'Are you sure about ',
            $btn.data('item') || 'this item',
            $btn.data('confirm-after') || '?',
            function () { post($btn); }
        );
    });
}(jQuery));

/*
 * The sidebar "+" (MaxemeAdminMenuProvider) links to a list with the add modal's id as its fragment,
 * e.g. /admin/services#manageAddModal: arriving that way opens the modal through its own button, so
 * the form is set up exactly as a click would set it up.
 */
(function () {
    'use strict';

    var id = window.location.hash.slice(1);
    if (!/^[A-Za-z][\w-]*$/.test(id) || !document.getElementById(id)) { return; }

    var trigger = document.querySelector('[data-modal-open="#' + id + '"]');
    if (trigger) {
        trigger.click();
        if (window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }
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
