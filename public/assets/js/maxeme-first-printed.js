/* Work order "First Printed On" (mockup f556ee8): the first time the page is printed, show the time
   in #wo-first-printed and tell the server (data-url on this script, CSRF in data-token), which sets
   it once and never changes it. Saving the PDF sets it on the server side. */
(function () {
    'use strict';
    var script = document.currentScript;
    var url = script && script.getAttribute('data-url');
    var token = script && script.getAttribute('data-token');
    if (!url) { return; }
    function stamp(d) {
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        var h = d.getHours();
        return p(d.getMonth() + 1) + '/' + p(d.getDate()) + '/' + d.getFullYear() + ' ' + p(h % 12 || 12) + ':' + p(d.getMinutes()) + ' ' + (h < 12 ? 'am' : 'pm');
    }
    window.addEventListener('beforeprint', function () {
        var el = document.getElementById('wo-first-printed');
        if (!el || !el.hasAttribute('data-unset')) { return; }
        el.removeAttribute('data-unset');
        el.textContent = stamp(new Date());
        fetch(url, { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': token || '', 'Accept': 'application/json' } }).catch(function () {});
    });
})();
