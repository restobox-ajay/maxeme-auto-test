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

    /* ── Item name autocomplete (legacy catcomplete over invoiceItemSearching) ── */
    var menu = null;
    var cache = {};

    function closeMenu() { if (menu) { menu.remove(); menu = null; } }

    function search(term, type) {
        var key = type + '|' + term;
        if (cache[key]) { return Promise.resolve(cache[key]); }
        return fetch(config.itemsUrl + '?' + new URLSearchParams({ q: term, type: type }), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (items) { cache[key] = items; return items; });
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

    function openMenu(input, items, term, onPick) {
        closeMenu();
        menu = document.createElement('div');
        menu.className = 'mx-ac-menu';
        var category = null;
        items.forEach(function (item) {
            if (item.category !== category) {
                category = item.category;
                var heading = document.createElement('div');
                heading.className = 'cat';
                heading.textContent = category;
                menu.appendChild(heading);
            }
            var link = document.createElement('a');
            link.href = '#';
            link.appendChild(highlight(item.label, term));
            if (item.price !== null && item.price !== undefined) {
                var price = document.createElement('small');
                price.textContent = '$' + money(cents(item.price));
                link.appendChild(price);
            }
            link.addEventListener('mousedown', function (event) { event.preventDefault(); onPick(item); closeMenu(); });
            menu.appendChild(link);
        });
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'mx-ac-empty';
            empty.textContent = 'No matching parts or services.';
            menu.appendChild(empty);
        }
        input.closest('.mx-name-group').appendChild(menu);
    }

    body.addEventListener('focusin', function (event) { if (event.target.matches('.item-name-search, .part-name-search')) { suggest(event.target); } });
    body.addEventListener('input', function (event) { if (event.target.matches('.item-name-search, .part-name-search')) { suggest(event.target); } });
    body.addEventListener('focusout', function () { setTimeout(closeMenu, 150); });

    function suggest(input) {
        var isPart = input.classList.contains('part-name-search');
        var term = input.value.trim();
        search(term, isPart ? 'parts' : '').then(function (items) {
            if (document.activeElement !== input) { return; }
            openMenu(input, items, term, function (item) {
                input.value = item.label;
                if (isPart) {
                    $('.part-value', input.closest('li.part-row')).value = item.value;
                    return;
                }
                var row = input.closest('tr.item-row');
                $('.item-value', row).value = item.value;
                $('.item-type', row).value = item.category;
                $('.item-price-field', row).value = item.price === null ? '' : money(cents(item.price));
                $('.mx-parts-list', row).innerHTML = '';
                $('.add_part', row).hidden = item.category !== 'Services';
                calculate();
            });
        });
    }

    /* ── Order a part (legacy #order-part-modal): created and received; not added to the invoice ── */
    var orderForm = document.getElementById('order-parts-form');
    orderForm.addEventListener('submit', function (event) {
        event.preventDefault();
        fetch(orderForm.action, { method: 'POST', body: new FormData(orderForm), headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json().then(function (json) { return { ok: response.ok, json: json }; }); })
            .then(function (result) {
                window.WC.showToast(result.json.message, result.ok ? 'success' : 'error');
                if (result.ok) {
                    orderForm.reset();
                    document.getElementById('order-part-modal').classList.remove('is-visible');
                    cache = {};
                }
            });
    });

    calculate();
}());
