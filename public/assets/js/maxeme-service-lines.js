/*
 * Service line editors (templates/maxeme/service_line/_editor.html.twig), on the service page and
 * on each service of a repair order: "+ Add line", Delete (asks when the row has anything in it),
 * the item search for the row's type (maxeme.js MxAutocomplete over the form's
 * data-line-items-url), and a Discount row's fixed quantity of 1 with no item.
 *
 * MxServiceLines.add(editor, values) adds a filled row: the repair order page pre-loads a picked
 * service's lines with it.
 */
(function () {
    'use strict';

    var template = document.getElementById('service-line-template');
    if (!template) { return; }

    var $ = function (selector, scope) { return scope.querySelector(selector); };

    function refreshEmpty(editor) {
        $('.mx-lines-empty', editor).hidden = $('.mx-lines-body tr', editor) !== null;
    }

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

    /** Adds a row to `editor`, filled from `values` ({ type, itemId, itemLabel, quantity, unitPrice, chargeThrough }) when given. */
    function add(editor, values) {
        var index = parseInt(editor.getAttribute('data-next'), 10) || 0;
        editor.setAttribute('data-next', String(index + 1));
        var holder = document.createElement('tbody');
        holder.innerHTML = template.innerHTML.replace(/__BASE__/g, editor.getAttribute('data-base')).replace(/__ROW__/g, String(index)).trim();
        var row = holder.firstElementChild;
        $('.mx-lines-body', editor).appendChild(row);
        if (values) {
            $('.line-type', row).value = values.type;
            $('.line-item-id', row).value = values.itemId === null || values.itemId === undefined ? '' : values.itemId;
            $('.line-item-search', row).value = values.itemLabel || '';
            $('.line-quantity', row).value = values.quantity;
            $('.line-price', row).value = parseFloat(values.unitPrice).toFixed(2);
            $('.line-charge-through', row).value = values.chargeThrough ? '1' : '0';
        }
        applyType(row, false);
        refreshEmpty(editor);

        return row;
    }

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('.mx-lines-add');
        if (addButton) {
            $('.line-type', add(addButton.closest('.mx-lines-editor'))).focus();
            return;
        }
        var removeButton = event.target.closest('.line-remove');
        if (!removeButton) { return; }
        var row = removeButton.closest('tr');
        var editor = row.closest('.mx-lines-editor');
        var remove = function () { row.remove(); refreshEmpty(editor); document.dispatchEvent(new CustomEvent('mx:lines-changed')); };
        var label = $('.line-item-search', row).value;
        if (label === '' && $('.line-price', row).value === '') { remove(); return; }
        window.WC.confirm('Delete Confirmation', 'Delete the line ', label || 'this line', '?', remove);
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('.mx-lines-editor .line-type')) { applyType(event.target.closest('tr'), true); }
    });

    /* Typing over a chosen item un-chooses it, so a half-typed name is not saved as the old item. */
    document.addEventListener('input', function (event) {
        if (event.target.matches('.mx-lines-editor .line-item-search')) { $('.line-item-id', event.target.closest('tr')).value = ''; }
    });

    window.MxAutocomplete.attach(document.body, '.mx-lines-editor .line-item-search', {
        empty: 'Nothing of this type matches.',
        url: function (input, term) {
            var type = $('.line-type', input.closest('tr')).value;
            var form = input.closest('[data-line-items-url]');
            return type === 'discount' || !form ? null : form.getAttribute('data-line-items-url') + '?' + new URLSearchParams({ type: type, q: term });
        },
        pick: function (input, item) {
            var row = input.closest('tr');
            input.value = item.label;
            $('.line-item-id', row).value = item.value;
            if (item.price !== null && item.price !== undefined) { $('.line-price', row).value = parseFloat(item.price).toFixed(2); }
            row.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    window.MxServiceLines = { add: add };
}());
