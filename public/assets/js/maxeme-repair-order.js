/*
 * The repair order page: the Customer search (or a new customer), the customer's vehicles (or a new
 * one), appointments, services (picking one loads its default price and lines, all editable),
 * custom fees and discounts, and the live totals.
 *
 * The totals shown here are for the person typing; the server recalculates them on save
 * (App\Maxeme\Accounting\RepairOrderCalculator), in the same way.
 */
(function () {
    'use strict';

    var form = document.getElementById('repair-order-form');
    if (!form) { return; }

    var $ = function (selector, scope) { return (scope || document).querySelector(selector); };
    var $$ = function (selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); };

    function cents(value) { var n = parseFloat(value); return isNaN(n) ? 0 : Math.round(n * 100); }
    function money(c) { return (c / 100).toFixed(2); }

    /* A row from a <template>, its placeholders filled in. */
    function fromTemplate(id, holderTag, replacements) {
        var html = document.getElementById(id).innerHTML;
        Object.keys(replacements).forEach(function (key) { html = html.split(key).join(replacements[key]); });
        var holder = document.createElement(holderTag);
        holder.innerHTML = html.trim();
        return holder.firstElementChild;
    }

    /* The next index of a growing list (data-next on its container). */
    function nextIndex(container) {
        var index = parseInt(container.getAttribute('data-next'), 10) || 0;
        container.setAttribute('data-next', String(index + 1));
        return String(index);
    }

    /* Removing something with anything typed in it asks first. */
    function removeAfterConfirm(element, name, done) {
        var filled = $$('input:not([type=hidden]), textarea', element).some(function (field) { return field.value.trim() !== ''; });
        var remove = function () { element.remove(); if (done) { done(); } };
        if (!filled) { remove(); return; }
        window.WC.confirm('Delete Confirmation', 'Delete ', name, '?', remove);
    }

    /* ── Totals ───────────────────────────────────────────────────────────── */
    var gstRate = parseInt(form.getAttribute('data-gst-rate'), 10) || 0;
    var pstRate = parseInt(form.getAttribute('data-pst-rate'), 10) || 0;

    function calculate() {
        var subtotal = 0;
        $$('#ro-jobs [data-job]').forEach(function (job) {
            subtotal += cents($('.job-price', job).value);
            $$('.mx-lines-body tr', job).forEach(function (row) {
                if ($('.line-charge-through', row).value === '1') {
                    subtotal += Math.round((parseFloat($('.line-quantity', row).value) || 0) * cents($('.line-price', row).value));
                }
            });
        });
        var charges = 0;
        $$('#ro-charge-body tr').forEach(function (row) {
            var amount = Math.abs(cents($('.charge-amount', row).value));
            charges += $('.charge-kind', row).value === 'discount' ? -amount : amount;
        });
        var taxable = subtotal + charges;
        var gst = Math.round(taxable * gstRate / 100);
        var pst = Math.round(taxable * pstRate / 100);

        $('#ro-subtotal').textContent = money(subtotal);
        $('#ro-gst').textContent = money(gst);
        $('#ro-pst').textContent = money(pst);
        $('#ro-total').textContent = money(taxable + gst + pst);
    }

    form.addEventListener('input', calculate);
    form.addEventListener('change', calculate);
    document.addEventListener('mx:lines-changed', calculate);

    /* ── Customer and vehicle ──────────────────────────────────────────────── */
    var clientId = $('#ro-client-id');
    var clientSearch = $('#ro-client-search');
    var clientInfo = $('#ro-client-info');
    var newClient = $('#ro-new-client');
    var toggle = $('#ro-client-toggle');
    var vehicle = $('#ro-vehicle');
    var newVehicle = $('#ro-new-vehicle');

    function setVehicles(vehicles, placeholder) {
        var keepNew = vehicle.querySelector('option[value="new"]');
        vehicle.innerHTML = '';
        var none = document.createElement('option');
        none.value = '';
        none.textContent = placeholder;
        vehicle.appendChild(none);
        vehicles.forEach(function (option) {
            var element = document.createElement('option');
            element.value = option.id;
            element.textContent = option.label;
            vehicle.appendChild(element);
        });
        vehicle.appendChild(keepNew);
        vehicle.value = vehicles.length === 1 ? String(vehicles[0].id) : '';
        newVehicle.hidden = true;
    }

    function showInfo(item) {
        clientInfo.innerHTML = '';
        [item.label].concat((item.phones || []).map(function (phone, i) { return 'Phone ' + (i + 1) + ': ' + phone; }), item.email ? [item.email] : [])
            .forEach(function (text) { var line = document.createElement('div'); line.textContent = text; clientInfo.appendChild(line); });
        clientInfo.hidden = false;
    }

    window.MxAutocomplete.attach(form, '#ro-client-search', {
        empty: 'No matching customers. Use "+ New customer".',
        url: function (input, term) { return term === '' ? null : form.getAttribute('data-clients-url') + '?' + new URLSearchParams({ q: term }); },
        pick: function (input, item) {
            input.value = item.label;
            clientId.value = item.value;
            showInfo(item);
            setVehicles(item.vehicles || [], '(none)');
        }
    });

    /* Typing over the chosen customer un-chooses them. */
    clientSearch.addEventListener('input', function () {
        clientId.value = '';
        clientInfo.hidden = true;
        setVehicles([], 'Choose the customer first');
    });

    toggle.addEventListener('click', function () {
        var typingNew = clientId.value !== 'new';
        clientId.value = typingNew ? 'new' : '';
        clientSearch.hidden = typingNew;
        clientSearch.value = '';
        clientInfo.hidden = true;
        newClient.hidden = !typingNew;
        toggle.textContent = toggle.getAttribute(typingNew ? 'data-search-label' : 'data-new-label');
        setVehicles([], typingNew ? '(none)' : 'Choose the customer first');
        if (typingNew) {
            // A new customer has no vehicles yet: offer the new vehicle straight away.
            vehicle.value = 'new';
            newVehicle.hidden = false;
            $('input', newClient).focus();
        } else {
            clientSearch.focus();
        }
    });

    vehicle.addEventListener('change', function () { newVehicle.hidden = vehicle.value !== 'new'; });

    /* ── Appointments ──────────────────────────────────────────────────────── */
    var appointments = $('#ro-appointment-body');
    $('#ro-appointment-add').addEventListener('click', function () {
        var row = fromTemplate('ro-appointment-template', 'tbody', { '__APPT__': nextIndex(appointments), '__NUMBER__': String(appointments.rows.length + 1) });
        appointments.appendChild(row);
        $('input[type=datetime-local]', row).focus();
    });
    appointments.addEventListener('click', function (event) {
        if (event.target.closest('.appointment-remove')) { event.target.closest('tr').remove(); }
    });

    /* ── Services ──────────────────────────────────────────────────────────── */
    var jobs = $('#ro-jobs');

    function renumberJobs() {
        $$('[data-job]', jobs).forEach(function (job, i) { $('.mx-job-number', job).textContent = (i + 1) + '.'; });
        $('#ro-jobs-empty').hidden = jobs.querySelector('[data-job]') !== null;
    }

    $('#ro-job-add').addEventListener('click', function () {
        var job = fromTemplate('ro-job-template', 'div', { '__JOB__': nextIndex(jobs) });
        jobs.appendChild(job);
        renumberJobs();
        $('.job-name', job).focus();
    });

    jobs.addEventListener('click', function (event) {
        if (!event.target.closest('.job-remove')) { return; }
        var job = event.target.closest('[data-job]');
        removeAfterConfirm(job, $('.job-name', job).value || 'this service', function () { renumberJobs(); calculate(); });
    });

    /* Typing a name of its own makes it a service of its own: no catalogue service, no category. */
    jobs.addEventListener('input', function (event) {
        if (!event.target.matches('.job-name')) { return; }
        var job = event.target.closest('[data-job]');
        $('.job-service-id', job).value = '';
        $('.job-category-value', job).value = '';
        $('.mx-job-category', job).textContent = '';
    });

    window.MxAutocomplete.attach(jobs, '.job-name', {
        empty: 'No matching services; the name typed is kept as it is.',
        url: function (input, term) { return form.getAttribute('data-services-url') + '?' + new URLSearchParams({ q: term }); },
        pick: function (input, item) {
            var job = input.closest('[data-job]');
            input.value = item.label;
            $('.job-service-id', job).value = item.value;
            $('.job-category-value', job).value = item.category || '';
            $('.mx-job-category', job).textContent = item.category ? 'Cat: ' + item.category : '';
            $('.job-price', job).value = item.price === null ? '' : parseFloat(item.price).toFixed(2);
            // The service's lines replace the ones there, ready to edit.
            var editor = $('.mx-lines-editor', job);
            $('.mx-lines-body', editor).innerHTML = '';
            (item.lines || []).forEach(function (line) { window.MxServiceLines.add(editor, line); });
            if (!(item.lines || []).length) { $('.mx-lines-empty', editor).hidden = false; }
            calculate();
        }
    });

    /* ── Custom fees and discounts ─────────────────────────────────────────── */
    var charges = $('#ro-charge-body');
    $('#ro-charge-add').addEventListener('click', function () {
        var row = fromTemplate('ro-charge-template', 'tbody', { '__CHARGE__': nextIndex(charges) });
        charges.appendChild(row);
        $('.charge-label', row).focus();
    });
    charges.addEventListener('click', function (event) {
        if (!event.target.closest('.charge-remove')) { return; }
        var row = event.target.closest('tr');
        removeAfterConfirm(row, $('.charge-label', row).value || 'this line', calculate);
    });

    renumberJobs();
    calculate();
}());
