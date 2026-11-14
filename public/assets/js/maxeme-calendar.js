/*
 * Schedule › Appointments (legacy calendar-setting.js). Reads its options from #calendar[data-calendar].
 *
 * Times are shop wall-clock times without an offset, so the calendar runs in timeZone 'UTC': it
 * shows them exactly as given and hands them back the same way, whatever the browser's zone.
 */
(function () {
    'use strict';

    var element = document.getElementById('calendar');
    if (!element || !window.FullCalendar) { return; }

    var config = JSON.parse(element.getAttribute('data-calendar'));
    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var ICON = {
        dollar: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        user: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        wrench: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        checkIn: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
        trash: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>'
    };

    function localIso(date) {
        return date.toISOString().slice(0, 19).replace('T', ' ');
    }

    function post(url, data) {
        var body = new URLSearchParams(data || {});
        body.set('_token', token);
        return fetch(url, { method: 'POST', body: body, headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok) { throw new Error(json.message || 'Something went wrong.'); }
                    return json;
                });
            });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function fail(error) { window.WC.showToast(error.message || 'Something went wrong.', 'error'); }

    /* The icon strip beside a clicked event (legacy createEditUI). */
    function removeStrip() {
        var strip = document.getElementById('calendar_event_edit_ui');
        if (strip) { strip.parentNode.removeChild(strip); }
    }

    function showStrip(event, el) {
        removeStrip();
        var status = event.extendedProps.status;
        var urls = event.extendedProps.urls;
        var items = [];
        if (config.canOpenClient) {
            items.push('<li><a class="client_info_btn" title="client profile" href="' + urls.client + '">' + ICON.user + '</a></li>');
        }
        // The invoice for Accounting, the work order for everyone else (the server decides).
        items.push('<li><a class="view_event_btn" title="invoice" href="' + urls.invoice + '" target="_blank" rel="noopener">' + ICON.dollar + '</a></li>');
        // Its repair order, or a new one for the client and vehicle (mockup 051cc07).
        if (config.canEditRepairOrders && urls.repairOrder) {
            var roTitle = event.extendedProps.repairOrder ? 'repair order ' + event.extendedProps.repairOrder : 'new repair order';
            items.push('<li><a class="repair_order_btn" title="' + escapeHtml(roTitle) + '" href="' + urls.repairOrder + '">' + ICON.wrench + '</a></li>');
        }
        // Check-in: a booked appointment's vehicle has arrived (mockup 33caa46).
        if (config.canManage && status === 'new') {
            items.push('<li><button type="button" class="check_in_btn" title="check-in">' + ICON.checkIn + '</button></li>');
        }
        if (config.canManage && status !== 'complete') {
            items.push('<li><button type="button" class="delete_event_btn" title="delete">' + ICON.trash + '</button></li>');
        }

        var strip = document.createElement('div');
        strip.id = 'calendar_event_edit_ui';
        strip.innerHTML = '<ul>' + items.join('') + '</ul>';
        var box = el.getBoundingClientRect();
        strip.style.top = (box.top + window.scrollY) + 'px';
        strip.style.left = (box.left + window.scrollX - 29) + 'px';
        document.body.appendChild(strip);

        var checkIn = strip.querySelector('.check_in_btn');
        if (checkIn) {
            checkIn.addEventListener('click', function () {
                post(urls.checkIn).then(function (json) {
                    removeStrip();
                    calendar.refetchEvents();
                    window.WC.showToast(json.message, 'success');
                }).catch(fail);
            });
        }

        var remove = strip.querySelector('.delete_event_btn');
        if (remove) {
            remove.addEventListener('click', function () {
                window.WC.confirm('Delete Confirmation', 'You sure you want to delete ', 'this event', '?', function () {
                    post(urls['delete']).then(function (json) {
                        removeStrip();
                        event.remove();
                        window.WC.showToast(json.message, 'success');
                    }).catch(fail);
                });
            });
        }
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.fc-event') && !e.target.closest('#calendar_event_edit_ui')) { removeStrip(); }
    });
    document.addEventListener('scroll', function (e) {
        if (e.target && e.target.closest && e.target.closest('#calendar')) { removeStrip(); }
    }, true);

    /* Dragged or resized (legacy editEventTime): saved, or put back. */
    function saveTime(info) {
        post(info.event.extendedProps.urls.time, { start: localIso(info.event.start), end: localIso(info.event.end) })
            .then(function (json) { window.WC.showToast(json.message, 'success'); })
            .catch(function (error) { info.revert(); fail(error); });
    }

    /* While dragging (mockup d6f741f): a red line across the day where the block will start, and that
       time in the time column; the moving block itself is outlined and shows its new times. */
    var guide = null;
    function clockOf(text) {
        var parts = text.split(':'), h = parseInt(parts[0], 10), m = parts[1];
        return (h % 12 || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM');
    }
    function trackDrag(e) {
        if (!guide) { return; }
        var lane = (document.elementsFromPoint(e.clientX, e.clientY) || []).filter(function (el) { return el.matches && el.matches('td.fc-timegrid-slot-lane[data-time]'); })[0];
        var body = element.querySelector('.fc-timegrid-body');
        if (!lane || !body) { guide.line.hidden = guide.chip.hidden = true; return; }
        var top = lane.getBoundingClientRect().top - body.getBoundingClientRect().top;
        guide.line.style.top = guide.chip.style.top = top + 'px';
        guide.chip.textContent = clockOf(lane.getAttribute('data-time'));
        guide.line.hidden = guide.chip.hidden = false;
    }
    function startDragGuide() {
        var body = element.querySelector('.fc-timegrid-body');
        if (!body) { return; }
        guide = { line: document.createElement('div'), chip: document.createElement('div') };
        guide.line.className = 'mx-drag-line';
        guide.chip.className = 'mx-drag-time';
        guide.line.hidden = guide.chip.hidden = true;
        body.appendChild(guide.line);
        body.appendChild(guide.chip);
        document.addEventListener('mousemove', trackDrag);
    }
    function stopDragGuide() {
        document.removeEventListener('mousemove', trackDrag);
        if (guide) { guide.line.remove(); guide.chip.remove(); guide = null; }
    }

    var calendar = new FullCalendar.Calendar(element, {
        timeZone: 'UTC',
        initialView: config.view,
        allDaySlot: false,
        height: 700,
        slotDuration: { minutes: config.slotMinutes },
        slotMinTime: config.slotMinTime,
        slotMaxTime: config.slotMaxTime,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay listLink' },
        // The List view is a page of its own (mockup deba1ef).
        customButtons: { listLink: { text: 'list', click: function () { window.location.href = config.listUrl; } } },
        buttonText: { today: 'today', month: 'month', week: 'week', day: 'day' },
        selectable: false,
        editable: config.canManage,
        eventDisplay: 'block',
        dayMaxEvents: 2,
        events: config.feedUrl,
        dateClick: function (info) { calendar.changeView('timeGridDay', info.date); },
        eventClick: function (info) { info.jsEvent.preventDefault(); showStrip(info.event, info.el); },
        // Time [status] vehicle, then the customer, the phone and the description, each on its own line.
        eventContent: function (arg) {
            if (arg.isMirror && arg.timeText) {
                return { html: '<div class="mx-event-lines"><div class="mx-event-line"><b>' + escapeHtml(arg.timeText) + '</b></div></div>' };
            }
            var lines = arg.event.extendedProps.lines || [arg.event.title];
            var html = '<div class="mx-event-lines">';
            lines.forEach(function (line, i) {
                html += '<div class="mx-event-line">' + (i === 0 && arg.timeText ? '<b>' + escapeHtml(arg.timeText) + '</b> ' : '') + escapeHtml(line) + '</div>';
            });
            return { html: html + '</div>' };
        },
        eventDragStart: function () { removeStrip(); startDragGuide(); },
        eventDragStop: stopDragGuide,
        eventDidMount: function (arg) {
            if (arg.event.extendedProps.repairOrder) { arg.el.title = 'Repair order ' + arg.event.extendedProps.repairOrder; }
        },
        eventDrop: saveTime,
        eventResize: saveTime,
        moreLinkClick: function () { removeStrip(); return 'popover'; },
        datesSet: function (info) {
            removeStrip();
            var params = new URLSearchParams(window.location.search);
            params.set('view', config.views[info.view.type]);
            window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
        }
    });
    calendar.render();
}());
