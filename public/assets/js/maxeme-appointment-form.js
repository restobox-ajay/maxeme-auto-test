/*
 * Schedule / Edit an appointment (legacy Appointment/form.html.twig + calendar-setting.js
 * addNewEvent / editEvent). The month view shows appointments per day; the day view shows every
 * 15-minute slot with how many appointments start in it. Clicking a slot sets the start (and the
 * end, from the duration) and lists who else starts then. Save confirms, then posts the form.
 *
 * Shop wall-clock times throughout, so the calendar runs in timeZone 'UTC' (see maxeme-calendar.js).
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-appointment-form]');
    if (!form || !window.FullCalendar) { return; }

    var config = JSON.parse(form.getAttribute('data-appointment-form'));
    var $ = function (selector) { return document.querySelector(selector); };
    var startInput = $('#appointment_start_time');
    var endInput = $('#appointment_end_time');
    var startText = $('#a_start_time');
    var duration = $('#appointment_duration');
    var vehicle = $('#client_vehicle_name');
    var saveButton = $('#appointment_submit');
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function parse(value) { return value ? new Date(value.replace(' ', 'T') + 'Z') : null; }
    function ymdhms(date) { return date.toISOString().slice(0, 19).replace('T', ' '); }
    function clock(date) {
        var hours = date.getUTCHours();
        return (hours % 12 || 12) + ':' + pad(date.getUTCMinutes()) + ' ' + (hours < 12 ? 'AM' : 'PM');
    }
    function ordinal(n) { var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }
    function longDate(date) { // "MMMM Do YYYY, h:mm a"
        return MONTHS[date.getUTCMonth()] + ' ' + ordinal(date.getUTCDate()) + ' ' + date.getUTCFullYear() + ', ' + clock(date);
    }

    function setEnd() {
        var start = parse(startInput.value);
        if (start) { endInput.value = ymdhms(new Date(start.getTime() + parseFloat(duration.value || 0) * 3600000)); }
    }
    duration.addEventListener('change', setEnd);

    function getJson(url, params) {
        return fetch(url + '?' + new URLSearchParams(params), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); });
    }

    /* Who else starts in the picked slot (legacy showDetails). */
    var detail = $('#timeslot-detail');
    var detailContainer = $('#timeslot-detail-container');
    function showSlot(start) {
        $('#timeslot-detail-time').textContent = clock(start);
        detailContainer.hidden = false;
        var list = $('#timeslot-detail-list');
        list.textContent = 'Loading...';
        getJson(config.slotUrl, { at: ymdhms(start) }).then(function (appointments) {
            list.innerHTML = '';
            appointments.forEach(function (a) {
                var item = document.createElement('div');
                item.className = 'timeslot-detail-event';
                ['Client: ' + a.name, 'Vehicle: ' + a.vehicle, 'Time: ' + a.start + ' - ' + a.end].forEach(function (line) {
                    var row = document.createElement('div');
                    row.textContent = line;
                    item.appendChild(row);
                });
                list.appendChild(item);
            });
        });
    }

    var wrap = $('.appointment-calendar-wrap');
    var calendar = new FullCalendar.Calendar($('#calendar'), {
        timeZone: 'UTC',
        initialView: config.initialDate ? 'timeGridDay' : 'dayGridMonth',
        initialDate: config.initialDate || undefined,
        allDaySlot: false,
        slotDuration: { minutes: config.slotMinutes },
        slotMinTime: config.slotMinTime,
        slotMaxTime: config.slotMaxTime,
        displayEventTime: false,
        slotLabelFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridDay' },
        buttonText: { today: 'today', month: 'month', day: 'day' },
        editable: false,
        lazyFetching: false,
        dayMaxEvents: true,
        height: 'auto',
        dateClick: function (info) { calendar.changeView('timeGridDay', info.date); },
        eventClick: function (info) {
            if (calendar.view.type !== 'timeGridDay') {
                calendar.changeView('timeGridDay', info.event.start);
                return;
            }
            var start = info.event.start;
            startInput.value = ymdhms(start);
            startText.textContent = start.toISOString().slice(0, 10) + ' ' + clock(start);
            setEnd();
            saveButton.disabled = false;
            document.querySelectorAll('#calendar .fc-event.is-picked').forEach(function (el) { el.classList.remove('is-picked'); });
            info.el.classList.add('is-picked');
            showSlot(start);
        },
        datesSet: function (info) {
            detailContainer.hidden = true;
            var isDay = info.view.type === 'timeGridDay';
            wrap.classList.toggle('is-day', isDay);
            detail.hidden = !isDay;
        },
        events: function (info, success, failure) {
            var day = info.startStr.slice(0, 10);
            var isDayRange = info.end - info.start <= 90000000; // one day, i.e. the day view
            var request = isDayRange
                ? getJson(config.slotsUrl, { date: day }).then(function (slots) {
                    return slots.map(function (slot) {
                        return { title: slot.title, start: slot.start, end: slot.end, extendedProps: { count: slot.count }, classNames: ['timeslot'] };
                    });
                })
                : getJson(config.daysUrl, { start: day, end: info.endStr.slice(0, 10) }).then(function (days) {
                    return days.map(function (d) { return { title: d.title, start: d.start, allDay: true, classNames: ['daily_count'] }; });
                });
            request.then(success).catch(failure);
        },
        eventContent: function (arg) {
            var count = arg.event.extendedProps.count;
            var title = document.createElement('div');
            title.className = 'fc-title';
            if (count === undefined) {
                var badge = document.createElement('span');
                badge.textContent = arg.event.title;
                title.appendChild(badge);
            } else {
                var level = count === 0 ? 'empty' : (count < config.busySlotCount ? 'has-event' : 'full');
                title.appendChild(document.createTextNode(arg.event.title + ' - '));
                var countBadge = document.createElement('span');
                countBadge.className = 'app-count ' + level;
                countBadge.textContent = count;
                title.appendChild(countBadge);
            }
            return { domNodes: [title] };
        }
    });
    calendar.render();

    /* Save: confirm what is being booked, then post the form. */
    form.addEventListener('submit', function (event) {
        if (form.dataset.confirmed === '1') { return; }
        event.preventDefault();
        var start = parse(startInput.value);
        var end = parse(endInput.value);
        if (!start || !end) {
            window.WC.showToast('Pick a start time from the calendar.', 'error');
            return;
        }
        var summary = '\nVehicle: ' + vehicle.options[vehicle.selectedIndex].text + '\nStart: ' + longDate(start) + '\nEnd: ' + longDate(end);
        window.WC.confirm('Confirm appointment', 'Client: ', $('#client_full_name').value, summary, function () {
            form.dataset.confirmed = '1';
            form.submit();
        });
    });
}());
