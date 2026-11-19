/* Repair Order Board (mockup aa322bb): drag a card to another column to change its repair order's
   status. The server applies the rules (RepairOrderStatusChange): a refusal comes back as a message,
   a move that needs a yes as {confirm}, asked here and sent again with confirmed=1. */
(function () {
    'use strict';
    var board = document.getElementById('repair-order-board');
    if (!board || !board.getAttribute('data-move-url')) { return; }
    var url = board.getAttribute('data-move-url'), token = board.getAttribute('data-token');
    var dragId = null;

    function toast(message, type) {
        if (window.WC && window.WC.showToast) { window.WC.showToast(message, type); }
    }
    function send(id, status, confirmed) {
        var body = new FormData();
        body.append('status', status);
        if (confirmed) { body.append('confirmed', '1'); }
        fetch(url.replace(/\/0\/status$/, '/' + id + '/status'), { method: 'POST', body: body, headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
            .then(function (response) { return response.json().then(function (json) { return { status: response.status, json: json }; }); })
            .then(function (result) {
                if (result.status === 409 && result.json.confirm) {
                    window.MxConfirm('Move repair order', '', '', result.json.confirm, function () { send(id, status, true); });
                    return;
                }
                if (result.status >= 400) { toast(result.json.message || 'The repair order could not be moved.', 'error'); return; }
                toast(result.json.message, 'success');
                var offer = result.json.offerInvoice;
                if (offer) {
                    /* Completed with no invoice yet (mockup 88ddf43): issue it now, or stay on the board. */
                    window.MxConfirm('Issue the invoice now?', '', '', offer.question, function () { postForm(offer.url); });
                    return;
                }
                window.setTimeout(function () { window.location.reload(); }, 500);
            })
            .catch(function () { toast('The repair order could not be moved.', 'error'); });
    }
    function postForm(action) {
        var post = document.createElement('form');
        post.method = 'post';
        post.action = action;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_token';
        input.value = token;
        post.appendChild(input);
        document.body.appendChild(post);
        post.submit();
    }
    function clearOver() {
        board.querySelectorAll('.is-over').forEach(function (el) { el.classList.remove('is-over'); });
    }

    board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.mx-kb-card');
        if (!card) { return; }
        dragId = card.getAttribute('data-id');
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragId);
    });
    board.addEventListener('dragend', function () {
        dragId = null;
        board.querySelectorAll('.dragging').forEach(function (el) { el.classList.remove('dragging'); });
        clearOver();
    });
    board.addEventListener('dragover', function (e) {
        var column = e.target.closest('.mx-kb-col');
        if (!column || !dragId) { return; }
        e.preventDefault();
        if (!column.classList.contains('is-over')) { clearOver(); column.classList.add('is-over'); }
    });
    board.addEventListener('drop', function (e) {
        var column = e.target.closest('.mx-kb-col');
        if (!column || !dragId) { return; }
        e.preventDefault();
        var id = dragId, from = board.querySelector('.mx-kb-card[data-id="' + id + '"]');
        clearOver();
        if (from && from.closest('.mx-kb-col') === column) { return; }
        send(id, column.getAttribute('data-stage'), false);
    });
})();
