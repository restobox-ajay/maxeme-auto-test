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
 *                                            ask first, with app.js's #delete-modal
 */
(function ($) {
    'use strict';

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

        if (!$btn.data('confirm-title')) {
            post($btn);
            return;
        }

        window.WC.confirm(
            $btn.data('confirm-title'),
            $btn.data('confirm-before') || 'Are you sure about ',
            $btn.data('item') || 'this item',
            $btn.data('confirm-after') || '?',
            function () { post($btn); }
        );
    });
}(jQuery));
