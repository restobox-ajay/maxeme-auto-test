/*
 * The service page's lines: Add line, Delete (asks when the row has anything in it), the item search
 * for the row's type (maxeme.js MxAutocomplete over maxeme_service_line_items), and a Discount row's
 * fixed quantity of 1 with no item.
 */
(function () {
    'use strict';

    var form = document.getElementById('service-form');
    if (!form) { return; }

    var body = document.getElementById('service-line-body');
    var template = document.getElementById('service-line-template').innerHTML;
    var empty = document.getElementById('service-line-empty');
    var nextRow = body.querySelectorAll('tr.service-line-row').length;
    var $ = function (selector, scope) { return scope.querySelector(selector); };

    function refreshEmpty() { empty.hidden = body.querySelector('tr.service-line-row') !== null; }

    /* A type change empties the item (it was of the old type); a discount has none and qty 1. */
    function applyType(row, clear) {
        var isDiscount = $('.line-type', row).value === 'discount';
        var search = $('.line-item-search', row);
        if (clear) {
            search.value = '';
            $('.line-item-id', row).value = '';
        }
        search.disabled = isDiscount;
        search.placeholder = isDiscount ? 'No item for a discount' : 'Type to search';
        $('.line-quantity', row).readOnly = isDiscount;
        if (isDiscount) { $('.line-quantity', row).value = '1'; }
    }

    document.getElementById('service-line-add').addEventListener('click', function () {
        var holder = document.createElement('tbody');
        holder.innerHTML = template.replace(/__ROW__/g, String(nextRow++)).trim();
        var row = holder.firstElementChild;
        body.appendChild(row);
        refreshEmpty();
        $('.line-type', row).focus();
    });

    body.addEventListener('click', function (event) {
        if (!event.target.closest('.line-remove')) { return; }
        var row = event.target.closest('tr.service-line-row');
        var remove = function () { row.remove(); refreshEmpty(); };
        var label = $('.line-item-search', row).value;
        if (label === '' && $('.line-price', row).value === '') { remove(); return; }
        window.WC.confirm('Delete Confirmation', 'Delete the line ', label || 'this line', '?', remove);
    });

    body.addEventListener('change', function (event) {
        if (event.target.matches('.line-type')) { applyType(event.target.closest('tr'), true); }
    });

    /* Typing over a chosen item un-chooses it, so a half-typed name is not saved as the old item. */
    body.addEventListener('input', function (event) {
        if (event.target.matches('.line-item-search')) { $('.line-item-id', event.target.closest('tr')).value = ''; }
    });

    window.MxAutocomplete.attach(body, '.line-item-search', {
        empty: 'Nothing of this type matches.',
        url: function (input, term) {
            var type = $('.line-type', input.closest('tr')).value;
            return type === 'discount' ? null : form.getAttribute('data-line-items-url') + '?' + new URLSearchParams({ type: type, q: term });
        },
        pick: function (input, item) {
            var row = input.closest('tr');
            input.value = item.label;
            $('.line-item-id', row).value = item.value;
            if (item.price !== null && item.price !== undefined) { $('.line-price', row).value = parseFloat(item.price).toFixed(2); }
        }
    });
}());
