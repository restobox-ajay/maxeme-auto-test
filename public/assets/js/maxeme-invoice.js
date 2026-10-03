/*
 * The invoice builder (legacy invoice_builder.js): item rows, a service's parts, the item name
 * autocomplete (parts and services, grouped), the live totals, and "Order a part".
 *
 * The totals shown here are for the person typing; the server recalculates them on save
 * (App\Maxeme\Accounting\InvoiceCalculator), in the same way.
 */
(function () {
    'use strict';

    var form = document.getElementById('invoice-builder-form');
    if (!form) { return; }

    var config = JSON.parse(form.getAttribute('data-invoice-builder'));
    var body = document.getElementById('mx-item-body');
    var rowTemplate = document.getElementById('item-row-template').innerHTML;
    var partTemplate = document.getElementById('part-row-template').innerHTML;
    var $ = function (selector, scope) { return (scope || document).querySelector(selector); };

    function cents(value) { var n = parseFloat(value); return isNaN(n) ? 0 : Math.round(n * 100); }
    function money(c) { return (c / 100).toFixed(2); }

    /* ── Totals (legacy calculatePrice) ─────────────────────────────────── */
    function calculate() {
        var lines = 0;
        body.querySelectorAll('tr.item-row').forEach(function (row) {
            var qty = parseInt($('.item-quantity-field', row).value, 10) || 0;
            lines += qty * cents($('.item-price-field', row).value);
            // A service's charge-through lines: those kept on the invoice (a fixed amount), or the
            // catalogue's for a service picked now (per one service, saved with it).
            var kept = $('.mx-charge-through', row);
            lines += kept ? (parseInt(kept.dataset.cents, 10) || 0) : qty * (parseInt(row.dataset.chargeThroughCents, 10) || 0);
        });
        var discountInput = $('#total-discount-amount');
        var discount = -Math.abs(cents(discountInput.value));
        var subtotal = lines + discount;
        var gst = Math.round(subtotal * (parseInt($('#tax-gst-amount').value, 10) || 0) / 100);
        var pst = Math.round(subtotal * (parseInt($('#tax-pst-amount').value, 10) || 0) / 100);
        var total = subtotal + gst + pst;

        $('#total-discount-display').textContent = money(discount);
        $('#total-subtotal').textContent = money(subtotal);
        $('#tax-gst').textContent = money(gst);
        $('#tax-pst').textContent = money(pst);
        $('#total-sum-display').textContent = money(total);
        $('#total-change-display').textContent = money(cents($('#paid-amount').value) - total);
    }

    /* ── Rows ─────────────────────────────────────────────────────────────── */
    var nextRow = body.querySelectorAll('tr.item-row').length;

    function addRow(after) {
        var holder = document.createElement('tbody');
        holder.innerHTML = rowTemplate.replace(/__ROW__/g, String(nextRow++)).trim();
        var row = holder.firstElementChild;
        after.parentNode.insertBefore(row, after.nextSibling);
        $('.item-name-search', row).focus();
    }

    function addPart(row) {
        var list = $('.mx-parts-list', row);
        var holder = document.createElement('ul');
        holder.innerHTML = partTemplate.replace(/__BASE__/g, list.getAttribute('data-base')).replace(/__PART__/g, String(Date.now())).trim();
        list.appendChild(holder.firstElementChild);
        $('.part-name-search', list.lastElementChild).focus();
    }

    /* Removing a line that has anything typed or chosen in it asks first; an empty one just goes. */
    function removeAfterConfirm(element, noun, done) {
        var filled = Array.prototype.some.call(element.querySelectorAll('input:not([type=hidden]), select, textarea'), function (field) {
            return field.value !== '' && field.value !== '0' && field.value !== '0.00';
        });
        var name = element.querySelector('.item-name-search, .part-name-search, input[type=text]');
        var remove = function () { element.remove(); if (done) { done(); } };

        if (!filled) { remove(); return; }
        window.WC.confirm('Remove Confirmation', 'Remove ', (name && name.value) || ('this ' + noun), ' from the invoice?', remove);
    }

    body.addEventListener('click', function (event) {
        var row = event.target.closest('tr.item-row');
        if (event.target.closest('.item-add')) { addRow(row); }
        if (event.target.closest('.item-remove') && body.querySelectorAll('tr.item-row').length > 1) { removeAfterConfirm(row, 'item', calculate); }
        if (event.target.closest('.add_part')) { addPart(row); }
        if (event.target.closest('.part-remove')) { removeAfterConfirm(event.target.closest('li.part-row'), 'part'); }
    });
    body.addEventListener('input', calculate);
    body.addEventListener('change', calculate);

    /* ── Item name autocomplete (legacy catcomplete over invoiceItemSearching; maxeme.js MxAutocomplete) ── */
    window.MxAutocomplete.attach(body, '.item-name-search, .part-name-search', {
        empty: 'No matching parts or services.',
        url: function (input, term) {
            return config.itemsUrl + '?' + new URLSearchParams({ q: term, type: input.classList.contains('part-name-search') ? 'parts' : '' });
        },
        pick: function (input, item) {
            input.value = item.label;
            if (input.classList.contains('part-name-search')) {
                $('.part-value', input.closest('li.part-row')).value = item.value;
                return;
            }
            var row = input.closest('tr.item-row');
            $('.item-value', row).value = item.value;
            $('.item-type', row).value = item.category;
            $('.item-price-field', row).value = item.price === null ? '' : money(cents(item.price));
            $('.mx-parts-list', row).innerHTML = '';
            var kept = $('.mx-charge-through', row);
            if (kept) { kept.remove(); }
            row.dataset.chargeThroughCents = item.chargeThroughCents || 0;
            $('.add_part', row).hidden = item.category !== 'Services';
            calculate();
        }
    });

    calculate();
}());
