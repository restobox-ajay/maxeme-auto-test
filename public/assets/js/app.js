/* ── CSRF for AJAX-only endpoints ─────────────────────────────────────────────
 * Forms do NOT depend on this: every form carries a server-rendered {{ csrf_field() }}, so a
 * no-JS browser submits fine. This only serves the admin endpoints that have no form at all
 * (row delete buttons, inline price/stock grids, drag-reorder).
 *
 * Same-origin is checked so the token is never disclosed to a third-party host.
 */
(function () {
    'use strict';

    var HEADER = 'X-CSRF-TOKEN';
    var SAFE = /^(GET|HEAD|OPTIONS|TRACE)$/i;

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    function sameOrigin(url) {
        try {
            return new URL(String(url == null ? '' : url), window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    if (window.jQuery) {
        window.jQuery(document).ajaxSend(function (e, jqXHR, settings) {
            var t = token();
            if (!t || SAFE.test(settings.type || 'GET') || !sameOrigin(settings.url)) { return; }
            jqXHR.setRequestHeader(HEADER, t);
        });
    }

    if (typeof window.fetch === 'function') {
        var nativeFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var url = (input && typeof input === 'object' && 'url' in input) ? input.url : input;
            var method = init.method || (input && typeof input === 'object' && input.method) || 'GET';
            var t = token();

            if (t && !SAFE.test(method) && sameOrigin(url)) {
                var headers = new Headers(init.headers || (input && typeof input === 'object' && input.headers) || undefined);
                if (!headers.has(HEADER)) {
                    headers.set(HEADER, t);
                    init = Object.assign({}, init, { headers: headers });
                }
            }

            return nativeFetch.call(this, input, init);
        };
    }
})();

(function ($) {
    'use strict';

    if (!$) { return; }

    var $window = $(window);
    var $body = $('body');
    var $topbar = $('.topbar');

    /* ── Utility ──────────────────────────────────────────────────────────── */
    function debounce(fn, wait) {
        var timer;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    /* ── Active nav link ──────────────────────────────────────────────────── */
    if (!$body.hasClass('site-customer')) {
        var currentPath = window.location.pathname.replace(/\/$/, '') || '/';
        var bestMatch = null;
        var bestLength = -1;

        // Row affordances (queue item 35) are excluded: an affordance points at the same route as
        // the create row it shortcuts, and it comes FIRST in the DOM, so letting it compete here
        // would move the highlight off that row and onto a 22px icon. The server already marks
        // the right row current; this only has to not undo it.
        $('.nav a').not('.nav-affordance').each(function () {
            var $link = $(this);
            var linkPath = ($link.attr('href') || '').split('?')[0].replace(/\/$/, '') || '/';
            var exact = linkPath === currentPath;

            // Smarter matching for nested admin routes (e.g. /admin/user/edit matches /admin/user/index)
            var nested = false;
            if (linkPath !== '/' && linkPath !== '/admin' && linkPath.indexOf('/admin/') === 0) {
                var linkBase = linkPath.replace(/\/index$/, '');
                if (currentPath.indexOf(linkBase + '/') === 0 || currentPath === linkBase) {
                    nested = true;
                }
            }

            if ((exact || nested) && linkPath.length > bestLength) {
                bestMatch = $link;
                bestLength = linkPath.length;
            }
        });

        if (bestMatch) {
            $('.nav a').removeClass('is-current');
            bestMatch.addClass('is-current');
            var $group = bestMatch.closest('.nav-group');
            if ($group.length) {
                $group.addClass('is-open').find('.nav-group-title').attr('aria-expanded', 'true');
            }
        }
    }

    $('.nav-group-title').on('click', function () {
        var $button = $(this);
        var $group = $button.closest('.nav-group');
        var isOpen = !$group.hasClass('is-open');

        if (isOpen) {
            // Close other open groups first
            $('.nav-group.is-open').not($group).each(function () {
                $(this).removeClass('is-open').find('.nav-group-title').attr('aria-expanded', 'false');
            });
        }

        $group.toggleClass('is-open', isOpen);
        $button.attr('aria-expanded', String(isOpen));
    });

    /* ── Admin sidebar collapse toggle ───────────────────────────────────── */
    var SIDEBAR_COLLAPSE_KEY = 'adminSidebarCollapsed';
    var $collapseToggle = $('.sidebar-collapse-toggle');
    if ($collapseToggle.length) {
        if (localStorage.getItem(SIDEBAR_COLLAPSE_KEY) === '1') {
            $body.addClass('admin-sidebar-collapsed');
            $collapseToggle.attr('aria-expanded', 'false');
        }

        $collapseToggle.on('click', function () {
            var isCollapsed = $body.toggleClass('admin-sidebar-collapsed').hasClass('admin-sidebar-collapsed');
            $(this).attr('aria-expanded', String(!isCollapsed));
            localStorage.setItem(SIDEBAR_COLLAPSE_KEY, isCollapsed ? '1' : '0');

            if (isCollapsed) {
                $('.nav-group.is-open').removeClass('is-open').find('.nav-group-title').attr('aria-expanded', 'false');
            }
        });
    }

    /* ── Wheel category "Advanced View" toggle persistence ──────────────────
     * The toggle itself is a CSS-only checkbox (see app.css); this only carries its checked
     * state across the Search button's full page reload, since ProductSearch[view=] submits
     * a plain GET form rather than an AJAX request.
     */
    var WHEEL_ADVANCED_VIEW_KEY = 'wheelAdvancedViewOpen';
    var $wheelAdvancedToggle = $('#wheel-advanced-toggle');
    if ($wheelAdvancedToggle.length) {
        try {
            if (window.sessionStorage && sessionStorage.getItem(WHEEL_ADVANCED_VIEW_KEY) === '1') {
                $wheelAdvancedToggle.prop('checked', true);
            }
        } catch (e) { /* private mode / storage unavailable */ }

        $wheelAdvancedToggle.on('change', function () {
            try {
                if (window.sessionStorage) {
                    sessionStorage.setItem(WHEEL_ADVANCED_VIEW_KEY, this.checked ? '1' : '0');
                }
            } catch (e) { /* ignore */ }
        });
    }

    /* ── Mobile nav toggle ────────────────────────────────────────────────── */
    $('.nav-toggle').on('click', function () {
        var $toggle = $(this);
        var $header = $toggle.closest('.customer-site-header');
        if ($header.length) {
            var $menu = $header.find('.customer-subnav-menu').first();
            var isCustomerOpen = !$menu.hasClass('is-open');
            $menu.toggleClass('is-open', isCustomerOpen);
            $toggle.attr('aria-expanded', String(isCustomerOpen));
            return;
        }

        var isOpen = !$body.hasClass('nav-open');
        $body.toggleClass('nav-open', isOpen);
        $toggle.attr('aria-expanded', String(isOpen));
    });

    $('.nav a').on('click', function () {
        $body.removeClass('nav-open');
        $('.nav-toggle').attr('aria-expanded', 'false');
        $('.customer-subnav-menu').removeClass('is-open');
    });

    /* Close mobile nav on outside click */
    $(document).on('click.nav', function (e) {
        if ($body.hasClass('nav-open') && !$(e.target).closest('.topbar, .customer-site-header').length) {
            $body.removeClass('nav-open');
            $('.nav-toggle').attr('aria-expanded', 'false');
        }
        if ($('.customer-subnav-menu').hasClass('is-open') && !$(e.target).closest('.customer-site-header').length) {
            $('.customer-subnav-menu').removeClass('is-open');
            $('.nav-toggle').attr('aria-expanded', 'false');
        }
    });

    /* ── Sticky header shadow ─────────────────────────────────────────────── */
    function updateHeaderShadow() {
        $topbar.toggleClass('has-shadow', $window.scrollTop() > 8);
    }
    updateHeaderShadow();
    $window.on('scroll.header', updateHeaderShadow);

    /* ── Admin User Menu toggle ───────────────────────────────────────────── */
    $(document).on('click', '.user-menu-toggle', function (e) {
        e.stopPropagation();
        var $menu = $(this).closest('.user-menu');
        var isOpen = !$menu.hasClass('is-open');
        $('.user-menu').not($menu).removeClass('is-open');
        $menu.toggleClass('is-open', isOpen);
        $(this).attr('aria-expanded', String(isOpen));
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.user-menu').length) {
            $('.user-menu').removeClass('is-open');
            $('.user-menu-toggle').attr('aria-expanded', 'false');
        }
    });

    /* ── Per-page / pagination helpers ───────────────────────────────────── */
    $('.js-auto-submit-form').each(function () {
        var form = this;
        if (!form || !form.querySelector) { return; }

        function submitAuto() {
            var pageField = form.querySelector('[name="page"]');
            if (pageField) { pageField.value = '1'; }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }

        // Use form.elements (not $(form).find(...)) so this also picks up controls that are
        // associated via a `form="..."` attribute rather than being DOM descendants — needed
        // on pages where filter inputs live inside a <table> that sits outside this <form>.
        $.each(form.elements, function (_, el) {
            if (!el || (el.tagName !== 'SELECT' && !(el.tagName === 'INPUT' && ['text', 'search', 'number'].indexOf(el.type) !== -1))) {
                return;
            }
            if (el.classList && el.classList.contains('js-no-autosubmit')) { return; }
            $(el).on('change.autosubmit', submitAuto);
        });
    });

    $(document).on('change', '.fulfillment-region-grid form.js-auto-submit-form select', function () {
        var form = this.form;
        if (!form) { return; }
        var pageField = form.querySelector('[name="page"]');
        if (pageField) { pageField.value = '1'; }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });

    var PER_PAGE_OPTS = [20, 100, 200, 500];

    function formatTableCount(start, end, total) {
        if (total <= 0) {
            return 'Showing 0 to 0 of 0 entries';
        }
        return 'Showing ' + start + ' to ' + end + ' of ' + total + ' entries';
    }

    function initFooterControls($card) {
        var $footer = $card.find('.table-footer');
        if (!$footer.length) {
            $footer = $('<div class="table-footer"><span class="table-count"></span></div>');
            $card.append($footer);
        }
        if (!$footer.length || $footer.data('pager-init')) { return; }
        $footer.data('pager-init', true);
        var currentPage = parseInt($card.data('current-page')) || 1;
        var currentLimit = parseInt($card.data('per-page')) || 100;
        $card.data('current-page', currentPage);

        if (!$footer.find('.table-count').length) {
            $footer.append('<span class="table-count"></span>');
        }

        var suppressPerPage = $card.hasClass('company-detail-card');
        var $perPage = $('<select class="per-page-select" aria-label="Rows per page"></select>');
        PER_PAGE_OPTS.forEach(function (n) {
            $perPage.append('<option value="' + n + '"' + (n === currentLimit ? ' selected' : '') + '>' + n + '</option>');
        });
        var $label = $('<label class="per-page-label">Per page</label>').append($perPage);
        var $pager = $('<div class="table-pagination"></div>');
        if (!suppressPerPage) {
            $footer.prepend($label);
        }
        var $actions = $footer.find('.table-footer-actions').first();
        if ($actions.length) { $pager.insertBefore($actions); }
        else { $footer.append($pager); }

        if (!suppressPerPage) {
            $perPage.on('change', function () {
                $card.data('current-page', 1);
                $card.trigger('wc:paginate');
            });
        }
    }

    function renderPagination($card, matched, total) {
        var $footer = $card.find('.table-footer');
        var $count = $footer.find('.table-count');
        var $perPage = $footer.find('.per-page-select');
        var $pager = $footer.find('.table-pagination');
        var allRows = $card.find('tbody tr').filter(function () {
            return !$(this).find('.empty-table-cell').length;
        });

        var perPage = parseInt($perPage.val()) || 0;
        var page = parseInt($card.data('current-page')) || 1;
        var pages = perPage ? Math.ceil(matched.length / perPage) : 1;
        if (page > pages) { page = 1; $card.data('current-page', 1); }

        var start = perPage ? (page - 1) * perPage : 0;
        var end = perPage ? start + perPage : matched.length;

        allRows.hide();
        $(matched).each(function (i) {
            if (i >= start && i < end) { $(this).show(); }
        });

        var visibleStart = matched.length ? (start + 1) : 0;
        var visibleEnd = matched.length ? Math.min(end, matched.length) : 0;
        $count.text(formatTableCount(visibleStart, visibleEnd, total));

        $pager.empty();
        if (pages <= 1) { return; }

        function mkBtn(label, target, active) {
            var $b = $('<button class="page-btn" type="button">' + label + '</button>');
            if (active) { $b.addClass('is-active'); }
            if (target === null) { $b.prop('disabled', true); }
            else {
                $b.on('click', function () {
                    $card.data('current-page', target);
                    $card.trigger('wc:paginate');
                });
            }
            return $b;
        }

        $pager.append(mkBtn('First', page > 1 ? 1 : null, false));
        $pager.append(mkBtn('<', page > 1 ? page - 1 : null, false));

        var maxButtons = 5;
        var lo = Math.max(1, page - 2);
        var hi = Math.min(pages, lo + maxButtons - 1);
        if (hi - lo < maxButtons - 1) { lo = Math.max(1, hi - maxButtons + 1); }

        if (lo > 1) {
            $pager.append(mkBtn(1, 1, false));
            if (lo > 2) { $pager.append('<span class="page-ellipsis">…</span>'); }
        }

        for (var p = lo; p <= hi; p++) {
            $pager.append(mkBtn(p, p, p === page));
        }

        if (hi < pages) {
            if (hi < pages - 1) { $pager.append('<span class="page-ellipsis">…</span>'); }
            $pager.append(mkBtn(pages, pages, false));
        }

        $pager.append(mkBtn('>', page < pages ? page + 1 : null, false));
        $pager.append(mkBtn('Last', page < pages ? pages : null, false));
    }

    /* ── Table search + pagination ────────────────────────────────────────── */
    $('.table-card').each(function () {
        var $card = $(this);
        var $table = $card.find('table').first();
        var $header = $card.find('.table-header').first();
        var $headerFilters = $table.find('thead .filter-row').find('input, select');
        var $filters = $card.find('[data-filter-field]');
        var useLinkControls = (($card.data('link-controls') || '').toString().toLowerCase() === 'true');

        if (!$table.length) { return; }
        if (useLinkControls) { return; }

        if (!$card.hasClass('no-paginate')) { initFooterControls($card); }

        var $search = $card.find('.table-search').first();

        /* Pre-fill from URL query param 'q' */
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('q') && $search.length && !window.location.hash.includes('no-q')) {
            $search.val(urlParams.get('q'));
        }

        /* Pre-fill filter row from URL query params: filters[field]=... */
        try {
            if ($filters && $filters.length) {
                $filters.each(function () {
                    var $f = $(this);
                    var field = ($f.data('filter-field') || '').toString();
                    if (!field) { return; }
                    var key = 'filters[' + field + ']';
                    if (!urlParams.has(key)) { return; }
                    var v = (urlParams.get(key) || '').toString();
                    $f.val(v);
                });
            }
        } catch (e) {
            // ignore
        }

	        function samePathAsCurrent(url) {
	            try {
	                var u = new URL(url, window.location.origin);
	                return u.pathname === window.location.pathname;
	            } catch (e) {
	                return false;
	            }
	        }

	        var ajaxUrl = $card.data('ajax-url');
	        var isAdminTable = window.location.pathname.indexOf('/admin') === 0;
	        // URL-only compliance for admin: never do in-page filtering or AJAX table reloads.
	        var isQuoteRequestTabSync = ($card.data('tab-sync') || '').toString() === 'quote-request';
	        var forceFullReload = (($card.data('full-reload') || '').toString().toLowerCase() === 'true');
	        var useFullReload = isAdminTable ? true : (forceFullReload || isQuoteRequestTabSync || (ajaxUrl && samePathAsCurrent(ajaxUrl) && !$card.hasClass('ajax-only')));

	        // Keep sort state on the card so both full reload + ajax can use it.
	        // Defaults:
	        // - Use explicit data-default-sort/data-default-dir when present
	        // - Otherwise fall back to the first sortable column in the table header
	        var defaultSort = ($card.data('default-sort') || '').toString();
	        if (!defaultSort) {
	            var $firstSortable = $table.find('thead th[data-sort-field]').first();
	            defaultSort = ($firstSortable.data('sort-field') || '').toString();
	        }
	        var defaultDirRaw = ($card.data('default-dir') || '').toString().toLowerCase();
	        var defaultDir = (defaultDirRaw === 'desc' || defaultDirRaw === 'asc') ? defaultDirRaw : 'asc';

	        $card.data('default-sort', defaultSort);
	        $card.data('default-dir', defaultDir);
	        var initialSort = '';
	        var initialDir = 'asc';
	        try {
	            var initParams = new URLSearchParams(window.location.search || '');
	            initialSort = (initParams.get('sort') || '').toString();
	            var dirParam = (initParams.get('dir') || '').toString().toLowerCase();
	            initialDir = (dirParam === 'desc' || dirParam === 'asc') ? dirParam : (defaultDir || 'asc');
	        } catch (e) {
	            // ignore
	        }
	        if (!initialSort && defaultSort) {
	            initialSort = defaultSort;
	        }
	        if (!initialDir) { initialDir = defaultDir || 'asc'; }
	        $card.data('sort', initialSort);
	        $card.data('dir', initialDir);

	        function navigateWithParams(extra) {
	            var params = new URLSearchParams(window.location.search || '');
	            Object.keys(extra || {}).forEach(function (k) {
	                var v = extra[k];
	                if (v === null || v === undefined || v === '') { params.delete(k); }
	                else { params.set(k, v); }
	            });
	            var qs = params.toString();
	            var next = window.location.pathname + (qs ? '?' + qs : '');
	            var current = window.location.pathname + (window.location.search || '');
	            if (next === current) { return; }
	            window.location.href = next;
	        }

	        function getLiveRows() {
	            return $table.find('tbody tr').filter(function () {
	                return !$(this).find('.empty-table-cell').length;
	            });
	        }

        function getMatched($liveRows) {
            var q = $search.length ? $search.val().toString().toLowerCase().trim() : '';
            return $liveRows.filter(function () {
                var $row = $(this);
                var $searchables = $row.find('.searchable');
                var textToSearch = $searchables.length ? $searchables.text() : $row.text();
                var ok = !q || textToSearch.toLowerCase().indexOf(q) !== -1;

                $headerFilters.each(function () {
                    if (!ok) { return false; }
                    var $filter = $(this);
                    var value = $filter.val().toString().toLowerCase().trim();
                    if (!value) { return; }

                    var columnIndex = $filter.closest('th').index();
                    var cellText = $row.children('td').eq(columnIndex).text().toLowerCase().trim();
                    ok = $filter.is('select') ? cellText === value : cellText.indexOf(value) !== -1;
                });

                return ok;
            }).toArray();
        }

	        $card.on('wc:paginate', function () {
	            if (useFullReload) {
	                var page = parseInt($card.data('current-page')) || 1;
	                var limit = parseInt($card.find('.per-page-select').val(), 10) || parseInt($card.data('per-page')) || 10;
	                var q = $search.length ? ($search.val() || '').toString() : '';
	                var sort = ($card.data('sort') || $card.data('default-sort') || '').toString();
	                var dir = (($card.data('dir') || $card.data('default-dir') || 'asc').toString().toLowerCase() === 'desc') ? 'desc' : 'asc';

	                // Full-reload admin tables still need URL-driven filters.
	                // Include current filter row values so external filter UIs that write into hidden
	                // filter inputs (like the Orders PO From/To bar) work on Search/Reset.
	                var extra = { page: String(page), limit: String(limit), q: q, sort: sort, dir: dir };
	                $filters.each(function () {
	                    var $f = $(this);
	                    var field = ($f.data('filter-field') || '').toString();
	                    if (!field) { return; }
	                    var value = ($f.val() || '').toString().trim();
	                    extra['filters[' + field + ']'] = value;
	                });

	                navigateWithParams(extra);
	                return;
	            }

	            var ajaxUrl = $card.data('ajax-url');
	            if (ajaxUrl) {
	                loadAjaxData();
	                return;
	            }

	            var $liveRows = getLiveRows();
	            var matched = getMatched($liveRows);
	            renderPagination($card, matched, $liveRows.length);
	            $table.find('tbody tr').filter(function () {
	                return $(this).find('.empty-table-cell').length;
	            }).toggle($liveRows.length === 0 || matched.length === 0);
	        });

		        function loadAjaxData() {
		            var ajaxUrl = $card.data('ajax-url');
		            var page = parseInt($card.data('current-page')) || 1;
		            var limit = parseInt($card.find('.per-page-select').val(), 10) || 10;
		            var q = $search.val() || '';
		            var sort = ($card.data('sort') || '').toString();
		            var dir = (($card.data('dir') || 'asc').toString().toLowerCase() === 'desc') ? 'desc' : 'asc';
	            var filters = {};
            $filters.each(function () {
                var $filter = $(this);
                var field = $filter.data('filter-field');
                var value = ($filter.val() || '').toString().trim();
                if (field && value) {
                    filters[field] = value;
                }
            });

            // Preserve existing query params (e.g. OrderSearch[company_id]) when paginating via AJAX.
            // Explicit AJAX params (page/limit/q/filters) override the URL.
            var extraParams = {};
            try {
                var urlParams = new URLSearchParams(window.location.search || '');
                urlParams.forEach(function (value, key) {
                    if (key === 'page' || key === 'limit' || key === 'q') { return; }
                    if (key.indexOf('filters[') === 0) { return; }
                    extraParams[key] = value;
                });
            } catch (e) {
                // ignore
            }
            
            $card.addClass('is-loading').attr('aria-busy', 'true');
            
		            $.ajax({
		                url: ajaxUrl,
		                data: $.extend({}, extraParams, { page: page, limit: limit, q: q, sort: sort, dir: dir, filters: filters }),
		                headers: { 'X-Requested-With': 'XMLHttpRequest' },
		                success: function (res) {
                    // Universal handler: if controller returns 'html', inject it.
                    // If it returns 'items' (old way), render error log rows.
                    var $tbody = $table.find('tbody');
                    
                    if (res.html) {
                        $tbody.html(res.html);
                    } else if (res.items) {
                        $tbody.empty();
                        res.items.forEach(function (item) {
                            var row = '<tr>' +
                                '<td data-label="#">' + item.id + '</td>' +
                                '<td data-label="Created At" class="log-date">' + item.createdAt + '</td>' +
                                '<td data-label="Method"><span class="error-method">' + item.method + '</span></td>' +
                                '<td data-label="Summary">' + item.summary + '</td>' +
                                '<td data-label="Error Description">' +
                                '<div class="error-desc-cell">' +
                                '<span class="error-file">' + (item.file || '') + '</span>' +
                                (item.line ? '<span class="error-line">Line: ' + item.line + '</span>' : '') +
                                (item.message ? '<span class="error-message">' + item.message + '</span>' : '') +
                                '</div>' +
                                '</td>' +
                                '<td data-label="Meta Data"><pre class="error-meta-preview">' + (item.metaData || '') + '</pre></td>' +
                                '<td data-label="Actions" class="actions-cell">' +
                                '<a class="table-action info" href="' + item.viewUrl + '">View</a>' +
                                '</td>' +
                                '</tr>';
                            $tbody.append(row);
                        });
                    } else {
                        $tbody.empty().append('<tr><td colspan="20" class="empty-table-cell"><strong>No records found.</strong></td></tr>');
                    }
                    
                    var pages = res.pages || 1;
                    var total = res.total || 0;
                    
                    var start = (res.page - 1) * res.limit + 1;
                    var end = Math.min(res.page * res.limit, total);
                    $card.find('.table-count').text(formatTableCount(total > 0 ? start : 0, total > 0 ? end : 0, total));
                    renderPaginationInternal($card, res.page, pages);
                },
                complete: function () {
                    $card.removeClass('is-loading').attr('aria-busy', 'false');
                }
	            });
	        }

        function updateSortIndicators() {
            var sort = '';
            var dir = 'asc';

            try {
                if (useFullReload) {
                    var params = new URLSearchParams(window.location.search || '');
                    sort = (params.get('sort') || '').toString();
                    dir = (params.get('dir') || 'asc').toString().toLowerCase() === 'desc' ? 'desc' : 'asc';
                } else {
                    // Only show indicator when user has explicitly sorted a column,
                    // not on initial load where sort is just the default fallback.
                    if (!$card.data('sort-active')) {
                        if ($table.find('thead th.is-sorted-asc[data-sort-field], thead th.is-sorted-desc[data-sort-field]').length) {
                            return;
                        }
                        $table.find('thead th[data-sort-field]').removeClass('is-sorted-asc is-sorted-desc');
                        return;
                    }
                    sort = ($card.data('sort') || '').toString();
                    dir = (($card.data('dir') || 'asc').toString().toLowerCase() === 'desc') ? 'desc' : 'asc';
                }
            } catch (e) {
                // ignore
            }

            $table.find('thead th[data-sort-field]').removeClass('is-sorted-asc is-sorted-desc');
            if (!sort) { return; }

            // Escape double-quotes (defense-in-depth; sort keys should be simple identifiers)
            sort = sort.replace(/"/g, '\\"');
            var $sorted = $table.find('thead th[data-sort-field="' + sort + '"]');
            if (!$sorted.length) { return; }
            $sorted.addClass(dir === 'desc' ? 'is-sorted-desc' : 'is-sorted-asc');
        }

	        if (useFullReload) {
	            if ($search.length) {
	                var $searchBtn = $header.find('.table-tools .button.primary, .table-tools button.button.primary').first();
	                if ($searchBtn.length) {
	                    $searchBtn.off('click.wcsearch').on('click.wcsearch', function () {
	                        var limit = parseInt($card.find('.per-page-select').val(), 10) || parseInt($card.data('per-page')) || 10;
	                        navigateWithParams({ page: '1', limit: String(limit), q: ($search.val() || '').toString() });
	                    });
	                }

	                $search.off('keydown.wcsearch').on('keydown.wcsearch', function (e) {
	                    if (e.key === 'Enter') {
	                        e.preventDefault();
	                        var limit = parseInt($card.find('.per-page-select').val(), 10) || parseInt($card.data('per-page')) || 10;
	                        navigateWithParams({ page: '1', limit: String(limit), q: ($search.val() || '').toString() });
	                    }
	                });
	            }

	            // URL-driven filters (thead .filter-row elements with data-filter-field="...").
	            // Avoid binding to raw "input" events to prevent browser autofill triggering reload loops.
	            // Inputs inside a form with an explicit submit button (e.g. the PO From/To/Batch bar)
	            // must NOT auto-navigate on blur/change — they wait for the Search button click.
	            var $autoFilters = $filters.filter(function () {
	                return !$(this).closest('form.js-table-filter-form, form.order-filter-fields').length;
	            });
	            var $formFilters = $filters.filter(function () {
	                return $(this).closest('form.js-table-filter-form, form.order-filter-fields').length > 0;
	            });

	            // Submit-button filter bar: navigate only on explicit form submit (Search button / Enter).
	            $formFilters.closest('form.js-table-filter-form, form.order-filter-fields').off('submit.wcfilter').on('submit.wcfilter', function (e) {
	                e.preventDefault();
	                var params = new URLSearchParams(window.location.search || '');
	                var sort = (params.get('sort') || $card.data('sort') || $card.data('default-sort') || '').toString();
	                var dir = (params.get('dir') || $card.data('dir') || $card.data('default-dir') || 'asc').toString().toLowerCase();
	                if (dir !== 'asc' && dir !== 'desc') { dir = 'asc'; }
	                var extra = { page: '1', sort: sort, dir: dir };
	                $filters.each(function () {
	                    var $f = $(this);
	                    var field = ($f.data('filter-field') || '').toString();
	                    if (!field) { return; }
	                    var v = ($f.val() || '').toString();
	                    extra['filters[' + field + ']'] = v;
	                });
	                navigateWithParams(extra);
	            });

	            $formFilters.off('focus.wcformfilter change.wcformfilter keydown.wcformfilter blur.wcformfilter')
	                .on('focus.wcformfilter', function () {
	                    $(this).data('wc-focus-value', $(this).val());
	                })
	                .on('keydown.wcformfilter', function (e) {
	                    if (e.key !== 'Enter') { return; }
	                    e.preventDefault();
	                    $(this).closest('form').trigger('submit');
	                })
	                .on('blur.wcformfilter', function () {
	                    // Only auto-submit if the value actually changed since focus — otherwise blurring
	                    // to click something else (e.g. a pagination link) would wrongly reset to page 1.
	                    if ($(this).is('input') && $(this).val() !== $(this).data('wc-focus-value')) {
	                        $(this).closest('form').trigger('submit');
	                    }
	                })
	                .on('change.wcformfilter', function () {
	                    $(this).closest('form').trigger('submit');
	                });

	            $autoFilters.off('focus.wcfilter change.wcfilter keydown.wcfilter blur.wcfilter')
	                .on('focus.wcfilter', function () {
	                    $(this).data('wc-focus-value', $(this).val());
	                })
	                .on('keydown.wcfilter', function (e) {
	                    if (e.key !== 'Enter') { return; }
	                    e.preventDefault();
	                    $(this).trigger('change');
	                })
	                .on('blur.wcfilter', function () {
	                    // Only auto-navigate if the value actually changed since focus — otherwise blurring
	                    // to click something else (e.g. a pagination link) would wrongly reset to page 1.
	                    if ($(this).is('input') && $(this).val() !== $(this).data('wc-focus-value')) {
	                        $(this).trigger('change');
	                    }
	                })
	                .on('change.wcfilter', function () {
	                    // Preserve current sort when applying filters so users can refine results without
	                    // losing the chosen ordering (and so subsequent header clicks toggle correctly).
	                    var params = new URLSearchParams(window.location.search || '');
	                    var sort = (params.get('sort') || $card.data('sort') || $card.data('default-sort') || '').toString();
	                    var dir = (params.get('dir') || $card.data('dir') || $card.data('default-dir') || 'asc').toString().toLowerCase();
	                    if (dir !== 'asc' && dir !== 'desc') { dir = 'asc'; }

	                    var extra = { page: '1', sort: sort, dir: dir };
	                    $filters.each(function () {
	                        var $f = $(this);
	                        var field = ($f.data('filter-field') || '').toString();
	                        if (!field) { return; }
	                        var v = ($f.val() || '').toString();
	                        extra['filters[' + field + ']'] = v;
	                    });
	                    if (($card.data('tab-sync') || '').toString() === 'quote-request') {
	                        var selectedStatus = (extra['filters[status]'] || '').toString();
	                        var quoteTab = 'ALL';
	                        if (selectedStatus === 'Pending Client Approval') {
	                            quoteTab = 'PENDING';
	                        } else if (selectedStatus === 'Waiting for Quote') {
	                            quoteTab = 'WAITING';
	                        } else if (selectedStatus === 'Accepted Quotes') {
	                            quoteTab = 'APPROVED';
	                        } else if (selectedStatus === 'Cancelled Quotes') {
	                            quoteTab = 'CANCELLED';
	                        }
	                        extra.tab = quoteTab;
	                    }
	                    navigateWithParams(extra);
	                });

	            // URL-driven column sorting for headers that declare a sortable field.
	            // Some tables have multi-row headers (e.g. grouped columns), so bind to all sortable th elements.
	            $table.find('thead th[data-sort-field]').each(function () {
	                var $th = $(this);
	                $th.addClass('is-sortable');
	                $th.css('cursor', 'pointer');
	                $th.off('click.wcsort').on('click.wcsort', function () {
	                    var field = ($th.data('sort-field') || '').toString();
	                    if (!field) { return; }

	                    var params = new URLSearchParams(window.location.search || '');

	                    // Prefer visual state, because filter actions can rewrite the URL and make it
	                    // harder to infer what the user is currently looking at.
	                    var currentField = (params.get('sort') || $card.data('default-sort') || '').toString();
	                    var currentDir = (params.get('dir') || $card.data('default-dir') || 'asc').toString().toLowerCase();

	                    // If any header is currently marked sorted, treat that as truth.
	                    var $sortedAsc = $table.find('thead th.is-sorted-asc[data-sort-field]').first();
	                    var $sortedDesc = $table.find('thead th.is-sorted-desc[data-sort-field]').first();
	                    if ($sortedAsc.length) {
	                        currentField = ($sortedAsc.data('sort-field') || '').toString();
	                        currentDir = 'asc';
	                    } else if ($sortedDesc.length) {
	                        currentField = ($sortedDesc.data('sort-field') || '').toString();
	                        currentDir = 'desc';
	                    } else if ($th.hasClass('is-sorted-asc')) {
	                        currentField = field; currentDir = 'asc';
	                    } else if ($th.hasClass('is-sorted-desc')) {
	                        currentField = field; currentDir = 'desc';
	                    }

	                    // Toggle when clicking same column.
	                    // When switching columns, flip the direction from the current column so the user
	                    // always sees an obvious change (prevents "nothing happened" when both orders look similar).
	                    var nextDir = currentField === field
	                        ? ((currentDir === 'asc') ? 'desc' : 'asc')
	                        : 'asc';

	                    // Preserve current filters when sorting (especially important if a user typed
	                    // a filter and clicked a header before the filter change event updated the URL).
	                    var extra = { page: '1', sort: field, dir: nextDir };
	                    $filters.each(function () {
	                        var $f = $(this);
	                        var f = ($f.data('filter-field') || '').toString();
	                        if (!f) { return; }
	                        extra['filters[' + f + ']'] = ($f.val() || '').toString();
	                    });
	                    navigateWithParams(extra);
	                });
	            });

                updateSortIndicators();
	        }

		        if (!useFullReload) {
		            // Some tables have multi-row headers (e.g. grouped columns), so bind to all sortable th elements.
		            $table.find('thead th[data-sort-field]').each(function () {
		                var $th = $(this);
		                $th.addClass('is-sortable');
		                $th.css('cursor', 'pointer');
		                $th.off('click.wcsort').on('click.wcsort', function () {
		                    var field = ($th.data('sort-field') || '').toString();
		                    if (!field) { return; }

		                    var currentField = ($card.data('sort') || $card.data('default-sort') || '').toString();
		                    var currentDir = (($card.data('dir') || $card.data('default-dir') || 'asc').toString().toLowerCase() === 'desc') ? 'desc' : 'asc';
		                    var nextDir = (currentField === field && currentDir === 'asc') ? 'desc' : 'asc';

		                    $card.data('sort', field);
		                    $card.data('dir', nextDir);
		                    $card.data('current-page', 1);
		                    $card.data('sort-active', true); // user explicitly chose this sort
		                    updateSortIndicators();
		                    $card.trigger('wc:paginate');
		                });
		            });

                    updateSortIndicators();
		        }

        function renderPaginationInternal($card, page, pages) {
            var $pager = $card.find('.table-pagination').empty();
            if (pages <= 1) { return; }

            function mkBtn(label, target, active) {
                var $b = $('<button class="page-btn" type="button">' + label + '</button>');
                if (active) { $b.addClass('is-active'); }
                if (target === null) { $b.prop('disabled', true); }
                else {
                    $b.on('click', function () {
                        $card.data('current-page', target);
                        $card.trigger('wc:paginate');
                    });
                }
                return $b;
            }

            $pager.append(mkBtn('First', page > 1 ? 1 : null, false));
            $pager.append(mkBtn('<', page > 1 ? page - 1 : null, false));
            var lo = Math.max(1, page - 2), hi = Math.min(pages, lo + 4);
            if (hi - lo < 4) { lo = Math.max(1, hi - 4); }
            if (lo > 1) {
                $pager.append(mkBtn(1, 1, false));
                if (lo > 2) { $pager.append('<span class="page-ellipsis">…</span>'); }
            }
            for (var p = lo; p <= hi; p++) { $pager.append(mkBtn(p, p, p === page)); }
            if (hi < pages) {
                if (hi < pages - 1) { $pager.append('<span class="page-ellipsis">…</span>'); }
                $pager.append(mkBtn(pages, pages, false));
            }
            $pager.append(mkBtn('>', page < pages ? page + 1 : null, false));
            $pager.append(mkBtn('Last', page < pages ? pages : null, false));
        }

	        // For URL-driven tables, render pagination buttons from server-provided page count.
	        if (useFullReload && !$card.find('.table-pagination[data-server-pagination="true"]').length) {
	            var pagesAttr = parseInt($card.data('pages'), 10);
	            var pageAttr = parseInt($card.data('current-page'), 10) || 1;
	            if (!isNaN(pagesAttr) && pagesAttr > 1) {
	                renderPaginationInternal($card, pageAttr, pagesAttr);
	            }
	        }

        if (!useFullReload) {
            var refilterTable = debounce(function () {
                $card.data('current-page', 1);
                $card.trigger('wc:paginate');
            }, 200);

            function triggerSearch() {
                $card.data('current-page', 1);
                $card.trigger('wc:paginate');
            }

            if ($search.length) {
                $search.on('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        triggerSearch();
                    }
                });
            }
            $filters.on('input change', refilterTable);
            $card.find('.table-tools .button[type="button"]').on('click', triggerSearch);

            if (ajaxUrl) {
                var initialPages = parseInt($card.data('pages'), 10) || 1;
                var initialPage = parseInt($card.data('current-page'), 10) || 1;
                if (initialPages > 1) {
                    renderPaginationInternal($card, initialPage, initialPages);
                }
            } else {
                $card.trigger('wc:paginate');
            }
        }
    });

    /* ── Row hover highlight ──────────────────────────────────────────────── */
    $('tbody tr').on('mouseenter focusin', function () {
        $(this).addClass('is-row-focus');
    }).on('mouseleave focusout', function () {
        $(this).removeClass('is-row-focus');
    });

    /* ── Scroll reveal ────────────────────────────────────────────────────── */
    $('.panel, .card, .table-card').addClass('reveal');

    function revealVisible() {
        var bottom = $window.scrollTop() + $window.height() - 20;

        $('.reveal').each(function () {
            if ($(this).offset().top < bottom) {
                $(this).addClass('is-visible');
            }
        });
    }

    revealVisible();
    $window.on('scroll.reveal resize.reveal', revealVisible);

    /* ── Catalog search ───────────────────────────────────────────────────── */
    (function () {
        var $shell = $('.catalog-shell').first();
        var $catalog = $shell.find('.catalog-products').first();
        if (!$shell.length || !$catalog.length) { return; }

        var $cards = $();
        var $listHead = $();
        var activeCategoryId = String($shell.data('active-category-id') || '0');
        var $search = $shell.find('#catalog-search');
        if (!$search.length) { $search = $('.catalog-search').first(); }
        var $headerSearch = $('#customer-header-search');
        var $count = $shell.find('.product-count').first();
        var $empty = $shell.find('.catalog-empty').first();
        var $pager = $shell.find('.js-catalog-pagination').first();
        var $toggles = $shell.find('.view-toggle');
        var $stock = $('#catalog-filter-stock');
        var $min = $('#catalog-filter-min');
        var $max = $('#catalog-filter-max');
        var $sort = $('#catalog-filter-sort');
        var $clear = $('.js-catalog-clear-filters').first();
        var $viewLabel = $shell.find('.selected-view-label').first();
        var $warehouse = $shell.find('#catalog-warehouse').first();
        var $warehouseForm = $shell.find('.catalog-warehouse-select-wrap').first();
        var currentView = (($shell.data('current-view') || 'grid') + '').toLowerCase() === 'list' ? 'list' : 'grid';
        var listSortField = '';
        var listSortDir = 'asc';
        var serverPages = parseInt($shell.data('total-pages'), 10) || 1;
        var serverTotal = parseInt($shell.data('total-products'), 10) || 0;

        var perPage = parseInt($pager.data('per-page'), 10);
        if (isNaN(perPage) || perPage <= 0) { perPage = 16; }
        var currentPage = parseInt($shell.data('current-page'), 10) || 1;

        function norm(v) { return (v || '').toString().toLowerCase().trim(); }

        function escapeSelector(value) {
            return String(value == null ? '' : value).replace(/(["\\])/g, '\\$1');
        }

        function cacheCatalogNodes() {
            // .wheel-list-row (Wheel category's <table>-based List View) has none of the
            // sort-button/list-head machinery below, but still needs counting here so the
            // "Showing X of Y" label reflects the real row count instead of 0.
            $cards = $catalog.find('.market-product, .wheel-list-row');
            $listHead = $catalog.find('.catalog-list-head').first();
        }

        function setView(view) {
            var isList = view === 'list';
            $shell.toggleClass('is-list-view', isList);
            $catalog.toggleClass('is-list-view', isList);
            $toggles.removeClass('active').attr('aria-pressed', 'false');
            $toggles.filter('[data-view="' + view + '"]').addClass('active').attr('aria-pressed', 'true');
            if ($viewLabel.length) { $viewLabel.text('Select view ' + (isList ? 'List' : 'Grid')); }
            updateListSortIndicators();
        }

        function numberValue(text) {
            if (typeof numberFromMoney === 'function') {
                return numberFromMoney(text);
            }
            var parsed = parseFloat(String(text || '').replace(/[^0-9.-]/g, ''));
            return isNaN(parsed) ? 0 : parsed;
        }

        function compareTextValues(left, right, numeric) {
            var a = String(left || '');
            var b = String(right || '');

            if (typeof a.localeCompare === 'function') {
                return a.localeCompare(b, undefined, {
                    numeric: !!numeric,
                    sensitivity: 'base'
                });
            }

            if (a < b) { return -1; }
            if (a > b) { return 1; }
            return 0;
        }

        function sortValue($item, field) {
            if (field === 'description') { return norm($item.find('h2').first().text()); }
            if (field === 'sku') { return norm($item.find('.sku').first().text()); }
            if (field === 'unit') { return norm($item.find('.product-unit').first().text()); }
            if (field === 'size') { return norm($item.find('.product-size').first().text()); }
            if (field === 'price') { return numberValue($item.find('.product-price').first().text() || $item.find('.product-footer strong').first().text()); }
            if (field === 'stock') { return norm($item.find('.product-avail').first().text()); }
            if (field === 'remarks') { return norm($item.find('.product-remarks').first().text()); }
            return '';
        }

        function updateListSortIndicators() {
            if (!$listHead.length) { return; }
            $listHead.find('.catalog-list-sort').removeClass('is-active is-desc').attr('aria-sort', 'none');
            if (!listSortField) { return; }
            var $active = $listHead.find('.catalog-list-sort[data-sort-field="' + listSortField + '"]').first();
            if (!$active.length) { return; }
            $active.addClass('is-active');
            if (listSortDir === 'desc') { $active.addClass('is-desc'); }
            $active.attr('aria-sort', listSortDir === 'desc' ? 'descending' : 'ascending');
        }

        function syncCategoryState() {
            $shell.attr('data-active-category-id', activeCategoryId);
            $shell.find('.filter-link').removeClass('active');
            $shell.find('.filter-link[data-category-id="' + escapeSelector(activeCategoryId) + '"]').addClass('active');
            if ($warehouseForm.length) {
                $warehouseForm.find('input[name="ProductSearch[category_id]"]').val(activeCategoryId);
                $warehouseForm.find('input[name="q"]').val($search.length ? $search.val() : '');
            }
        }

        function syncSearchInputs(value) {
            var normalized = String(value == null ? '' : value);
            if ($search.length && $search.val() !== normalized) {
                $search.val(normalized);
            }
            if ($headerSearch.length && $headerSearch.val() !== normalized) {
                $headerSearch.val(normalized);
            }
        }

        function updateWarehouseContext() {
            // No warehouse picker on the page (single-region companies never render one) means
            // there's nothing to sync — leave the server-rendered region text alone rather than
            // guessing "Main".
            if (!$warehouse.length) { return; }
            var label = $.trim($warehouse.find('option:selected').text());
            if (label) {
                $('.catalog-page-context strong').text(label);
            }
        }

        function getMatched() {
            var stock = $stock.length ? norm($stock.val()) : '';
            var minPrice = $min.length ? parseFloat($min.val()) : NaN;
            var maxPrice = $max.length ? parseFloat($max.val()) : NaN;

            return $cards.filter(function () {
                var $c = $(this);

                if (stock) {
                    var inv = norm($c.find('.product-avail').first().text());
                    if (stock === 'in_stock' && inv !== 'in stock') { return false; }
                    if (stock === 'company_only' && inv !== 'company only') { return false; }
                    if (stock === 'not_in_stock' && inv === 'in stock') { return false; }
                }

                var priceText = $c.find('.product-price').first().text();
                if (!priceText) { priceText = $c.find('.product-footer strong').first().text(); }
                var price = typeof numberFromMoney === 'function' ? numberFromMoney(priceText) : parseFloat(String(priceText || '').replace(/[^0-9.-]/g, ''));
                if (!isNaN(minPrice) && price < minPrice) { return false; }
                if (!isNaN(maxPrice) && price > maxPrice) { return false; }

                return true;
            }).toArray();
        }

        function setCountLabel(start, end, total) {
            if (!$count.length) { return; }
            if (total <= 0) { $count.text('0'); return; }
            if (start === end) { $count.text(String(end) + ' of ' + total); return; }
            $count.text(start + '\u2013' + end + ' of ' + total);
        }

        function render() {
            var matched = getMatched();
            var total = matched.length;
            var hasClientFilters = (!!$stock.length && norm($stock.val()) !== '')
                || (!!$min.length && $.trim($min.val()) !== '')
                || (!!$max.length && $.trim($max.val()) !== '');

            var sort = $sort.length ? norm($sort.val()) : 'relevance';
            if (sort && sort !== 'relevance') {
                matched.sort(function (a, b) {
                    var $a = $(a);
                    var $b = $(b);
                    if (sort === 'price_asc' || sort === 'price_desc') {
                        var ap = typeof numberFromMoney === 'function' ? numberFromMoney($a.find('.product-price').first().text() || $a.find('.product-footer strong').first().text()) : 0;
                        var bp = typeof numberFromMoney === 'function' ? numberFromMoney($b.find('.product-price').first().text() || $b.find('.product-footer strong').first().text()) : 0;
                        return sort === 'price_asc' ? (ap - bp) : (bp - ap);
                    }
                    if (sort === 'name_asc' || sort === 'name_desc') {
                        var an = norm($a.find('h2').first().text());
                        var bn = norm($b.find('h2').first().text());
                        if (an < bn) { return sort === 'name_asc' ? -1 : 1; }
                        if (an > bn) { return sort === 'name_asc' ? 1 : -1; }
                        return 0;
                    }
                    return 0;
                });
            }

            if (listSortField) {
                matched.sort(function (a, b) {
                    var $a = $(a);
                    var $b = $(b);
                    var av = sortValue($a, listSortField);
                    var bv = sortValue($b, listSortField);
                    var dir = listSortDir === 'desc' ? -1 : 1;

                    if (typeof av === 'number' && typeof bv === 'number') {
                        return dir * (av - bv);
                    }

                    return dir * compareTextValues(av, bv, listSortField === 'sku');
                });
            }

            $cards.hide();
            $(matched).each(function (i) {
                $(this).show();
            });

            var startLabel = 0;
            var endLabel = 0;
            var countTotal = total;

            if (!hasClientFilters) {
                countTotal = serverTotal;
                startLabel = total ? ((currentPage - 1) * perPage) + 1 : 0;
                endLabel = total ? (startLabel + total - 1) : 0;
                if ($pager.length) { $pager.prop('hidden', serverPages <= 1); }
            } else {
                startLabel = total ? 1 : 0;
                endLabel = total;
                if ($pager.length) { $pager.prop('hidden', true); }
            }
            setCountLabel(startLabel, endLabel, countTotal);

            if ($listHead.length) {
                $listHead.prop('hidden', total === 0);
                $catalog.append($listHead);
            }
            $(matched).each(function () {
                // Re-insert within each card's own current parent rather than hardcoding
                // $catalog - plain product cards sit directly under $catalog so this is a
                // no-op change for them, but it keeps table-based list views (e.g. Wheel's
                // <table class="wheel-list-table">) from having their <tr> rows ripped out
                // of <tbody> and dumped as stray children of $catalog on every render().
                $(this).appendTo($(this).parent());
            });

        }

        if ($search.length) {
            $search.on('input', function () {
                syncSearchInputs($(this).val());
            });
        }

        function onFilterChange() {
            render();
        }

        if ($stock.length) { $stock.on('change', onFilterChange); }
        if ($min.length) { $min.on('input', debounce(onFilterChange, 180)); }
        if ($max.length) { $max.on('input', debounce(onFilterChange, 180)); }
        if ($sort.length) { $sort.on('change', onFilterChange); }
        if ($clear.length) {
            $clear.on('click', function () {
                if ($stock.length) { $stock.val(''); }
                if ($min.length) { $min.val(''); }
                if ($max.length) { $max.val(''); }
                if ($sort.length) { $sort.val('relevance'); }
                onFilterChange();
            });
        }
        cacheCatalogNodes();
        // Adopt the server-rendered sort (from ProductSearch[sort]/[dir] in the URL) as the
        // starting client state so the active-column indicator survives a no-JS-style page load
        // and the first click toggles from the right direction.
        if ($listHead.length) {
            var $serverActiveSort = $listHead.find('.catalog-list-sort.is-active').first();
            if ($serverActiveSort.length) {
                listSortField = ($serverActiveSort.data('sort-field') || '').toString();
                listSortDir = $serverActiveSort.hasClass('is-desc') ? 'desc' : 'asc';
            }
        }
        updateListSortIndicators();

        $catalog.on('click', '.catalog-list-sort', function (e) {
            e.preventDefault();
            var field = ($(this).data('sort-field') || '').toString();
            if (!field) { return; }

            if (listSortField === field) {
                listSortDir = listSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                listSortField = field;
                listSortDir = 'asc';
            }

            updateListSortIndicators();
            render();
        });
        syncSearchInputs($search.length ? $search.val() : ($headerSearch.length ? $headerSearch.val() : ''));
        syncCategoryState();
        updateWarehouseContext();
        setView(currentView);
        render();
    })();

    $(document).on('click', '.catalog-filter-button', function (event) {
        event.stopPropagation();
        var $menu = $(this).closest('.catalog-category-menu');
        var isOpen = !$menu.hasClass('is-open');
        $('.catalog-category-menu').not($menu).removeClass('is-open').find('.catalog-filter-button').attr('aria-expanded', 'false');
        $menu.toggleClass('is-open', isOpen);
        $(this).attr('aria-expanded', String(isOpen));
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.catalog-category-menu').length) {
            $('.catalog-category-menu').removeClass('is-open').find('.catalog-filter-button').attr('aria-expanded', 'false');
        }
    });

    /* ── Product count display ────────────────────────────────────────────── */
    
    /* ── View toggle (grid / list) ────────────────────────────────────────── */
    // (Catalog view toggle handled by catalog IIFE.)

    /* ── Category filter links ────────────────────────────────────────────── */
    $(document).on('click', '.filter-link', function () {
        $('.filter-link').removeClass('active');
        $(this).addClass('active');
    });

    /* Stage 1 inline category visibility toggle */
    $(document).on('click', '.js-category-status', function () {
        var $switch = $(this);
        var $statusCell = $switch.closest('td');
        var $row = $switch.closest('tr');
        var url = $switch.data('url');
        var isVisible = !$switch.hasClass('is-on');

        function applyStatus(visible) {
            $switch.toggleClass('is-on', visible).attr('aria-pressed', String(visible));
            $statusCell.find('.badge')
                .toggleClass('danger-soft', !visible)
                .text(visible ? 'Visible' : 'Hidden');
            $row.find('td[data-label="Client catalog"]').text(visible ? 'Shown to customers' : 'Hidden from customers');
        }

        if (!url) {
            applyStatus(isVisible);
            showToast('Category status changed to ' + (isVisible ? 'Visible' : 'Hidden') + '.', 'success');
            return;
        }

        $switch.prop('disabled', true);
        $.post(url, { _token: $switch.data('token') || '' })
            .done(function (response) {
                var visible = response.status === 'Visible';
                applyStatus(visible);
                showToast(response.message || 'Category status saved as ' + response.status + '.', 'success');
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Could not save category status. Please try again.';
                showToast(message, 'error');
            })
            .always(function () {
                $switch.prop('disabled', false);
            });
    });

    /* ── Cart display helpers ─────────────────────────────────────────────────
       The client-side localStorage cart ("wcCart") that used to live here is gone (#200).
       /cart, the cart badge and the per-product quantities are all server-rendered from the
       session cart, and every mutation is a real form POST (/cart/add, /cart/update,
       /cart/remove, /cart/clear, /cart/reorder). Nothing in this file writes a browser-side
       cart any more; the functions below only re-render server-supplied numbers. */

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMoney(value) {
        var n = typeof value === 'number' && isFinite(value) ? value : 0;
        return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* The quantity is rendered onto the button by the server (data-cart-qty), from the same
       session cart the badge and /cart read. It used to come from a localStorage cart that
       nothing writes any more, so the number shown was whatever happened to be in that browser
       before the cart moved server-side, and it never changed again — issue #197. */
    function refreshProductCardCartState() {
        $('.js-product-card-cart-btn').each(function () {
            var $btn = $(this);
            var qty = parseInt($btn.attr('data-cart-qty'), 10) || 0;

            if (!$btn.data('defaultHtml')) {
                $btn.data('defaultHtml', $btn.html());
            }

            if (qty > 0) {
                $btn
                    .addClass('is-in-cart')
                    .prop('disabled', false)
                    .attr('aria-disabled', 'false')
                    .html('<span class="product-card-cart-qty">' + qty + '</span>');
            } else {
                $btn
                    .removeClass('is-in-cart')
                    .prop('disabled', false)
                    .attr('aria-disabled', 'false')
                    .html($btn.data('defaultHtml'));
                }
        });
    }

    function refreshDetailCartState() {
        $('.customer-product-cart-btn').each(function () {
            var $btn = $(this);
            var qty = parseInt($btn.attr('data-cart-qty'), 10) || 0;

            if (!$btn.data('defaultHtml')) {
                $btn.data('defaultHtml', $btn.html());
            }

            if (qty > 0) {
                $btn
                    .addClass('is-in-cart')
                    .prop('disabled', false)
                    .attr('aria-disabled', 'false')
                    .html('<span class="product-card-cart-qty">' + qty + '</span>');
            } else {
                $btn
                    .removeClass('is-in-cart')
                    .prop('disabled', false)
                    .attr('aria-disabled', 'false')
                    .html($btn.data('defaultHtml'));
            }
        });
    }

    refreshProductCardCartState();
    refreshDetailCartState();

    function checkoutConfig() {
        var raw = String($('#checkout-form').attr('data-checkout-config') || '');
        if (!raw) {
            return { shippingFee: 30, freeShippingThreshold: 150, taxRatePercent: 12, gstRatePercent: 5, pstRatePercent: 7, pstExempt: false, coupons: [] };
        }

        try {
            var parsed = JSON.parse(raw);
            return {
                shippingFee: typeof parsed.shippingFee === 'number' ? parsed.shippingFee : 30,
                freeShippingThreshold: typeof parsed.freeShippingThreshold === 'number' ? parsed.freeShippingThreshold : 150,
                taxRatePercent: typeof parsed.taxRatePercent === 'number' ? parsed.taxRatePercent : 12,
                gstRatePercent: typeof parsed.gstRatePercent === 'number' ? parsed.gstRatePercent : 5,
                pstRatePercent: typeof parsed.pstRatePercent === 'number' ? parsed.pstRatePercent : 7,
                pstExempt: !!parsed.pstExempt,
                coupons: Array.isArray(parsed.coupons) ? parsed.coupons : []
            };
        } catch (e) {
            return { shippingFee: 30, freeShippingThreshold: 150, taxRatePercent: 12, gstRatePercent: 5, pstRatePercent: 7, pstExempt: false, coupons: [] };
        }
    }

    function isOrderPaymentCheckout($form) {
        var $target = $form && $form.length ? $form : $('#checkout-form');
        return String($target.attr('data-order-payment') || '') === '1';
    }

    function orderPaymentSubtotal($form) {
        return Math.max(0, parseFloat(($form && $form.length ? $form : $('#checkout-form')).attr('data-order-subtotal') || 0) || 0);
    }

    function orderPaymentShipping($form) {
        return Math.max(0, parseFloat(($form && $form.length ? $form : $('#checkout-form')).attr('data-order-shipping') || 0) || 0);
    }

    function orderPaymentFeeTotal($form) {
        return Math.max(0, parseFloat(($form && $form.length ? $form : $('#checkout-form')).attr('data-order-fee-total') || 0) || 0);
    }

    function orderPaymentTaxRate($form) {
        return Math.max(0, parseFloat(($form && $form.length ? $form : $('#checkout-form')).attr('data-order-tax-rate') || 0) || 0);
    }

    function storedCheckoutCouponCode() {
        var $form = $('#checkout-form');
        if ($form.length) {
            var hiddenCode = String($form.find('[name="coupon_code"]').val() || '').trim();
            if (hiddenCode) { return hiddenCode.toUpperCase(); }
            if (isOrderPaymentCheckout($form)) { return ''; }
        }

        try {
            return String(window.localStorage.getItem('customer_checkout_coupon_code') || '').trim().toUpperCase();
        } catch (e) {
            return '';
        }
    }

    function persistCheckoutCouponCode(code) {
        var normalized = String(code || '').trim().toUpperCase();
        $('#checkout-form').find('[name="coupon_code"]').val(normalized);

        if (isOrderPaymentCheckout($('#checkout-form'))) {
            return;
        }

        try {
            if (normalized) { window.localStorage.setItem('customer_checkout_coupon_code', normalized); }
            else { window.localStorage.removeItem('customer_checkout_coupon_code'); }
        } catch (e) {}
    }

    function resolveCheckoutCoupon(code, subtotal) {
        var normalized = String(code || '').trim().toUpperCase();
        if (!normalized) { return { coupon: null, error: '' }; }

        var coupons = checkoutConfig().coupons || [];
        for (var index = 0; index < coupons.length; index++) {
            var coupon = coupons[index] || {};
            if (String(coupon.code || '').toUpperCase() !== normalized) { continue; }

            var minSubtotal = typeof coupon.minSubtotal === 'number' ? coupon.minSubtotal : parseFloat(coupon.minSubtotal || 0);
            minSubtotal = isNaN(minSubtotal) ? 0 : Math.max(0, minSubtotal);
            if (subtotal < minSubtotal) {
                return { coupon: null, error: 'Coupon ' + normalized + ' requires a minimum subtotal of ' + formatMoney(minSubtotal) + '.' };
            }

            return { coupon: coupon, error: '' };
        }

        return { coupon: null, error: 'That coupon code is not valid.' };
    }

    function selectedCheckoutFulfillmentMethod() {
        var method = String($('#checkout-form').find('[name="fulfillment_method"]').val() || 'delivery').trim().toLowerCase();
        return method === 'pickup' ? 'pickup' : 'delivery';
    }

    function setCheckoutFulfillmentMethod(method) {
        var normalized = String(method || '').trim().toLowerCase() === 'pickup' ? 'pickup' : 'delivery';
        $('#checkout-form').find('[name="fulfillment_method"]').val(normalized);
        clearCheckoutPaymentIntent();
        renderCheckoutPage();
    }

    var _checkoutShippingRefreshTimer = null;
    var _checkoutFeeRefreshTimer = null;
    var _currentFeeTotal = 0;

    function loadCheckoutFeeLines() {
        var $form = $('#checkout-form');
        var url = String($form.data('fee-lines-url') || '');
        if (!url || isOrderPaymentCheckout($form)) { return; }
        var province = String($form.find('[name="ship_province"]').val() || '').trim();

        $.ajax({
            url: url,
            method: 'GET',
            data: { province: province },
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            _currentFeeTotal = parseFloat(res.total || 0) || 0;
            var $wrap = $('.js-checkout-fee-lines-wrap');
            var lines = Array.isArray(res.lines) ? res.lines : [];
            if (lines.length === 0) {
                $wrap.prop('hidden', true).html('');
            } else {
                var html = '';
                lines.forEach(function (line) {
                    html += '<div class="cart-summary-row">'
                        + '<span>' + escapeHtml(String(line.label || '')) + ':</span>'
                        + '<strong>' + escapeHtml(formatMoney(parseFloat(line.amount || 0))) + '</strong>'
                        + '</div>';
                });
                $wrap.prop('hidden', false).html(html);
            }
            renderCheckoutPage();
        }).fail(function () {
            _currentFeeTotal = 0;
            $('.js-checkout-fee-lines-wrap').prop('hidden', true).html('');
        });
    }

    function scheduleCheckoutFeeLinesRefresh() {
        if (_checkoutFeeRefreshTimer) { clearTimeout(_checkoutFeeRefreshTimer); }
        _checkoutFeeRefreshTimer = setTimeout(loadCheckoutFeeLines, 400);
    }

    function refreshCheckoutShippingOptions() {
        var $form = $('#checkout-form');
        if (!$form.length || isOrderPaymentCheckout($form)) { return; }

        var url = String($form.attr('data-shipping-options-url') || '').trim();
        if (!url) { return; }

        var province = String($form.find('[name="ship_province"]').val() || '').trim();

        var $wrap = $('.js-checkout-shipping-options');

        $.ajax({
            url: url,
            method: 'GET',
            data: { province: province },
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            if (!res || !Array.isArray(res.options)) { return; }
            var options = res.options;
            var currentLabel = String($form.find('[name="shipping_method"]').val() || '').trim();

            if (options.length === 0) {
                $form.find('[name="shipping_method"]').val('');
                $form.find('[name="shipping_amount"]').val('0');
                $wrap.html('<p class="checkout-shipping-placeholder">No shipping options are available for the selected address and cart items.</p>');
                renderCheckoutPage();
                return;
            }

            var html = '';
            var foundCurrent = false;
            options.forEach(function (opt) {
                var label = String(opt.label || '');
                var amount = parseFloat(opt.amount || 0);
                var days = opt.deliveryDays ? opt.deliveryDays + ' day' + (opt.deliveryDays !== 1 ? 's' : '') : '';
                var isSelected = (label === currentLabel);
                var checked = isSelected ? ' checked' : '';
                var selectedClass = isSelected ? ' is-selected' : '';
                if (isSelected) { foundCurrent = true; }
                html += '<label class="checkout-shipping-option js-checkout-shipping-option-label' + selectedClass + '">'
                    + '<input type="radio" class="js-checkout-shipping-option" name="_shipping_option_select"'
                    + ' value="' + escapeHtml(label) + '"'
                    + ' data-amount="' + amount.toFixed(2) + '"'
                    + ' data-label="' + escapeHtml(label) + '"'
                    + checked + '>'
                    + '<span class="checkout-shipping-option__info">'
                    + '<strong>' + escapeHtml(label) + '</strong>'
                    + (days ? '<span class="checkout-shipping-option__days">' + escapeHtml(days) + '</span>' : '')
                    + '</span>'
                    + '<span class="checkout-shipping-option__price">' + escapeHtml(formatMoney(amount)) + '</span>'
                    + '</label>';
            });

            $wrap.html(html);

            if (!foundCurrent) {
                $form.find('[name="shipping_method"]').val('');
                $form.find('[name="shipping_amount"]').val('0');
            }

            renderCheckoutPage();
        }).fail(function () {
            $wrap.html('<p class="checkout-shipping-placeholder">Could not load shipping options. Please refresh the page.</p>');
        });
    }

    function scheduleCheckoutShippingRefresh() {
        if (_checkoutShippingRefreshTimer) { clearTimeout(_checkoutShippingRefreshTimer); }
        _checkoutShippingRefreshTimer = setTimeout(refreshCheckoutShippingOptions, 400);
    }

    // Only the order-payment page reaches this. The cart-driven branch this used to carry was
    // unreachable — #checkout-form exists in one template, which hardcodes data-order-payment="1" —
    // so it has been removed rather than left computing totals from rates invented in the browser.
    // Returns null off the order-payment path; callers must treat that as "no totals available"
    // rather than assume a shape.
    function checkoutTotals(subtotal, coupon) {
        var $form = $('#checkout-form');
        if (isOrderPaymentCheckout($form)) {
            var orderDiscount = 0;
            if (coupon) {
                if (String(coupon.type || '').toLowerCase() === 'percent') {
                    orderDiscount = subtotal * ((parseFloat(coupon.value || 0) || 0) / 100);
                } else {
                    orderDiscount = parseFloat(coupon.value || 0) || 0;
                }
            }

            orderDiscount = Math.min(Math.max(orderDiscount, 0), subtotal);
            var discountedOrderSubtotal = Math.max(0, subtotal - orderDiscount);
            var orderShipping = orderPaymentShipping($form);
            // Mirrors orderPaymentTotals() in Customer/OrderController.php — fees are taxed
            // alongside subtotal+shipping.
            var orderFeeTotal = orderPaymentFeeTotal($form);
            var orderPreTax = discountedOrderSubtotal + orderShipping + orderFeeTotal;
            var orderTax = orderPreTax * (orderPaymentTaxRate($form) / 100);

            return {
                discount: orderDiscount,
                discountedSubtotal: discountedOrderSubtotal,
                shipping: orderShipping,
                preTax: orderPreTax,
                tax: orderTax,
                total: orderPreTax + orderTax
            };
        }

        return null;
    }

    function applyCheckoutCoupon(code, options) {
        var settings = options || {};
        var $form = $('#checkout-form');
        // Same reachability note as checkoutTotals() above: #checkout-form exists in exactly one
        // template, which hardcodes data-order-payment="1", so the subtotal always comes off the
        // persisted order. The branch that used to sum a localStorage cart here was unreachable and
        // is gone with the rest of that cart (#200); off the order-payment path the subtotal is 0,
        // which is what summing an empty cart already produced.
        var subtotal = isOrderPaymentCheckout($form) ? orderPaymentSubtotal($form) : 0;

        var result = resolveCheckoutCoupon(code, subtotal);
        var $feedback = $('.js-checkout-coupon-feedback');
        var $discountRow = $('.js-checkout-discount-row');
        var $discount = $('.js-checkout-discount');
        clearCheckoutPaymentIntent();

        if (result.error) {
            persistCheckoutCouponCode('');
            $discountRow.prop('hidden', true);
            $discount.text('-' + formatMoney(0));
            $feedback.text(result.error).toggleClass('is-error', true).toggleClass('is-success', false);
            if (!settings.silent) { showToast(result.error, 'error'); }
            renderCheckoutPage();
            return false;
        }

        var coupon = result.coupon;
        persistCheckoutCouponCode(coupon ? coupon.code : '');
        renderCheckoutPage(coupon);

        var totals = coupon ? checkoutTotals(subtotal, coupon) : null;
        if (coupon && totals) {
            $discountRow.prop('hidden', false);
            $discount.text('-' + formatMoney(totals.discount));
            $feedback.text('Coupon ' + coupon.code + ' applied.').toggleClass('is-error', false).toggleClass('is-success', true);
            if (!settings.silent) { showToast('Coupon ' + coupon.code + ' applied.', 'success'); }
            return true;
        }

        $discountRow.prop('hidden', true);
        $discount.text('-' + formatMoney(0));
        $feedback.text('').toggleClass('is-error', false).toggleClass('is-success', false);
        return true;
    }

    function renderCheckoutPage(appliedCoupon) {
        var $form = $('#checkout-form');
        if (!$form.length) { return; }
        if (isOrderPaymentCheckout($form)) {
            var orderSubtotal = orderPaymentSubtotal($form);
            var orderCoupon = appliedCoupon || resolveCheckoutCoupon(storedCheckoutCouponCode(), orderSubtotal).coupon;
            var orderTotals = checkoutTotals(orderSubtotal, orderCoupon);
            var orderConfig = checkoutConfig();

            $('.js-checkout-subtotal').text(formatMoney(orderSubtotal));
            $('.js-checkout-shipping').text(formatMoney(orderTotals.shipping));
            $('.js-checkout-pre-tax').text(formatMoney(orderTotals.preTax));
            $('.js-checkout-gst-label').text('GST ' + (parseFloat(orderConfig.gstRatePercent || 0) || 0) + '%:');
            $('.js-checkout-gst').text(formatMoney(orderTotals.tax));
            $('.js-checkout-pst-row').prop('hidden', true);
            $('.js-checkout-total').text(formatMoney(orderTotals.total));
            $('.js-checkout-discount-row').prop('hidden', !orderCoupon || orderTotals.discount <= 0);
            $('.js-checkout-discount').text('-' + formatMoney(orderTotals.discount));
            $('.js-checkout-free-shipping').text('');
            $('.js-checkout-submit').prop('disabled', false).removeClass('is-disabled').attr('aria-disabled', 'false');
        }
    }

    var checkoutStripe = {
        stripe: null,
        elements: null,
        cardNumber: null,
        cardExpiry: null,
        cardCvc: null,
        mounted: false
    };

    function selectedCheckoutPaymentMethod($form) {
        if (!$form || !$form.length) { return ''; }
        var checked = String($form.find('[name="payment_method"]:checked').val() || '').trim();
        if (checked) { return checked; }
        return String($form.find('[name="payment_method"]').first().val() || '').trim();
    }

    function checkoutUsesStripe($form) {
        if (!$form || !$form.length) { return false; }
        return String($form.attr('data-stripe-enabled') || '') === '1'
            && String($form.attr('data-stripe-method-name') || '').trim() === selectedCheckoutPaymentMethod($form);
    }

    function setStripeCheckoutError(message) {
        var $error = $('[data-stripe-error]').first();
        if (!$error.length) { return; }
        var text = String(message || '').trim();
        $error.text(text).prop('hidden', !text);
    }

    function clearCheckoutPaymentIntent() {
        var $form = $('#checkout-form');
        if (!$form.length) { return; }
        $form.find('[name="payment_intent_id"]').val('');
        setStripeCheckoutError('');
    }

    function toggleStripeCheckoutPanel() {
        var $form = $('#checkout-form');
        if (!$form.length) { return; }
        $('[data-stripe-panel]').toggleClass('is-hidden', !checkoutUsesStripe($form));
        if (!checkoutUsesStripe($form)) {
            setStripeCheckoutError('');
        }
    }

    function initStripeCheckoutElements() {
        var $form = $('#checkout-form');
        if (!$form.length || String($form.attr('data-stripe-enabled') || '') !== '1') { return; }
        if (checkoutStripe.mounted || typeof window.Stripe !== 'function') { return; }

        var publishableKey = String($form.attr('data-stripe-publishable-key') || '').trim();
        if (!publishableKey) { return; }

        checkoutStripe.stripe = window.Stripe(publishableKey);
        checkoutStripe.elements = checkoutStripe.stripe.elements();

        var elementStyle = {
            style: {
                base: {
                    color: '#0f172a',
                    fontFamily: 'Inter, sans-serif',
                    fontSize: '16px',
                    '::placeholder': { color: '#94a3b8' }
                }
            }
        };

        checkoutStripe.cardNumber = checkoutStripe.elements.create('cardNumber', elementStyle);
        checkoutStripe.cardExpiry = checkoutStripe.elements.create('cardExpiry', elementStyle);
        checkoutStripe.cardCvc = checkoutStripe.elements.create('cardCvc', elementStyle);

        checkoutStripe.cardNumber.mount('#stripe-card-number');
        checkoutStripe.cardExpiry.mount('#stripe-card-expiry');
        checkoutStripe.cardCvc.mount('#stripe-card-cvc');

        [checkoutStripe.cardNumber, checkoutStripe.cardExpiry, checkoutStripe.cardCvc].forEach(function (element) {
            element.on('change', function (event) {
                setStripeCheckoutError(event && event.error ? event.error.message : '');
            });
        });

        checkoutStripe.mounted = true;
    }

    /* The intent is always raised against a persisted order: #checkout-form only ever exists on
       customer/order/detail.html.twig, which sets data-order-payment="1". The alternative branch
       posted the browser's localStorage cart as `cart_payload`, a parameter no controller in src/
       has ever read — it went with that cart (#200). */
    function requestStripeCheckoutIntent($form) {
        var deferred = $.Deferred();
        var formData = new FormData();
        formData.append('_token', String($form.find('[name="_token"]').val() || ''));
        formData.append('order_id', String($form.attr('data-order-id') || ''));
        formData.append('coupon_code', String($form.find('[name="coupon_code"]').val() || ''));
        formData.append('fulfillment_method', selectedCheckoutFulfillmentMethod());
        formData.append('shipping_amount', String($form.find('[name="shipping_amount"]').val() || '0'));

        $.ajax({
            url: $form.attr('data-stripe-intent-url'),
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .done(function (res) {
                if (res && res.ok && res.client_secret && res.payment_intent_id) {
                    deferred.resolve(res);
                    return;
                }

                deferred.reject(res && res.message ? res.message : 'Could not start card payment.');
            })
            .fail(function (xhr) {
                deferred.reject(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not start card payment.');
            });

        return deferred.promise();
    }

    function confirmStripeCheckoutPayment($form) {
        var deferred = $.Deferred();

        if (!checkoutStripe.mounted || !checkoutStripe.stripe || !checkoutStripe.cardNumber) {
            deferred.reject('Card payment form is not ready.');
            return deferred.promise();
        }

        requestStripeCheckoutIntent($form)
            .done(function (intent) {
                setStripeCheckoutError('');
                var billingCountry = String($form.find('[name="bill_country"]').val() || '').trim().toUpperCase();
                if (billingCountry.length !== 2) {
                    billingCountry = '';
                }
                var billingAddress = {
                    line1: String($form.find('[name="bill_address1"]').val() || '').trim(),
                    line2: String($form.find('[name="bill_address2"]').val() || '').trim(),
                    city: String($form.find('[name="bill_city"]').val() || '').trim(),
                    state: String($form.find('[name="bill_province"]').val() || '').trim(),
                    postal_code: String($form.find('[name="bill_postal_code"]').val() || '').trim()
                };
                if (billingCountry) {
                    billingAddress.country = billingCountry;
                }

                checkoutStripe.stripe.confirmCardPayment(String(intent.client_secret || ''), {
                    payment_method: {
                        card: checkoutStripe.cardNumber,
                        billing_details: {
                            name: String(($form.find('[name="bill_first_name"]').val() || '') + ' ' + ($form.find('[name="bill_last_name"]').val() || '')).trim(),
                            phone: String($form.find('[name="bill_phone"]').val() || '').trim(),
                            address: billingAddress
                        }
                    }
                }).then(function (result) {
                    if (result.error) {
                        deferred.reject(result.error.message || 'Card payment failed.');
                        return;
                    }

                    if (!result.paymentIntent || result.paymentIntent.status !== 'succeeded') {
                        deferred.reject('Your card payment is not complete yet.');
                        return;
                    }

                    $form.find('[name="payment_intent_id"]').val(String(result.paymentIntent.id || intent.payment_intent_id || ''));
                    deferred.resolve(result.paymentIntent);
                });
            })
            .fail(function (message) {
                deferred.reject(message || 'Could not start card payment.');
            });

        return deferred.promise();
    }

    /* ── Country/province selects ─────────────────────────────────────────────
       One handler for every address form (customer address book, admin address book,
       registration, admin order). The province list comes from data-region-map, which the
       server renders from geo_country/geo_province — so the browser can never offer a province
       the server would then reject, which is what the old per-template inline REGION_MAP
       literal allowed. */
    function populateRegionProvinces($country) {
        var $form = $country.closest('form');
        // A form can hold more than one country/province pair (registration has shipping *and*
        // billing), so a country select names its partner via data-province-target. Without that
        // pairing, changing the billing country would repopulate the shipping province.
        var target = $country.attr('data-province-target');
        var $province = target
            ? $form.find('.js-region-province[data-province-name="' + target + '"]').first()
            : $form.find('.js-region-province').first();
        if (!$province.length) { return; }

        var map;
        try { map = JSON.parse($country.attr('data-region-map') || '{}'); } catch (e) { map = {}; }

        var provinces = map[String($country.val() || '')] || {};
        // Prefer the value already selected; fall back to data-selected so a server-rendered
        // choice survives the first repopulate on page load.
        var wanted = String($province.val() || $province.attr('data-selected') || '');

        $province.empty().append($('<option>', { value: '', text: '' }));
        Object.keys(provinces).forEach(function (code) {
            $province.append($('<option>', {
                value: code,
                text: provinces[code],
                selected: code === wanted
            }));
        });
    }

    $(document).on('change', '.js-region-country', function () {
        populateRegionProvinces($(this));
    });

    $(function () {
        $('.js-region-country').each(function () {
            var $country = $(this);
            var target = $country.attr('data-province-target');
            var $province = target
                ? $country.closest('form').find('.js-region-province[data-province-name="' + target + '"]').first()
                : $country.closest('form').find('.js-region-province').first();
            // Only repopulate when the server did not already render options — otherwise a
            // freshly-rendered, correctly-selected list would be rebuilt for no reason.
            if ($province.length && $province.find('option').length <= 1) {
                populateRegionProvinces($country);
            }
        });
    });

    /* Card payment is no longer taken on the checkout page. Checkout persists the order at
       'On Hold' and redirects to the order detail page, whose payment panel (checkoutStripe
       above) creates a PaymentIntent carrying metadata.order_id and validates the captured
       amount against the persisted order total. */

    function submitCheckoutAjax($form, $submit, originalSubmitText) {
        var data = new FormData($form.get(0));
        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: data,
            processData: false,
            contentType: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .done(function (res) {
                if (res && res.ok && res.redirect_url) {
                    // The localStorage cart this used to clear here is gone (#200); the server
                    // empties the session cart when it persists the order, and the badge is
                    // rendered from that same session cart on the page we redirect to.
                    if (window.sessionStorage) {
                        window.sessionStorage.setItem('wcFlashMessage', JSON.stringify({
                            message: res.message || 'Order placed successfully.',
                            type: 'success'
                        }));
                    }
                    window.location.href = res.redirect_url;
                    return;
                }

                var message = res && res.message ? res.message : 'Could not place order. Please try again.';
                showToast(message, 'error');
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Could not place order. Please try again.';
                showToast(message, 'error');
            })
            .always(function () {
                // This used to be `readCart().length <= 0`. Nothing has written the localStorage
                // cart since /cart moved server-side, so for any browser without a stale wcCart key
                // it already evaluated to true — i.e. the button was left disabled after a failed
                // submit. Kept as-is: re-enabling it is a behaviour change on the payment path and
                // wants its own issue, not a side effect of deleting the cart.
                var disableForEmptyCart = true;
                $submit.data('submitting', false)
                    .prop('disabled', disableForEmptyCart)
                    .toggleClass('is-disabled', disableForEmptyCart)
                    .attr('aria-disabled', disableForEmptyCart ? 'true' : 'false')
                    .text(originalSubmitText);
            });
    }

    function ensureCheckoutDeliveryModal() {
        return $('.js-checkout-delivery-modal').first();
    }

    function openCheckoutDeliveryModal() {
        var $modal = ensureCheckoutDeliveryModal();
        if (!$modal.length) { return; }

        $modal.prop('hidden', false).addClass('is-open');
        setTimeout(function () { $modal.find('.js-checkout-pickup').trigger('focus'); }, 20);
    }

    function closeCheckoutDeliveryModal() {
        $('.js-checkout-delivery-modal').prop('hidden', true).removeClass('is-open');
    }

    renderCheckoutPage();
    initStripeCheckoutElements();
    toggleStripeCheckoutPanel();

    $(document).on('click', '.js-checkout-coupon-apply', function () {
        var code = $('.js-checkout-coupon-input').val();
        applyCheckoutCoupon(code, { silent: false });
    });

    $(document).on('keydown', '.js-checkout-coupon-input', function (event) {
        if (event.key !== 'Enter') { return; }
        event.preventDefault();
        applyCheckoutCoupon($(this).val(), { silent: false });
    });

    $(document).on('change', 'input[name="payment_method"]', function () {
        $('.payment-option-card').removeClass('is-selected');
        $(this).closest('.payment-option-card').addClass('is-selected');
        clearCheckoutPaymentIntent();
        toggleStripeCheckoutPanel();
    });

    $(function () {
        var savedCouponCode = storedCheckoutCouponCode();
        if (savedCouponCode) {
            $('.js-checkout-coupon-input').val(savedCouponCode);
            applyCheckoutCoupon(savedCouponCode, { silent: true });
        } else {
            renderCheckoutPage();
        }
        initStripeCheckoutElements();
        toggleStripeCheckoutPanel();
        refreshCheckoutShippingOptions();
        loadCheckoutFeeLines();
    });

    function applyCheckoutAddressFromCard($card) {
        if (!$card || !$card.length) { return; }
        var $form = $('#checkout-form');
        if (!$form.length) { return; }

        $form.find('[name="ship_address_id"]').val(String($card.data('address-id') || ''));
        $form.find('[name="ship_name"]').val(String($card.data('ship-name') || ''));
        $form.find('[name="ship_company"]').val(String($card.data('ship-company') || ''));
        $form.find('[name="ship_address1"]').val(String($card.data('ship-address1') || ''));
        $form.find('[name="ship_address2"]').val(String($card.data('ship-address2') || ''));
        $form.find('[name="ship_city"]').val(String($card.data('ship-city') || ''));
        $form.find('[name="ship_zip"]').val(String($card.data('ship-zip') || ''));
        $form.find('[name="ship_province"]').val(String($card.data('ship-province') || ''));
        $form.find('[name="ship_country"]').val(String($card.data('ship-country') || ''));
    }

    function persistCheckoutAddressSelection(addressId) {
        if (!addressId) { return; }

        try {
            window.localStorage.setItem('customer_checkout_ship_address_id', String(addressId));
        } catch (e) {}

        if (!window.history || !window.history.replaceState || !window.URL) { return; }

        var url = new URL(window.location.href);
        url.searchParams.set('ship_address_id', String(addressId));
        window.history.replaceState({}, '', url.toString());
    }

    function storedCheckoutAddressId() {
        var hiddenId = String($('#checkout-form').find('[name="ship_address_id"]').val() || '');
        if (hiddenId) { return hiddenId; }

        try {
            var localId = String(window.localStorage.getItem('customer_checkout_ship_address_id') || '');
            if (localId) { return localId; }
        } catch (e) {}

        return '';
    }

    function findCheckoutAddressCardById(addressId) {
        if (!addressId) { return $(); }

        return $('.checkout-address-card').not('.add-address-card, .is-empty').filter(function () {
            return String($(this).data('address-id') || '') === String(addressId);
        }).first();
    }

    function setCheckoutAddressSelection($card) {
        if (!$card || !$card.length) { return; }

        var $cards = $('.checkout-address-card').not('.add-address-card, .is-empty');

        $cards.removeClass('is-selected').find('.selected-pill').remove();

        $cards.each(function () {
            var $item = $(this);
            var $actions = $item.find('.checkout-address-actions').first();
            if (!$actions.length || $actions.find('.js-select-checkout-address').length) { return; }

            var $edit = $actions.find('.button.outline').first();
            $('<button class="button primary js-select-checkout-address" type="button" aria-pressed="false">Select</button>')
                .insertBefore($edit.length ? $edit : null);
        });

        $card.addClass('is-selected');
        if (!$card.find('.selected-pill').length) {
            $card.prepend('<span class="selected-pill">Selected</span>');
        }

        $card.find('.js-select-checkout-address').remove();

        applyCheckoutAddressFromCard($card);
        persistCheckoutAddressSelection(String($card.data('address-id') || ''));
    }

    $(document).on('click', '.js-select-checkout-address', function () {
        var $card = $(this).closest('.checkout-address-card');
        if (!$card.length) { return; }

        setCheckoutAddressSelection($card);
        scheduleCheckoutShippingRefresh();
        scheduleCheckoutFeeLinesRefresh();
    });

    $(document).on('change', '.js-checkout-shipping-option', function () {
        var $form = $('#checkout-form');
        var label = String($(this).data('label') || '').trim();
        var amount = parseFloat($(this).data('amount') || 0) || 0;
        $form.find('[name="shipping_method"]').val(label);
        $form.find('[name="shipping_amount"]').val(amount.toFixed(2));
        $('.js-checkout-shipping-option-label').removeClass('is-selected');
        $(this).closest('.js-checkout-shipping-option-label').addClass('is-selected');
        clearCheckoutPaymentIntent();
        renderCheckoutPage();
    });

    $(document).on('click', '.checkout-address-card', function (event) {
        var $target = $(event.target);
        if ($target.closest('a, button').length) { return; }

        var $card = $(this);
        if ($card.is('.add-address-card, .is-empty')) { return; }

        setCheckoutAddressSelection($card);
    });

    $(function () {
        var preferredAddressId = storedCheckoutAddressId();
        var $selectedAddress = findCheckoutAddressCardById(preferredAddressId);

        if (!$selectedAddress.length) {
            $selectedAddress = $('.checkout-address-card.is-selected').first();
        }
        if (!$selectedAddress.length) {
            $selectedAddress = $('.checkout-address-card').not('.add-address-card, .is-empty').first();
        }
        if ($selectedAddress.length) {
            setCheckoutAddressSelection($selectedAddress);
        }
    });

    function closeCustomerTaxModal() {
        $('.customer-tax-modal').prop('hidden', true);
    }

    $(document).on('click', '.js-customer-tax-open', function () {
        $('.customer-tax-modal').prop('hidden', false);
    });

    $(document).on('click', '.js-customer-tax-close', function () {
        closeCustomerTaxModal();
    });

    $(document).on('click', '.customer-tax-modal__backdrop', function () {
        closeCustomerTaxModal();
    });

    $(document).on('click', '.customer-tax-modal', function (event) {
        if ($(event.target).is('.customer-tax-modal')) {
            closeCustomerTaxModal();
        }
    });

    $(document).on('change', '#customer-order-warehouse', function () {
        $(this).closest('form').trigger('submit');
    });

    /* Add to cart buttons are each a real form (POST /cart/add) that writes
       to the server-side session cart and reloads the page - this always
       works with no JS. On product listing pages a static per-product qty
       modal also exists (see below); when JS is available we intercept the
       button click to open that modal instead of submitting immediately,
       but the modal's own form still posts to /cart/add with no JS needed. */

    $(document).on('click', '.js-detail-qty-inc, .js-detail-qty-dec', function () {
        var $wrap = $(this).closest('.customer-product-qty');
        var $input = $wrap.find('.customer-product-qty-input').first();
        var current = parseInt($input.val(), 10);
        if (isNaN(current)) { current = 1; }
        var next = $(this).hasClass('js-detail-qty-inc') ? current + 1 : current - 1;
        $input.val(String(Math.max(1, Math.min(999, next))));
    });

    $(document).on('input change', '.customer-product-qty-input', function () {
        var $input = $(this);
        var qty = parseInt($input.val(), 10);
        if (isNaN(qty)) { qty = 1; }
        $input.val(String(Math.max(1, Math.min(999, qty))));
    });

    /* Cart page qty steppers - convenience enhancement only; the number
       input itself still works by typing + "Update Cart" with no JS. */
    $(document).on('click', '.js-cart-qty-inc, .js-cart-qty-dec', function () {
        var $wrap = $(this).closest('.cart-qty-control');
        var $input = $wrap.find('.cart-qty').first();
        var current = parseInt($input.val(), 10);
        if (isNaN(current)) { current = 1; }
        var next = $(this).hasClass('js-cart-qty-inc') ? current + 1 : current - 1;
        $input.val(String(Math.max(1, Math.min(999, next))));
    });

    /* Add-to-cart quantity modal: one real, static <form> per product
       (see customer/catalog/_cart_qty_modal.html.twig), already posting to
       /cart/add with no JS needed - JS only toggles which one is visible,
       so a no-JS client (human or automated test) can find and submit the
       form directly regardless of whether the modal was ever "opened". */
    $(document).on('click', '.js-product-card-cart-btn[data-modal-target]', function (event) {
        var targetId = String($(this).attr('data-modal-target') || '').trim();
        if (!targetId) { return; }
        var $modal = $(document.getElementById(targetId));
        if (!$modal.length) { return; }

        event.preventDefault();
        $modal.prop('hidden', false).addClass('is-open');
        $('body').addClass('has-cart-modal');
        var $qty = $modal.find('.js-modal-qty').first().val('1');
        setTimeout(function () { $qty.trigger('focus').trigger('select'); }, 20);
    });

    function closeCartModal($modal) {
        $modal.prop('hidden', true).removeClass('is-open');
        if (!$('.cart-qty-modal.is-open').length) {
            $('body').removeClass('has-cart-modal');
        }
    }

    $(document).on('click', '.js-cart-modal-close', function () {
        closeCartModal($(this).closest('.cart-qty-modal'));
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') {
            $('.cart-qty-modal.is-open').each(function () { closeCartModal($(this)); });
        }
    });

    $(document).on('input change', '.cart-qty-modal .js-modal-qty', function () {
        var $input = $(this);
        var qty = parseInt($input.val(), 10);
        if (isNaN(qty)) { qty = 1; }
        var max = parseInt($input.attr('max'), 10);
        if (!isNaN(max) && max > 0) { qty = Math.min(qty, max); }
        $input.val(String(Math.max(1, Math.min(999, qty))));
    });

    $(document).on('click', '.js-modal-qty-inc, .js-modal-qty-dec', function () {
        var $input = $(this).closest('.cart-qty-dialog-foot').find('.js-modal-qty');
        var current = parseInt($input.val(), 10);
        if (isNaN(current)) { current = 1; }
        var max = parseInt($input.attr('max'), 10);
        var next = $(this).hasClass('js-modal-qty-inc') ? current + 1 : current - 1;
        if (!isNaN(max) && max > 0) { next = Math.min(next, max); }
        $input.val(String(Math.max(1, Math.min(999, next))));
    });

    /* The cart page's remove / clear / update actions are real form POSTs
       (customer/cart/index.html.twig -> /cart/remove, /cart/clear, /cart/update). The JS
       handlers that used to sit here drove a second, client-rendered cart table off
       .js-cart-rows / .js-cart-count / .js-cart-empty / .js-checkout-btn — classes no template
       has ever carried — and mutated the localStorage cart. Both are gone (#200); the only
       surviving cart-page JS is the qty stepper above, which just nudges the number input. */

    refreshProductCardCartState();

    $(document).on('submit', '.js-checkout-form', function (e) {
        e.preventDefault();

        var form = this;
        if (form.reportValidity && !form.reportValidity()) {
            return;
        }

        var $form = $(form);
        // Was `isOrderPaymentCheckout($form) ? [] : readCart()`. .js-checkout-form matches exactly
        // one element in the codebase — the order-payment form in customer/order/detail.html.twig,
        // which sets data-order-payment="1" — so this was already [] on the only reachable path,
        // and off it readCart() returned [] too because nothing has written the localStorage cart
        // since /cart moved server-side (#197, #200). The guards below are therefore unchanged.
        var cart = [];
        if (!isOrderPaymentCheckout($form) && !cart.length) {
            showToast('Your cart is empty.', 'error');
            return;
        }

        var $submit = $form.find('.js-checkout-submit').first();
        if ($submit.prop('disabled') || $submit.data('submitting')) {
            return;
        }

        var shipAddressId = String($form.find('[name="ship_address_id"]').val() || '').trim();
        if (!isOrderPaymentCheckout($form) && !shipAddressId) {
            showToast('Please select a shipping address.', 'error');
            return;
        }

        var paymentMethod = selectedCheckoutPaymentMethod($form);
        if (!paymentMethod) {
            showToast('Please select a payment method.', 'error');
            return;
        }

        // The cart_payload hidden field this used to fill does not exist in any template, and no
        // controller in src/ has ever read a `cart_payload` parameter — it was a write into the
        // void, and it went with the localStorage cart it serialised (#200).

        if (!isOrderPaymentCheckout($form) && !$form.data('fulfillment-confirmed')) {
            openCheckoutDeliveryModal();
            return;
        }

        if (!isOrderPaymentCheckout($form) && selectedCheckoutFulfillmentMethod() === 'delivery') {
            var $shippingOptions = $('.js-checkout-shipping-option');
            var shippingMethod = String($form.find('[name="shipping_method"]').val() || '').trim();
            if ($shippingOptions.length > 0 && !shippingMethod) {
                showToast('Please select a shipping method.', 'error');
                $form.data('fulfillment-confirmed', false);
                return;
            }
        }

        var originalSubmitText = $submit.text();
        $submit.data('submitting', true)
            .prop('disabled', true)
            .addClass('is-disabled')
            .attr('aria-disabled', 'true')
            .text('Processing...');

        if (checkoutUsesStripe($form)) {
            confirmStripeCheckoutPayment($form)
                .done(function () {
                    submitCheckoutAjax($form, $submit, originalSubmitText);
                })
                .fail(function (message) {
                    setStripeCheckoutError(message || 'Card payment failed.');
                    showToast(message || 'Card payment failed.', 'error');
                    $submit.data('submitting', false)
                        .prop('disabled', false)
                        .removeClass('is-disabled')
                        .attr('aria-disabled', 'false')
                        .text(originalSubmitText);
                });
            return;
        }

        submitCheckoutAjax($form, $submit, originalSubmitText);
    });

    $(document).on('click', '.js-checkout-delivery-close', function () {
        closeCheckoutDeliveryModal();
    });

    $(document).on('click', '.js-checkout-pickup', function () {
        var $form = $('#checkout-form');
        setCheckoutFulfillmentMethod('pickup');
        $form.data('fulfillment-confirmed', true);
        closeCheckoutDeliveryModal();
        showToast('Pickup address updated successfully', 'success');
    });

    $(document).on('click', '.js-checkout-delivery', function () {
        var $form = $('#checkout-form');
        setCheckoutFulfillmentMethod('delivery');
        $form.data('fulfillment-confirmed', true);
        closeCheckoutDeliveryModal();
        $form.trigger('submit');
    });

    var storedFlash = window.sessionStorage ? window.sessionStorage.getItem('wcFlashMessage') : null;
    if (storedFlash) {
        try {
            var flash = JSON.parse(storedFlash);
            showToast(flash.message, flash.type || 'success');
        } catch (e) {
            showToast(storedFlash, 'success');
        }
        window.sessionStorage.removeItem('wcFlashMessage');
    }

    $('.flash-message').each(function () {
        showToast($(this).text(), $(this).data('type') || 'success');
    });

    /* ── Delete confirmation modal ────────────────────────────────────────── */
    function showConfirmModal(title, beforeText, itemLabel, afterText, onConfirm) {
        var $overlay = $('#delete-modal');
        var $title = $('#delete-modal-title');
        var $body = $overlay.find('.delete-modal-body').first();

        if ($title.length) { $title.text(title || 'Confirmation'); }
        if ($body.length) {
            var strong = $('<strong id="delete-modal-item"></strong>').text(itemLabel || 'this item');
            $body.empty();
            if (beforeText) {
                $body.append(document.createTextNode(beforeText));
            }
            $body.append(strong);
            if (afterText) {
                $body.append(document.createTextNode(afterText));
            }
        }

        $overlay.addClass('is-open');
        $overlay.find('.delete-modal-confirm').off('click.dm').one('click.dm', function () {
            closeDeleteModal();
            onConfirm();
        });
        setTimeout(function () { $overlay.find('.delete-modal-confirm').trigger('focus'); }, 60);
    }

    function showDeleteModal(item, onConfirm) {
        showConfirmModal(
            'Delete Confirmation',
            'Are you sure you want to delete ',
            item,
            '? This action cannot be undone.',
            onConfirm
        );
    }

    function closeDeleteModal() {
        $('#delete-modal').removeClass('is-open');
    }

    $('#delete-modal').on('click', function (e) {
        if ($(e.target).is('#delete-modal')) { closeDeleteModal(); }
    });

    $('#delete-modal .delete-modal-cancel').on('click', function () { closeDeleteModal(); });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#delete-modal').hasClass('is-open')) { closeDeleteModal(); }
    });

    /* Delete buttons call backend routes when data-url is present. */
    $(document).on('click', '.js-delete', function () {
        var $btn = $(this);
        var item = $btn.data('item') || 'this item';

        showDeleteModal(item, function () {
            if ($btn.data('url')) {
                $btn.prop('disabled', true);
                var payload = {};
                if ($btn.data('token')) {
                    payload._token = $btn.data('token');
                }
                $.post($btn.data('url'), payload)
                    .done(function (response) {
                        var message = response && response.message ? response.message : item + ' deleted.';
                        if ($btn.data('redirect')) {
                            if (window.sessionStorage) {
                                window.sessionStorage.setItem('wcFlashMessage', JSON.stringify({
                                    message: message,
                                    type: 'success'
                                }));
                            }
                            window.location.href = $btn.data('redirect');
                            return;
                        }

                        var $deleteTarget = $btn.closest('tr, .card, .module-card, .address-list-card, .address-book-card');
                        if (!$deleteTarget.length && typeof $activeToggle !== 'undefined' && $activeToggle) {
                            $deleteTarget = $activeToggle.closest('tr, .card, .module-card, .address-list-card, .address-book-card');
                        }
                        if (typeof closeFixedDrop === 'function') {
                            closeFixedDrop();
                        }
                        var $parentCard = $deleteTarget.closest('.table-card');
                        $deleteTarget.fadeOut(180, function () {
                            $(this).remove();
                            if ($parentCard.length) { $parentCard.trigger('wc:paginate'); }
                        });
                        showToast(message, 'success');
                    })
                    .fail(function (xhr) {
                        $btn.prop('disabled', false);
                        var message = xhr.responseJSON && xhr.responseJSON.message
                            ? xhr.responseJSON.message
                            : 'Could not delete ' + item + '. Please try again.';
                        showToast(message, 'error');
                    });
                return;
            }

            $btn.closest('tr, .card, .module-card, .address-list-card, .address-book-card').fadeOut(180, function () {
                $(this).remove();
            });

            showToast(item + ' deleted.', 'success');
        });
    });

    /* Company status toggles: deactivate/reactivate without removing the row. */
    $(document).on('click', '.js-company-status', function () {
        var $btn = $(this);
        var url = $btn.data('url');
        if (!url) { return; }

        var item = $btn.data('item') || 'this company';
        var action = ($btn.data('action') || '').toString().toLowerCase();
        var isDeactivate = action === 'deactivate';
        var isActivate = action === 'activate' || action === 'reactivate';
        // A record type other than "Company" (e.g. a vendor, which has no logins of its own)
        // supplies its own noun and closing sentence rather than inheriting Company's wording.
        var noun = $btn.data('noun') || 'Company';

        var title = isDeactivate ? ('Deactivate ' + noun) : ('Activate ' + noun);
        var beforeText = isDeactivate
            ? 'Are you sure you want to deactivate '
            : 'Are you sure you want to activate ';
        var afterText = $btn.data('confirmNote') !== undefined
            ? ('? ' + $btn.data('confirmNote'))
            : (isDeactivate
                ? '? Users under this company will no longer be able to log in.'
                : '? Users under this company will be able to log in again.');

        showConfirmModal(title, beforeText, item, afterText, function () {
            $btn.prop('disabled', true);
            $.post(url)
                .done(function (response) {
                    var message = response && response.message ? response.message : 'Updated successfully.';

                    if ($btn.data('redirect')) {
                        if (window.sessionStorage) {
                            window.sessionStorage.setItem('wcFlashMessage', JSON.stringify({
                                message: message,
                                type: 'success'
                            }));
                        }
                        window.location.href = $btn.data('redirect');
                        return;
                    }

                    if (typeof closeFixedDrop === 'function') {
                        closeFixedDrop();
                    }

                    var $parentCard = $btn.closest('.table-card');
                    if ($parentCard.length) {
                        $parentCard.trigger('wc:paginate');
                    } else {
                        window.location.reload();
                    }
                    showToast(message, 'success');
                })
                .fail(function (xhr) {
                    $btn.prop('disabled', false);
                    var message = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : 'Action failed. Please try again.';
                    showToast(message, 'error');
                });
        });
    });

    /* Generic action buttons call backend routes when data-url is present. */
    $(document).on('click', '.js-action', function () {
        var $btn = $(this);
        var url = $btn.data('url');

        if (!url) { return; }

        var payload = {};
        if ($btn.data('token')) {
            payload._token = $btn.data('token');
        }

        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('Sending... <svg style="display:inline; margin-left:4px; vertical-align:middle; animation: spin 1s linear infinite;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>');

        $.post(url, payload)
            .done(function (res) {
                var message = res && res.message ? res.message : 'Action completed successfully.';
                showToast(message, 'success');
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Action failed. Please try again.';
                showToast(message, 'error');
            })
            .always(function () {
                if (typeof closeFixedDrop === 'function') {
                    closeFixedDrop();
                }
                $btn.prop('disabled', false).html(originalHtml);
            });
    });

    var $draggedConfigRow = null;

    $(document).on('dragstart', '.js-drag-handle', function (event) {
        $draggedConfigRow = $(this).closest('.js-sortable-row');
        $draggedConfigRow.addClass('is-dragging');
        event.originalEvent.dataTransfer.effectAllowed = 'move';
        event.originalEvent.dataTransfer.setData('text/plain', $draggedConfigRow.data('config-id'));
    });

    $(document).on('dragover', '.js-sortable-config .js-sortable-row', function (event) {
        if (!$draggedConfigRow || $draggedConfigRow[0] === this) { return; }

        event.preventDefault();
        var $target = $(this);
        var targetMiddle = $target.offset().top + ($target.outerHeight() / 2);
        if (event.originalEvent.pageY < targetMiddle) {
            $target.before($draggedConfigRow);
        } else {
            $target.after($draggedConfigRow);
        }
    });

    $(document).on('dragover', '.js-sortable-config', function (event) {
        if (!$draggedConfigRow) { return; }
        event.preventDefault();

        var $lastRow = $(this).find('.js-sortable-row').not($draggedConfigRow).last();
        if ($lastRow.length && event.originalEvent.pageY > $lastRow.offset().top + ($lastRow.outerHeight() / 2)) {
            $lastRow.after($draggedConfigRow);
        }
    });

    $(document).on('drop dragend', '.js-sortable-config, .js-drag-handle', function (event) {
        if (!$draggedConfigRow) { return; }
        event.preventDefault();

        var $tbody = $draggedConfigRow.closest('.js-sortable-config');
        var url = $tbody.data('reorder-url');
        var token = $tbody.data('reorder-token');
        var ids = $tbody.find('.js-sortable-row').map(function () {
            return $(this).data('config-id');
        }).get();

        $draggedConfigRow.removeClass('is-dragging');
        $draggedConfigRow = null;

        if (!url || ids.length === 0) { return; }

        $.ajax({
            url: url,
            method: 'POST',
            data: JSON.stringify({ ids: ids, _token: token }),
            contentType: 'application/json'
        }).done(function (res) {
            showToast((res && res.message) || 'Order was updated.', 'success');
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : 'Could not update order. Please refresh and try again.';
            showToast(message, 'error');
        });
    });

    /* ── Toast ─────────────────────────────────────────────────────────────────
     * showToast() lives further down, next to showNotification() which implements it.
     *
     * There used to be a SECOND `function showToast()` right here, driving the single
     * <div class="toast"> in base.html.twig. It never ran once: both declarations sat in
     * the same IIFE scope, so hoisting let the later one silently overwrite this one and
     * every call in this file — all ~60 of them — went to showNotification() instead
     * (#448). Do not re-add a declaration of this name here.
     *
     * The bare `.toast` element and its CSS are still live, but only for the inline
     * scripts that address it directly (admin/product/prices, admin/inventory,
     * admin/company_fulfillment_region). Nothing in this file touches it.
     */

    var orderScrollKey = 'wcCreateOrderScrollY';

    if (window.sessionStorage) {
        if ($('.js-order-form').length) {
            var savedOrderScroll = window.sessionStorage.getItem(orderScrollKey);
            if (savedOrderScroll !== null) {
                window.sessionStorage.removeItem(orderScrollKey);
                var savedY = parseInt(savedOrderScroll, 10);
                if (!isNaN(savedY)) {
                    setTimeout(function () {
                        window.scrollTo(0, savedY);
                    }, 0);
                }
            }
        } else {
            window.sessionStorage.removeItem(orderScrollKey);
        }
    }

    var _shippingRefreshTimer = null;

    function refreshShippingOptions() {
        var $form = $('.js-order-form');
        var $select = $form.find('.js-order-charge-type');
        if (!$form.length || !$select.length) { return; }

        var url = $select.data('shipping-options-url');
        if (!url) { return; }

        var companyId = $form.find('[name="company_id"]').val();
        if (!companyId) { return; }

        var addressId = $form.find('[name="shipping_address_id"]').val() || '';
        var province = $form.find('[name="shipping_province"]').val() || '';
        var lines = [];
        $form.find('.js-order-lines-body .order-line-row').each(function () {
            var productId = parseInt($(this).find('.js-order-product-select').val() || '0', 10);
            var qty = parseInt($(this).find('.js-order-qty').val() || '0', 10);
            if (productId > 0 && qty > 0) {
                lines.push({ product_id: productId, qty: qty });
            }
        });

        // The select only holds the *choices*; once a row is added its amount is a normal
        // editable number that never gets touched again, so refreshing here never rewrites
        // anything already on the page — it only replaces the shipping-method <option>s.
        $.getJSON(url, { company_id: companyId, address_id: addressId, province: province, lines: JSON.stringify(lines) })
            .done(function (data) {
                var options = data.options || [];
                $select.find('option[value^="shipping-method:"]').remove();

                // #669: "Custom Shipping" (value "shipping:...") is gone — "Empty Shipping Line"
                // (now "Custom Shipping (Type anything)", value "empty-shipping:") is the
                // remaining shipping-only option after the computed methods, so refreshed methods
                // are inserted before THAT one now, keeping them out of the tax/fee options below.
                var $customShippingOpt = $select.find('option[value^="empty-shipping:"]').first();
                $.each(options, function (i, opt) {
                    var days = opt.deliveryDays ? ' (' + opt.deliveryDays + ' day' + (opt.deliveryDays !== 1 ? 's' : '') + ')' : '';
                    var value = 'shipping-method:' + opt.label + '|' + opt.amount;
                    var $opt = $('<option>').val(value).text(opt.label + days + ' — $' + parseFloat(opt.amount).toFixed(2));
                    if ($customShippingOpt.length) {
                        $customShippingOpt.before($opt);
                    } else {
                        $select.append($opt);
                    }
                });

                $select.val('');
                $select.trigger('order:shipping-options-refreshed');
            });
    }

    function scheduleShippingRefresh() {
        clearTimeout(_shippingRefreshTimer);
        _shippingRefreshTimer = setTimeout(refreshShippingOptions, 350);
    }

    function refreshAdminSellDocFeeLines(prefix) {
        var $form = $('.js-' + prefix + '-form');
        if (!$form.length) { return; }

        var url = $form.data('fee-lines-url');
        if (!url) { return; }

        var companyId = $form.find('[name="company_id"]').val();
        if (!companyId) { return; }

        var addressId = $form.find('[name="shipping_address_id"]').val() || '';
        var province = $form.find('[name="shipping_province"]').val() || '';
        var lines = [];
        $form.find('.js-' + prefix + '-lines-body .' + prefix + '-line-row').each(function () {
            var productId = parseInt($(this).find('.js-' + prefix + '-product-select').val() || '0', 10);
            var qty = parseInt($(this).find('.js-' + prefix + '-qty').val() || '0', 10);
            if (productId > 0 && qty > 0) {
                lines.push({ product_id: productId, qty: qty });
            }
        });

        $.getJSON(url, { company_id: companyId, address_id: addressId, province: province, lines: JSON.stringify(lines) })
            .done(function (data) {
                var feeLines = data.lines || [];
                var $wrap = $form.find('.js-' + prefix + '-fee-lines-wrap');
                $wrap.empty();
                $.each(feeLines, function (i, line) {
                    $wrap.append(
                        $('<div>').append(
                            $('<span>').text(line.label + ':'),
                            $('<strong>').text('$' + parseFloat(line.amount).toFixed(2))
                        )
                    );
                });
                $form.find('.js-' + prefix + '-fee-total').val(parseFloat(data.total || 0).toFixed(2));
                recalcSellDocTotals(prefix, SELL_DOC_OPTS[prefix]);
            });
    }

    /**
     * The purchase document's own live fee/tax refresh — PurchaseOrder and VendorBill both
     * (#full-parity, 2026-09-14: VendorBillController::save() already converged its charge model
     * onto PurchaseOrder's, and both post the identical vendor_id/warehouse_id fields, so this is
     * one prefix-driven function rather than a third near-copy). Same shape as
     * refreshAdminSellDocFeeLines()/refreshAdminSellDocTaxBreakdown() but not generalised into
     * them: those two read the sell side's own field names (company_id, shipping_address_id,
     * shipping_province) directly off the form, and a purchase document has no such fields at all
     * — it has vendor_id and warehouse_id instead, and its AJAX endpoints
     * (PurchaseOrderController/VendorBillController's own feeLinesAjax()/taxBreakdownAjax()) take
     * a province derived server-side from the warehouse rather than an address id. Forcing one
     * function to read either vocabulary would be the same kind of hidden branching #669 already
     * ruled against once.
     */
    function refreshPurchaseDocumentFeeLines(prefix) {
        var $form = $('.js-' + prefix + '-form');
        if (!$form.length) { return; }

        var url = $form.data('fee-lines-url');
        if (!url) { return; }

        var vendorId = $form.find('[name="vendor_id"]').val();
        if (!vendorId) { return; }

        var warehouseId = $form.find('[name="warehouse_id"]').val() || '';
        var subtotal = numberFromMoney($('.js-' + prefix + '-total-before-tax').text());
        var lines = [];
        $form.find('.js-' + prefix + '-lines-body .' + prefix + '-line-row').each(function () {
            var productId = parseInt($(this).find('.js-' + prefix + '-product-select').val() || '0', 10);
            var qty = parseFloat($(this).find('.js-' + prefix + '-qty').val() || '0');
            if (productId > 0 && qty > 0) {
                lines.push({ product_id: productId, qty: qty });
            }
        });

        $.getJSON(url, { vendor_id: vendorId, warehouse_id: warehouseId, subtotal: subtotal, lines: JSON.stringify(lines) })
            .done(function (data) {
                var feeLines = data.lines || [];
                var $wrap = $form.find('.js-' + prefix + '-fee-lines-wrap');
                $wrap.empty();
                $.each(feeLines, function (i, line) {
                    $wrap.append(
                        $('<div>').append(
                            $('<span>').text(line.label + ':'),
                            $('<strong>').text('$' + parseFloat(line.amount).toFixed(2))
                        )
                    );
                });
                $form.find('.js-' + prefix + '-fee-total').val(parseFloat(data.total || 0).toFixed(2));
                recalcSellDocTotals(prefix, SELL_DOC_OPTS[prefix]);
            });
    }

    function refreshPurchaseDocumentTaxBreakdown(prefix) {
        var $form = $('.js-' + prefix + '-form');
        if (!$form.length) { return; }

        var url = $form.data('tax-breakdown-url');
        if (!url) { return; }

        var warehouseId = $form.find('[name="warehouse_id"]').val() || '';
        var freight = 0;
        $('.purchase-charge-row[data-charge-type="freight"] .js-' + prefix + '-charge-amount').each(function () {
            var v = parseFloat($(this).val() || 0);
            freight += isNaN(v) ? 0 : v;
        });
        var $rows = $form.find('.js-' + prefix + '-lines-body .' + prefix + '-line-row');
        var lines = [];
        $rows.each(function () {
            var $row = $(this);
            var productId = parseInt($row.find('.js-' + prefix + '-product-select').val() || '0', 10);
            var qty = parseFloat($row.find('.js-' + prefix + '-qty').val() || '0');
            var price = parseFloat($row.find('.js-' + prefix + '-price').val() || '0');
            var subtotal = (isNaN(qty) ? 0 : qty) * (isNaN(price) ? 0 : price);
            lines.push({
                product_id: isNaN(productId) ? 0 : productId,
                qty: isNaN(qty) ? 0 : qty,
                subtotal: subtotal,
                tax_code: $row.find('.js-' + prefix + '-tax').val() || ''
            });
        });

        $.getJSON(url, { warehouse_id: warehouseId, freight: freight, lines: JSON.stringify(lines) })
            .done(function (data) {
                var taxLines = data.lines || [];
                var $wrap = $form.find('.js-' + prefix + '-tax-lines-wrap');
                $wrap.empty();
                $.each(taxLines, function (i, line) {
                    var rate = line.rate === null || line.rate === undefined
                        ? ''
                        : ' ' + Math.round(parseFloat(line.rate) * 100) + '%';
                    $wrap.append(
                        $('<div>').append(
                            $('<span>').text(line.label + rate + ':'),
                            $('<strong>').text('$' + parseFloat(line.amount).toFixed(2))
                        )
                    );
                });

                $form.find('.js-' + prefix + '-tax-total').val(parseFloat(data.total || 0).toFixed(2));

                var perLineTax = data.perLineTax || {};
                $rows.each(function (index) {
                    var amount = perLineTax[index] || 0;
                    $(this).find('.js-' + prefix + '-line-tax').text('$' + parseFloat(amount).toFixed(2));
                });

                recalcSellDocTotals(prefix, SELL_DOC_OPTS[prefix]);
            });
    }

    var _sellDocFeeRefreshTimer = {};
    function scheduleAdminFeeRefresh(prefix) {
        clearTimeout(_sellDocFeeRefreshTimer[prefix]);
        _sellDocFeeRefreshTimer[prefix] = setTimeout(function () {
            if (prefix === 'po' || prefix === 'bill') { refreshPurchaseDocumentFeeLines(prefix); } else { refreshAdminSellDocFeeLines(prefix); }
        }, 350);
    }

    var _sellDocTaxRefreshTimer = {};

    function refreshAdminSellDocTaxBreakdown(prefix) {
        var $form = $('.js-' + prefix + '-form');
        if (!$form.length) { return; }

        var url = $form.data('tax-breakdown-url');
        if (!url) { return; }

        var companyId = $form.find('[name="company_id"]').val();
        if (!companyId) { return; }

        var addressId = $form.find('[name="shipping_address_id"]').val() || '';
        var province = $form.find('[name="shipping_province"]').val() || '';
        var shipping = 0;
        $('.order-charge-line-row[data-charge-type="shipping"] .js-' + prefix + '-charge-amount').each(function () {
            var v = parseFloat($(this).val() || 0);
            shipping += isNaN(v) ? 0 : v;
        });
        var $rows = $form.find('.js-' + prefix + '-lines-body .' + prefix + '-line-row');
        var lines = [];
        $rows.each(function () {
            var $row = $(this);
            var productId = parseInt($row.find('.js-' + prefix + '-product-select').val() || '0', 10);
            var qty = parseFloat($row.find('.js-' + prefix + '-qty').val() || '0');
            var price = parseFloat($row.find('.js-' + prefix + '-price').val() || '0');
            var subtotal = (isNaN(qty) ? 0 : qty) * (isNaN(price) ? 0 : price);
            lines.push({
                product_id: isNaN(productId) ? 0 : productId,
                qty: isNaN(qty) ? 0 : qty,
                subtotal: subtotal,
                tax_code: $row.find('.js-' + prefix + '-tax').val() || ''
            });
        });

        $.getJSON(url, { company_id: companyId, address_id: addressId, province: province, shipping: shipping, lines: JSON.stringify(lines) })
            .done(function (data) {
                var taxLines = data.lines || [];
                var $wrap = $form.find('.js-' + prefix + '-tax-lines-wrap');
                $wrap.empty();
                $.each(taxLines, function (i, line) {
                    // A manual adjustment has no rate — the admin typed a dollar amount, not a
                    // percentage — so it shows as a bare label rather than "Adjustment 0%".
                    var rate = line.rate === null || line.rate === undefined
                        ? ''
                        : ' ' + Math.round(parseFloat(line.rate) * 100) + '%';
                    $wrap.append(
                        $('<div>').append(
                            $('<span>').text(line.label + rate + ':'),
                            $('<strong>').text('$' + parseFloat(line.amount).toFixed(2))
                        )
                    );
                });

                $form.find('.js-' + prefix + '-tax-total').val(parseFloat(data.total || 0).toFixed(2));

                var perLineTax = data.perLineTax || {};
                $rows.each(function (index) {
                    var amount = perLineTax[index] || 0;
                    $(this).find('.js-' + prefix + '-line-tax').text('$' + parseFloat(amount).toFixed(2));
                });

                recalcSellDocTotals(prefix, SELL_DOC_OPTS[prefix]);
            });
    }

    function scheduleAdminTaxRefresh(prefix) {
        clearTimeout(_sellDocTaxRefreshTimer[prefix]);
        _sellDocTaxRefreshTimer[prefix] = setTimeout(function () {
            if (prefix === 'po' || prefix === 'bill') { refreshPurchaseDocumentTaxBreakdown(prefix); } else { refreshAdminSellDocTaxBreakdown(prefix); }
        }, 350);
    }

    function money(value) {
        var n = parseFloat(value || 0);
        if (isNaN(n)) { n = 0; }
        return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function numberFromMoney(value) {
        var n = parseFloat(String(value || '').replace(/[^0-9.-]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    /**
     * The one place the three documents' real differences are named (#full-parity, 2026-09-13).
     * Everything else about adding a line, adding a charge row, and recalculating totals is one
     * shared implementation now — see addSellDocLine()/addSellDocChargeRow()/recalcSellDocTotals().
     *
     *   supportsBatch  lot/serial UI — Order and Invoice fulfill real goods; Quote never does (#250).
     *   supportsTbd    "not priced/resolved yet" states — only a Quote can be saved before every
     *                  line and its shipping are known; Order and Invoice always require them.
     */
    var SELL_DOC_OPTS = {
        order: { supportsBatch: true, supportsTbd: false },
        invoice: { supportsBatch: true, supportsTbd: false },
        estimate: { supportsBatch: false, supportsTbd: true },
        // PurchaseOrder (#full-parity, 2026-09-15): same live charge-row editing/recalc Order's own
        // form uses, ported rather than left as a server-round-trip substitute. `shippingType`
        // is the one real vocabulary difference — the buy side's document-level charge is called
        // "freight", not "shipping" (PurchaseDocumentChargeLines::TYPE_FREIGHT), so every place
        // below that hardcoded the word 'shipping' now reads `opts.shippingType || 'shipping'`
        // instead, which is 'shipping' for every existing prefix and 'freight' only for 'po'.
        po: { supportsBatch: true, supportsTbd: false, shippingType: 'freight' },
        // VendorBill (#full-parity, 2026-09-14): same live charge-row editing/recalc as
        // PurchaseOrder's, which VendorBillController's own save() already converged its charge
        // model onto (PurchaseDocumentChargeLines, the same TYPE_FREIGHT vocabulary).
        bill: { supportsBatch: true, supportsTbd: false, shippingType: 'freight' },
    };

    /**
     * Add a product or blank line to a sell-side document's line table — Order, Invoice or Quote
     * (#full-parity, 2026-09-13: Order's own implementation, parameterized by `prefix` rather than
     * copied, per the owner's ruling that there is no justified difference between the three here).
     *
     * `prefix` names the document ('order' | 'invoice' | 'estimate') and drives every selector and
     * template id the same way the hand-written 'order-' versions always did — `.js-order-*` for
     * order, `.js-invoice-*` for invoice, `.js-estimate-*` for estimate. `opts.supportsBatch` gates
     * the lot/serial UI, real for Order and Invoice (both fulfill real goods) and never for Quote
     * (#250 — a quote allocates nothing, so a batch value on it would be speculative).
     */
    function addSellDocLine(prefix, type, $after, opts) {
        opts = opts || {};
        var template = document.getElementById(prefix + (type === 'blank' ? '-blank-line-template' : '-product-line-template'));
        var $body = $('.js-' + prefix + '-lines-body');
        if (!template || !$body.length) { return; }

        var html = template.innerHTML.replace(/__INDEX__/g, String(sellDocNextLineIndex(prefix)));
        var $row = $(html.trim());
        $body.find('.' + prefix + '-lines-empty').remove();

        if ($after && $after.length) {
            $after.after($row);
        } else {
            $body.append($row);
        }

        // No product is named yet on a freshly cloned row — blank rows never gain one, product
        // rows haven't had one picked — so the batch UI starts hidden either way (#670). The
        // template's own `hidden` attributes already say so; this is just the explicit statement
        // of it, in case a future template stops matching that default.
        if (opts.supportsBatch) {
            syncSellDocBatchVisibility($row, false);
            syncSellDocLotPicker($row, '', null);
        }

        $row.find('select.js-searchable-select').each(function () {
            initSearchableSelect($(this));
        });

        recalcSellDocLine(prefix, $row, opts);
        return $row;
    }

    /** Per-document running line-row index, read once off that document's form and cached. */
    var _sellDocLineIndex = {};
    function sellDocNextLineIndex(prefix) {
        if (!(prefix in _sellDocLineIndex)) {
            _sellDocLineIndex[prefix] = parseInt($('.js-' + prefix + '-form').attr('data-next-line-index'), 10) || 0;
        }
        return _sellDocLineIndex[prefix]++;
    }

    /**
     * Show or hide a line row's batch/lot capture UI (#670): batch (or serial) tracking is a
     * per-product attribute, so a row only offers it while its currently-picked product tracks
     * lots or serials outbound. Hiding also clears out any rows already added — this only ever
     * runs against a row whose product selection just changed, never against a row hydrated from
     * a saved line's own batch value (that path stays server-rendered, see sales_line_row.html.twig),
     * so there is nothing worth preserving on the way to a product that does not track it.
     */
    function syncSellDocBatchVisibility($row, tracksBatch) {
        var show = !!tracksBatch;
        $row.find('.js-batch-toggle').prop('hidden', !show);
        var $rows = $row.find('.js-batch-rows').prop('hidden', !show);
        if (!show) {
            $rows.empty();
            $row.find('.js-batch-combined').val('');
        }
    }

    /**
     * The real lot/serial picker (2026-09-14 lot/serial/expiry plan), shown alongside — never
     * instead of — the legacy batch box above. `trackingMode` is 'lot', 'serial', or '': the same
     * per-product derivation `tracksBatch` already reads (productTrackingMode(), via the picked
     * option's data-tracking-mode), one step more specific about which box applies.
     */
    var SELL_DOC_LOT_PICKER_URL = '/admin/bundles/inventory-depth/lots/available';

    function syncSellDocLotPicker($row, trackingMode, productId) {
        var $select = $row.find('.js-lot-select');
        var $serial = $row.find('.js-serial-input');

        $select.prop('hidden', trackingMode !== 'lot');
        $serial.prop('hidden', trackingMode !== 'serial');

        if (trackingMode === 'lot') {
            populateLotSelect($select, productId);
        } else {
            $select.empty().append('<option value="">Select a lot&hellip;</option>');
        }
    }

    /**
     * Fetches SELL_DOC_LOT_PICKER_URL for one product and fills a lot `<select>`, preserving
     * whichever value the row already carries (its own `data-selected`, stamped by
     * sales_line_row.html.twig from the saved line's lotId, or whatever the admin already picked).
     * A previously-picked lot that no longer comes back — fully drawn down since, or from another
     * product entirely — still renders as a selected option rather than silently reverting to
     * blank, so a save that does not touch this row cannot mistake "not shown" for "not picked".
     */
    var _lotOptionsCache = {};
    function populateLotSelect($select, productId) {
        productId = String(productId || '').trim();
        var selected = String($select.attr('data-selected') || $select.val() || '').trim();

        function render(lots) {
            var html = '<option value="">Select a lot&hellip;</option>';
            var found = false;
            (lots || []).forEach(function (lot) {
                var isSelected = String(lot.id) === selected;
                found = found || isSelected;
                html += '<option value="' + lot.id + '"' + (isSelected ? ' selected' : '') + '>'
                    + escapeHtml(lot.label) + ' (' + escapeHtml(String(lot.available)) + ' available)</option>';
            });
            if (selected && !found) {
                html += '<option value="' + escapeHtml(selected) + '" selected>Lot #' + escapeHtml(selected) + ' (no longer available)</option>';
            }
            $select.html(html);
            $select.removeAttr('data-selected');
        }

        if (!productId) {
            render([]);
            return;
        }

        if (_lotOptionsCache[productId]) {
            render(_lotOptionsCache[productId]);
            return;
        }

        $.getJSON(SELL_DOC_LOT_PICKER_URL, { product_id: productId })
            .done(function (lots) {
                _lotOptionsCache[productId] = lots || [];
                render(_lotOptionsCache[productId]);
            })
            .fail(function () {
                render([]);
            });
    }

    /** Re-populates every visible lot select already on the page — existing lines, on load. */
    function initSellDocLotPickers(prefix) {
        $('.js-' + prefix + '-lines-body .' + prefix + '-line-row').each(function () {
            var $row = $(this);
            var $select = $row.find('.js-lot-select');
            if (!$select.length || $select.prop('hidden')) { return; }
            populateLotSelect($select, $row.find('.js-' + prefix + '-product-select').val());
        });
    }

    function isIsoDateString(value) {
        return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value.trim());
    }

    /**
     * `prefix` picks the batch-cell class and row `<template>` — 'order' or 'invoice' only, the
     * two documents that fulfill real goods (#full-parity, 2026-09-13; Quote never calls this,
     * #250).
     */
    function hydrateSellDocBatchRows(prefix, $row, combined) {
        var raw = (combined || '').toString().trim();
        if (!raw) { return; }

        var $cell = $row.find('.' + prefix + '-batch-cell').first();
        var $container = $cell.find('.js-batch-rows').first();
        if (!$cell.length || !$container.length) { return; }

        var template = document.getElementById(prefix + '-batch-row-template');
        if (!template) { return; }

        var parts = raw.split(';').map(function (s) { return (s || '').trim(); }).filter(Boolean);
        if (!parts.length) { return; }

        $container.empty();

        parts.forEach(function (part, index) {
            var tokens = part.split(/\s+/).filter(Boolean);
            var date = '';
            if (tokens.length && isIsoDateString(tokens[tokens.length - 1])) {
                date = tokens.pop();
            }
            var label = tokens.join(' ');

            var $batchRow = $(template.innerHTML);
            if (index === 0) {
                $batchRow.find('.js-batch-remove').remove();
            }
            $batchRow.find('.js-batch-label').val(label);
            $batchRow.find('.js-batch-date').val(date);
            $container.append($batchRow);
        });
    }

    function serializeSellDocBatchCell(prefix, $cell) {
        var parts = [];
        $cell.find('.' + prefix + '-batch-row').each(function () {
            var label = ($(this).find('.js-batch-label').val() || '').toString().trim();
            var date = ($(this).find('.js-batch-date').val() || '').toString().trim();
            if (label || date) {
                parts.push(label + (date ? ' ' + date : ''));
            }
        });
        $cell.find('.js-batch-combined').val(parts.join('; '));
    }

    function hydrateSellDocBatchRowsForExistingLines(prefix) {
        $('.js-' + prefix + '-lines-body .' + prefix + '-line-row').each(function () {
            var $row = $(this);
            var combined = ($row.find('.js-batch-combined').val() || '').toString();
            if (combined.trim() !== '') {
                hydrateSellDocBatchRows(prefix, $row, combined);
            }
        });
    }

    /* The three words a charge row's type is allowed to be, matching SalesDocumentChargeLines (or,
       for 'po', PurchaseDocumentChargeLines — same three slots, "freight" instead of "shipping").
       Anything else is refused server-side rather than filed somewhere, so nothing is coerced here
       either — the select only ever produces these. */
    function normalizeChargeType(raw, shippingType) {
        var type = (raw || '').toString().toLowerCase();
        return (type === (shippingType || 'shipping') || type === 'fee') ? type : 'tax';
    }

    /* A tax or fee row asks for a slug. A shipping row becomes a line too, but its reporting key is
       derived from the label server-side, so there is nothing here for the admin to type.
       A fee row also carries the two things a Fee definition would have supplied: the tax class it
       is charged at and where in the totals it lands.

       Placement (#669) is never a control on the row itself any more: it used to be an editable
       select right here, and changing it after the row existed never actually moved the row — there
       was nothing that re-anchored it, so the choice only mattered on the NEXT save, silently. It is
       asked once, in the add-line bar, before the row exists, and travels with the row from there on
       as a hidden field — this function just carries it through, the same way it already carries
       slug and tax class. */
    var PLACEMENT_LABELS = { main_line: 'Main Line', before_tax_line: 'Before Tax', after_tax_line: 'After Tax' };

    function applySellDocChargeExtraInputs(prefix, $row, idx, type, values, shippingType) {
        var name = function (field) { return 'charge_lines[' + idx + '][' + field + ']'; };
        var $slug = $row.find('.js-' + prefix + '-charge-slug');
        var $taxClass = $row.find('.js-' + prefix + '-charge-tax-class');
        var $placementLabel = $row.find('.order-charge-placement-label');

        if (type === (shippingType || 'shipping')) {
            $slug.remove();
        } else {
            $slug.show().attr('name', name('slug')).val((values && values.slug) || '');
        }

        if (type !== 'fee') {
            $taxClass.remove();
            $placementLabel.remove();
            return;
        }
        $taxClass.show().attr('name', name('taxClass')).val((values && values.taxClass) || 'E');
        var placement = (values && values.placement) || 'main_line';
        $row.append($('<input type="hidden">').attr('name', name('placement')).val(placement));
        // States the same choice in plain text — see the template's own note on this span for why:
        // Main Line and Before Tax both anchor at the same spot, and nothing else on the row says
        // which one this is.
        $placementLabel.text(PLACEMENT_LABELS[placement] || placement).prop('hidden', false);
    }

    /* A fee row's placement decides where its charge lands in the totals — as part of the
       line-item subtotal (Main Line) or as a flat addition before/after tax is applied — and only
       an After Tax fee gets its own anchor point (#669): Main Line and Before Tax both feed the
       same pre-tax total today, so both still anchor beside Subtotal. #full-parity: one shared
       function, `prefix`-selected, since Order/Invoice/Quote all anchor a charge row the same way. */
    function sellDocChargeRowAnchor(prefix, type, placement) {
        // A Main Line fee anchors directly under the line-items table (sales_lines_table.html.twig
        // renders the marker) rather than beside Subtotal — it is conceptually part of what is
        // being billed, not a flat addition the way Before Tax/After Tax are. Falls through to the
        // old Subtotal anchor when the marker isn't there (a document type that hasn't been given
        // one, e.g. the purchase order form, which this function is also shared with).
        if (type === 'fee' && placement === 'main_line') {
            var $mainlineAnchor = $('.js-' + prefix + '-mainline-fee-anchor');
            if ($mainlineAnchor.length) {
                return $mainlineAnchor;
            }
        }
        var isAfterTaxFee = type === 'fee' && placement === 'after_tax_line';
        return (type === 'tax' || isAfterTaxFee)
            ? $('.js-' + prefix + '-total-grand').closest('div')
            : $('.js-' + prefix + '-total-before-tax').closest('div');
    }

    /**
     * Rebuilds a document's editable charge rows from its own stored charges JSON, once at page
     * load — the `<noscript>` blocks in the totals footer hide the server-rendered rows from a
     * JS-enabled browser, and this is what shows them instead, exactly as clickable rows rather
     * than as plain text. #full-parity: same function for Order, Invoice and Quote — `dataAttr`
     * names which `data-*-charges` attribute the document's own form carries.
     */
    function hydrateSellDocChargeLinesFromData(prefix) {
        var $form = $('.js-' + prefix + '-form').first();
        if (!$form.length) return;

        var raw = $form.attr('data-' + prefix + '-charges');
        if (!raw) return;

        var charges;
        try {
            charges = JSON.parse(raw);
        } catch (e) {
            return;
        }
        if (!Array.isArray(charges) || !charges.length) return;

        var template = document.getElementById(prefix + '-charge-row-template');
        if (!template) return;

        var shipType = (SELL_DOC_OPTS[prefix] && SELL_DOC_OPTS[prefix].shippingType) || 'shipping';

        var builtIn = {};
        $('.js-' + prefix + '-charge-type option').each(function () {
            var v = ($(this).val() || '').toString();
            if (!v) { return; }
            var colonIdx = v.indexOf(':');
            var kind = colonIdx === -1 ? v : v.substring(0, colonIdx);
            var label = colonIdx === -1 ? '' : v.substring(colonIdx + 1);
            if (kind === 'shipping-method') {
                var pipeIdx = label.lastIndexOf('|');
                var methodName = pipeIdx === -1 ? label : label.substring(0, pipeIdx);
                label = 'Shipping (' + methodName + ')';
            }
            if (kind !== 'empty' && kind !== 'empty-' + shipType && kind !== 'empty-tax' && kind !== 'empty-fee' && label) {
                builtIn[label] = true;
            }
        });

        charges.forEach(function (ch) {
            if (!ch || typeof ch !== 'object') return;
            var label = (ch.label || '').toString();
            var amount = parseFloat(ch.amount || 0);
            if (isNaN(amount)) amount = 0;
            var type = normalizeChargeType(ch.type, shipType);

            var isShipping = type === shipType;
            var isNamedShipping = isShipping && (label === 'Custom Shipping' || label === 'Custom Freight' || /^Shipping \(.+\)$/.test(label));
            var $anchor = sellDocChargeRowAnchor(prefix, type, (ch.placement || 'main_line').toString());
            if (!$anchor.length) return;

            var idx = sellDocNextChargeIndex(prefix);
            var $row = $(template.innerHTML);
            $row.attr('data-charge-type', type);
            if (isNamedShipping) {
                $row.attr('data-charge-kind', 'named');
            }

            if (builtIn[label]) {
                $row.find('.order-charge-label-text').text(label);
                $row.find('.order-charge-label-input').remove();
                $row.append($('<input type="hidden">').attr('name', 'charge_lines[' + idx + '][label]').val(label));
            } else {
                $row.find('.order-charge-label-text').hide();
                $row.find('.order-charge-label-input')
                    .show()
                    .attr('name', 'charge_lines[' + idx + '][label]')
                    .val(label);
            }

            $row.find('.js-' + prefix + '-charge-amount')
                .attr('name', 'charge_lines[' + idx + '][amount]')
                .val(String(amount));
            applySellDocChargeExtraInputs(prefix, $row, idx, type, {
                slug: (ch.slug || '').toString(),
                taxClass: (ch.taxClass || '').toString(),
                placement: (ch.placement || '').toString()
            }, shipType);
            $row.append($('<input type="hidden">').attr('name', 'charge_lines[' + idx + '][type]').val(type));

            $anchor.before($row);
        });
    }

    /** Per-document running charge-row index, read once off that document's form and cached. */
    var _sellDocChargeIndex = {};
    function sellDocNextChargeIndex(prefix) {
        if (!(prefix in _sellDocChargeIndex)) {
            _sellDocChargeIndex[prefix] = parseInt($('.js-' + prefix + '-form').attr('data-next-charge-index'), 10) || 0;
        }
        return _sellDocChargeIndex[prefix]++;
    }

    function recalcSellDocLine(prefix, $row, opts) {
        opts = opts || {};
        var priceRaw = String($row.find('.js-' + prefix + '-price').val() || '').trim();
        // A blank price is a real state on a Quote ("TBD", not priced yet) — never true for Order
        // or Invoice, which always require a number and default a blank one to 0.
        if (opts.supportsTbd && priceRaw === '') {
            $row.find('.js-' + prefix + '-subtotal').text('TBD');
        } else {
            var qty = parseFloat($row.find('.js-' + prefix + '-qty').val() || 0);
            var price = parseFloat(priceRaw);
            if (isNaN(qty)) { qty = 0; }
            if (isNaN(price)) { price = 0; }
            $row.find('.js-' + prefix + '-subtotal').text(money(qty * price));
        }
        recalcSellDocTotals(prefix, opts);
    }

    /**
     * Subtotal/Total Tax/Grand Total, live from whatever is currently on screen — one shared
     * function for Order, Invoice and Quote (#full-parity, 2026-09-13). `opts.supportsTbd` is the
     * one real difference: only a Quote can be saved before every line is priced and shipping is
     * resolved (Order and Invoice always require both), so only it ever shows "TBD" instead of a
     * dollar figure — added as a condition on this same math, per the owner's ruling, not a
     * separate calculation. Adding a real shipping charge clears a pending Shipping row the same
     * way pricing the last line clears a pending Subtotal: both are just "is there any absence to
     * still report" checks against data already being summed here.
     */
    function recalcSellDocTotals(prefix, opts) {
        opts = opts || {};
        var shipType = opts.shippingType || 'shipping';
        var chargeRowSelector = opts.shippingType ? '.purchase-charge-row' : '.order-charge-line-row';
        var pendingLines = 0;
        var subtotal = 0;
        $('.js-' + prefix + '-lines-body .js-' + prefix + '-subtotal').each(function () {
            var text = String($(this).text()).trim();
            if (opts.supportsTbd && text === 'TBD') { pendingLines++; return; }
            subtotal += numberFromMoney(text);
        });

        var $shippingRows = $(chargeRowSelector + '[data-charge-type="' + shipType + '"] .js-' + prefix + '-charge-amount');
        var shippingCharges = 0;
        $shippingRows.each(function () {
            var v = parseFloat($(this).val() || 0);
            shippingCharges += isNaN(v) ? 0 : v;
        });
        // Only meaningful where TBD applies at all: Order and Invoice always require shipping to
        // be typed before the row can exist, so there is never a "missing" state to report.
        var shippingPending = opts.supportsTbd && $shippingRows.length === 0;

        var taxCharges = 0;
        $(chargeRowSelector + '[data-charge-type="tax"] .js-' + prefix + '-charge-amount').each(function () {
            var v = parseFloat($(this).val() || 0);
            taxCharges += isNaN(v) ? 0 : v;
        });

        // The manual fee rows are summed from the rows themselves, since the admin is editing them
        // live; .js-{prefix}-fee-total carries only the calculated lines, which have no row on screen.
        var feeCharges = 0;
        $(chargeRowSelector + '[data-charge-type="fee"] .js-' + prefix + '-charge-amount').each(function () {
            var v = parseFloat($(this).val() || 0);
            feeCharges += isNaN(v) ? 0 : v;
        });

        var tax = parseFloat($('.js-' + prefix + '-tax-total').val() || 0) || 0;
        var feeTotal = parseFloat($('.js-' + prefix + '-fee-total').val() || 0) || 0;
        var beforeTax = subtotal + shippingCharges + feeTotal + feeCharges;
        var grand = beforeTax + tax + taxCharges;
        var isPending = opts.supportsTbd && (pendingLines > 0 || shippingPending);

        $('.js-' + prefix + '-total-subtotal').text(money(subtotal));
        $('.js-' + prefix + '-total-before-tax').text(isPending ? 'TBD' : money(beforeTax));
        $('.js-' + prefix + '-total-tax').text(isPending ? 'TBD' : money(tax));
        $('.js-' + prefix + '-total-grand').text(isPending ? 'TBD' : money(grand));

        if (opts.supportsTbd) {
            $('.js-' + prefix + '-total-shipping').text(shippingPending ? 'TBD' : money(shippingCharges));
            $('.js-' + prefix + '-total-pending-count').text(String(pendingLines));
            $('.js-' + prefix + '-total-pending').prop('hidden', pendingLines === 0);
        }
    }

    // Add Product also opens the new line's own product search and focuses it (#707) — the same
    // searchable-select every line already has, whose own open() does the focusing.
    $(document).on('click', '.js-order-add-product', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $row = addSellDocLine('order', 'product', null, SELL_DOC_OPTS.order);
        scheduleShippingRefresh();
        scheduleAdminFeeRefresh('order');
        scheduleAdminTaxRefresh('order');
        if ($row) { $row.find('.ss-trigger').trigger('click'); }
    });

    $(document).on('click', '.js-order-add-blank', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        addSellDocLine('order', 'blank', null, SELL_DOC_OPTS.order);
    });

    // Order lines are server-rendered; JS only hydrates the batch sub-row widget on top of them
    if ($('.js-order-form').length) {
        hydrateSellDocBatchRowsForExistingLines('order');
        initSellDocLotPickers('order');
        hydrateSellDocChargeLinesFromData('order');
        recalcSellDocTotals('order', SELL_DOC_OPTS.order);
        scheduleAdminFeeRefresh('order');
        scheduleAdminTaxRefresh('order');
    }

    /* ── Order edit-page staleness check (#417) ───────────────────────────────────────────
     * A heads-up, not the enforcement: OrderController::edit() already refuses a stale submit
     * outright (the version round-trips through .js-order-version, a hidden field). This just
     * lets an admin who has left the page open find out BEFORE they finish editing and hit Save,
     * by polling a lightweight endpoint that returns nothing but the order's current version.
     *
     * Two triggers feed the same check, per #417's spec, both needed:
     *   - a timer, every 2 minutes;
     *   - any click on the page, but debounced — skipped if the last ping (from EITHER trigger)
     *     was under 10 seconds ago, so a click right after the timer fires (or after another
     *     click) doesn't turn this into a ping-per-click.
     * Once a check finds the order stale, polling stops for good (clearInterval, and the click
     * handler's own isStale guard) and the banner it shows stays up — there is nothing left to
     * learn by asking again.
     */
    (function () {
        var $versionField = $('.js-order-version');
        if (!$versionField.length) { return; }

        var checkUrl = $versionField.data('version-check-url');
        var knownVersion = parseInt($versionField.val(), 10);
        if (!checkUrl || isNaN(knownVersion)) { return; }

        var PING_INTERVAL_MS = 2 * 60 * 1000;
        var CLICK_DEBOUNCE_MS = 10 * 1000;
        var lastPingAt = 0;
        var isStale = false;
        var timer = null;

        function showStaleBanner() {
            if ($('.js-order-stale-banner').length) { return; }
            $('<div class="form-error-banner js-order-stale-banner"></div>')
                .text('This order was changed by someone else since you opened this page. Saving now would be rejected and could overwrite their changes — reload the page before making further edits.')
                .prependTo('.js-order-form');
        }

        function checkVersion() {
            lastPingAt = Date.now();
            $.getJSON(checkUrl).done(function (data) {
                if (isStale || !data || data.found === false) { return; }
                if (parseInt(data.version, 10) !== knownVersion) {
                    isStale = true;
                    showStaleBanner();
                    if (timer !== null) {
                        clearInterval(timer);
                        timer = null;
                    }
                }
            });
        }

        timer = setInterval(function () {
            if (isStale) { return; }
            checkVersion();
        }, PING_INTERVAL_MS);

        $(document).on('click', function () {
            if (isStale) { return; }
            if (Date.now() - lastPingAt < CLICK_DEBOUNCE_MS) { return; }
            checkVersion();
        });
    })();

    /**
     * Which document's batch-row template/hook classes a clicked "+ " button answers for
     * (#full-parity, 2026-09-13): 'order', 'invoice', 'po' or 'bill', read off the button's own
     * `js-{prefix}-add-after` class rather than a hasClass() per prefix that would need editing
     * again the next time a document grows a batch cell.
     */
    function sellDocOrPurchaseBatchPrefixOfButton($button) {
        var match = /\bjs-([a-z]+)-add-after\b/.exec($button.attr('class') || '');
        return match ? match[1] : 'order';
    }

    $(document).on('click', '.js-order-add-after, .js-invoice-add-after, .js-po-add-after, .js-bill-add-after', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var prefix = sellDocOrPurchaseBatchPrefixOfButton($(this));
        var template = document.getElementById(prefix + '-batch-row-template');
        if (!template) { return; }
        $(this).closest('tr').find('.js-batch-rows').append($(template.innerHTML));
    });

    /**
     * Drops a new editable shipping/tax/fee row into a document's totals footer — one shared
     * implementation for Order, Invoice and Quote (#full-parity, 2026-09-13), Order's own logic
     * verbatim including the shipping-method preset parsing and the placement choice for a fee.
     */
    function addSellDocChargeRow(prefix) {
        var opts = SELL_DOC_OPTS[prefix] || {};
        var shipType = opts.shippingType || 'shipping';
        var chargeRowSelector = opts.shippingType ? '.purchase-charge-row' : '.order-charge-line-row';
        var rawType = $('.js-' + prefix + '-charge-type').val();
        if (!rawType) {
            showToast('Please select a line type first.', 'error');
            return;
        }
        var template = document.getElementById(prefix + '-charge-row-template');
        if (!template) { return; }

        var colonIdx = String(rawType).indexOf(':');
        var kind = colonIdx === -1 ? String(rawType) : String(rawType).substring(0, colonIdx);
        var rest = colonIdx === -1 ? '' : String(rawType).substring(colonIdx + 1);
        var isShippingMethod = kind === 'shipping-method';
        // Named methods and Custom Shipping (or, on a purchase order, the plain "Freight" preset)
        // represent "the" document's shipping — only one can exist at a time. Empty Shipping Line
        // is just a manual extra (rush fee, etc.) that stacks alongside it, exactly like Empty Tax
        // Line does on the tax side.
        var isNamedShipping = isShippingMethod || kind === shipType;
        var isShipping = isNamedShipping || kind === 'empty-' + shipType;
        // 'fee' (#full-parity, 2026-09-15, GitHub #690): PurchaseOrder's own fixed-label fee
        // presets (Duty, Brokerage, Fuel Surcharge — see _purchase_add_line_bar.html.twig, which
        // has no sell-side equivalent since the sell side's only fee option is free-text
        // 'empty-fee'). Without this, kind === 'fee' fell through to the tax branch below and a
        // preset fee silently saved as a tax line instead.
        var isEmptyFee = kind === 'empty-fee';
        var isFee = isEmptyFee || kind === 'fee';
        var isEmpty = kind === 'empty' || kind === 'empty-' + shipType || kind === 'empty-tax' || isEmptyFee;
        var rowType = isShipping ? shipType : (isFee ? 'fee' : 'tax');
        var label = rest;
        var presetAmount = null;

        if (isShippingMethod) {
            // value is "shipping-method:Label|Amount" — the amount is computed server-side
            // once, at the moment this option was rendered; it's just a normal editable
            // number from here on, nothing keeps it in sync afterward.
            var pipeIdx = rest.lastIndexOf('|');
            var methodName = pipeIdx === -1 ? rest : rest.substring(0, pipeIdx);
            label = 'Shipping (' + methodName + ')';
            presetAmount = pipeIdx === -1 ? null : parseFloat(rest.substring(pipeIdx + 1));
        }

        // #669: asked once, here, rather than on the row after it exists — see
        // applySellDocChargeExtraInputs() for why an editable placement select never belonged there.
        var placement = 'main_line';
        if (isFee) {
            var $placementChoice = $('.js-' + prefix + '-charge-placement-choice');
            placement = String($placementChoice.val() || 'main_line');
        }
        var $anchor = sellDocChargeRowAnchor(prefix, rowType, placement);
        if (!$anchor.length) { return; }

        // Only one *named* shipping/freight charge can represent "the" document's shipping at a
        // time — this must not remove a manually-added Empty Shipping/Freight Line, which stacks
        // freely.
        if (isNamedShipping) {
            $(chargeRowSelector + '[data-charge-type="' + shipType + '"][data-charge-kind="named"]').remove();
        }

        var idx = sellDocNextChargeIndex(prefix);
        var $row = $(template.innerHTML);
        $row.attr('data-charge-type', rowType);
        if (isNamedShipping) {
            $row.attr('data-charge-kind', 'named');
        }

        if (isEmpty) {
            $row.find('.order-charge-label-text').hide();
            $row.find('.order-charge-label-input').show().attr('name', 'charge_lines[' + idx + '][label]');
        } else {
            $row.find('.order-charge-label-text').text(label);
            $row.find('.order-charge-label-input').remove();
            $row.append($('<input type="hidden">').attr('name', 'charge_lines[' + idx + '][label]').val(label));
        }
        $row.find('.js-' + prefix + '-charge-amount').attr('name', 'charge_lines[' + idx + '][amount]');
        if (presetAmount !== null && !isNaN(presetAmount)) {
            $row.find('.js-' + prefix + '-charge-amount').val(presetAmount.toFixed(2));
        }
        applySellDocChargeExtraInputs(prefix, $row, idx, rowType, { placement: placement }, shipType);
        $row.append($('<input type="hidden">').attr('name', 'charge_lines[' + idx + '][type]').val(rowType));

        $anchor.before($row);
        $('.js-' + prefix + '-charge-type').val('');
        $('.js-' + prefix + '-charge-placement-choice').val('main_line').prop('hidden', true);
        recalcSellDocTotals(prefix, SELL_DOC_OPTS[prefix]);
    }

    $(document).on('click', '.js-order-bottom-add', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        addSellDocChargeRow('order');
    });

    $(document).on('click', '.js-order-remove-charge', function (event) {
        event.preventDefault();
        $(this).closest('.order-charge-line-row').remove();
        recalcSellDocTotals('order', SELL_DOC_OPTS.order);
    });

    /* #669: the Tax/Fee/Shipping category picker in front of the full add-line select. It filters
       that select's own <option>s rather than replacing them, so a no-JS admin — who never sees
       this control at all (it is .js-only) — still gets the exact same full list. */
    function chargeTypeCategory(rawValue) {
        var value = String(rawValue || '');
        var colonIdx = value.indexOf(':');
        var kind = colonIdx === -1 ? value : value.substring(0, colonIdx);
        if (kind === 'shipping-method' || kind === 'shipping' || kind === 'empty-shipping') { return 'shipping'; }
        // PurchaseOrder's own word for the same role (#full-parity, 2026-09-15) — see
        // SELL_DOC_OPTS.po.shippingType — files under the same 'shipping' filter category so one
        // category select works for both vocabularies.
        if (kind === 'freight' || kind === 'empty-freight') { return 'shipping'; }
        if (kind === 'tax' || kind === 'empty-tax') { return 'tax'; }
        // 'fee', not only 'empty-fee' (#full-parity, 2026-09-15, GitHub #690): PurchaseOrder's
        // own fixed-label fee presets (Duty, Brokerage, Fuel Surcharge) are kind 'fee', not
        // 'empty-fee' — without this, picking "Fee" in the category filter hid them entirely.
        if (kind === 'fee' || kind === 'empty-fee') { return 'fee'; }
        return '';
    }

    function applySellDocChargeCategoryFilter(prefix, $category) {
        var category = String($category.val() || '');
        // '.order-add-line-bar' is the sell side's own bar wrapper class, shared verbatim by
        // order/estimate/invoice; PurchaseOrder's is '.purchase-charge-bar' (#full-parity,
        // 2026-09-15) — both are searched rather than keying the wrapper class off `prefix`
        // itself, since 'order'/'estimate'/'invoice' all already agree on the one name.
        var $select = $category.closest('.order-add-line-bar, .purchase-charge-bar').find('.js-' + prefix + '-charge-type');
        $select.find('option').each(function () {
            var $opt = $(this);
            if (!$opt.val()) { return; }
            $opt.prop('hidden', !!category && chargeTypeCategory($opt.val()) !== category);
        });
    }

    $(document).on('change', '.js-order-charge-category', function () {
        applySellDocChargeCategoryFilter('order', $(this));
        $(this).closest('.order-add-line-bar').find('.js-order-charge-type').val('');
        $(this).closest('.order-add-line-bar').find('.js-order-charge-placement-choice').val('main_line').prop('hidden', true);
    });

    // The placement picker only means anything for a fee, and it has to appear whether the admin
    // reached "Empty Fee Line" through the category filter above or straight off the full select.
    $(document).on('change', '.js-order-charge-type', function () {
        var isFee = chargeTypeCategory($(this).val()) === 'fee';
        $(this).closest('.order-add-line-bar').find('.js-order-charge-placement-choice').prop('hidden', !isFee);
    });

    // Freshly-fetched shipping-method <option>s carry no hidden state of their own; if a category
    // is already picked, re-apply it so they don't leak into an unrelated filtered view.
    $(document).on('order:shipping-options-refreshed', '.js-order-charge-type', function () {
        var $category = $(this).closest('.order-add-line-bar').find('.js-order-charge-category');
        if ($category.val()) { applySellDocChargeCategoryFilter('order', $category); }
    });

    $(document).on('input change', '.js-order-charge-amount', function () {
        recalcSellDocTotals('order', SELL_DOC_OPTS.order);
    });

    /**
     * Which document a batch cell belongs to — 'order', 'invoice', 'po' or 'bill' (#full-parity,
     * 2026-09-13) — read generically off its own `{prefix}-batch-cell` class rather than one
     * hasClass() per document, which is what made this list of four instead of two: a fifth
     * document's batch cell needs no edit here at all.
     */
    function sellDocBatchPrefixOf($el) {
        var $cell = $el.hasClass('order-batch-cell') || $el.hasClass('invoice-batch-cell')
            || $el.hasClass('po-batch-cell') || $el.hasClass('bill-batch-cell')
            ? $el
            : $el.closest('.order-batch-cell, .invoice-batch-cell, .po-batch-cell, .bill-batch-cell');
        var match = /\b([a-z]+)-batch-cell\b/.exec($cell.attr('class') || '');
        return match ? match[1] : 'order';
    }

    var SELL_DOC_BATCH_CELL_SELECTOR = '.order-batch-cell, .invoice-batch-cell, .po-batch-cell, .bill-batch-cell';
    var SELL_DOC_BATCH_ROW_SELECTOR = '.order-batch-row, .invoice-batch-row, .po-batch-row, .bill-batch-row';

    /* ── Batch rows: remove ────────────────────────────────────────── */
    $(document).on('click', '.js-batch-remove', function () {
        var $cell = $(this).closest(SELL_DOC_BATCH_CELL_SELECTOR);
        $(this).closest(SELL_DOC_BATCH_ROW_SELECTOR).remove();
        if ($cell.length) {
            serializeSellDocBatchCell(sellDocBatchPrefixOf($cell), $cell);
        }
    });

    /* ── Batch rows: serialize into hidden field before submit ─────── */
    $(document).on('submit', '.js-order-form, .js-invoice-form, .js-po-form, .js-bill-form', function () {
        var $form = $(this);
        $form.find(SELL_DOC_BATCH_CELL_SELECTOR).each(function () {
            serializeSellDocBatchCell(sellDocBatchPrefixOf($(this)), $(this));
        });
    });

    $(document).on('input change', '.order-batch-cell .js-batch-label, .order-batch-cell .js-batch-date, .invoice-batch-cell .js-batch-label, .invoice-batch-cell .js-batch-date, .po-batch-cell .js-batch-label, .po-batch-cell .js-batch-date, .bill-batch-cell .js-batch-label, .bill-batch-cell .js-batch-date', function () {
        var $cell = $(this).closest(SELL_DOC_BATCH_CELL_SELECTOR);
        if ($cell.length) {
            serializeSellDocBatchCell(sellDocBatchPrefixOf($cell), $cell);
        }
    });

    /**
     * Re-syncs a PO/Bill row's batch UI visibility when its product changes (#full-parity,
     * 2026-09-13) — the buy-side half of the sell side's own product-select handler
     * (syncSellDocBatchVisibility($row, $selected.data('tracksBatch'))): the picked option's
     * data-tracks-batch attribute answers ProductCore::isTracksBatchInbound() for whichever product
     * was just selected, via the `tracks-batch` key in _purchase_line_row.html.twig's optionData.
     */
    $(document).on('change', '.js-po-product-select, .js-bill-product-select', function () {
        var $selected = $(this).find('option:selected');
        var $row = $(this).closest('tr');
        syncSellDocBatchVisibility($row, $selected.data('tracksBatch'));
    });

    /* ── Product-edit shortcut: keep the row's link pointing at its current product ── */
    function syncSellDocProductEditLink(prefix, $row) {
        var $link = $row.find('.js-' + prefix + '-search-product');
        if (!$link.length) { return; }

        var template = String($link.attr('data-product-edit-url-template') || '');
        var productId = String($row.find('.js-' + prefix + '-product-select').val() || '').trim();

        if (!template || !productId) {
            $link.removeAttr('href').attr('hidden', 'hidden');
            return;
        }

        $link.attr('href', template.replace('__PRODUCT_ID__', encodeURIComponent(productId))).removeAttr('hidden');
    }

    $(document).on('click', '.js-order-toggle-address-book', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $panel = $(this).closest('[data-address-panel]');
        var $book = $panel.find('.js-order-address-book');
        $('.js-order-address-book').not($book).prop('hidden', true);
        $book.prop('hidden', !$book.prop('hidden'));
    });

    $(document).on('click', '.js-order-close-address-book', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        $(this).closest('.js-order-address-book').prop('hidden', true);
    });

    $(document).on('click', '.js-order-apply-address', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $book = $(this).closest('.js-order-address-book');
        var $panel = $(this).closest('[data-address-panel]');
        var $selected = $book.find('.js-order-address-select option:selected');
        if (!$selected.val()) {
            showToast('Please select an address first.', 'error');
            return;
        }

        var map = {
            companyName: 'data-company-name',
            firstName: 'data-first-name',
            lastName: 'data-last-name',
            phone: 'data-phone',
            addressLine1: 'data-address-line1',
            addressLine2: 'data-address-line2',
            city: 'data-city',
            country: 'data-country',
            province: 'data-province',
            postalCode: 'data-postal-code',
            fax: 'data-fax',
            primaryEmail: 'data-primary-email',
            secondaryEmail: 'data-secondary-email',
            id: 'value'
        };

        Object.keys(map).forEach(function (field) {
            var $input = $panel.find('[data-address-field="' + field + '"]');
            var val = (field === 'id') ? $selected.val() : ($selected.attr(map[field]) || '');
            $input.val(val).trigger('change');
        });

        $book.prop('hidden', true);
        showToast('Address applied.', 'success');
    });

    /* Country -> province repopulation for the admin order address panels is handled by the shared
       .js-region-* handler above, which sources its list from geo_country/geo_province. The 63-entry
       REGION_MAP literal and its two handlers that used to live here duplicated that list in
       display-name form, and would have fought the shared handler for the same selects. */

    $(document).on('click', '.js-expand-address', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $short = $(this).closest('.js-address-short');
        var $full = $short.siblings('.js-address-full');
        $short.hide();
        $full.show();
    });

    $(document).on('click', '.js-order-remove-line', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $body = $('.js-order-lines-body');
        var $thisRow = $(this).closest('tr');

        $thisRow.remove();
        if ($body.children('tr').not('.no-js-row, .order-lines-empty').length === 0) {
            $body.append('<tr class="order-lines-empty"><td colspan="14">No order lines yet. Use Add Product or Add Blank Line to start.</td></tr>');
        }
        recalcSellDocTotals('order', SELL_DOC_OPTS.order);
        scheduleShippingRefresh();
        scheduleAdminFeeRefresh('order');
        scheduleAdminTaxRefresh('order');
    });

    $(document).on('change', '.js-order-product-select', function () {
        var $selected = $(this).find(':selected');
        var $row = $(this).closest('tr');
        $row.find('.js-order-sku').val($selected.data('sku') || '');
        $row.find('.js-order-weight').val($selected.data('weight') || '');
        $row.find('.js-order-unit').val($selected.data('unit') || '');
        $row.find('.js-order-tax').val($selected.data('tax-code') || '');
        $row.find('.js-order-cost').val($selected.data('cost') || '');
        $row.find('.js-order-original').text(money($selected.data('original-price') || 0));
        $row.find('.js-order-price').val($selected.data('price') || '');
        syncSellDocBatchVisibility($row, $selected.data('tracksBatch'));
        syncSellDocLotPicker($row, $selected.data('trackingMode'), $selected.val());
        syncSellDocProductEditLink('order', $row);
        recalcSellDocLine('order', $row, SELL_DOC_OPTS.order);
        scheduleShippingRefresh();
        scheduleAdminFeeRefresh('order');
        scheduleAdminTaxRefresh('order');
    });

    $(document).on('input change', '.js-order-qty, .js-order-price', function () {
        recalcSellDocLine('order', $(this).closest('tr'), SELL_DOC_OPTS.order);
        scheduleAdminTaxRefresh('order');
        if ($(this).hasClass('js-order-qty')) {
            scheduleShippingRefresh();
            scheduleAdminFeeRefresh('order');
        }
    });

    $(document).on('change', '.js-order-tax', function () {
        scheduleAdminTaxRefresh();
    });

    /* ── Admin estimate line table ─────────────────────────────────────────────────────────
       Quote used to keep its own separate copy of every one of these — add-line, add-charge-row,
       recalc — with a genuinely different charge-row insertion mechanism from Order's (a fixed
       container Quote appended into directly, vs Order's anchor-lookup + <noscript> + hydrate).
       Neither the owner nor this session could find a spec reason for that difference; it was
       drift from being built separately, not a decision (#full-parity, 2026-09-13: "so u coudl
       actually just use sales order implementation as starting point + TBD"). Quote now calls the
       exact same shared functions Order and Invoice do — SELL_DOC_OPTS.estimate turns on the one
       genuine difference (TBD), and turns off the other (batch, #250). Its Twig template was moved
       onto the same <noscript>+data-estimate-charges hydrate pattern Order's already uses, so
       addSellDocChargeRow/hydrateSellDocChargeLinesFromData/sellDocChargeRowAnchor need nothing
       estimate-specific at all. */

    // Add Product also opens the new line's own product search and focuses it (#707) — see the
    // '.js-order-add-product' handler above.
    $(document).on('click', '.js-estimate-add-product', function (event) {
        event.preventDefault();
        var $row = addSellDocLine('estimate', 'product', null, SELL_DOC_OPTS.estimate);
        scheduleAdminFeeRefresh('estimate');
        scheduleAdminTaxRefresh('estimate');
        if ($row) { $row.find('.ss-trigger').trigger('click'); }
    });

    $(document).on('click', '.js-estimate-add-blank', function (event) {
        event.preventDefault();
        addSellDocLine('estimate', 'blank', null, SELL_DOC_OPTS.estimate);
    });

    // No .js-estimate-add-after handler: the quote's per-row "+" was removed in #245 because it
    // looked like order's batch "+" and cloned a whole product line instead. addSellDocLine()
    // still takes an anchor row — the bottom Add Line controls above use it without one.

    $(document).on('click', '.js-estimate-remove-line', function (event) {
        event.preventDefault();
        var $body = $('.js-estimate-lines-body');
        $(this).closest('tr').remove();
        if ($body.children('tr').not('.no-js-row, .estimate-lines-empty').length === 0) {
            var colCount = $body.closest('table').find('thead th').length || 1;
            $body.append('<tr class="estimate-lines-empty"><td colspan="' + colCount + '">No quote lines yet. Use Add Product or Add Blank Line to start.</td></tr>');
        }
        recalcSellDocTotals('estimate', SELL_DOC_OPTS.estimate);
        scheduleAdminFeeRefresh('estimate');
        scheduleAdminTaxRefresh('estimate');
    });

    $(document).on('change', '.js-estimate-product-select', function () {
        var $selected = $(this).find(':selected');
        var $row = $(this).closest('tr');
        $row.find('.js-estimate-sku').val($selected.data('sku') || '');
        $row.find('.js-estimate-weight').val($selected.data('weight') || '');
        $row.find('.js-estimate-unit').val($selected.data('unit') || '');
        $row.find('.js-estimate-tax').val($selected.data('tax-code') || '');
        $row.find('.js-estimate-cost').val($selected.data('cost') || '');
        $row.find('.js-estimate-original').text(money($selected.data('original-price') || 0));
        $row.find('.js-estimate-price').val($selected.data('original-price') || '');
        syncSellDocProductEditLink('estimate', $row);
        recalcSellDocLine('estimate', $row, SELL_DOC_OPTS.estimate);
        scheduleAdminFeeRefresh('estimate');
        scheduleAdminTaxRefresh('estimate');
    });

    $(document).on('input change', '.js-estimate-qty, .js-estimate-price', function () {
        recalcSellDocLine('estimate', $(this).closest('tr'), SELL_DOC_OPTS.estimate);
        scheduleAdminTaxRefresh('estimate');
        if ($(this).hasClass('js-estimate-qty')) {
            scheduleAdminFeeRefresh('estimate');
        }
    });

    $(document).on('click', '.js-estimate-bottom-add', function (event) {
        event.preventDefault();
        addSellDocChargeRow('estimate');
    });

    $(document).on('click', '.js-estimate-remove-charge', function (event) {
        event.preventDefault();
        $(this).closest('.order-charge-line-row').remove();
        recalcSellDocTotals('estimate', SELL_DOC_OPTS.estimate);
    });

    $(document).on('change', '.js-estimate-charge-category', function () {
        applySellDocChargeCategoryFilter('estimate', $(this));
        $(this).closest('.order-add-line-bar').find('.js-estimate-charge-type').val('');
        $(this).closest('.order-add-line-bar').find('.js-estimate-charge-placement-choice').val('main_line').prop('hidden', true);
    });

    $(document).on('change', '.js-estimate-charge-type', function () {
        var isFee = chargeTypeCategory($(this).val()) === 'fee';
        $(this).closest('.order-add-line-bar').find('.js-estimate-charge-placement-choice').prop('hidden', !isFee);
    });

    $(document).on('input change', '.js-estimate-charge-amount', function () {
        recalcSellDocTotals('estimate', SELL_DOC_OPTS.estimate);
    });

    // Estimate lines are server-rendered; JS hydrates the charge rows on top of them the same way
    // Order's own init block does.
    if ($('.js-estimate-form').length) {
        hydrateSellDocChargeLinesFromData('estimate');
        recalcSellDocTotals('estimate', SELL_DOC_OPTS.estimate);
        scheduleAdminFeeRefresh('estimate');
        scheduleAdminTaxRefresh('estimate');
    }

    /* ── Admin standalone invoice line table ───────────────────────────────────────────────
       Same shared functions order's and quote's use (#full-parity, 2026-09-13); the only
       document-specific bits are SELL_DOC_OPTS.invoice (batch on, TBD off) and this screen's own
       Add-Line-doesn't-save rule (see InvoiceController's own comment on the `add_line` gate) — not
       reflected here at all, since that gate lives entirely server-side. The order-linked entry
       point has none of this: its rows are locked/drawn-down and never reach a form carrying
       .js-invoice-form. */

    // Add Product also opens the new line's own product search and focuses it (#707) — see the
    // '.js-order-add-product' handler above.
    $(document).on('click', '.js-invoice-add-product', function (event) {
        event.preventDefault();
        var $row = addSellDocLine('invoice', 'product', null, SELL_DOC_OPTS.invoice);
        scheduleAdminFeeRefresh('invoice');
        scheduleAdminTaxRefresh('invoice');
        if ($row) { $row.find('.ss-trigger').trigger('click'); }
    });

    $(document).on('click', '.js-invoice-add-blank', function (event) {
        event.preventDefault();
        addSellDocLine('invoice', 'blank', null, SELL_DOC_OPTS.invoice);
    });

    $(document).on('click', '.js-invoice-remove-line', function (event) {
        event.preventDefault();
        var $body = $('.js-invoice-lines-body');
        $(this).closest('tr').remove();
        if ($body.children('tr').not('.no-js-row, .invoice-lines-empty').length === 0) {
            var colCount = $body.closest('table').find('thead th').length || 1;
            $body.append('<tr class="invoice-lines-empty"><td colspan="' + colCount + '">No lines yet. Use Add Product or Add Blank Line to start.</td></tr>');
        }
        recalcSellDocTotals('invoice', SELL_DOC_OPTS.invoice);
        scheduleAdminFeeRefresh('invoice');
        scheduleAdminTaxRefresh('invoice');
    });

    $(document).on('change', '.js-invoice-product-select', function () {
        var $selected = $(this).find(':selected');
        var $row = $(this).closest('tr');
        $row.find('.js-invoice-sku').val($selected.data('sku') || '');
        $row.find('.js-invoice-weight').val($selected.data('weight') || '');
        $row.find('.js-invoice-unit').val($selected.data('unit') || '');
        $row.find('.js-invoice-tax').val($selected.data('tax-code') || '');
        $row.find('.js-invoice-cost').val($selected.data('cost') || '');
        $row.find('.js-invoice-original').text(money($selected.data('original-price') || 0));
        $row.find('.js-invoice-price').val($selected.data('original-price') || '');
        syncSellDocBatchVisibility($row, $selected.data('tracksBatch'));
        syncSellDocLotPicker($row, $selected.data('trackingMode'), $selected.val());
        syncSellDocProductEditLink('invoice', $row);
        recalcSellDocLine('invoice', $row, SELL_DOC_OPTS.invoice);
        scheduleAdminFeeRefresh('invoice');
        scheduleAdminTaxRefresh('invoice');
    });

    $(document).on('input change', '.js-invoice-qty, .js-invoice-price', function () {
        recalcSellDocLine('invoice', $(this).closest('tr'), SELL_DOC_OPTS.invoice);
        scheduleAdminTaxRefresh('invoice');
        if ($(this).hasClass('js-invoice-qty')) {
            scheduleAdminFeeRefresh('invoice');
        }
    });

    $(document).on('click', '.js-invoice-bottom-add', function (event) {
        event.preventDefault();
        addSellDocChargeRow('invoice');
    });

    $(document).on('click', '.js-invoice-remove-charge', function (event) {
        event.preventDefault();
        $(this).closest('.order-charge-line-row').remove();
        recalcSellDocTotals('invoice', SELL_DOC_OPTS.invoice);
    });

    $(document).on('change', '.js-invoice-charge-category', function () {
        applySellDocChargeCategoryFilter('invoice', $(this));
        $(this).closest('.order-add-line-bar').find('.js-invoice-charge-type').val('');
        $(this).closest('.order-add-line-bar').find('.js-invoice-charge-placement-choice').val('main_line').prop('hidden', true);
    });

    $(document).on('change', '.js-invoice-charge-type', function () {
        var isFee = chargeTypeCategory($(this).val()) === 'fee';
        $(this).closest('.order-add-line-bar').find('.js-invoice-charge-placement-choice').prop('hidden', !isFee);
    });

    $(document).on('input change', '.js-invoice-charge-amount', function () {
        recalcSellDocTotals('invoice', SELL_DOC_OPTS.invoice);
    });

    if ($('.js-invoice-form').length) {
        hydrateSellDocChargeLinesFromData('invoice');
        hydrateSellDocBatchRowsForExistingLines('invoice');
        initSellDocLotPickers('invoice');
        recalcSellDocTotals('invoice', SELL_DOC_OPTS.invoice);
        scheduleAdminFeeRefresh('invoice');
        scheduleAdminTaxRefresh('invoice');
    }

    /* ── PurchaseOrder line table (#full-parity, 2026-09-15) ──────────────────────────────────
       Same shared functions order's own does — recalcSellDocLine/scheduleAdminFeeRefresh/
       scheduleAdminTaxRefresh — reading this form's own field names (vendor_id/warehouse_id, not
       company_id) through refreshPurchaseOrderFeeLines()/refreshPurchaseOrderTaxBreakdown() (see
       those functions' own note on why they are not just SELL_DOC_OPTS.po fed into the sell side's
       two). Line-ADDING stays exactly as it was: PurchaseOrder's own spare-row convention, not
       JS-cloned — this pass is about live charge/fee/tax editing, the thing the owner asked to be
       preserved from the sell side, not about how a line gets onto the table in the first place. */

    /** This form's own `data-po-vendor-rates` JSON, parsed once and cached — {productId: {unitCost, vendorSku}}. */
    var _poVendorRates = null;
    function poVendorRateFor(productId) {
        if (_poVendorRates === null) {
            var raw = $('.js-po-form').attr('data-po-vendor-rates');
            try { _poVendorRates = raw ? JSON.parse(raw) : {}; } catch (e) { _poVendorRates = {}; }
        }
        return _poVendorRates[String(productId)] || null;
    }

    $(document).on('change', '.js-po-product-select', function () {
        var $selected = $(this).find(':selected');
        var $row = $(this).closest('tr');
        // product_field.html.twig puts the picked product's own plain facts on the <option> as
        // data-* attributes (sku, sales-tax-code, tracks-batch — see _purchase_line_row.html.twig's
        // own optionData). The vendor's cost and their own SKU are not product facts at all — see
        // poVendorRateFor()'s own note — so they come from the document's own vendor-rates map
        // instead, keyed by the picked product id.
        var rate = poVendorRateFor($selected.val());
        $row.find('.js-po-sku').val($selected.data('sku') || '');
        $row.find('.js-po-tax').val($selected.data('salesTaxCode') || '');
        if (rate) {
            $row.find('.js-po-vendor-sku').val(rate.vendorSku || '');
            $row.find('.js-po-price').val(rate.unitCost || '');
        }
        syncSellDocBatchVisibility($row, $selected.data('tracksBatch'));
        // Re-reads the price box just filled above and updates this row's own subtotal live, the
        // same as Order's own product-select handler does.
        recalcSellDocLine('po', $row, SELL_DOC_OPTS.po);
        scheduleAdminFeeRefresh('po');
        scheduleAdminTaxRefresh('po');
    });

    $(document).on('input change', '.js-po-qty, .js-po-price', function () {
        // recalcSellDocLine writes this row's OWN .js-po-subtotal live (qty * price) before
        // re-summing every row into the totals box — the same function Order's own qty/price
        // handler calls. Calling recalcSellDocTotals directly here, without this, left a line's
        // own subtotal cell stale until the next full save, which fed a wrong figure into
        // "Subtotal" the moment anyone typed a qty or price on an unsaved row.
        recalcSellDocLine('po', $(this).closest('tr'), SELL_DOC_OPTS.po);
        scheduleAdminTaxRefresh('po');
        if ($(this).hasClass('js-po-qty')) {
            scheduleAdminFeeRefresh('po');
        }
    });

    $(document).on('change', '.js-po-tax', function () {
        scheduleAdminTaxRefresh('po');
    });

    $(document).on('click', '.js-po-bottom-add', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        addSellDocChargeRow('po');
    });

    // Add Product / Add Blank Line (#full-parity, 2026-09-15) — the same addSellDocLine() the
    // sell side's own .js-order-add-product/.js-order-add-blank call, cloning from
    // #po-product-line-template / #po-blank-line-template (see purchase_order/edit.html.twig's
    // own page_end block). A prior version restored these buttons' markup without ever wiring a
    // click handler or the templates they clone from, so they rendered but did nothing.
    // Add Product also opens the new line's own product search and focuses it (#707) — the same
    // searchable-select every line already has, whose own open() does the focusing. A barcode
    // scanner's keystrokes land straight in it with no second click needed.
    $(document).on('click', '.js-po-add-product', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $row = addSellDocLine('po', 'product', null, SELL_DOC_OPTS.po);
        if ($row) { $row.find('.ss-trigger').trigger('click'); }
    });

    $(document).on('click', '.js-po-add-blank', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        addSellDocLine('po', 'blank', null, SELL_DOC_OPTS.po);
    });

    $(document).on('change', '.js-po-charge-type', function () {
        var kind = String($(this).val() || '').split(':')[0];
        var isFee = kind === 'fee' || kind === 'empty-fee';
        $(this).closest('.purchase-charge-bar').find('.js-po-charge-placement-choice').prop('hidden', !isFee);
    });

    // #669's category filter, ported onto PO (#full-parity, 2026-09-15) — this is a pure client-
    // side filter over the existing <option>s, with no live carrier-rate dependency, so the earlier
    // claim that PO's bar has "no categoryFilter" conflated it with `shippingOptions` (which
    // genuinely does need a live rate lookup PO has none of). See applySellDocChargeCategoryFilter.
    $(document).on('change', '.js-po-charge-category', function () {
        applySellDocChargeCategoryFilter('po', $(this));
        $(this).closest('.purchase-charge-bar').find('.js-po-charge-type').val('');
        $(this).closest('.purchase-charge-bar').find('.js-po-charge-placement-choice').val('main_line').prop('hidden', true);
    });

    $(document).on('click', '.js-po-remove-charge', function (event) {
        event.preventDefault();
        $(this).closest('.purchase-charge-row').remove();
        recalcSellDocTotals('po', SELL_DOC_OPTS.po);
        scheduleAdminTaxRefresh('po');
    });

    $(document).on('input change', '.js-po-charge-amount', function () {
        recalcSellDocTotals('po', SELL_DOC_OPTS.po);
        scheduleAdminTaxRefresh('po');
    });

    /* Changing the vendor or the delivery warehouse changes what a live calculator would price
       (vendor-specific freight terms, the warehouse's own province) — the buy-side equivalent of
       the sell side's own address/province change refreshing its fee and tax preview. */
    $(document).on('change', '[name="vendor_id"], [name="warehouse_id"]', function () {
        var $form = $(this).closest('.js-po-form');
        if (!$form.length) { return; }
        scheduleAdminFeeRefresh('po');
        scheduleAdminTaxRefresh('po');
    });

    if ($('.js-po-form').length) {
        hydrateSellDocBatchRowsForExistingLines('po');
        hydrateSellDocChargeLinesFromData('po');
        recalcSellDocTotals('po', SELL_DOC_OPTS.po);
        scheduleAdminFeeRefresh('po');
        scheduleAdminTaxRefresh('po');
    }

    /* ── VendorBill line table (#full-parity, 2026-09-14) ─────────────────────────────────────
       Same shared functions and the same shape as PurchaseOrder's own block above — VendorBill's
       save() already converged its charge model onto PurchaseOrder's, and both post the identical
       vendor_id/warehouse_id field names, so refreshPurchaseDocumentFeeLines/TaxBreakdown(prefix)
       above already covers 'bill' too.

       One real, documented gap versus PO's own block: no vendor-rate autofill (poVendorRateFor()'s
       twin). PO's own reads a `data-po-vendor-rates` JSON the form carries
       (PurchaseOrderController::formContext()'s own `vendorRates`, sourced from
       VendorPriceRepository::ratesFor()); VendorBillController has no VendorPriceRepository
       injected and formContext() builds no such JSON, so there is nothing here to read yet. A
       picked product's own plain facts (sku, tax code) still autofill, same as PO's. */

    $(document).on('change', '.js-bill-product-select', function () {
        var $selected = $(this).find(':selected');
        var $row = $(this).closest('tr');
        $row.find('.js-bill-sku').val($selected.data('sku') || '');
        $row.find('.js-bill-tax').val($selected.data('salesTaxCode') || '');
        syncSellDocBatchVisibility($row, $selected.data('tracksBatch'));
        recalcSellDocLine('bill', $row, SELL_DOC_OPTS.bill);
        scheduleAdminFeeRefresh('bill');
        scheduleAdminTaxRefresh('bill');
    });

    $(document).on('input change', '.js-bill-qty, .js-bill-price', function () {
        recalcSellDocLine('bill', $(this).closest('tr'), SELL_DOC_OPTS.bill);
        scheduleAdminTaxRefresh('bill');
        if ($(this).hasClass('js-bill-qty')) {
            scheduleAdminFeeRefresh('bill');
        }
    });

    $(document).on('change', '.js-bill-tax', function () {
        scheduleAdminTaxRefresh('bill');
    });

    $(document).on('click', '.js-bill-bottom-add', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        addSellDocChargeRow('bill');
    });

    // See the '.js-po-add-product' handler above — same auto-focus, Bill's own hook (#707).
    $(document).on('click', '.js-bill-add-product', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var $row = addSellDocLine('bill', 'product', null, SELL_DOC_OPTS.bill);
        if ($row) { $row.find('.ss-trigger').trigger('click'); }
    });

    $(document).on('click', '.js-bill-add-blank', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        addSellDocLine('bill', 'blank', null, SELL_DOC_OPTS.bill);
    });

    $(document).on('change', '.js-bill-charge-type', function () {
        var kind = String($(this).val() || '').split(':')[0];
        var isFee = kind === 'fee' || kind === 'empty-fee';
        $(this).closest('.purchase-charge-bar').find('.js-bill-charge-placement-choice').prop('hidden', !isFee);
    });

    $(document).on('change', '.js-bill-charge-category', function () {
        applySellDocChargeCategoryFilter('bill', $(this));
        $(this).closest('.purchase-charge-bar').find('.js-bill-charge-type').val('');
        $(this).closest('.purchase-charge-bar').find('.js-bill-charge-placement-choice').val('main_line').prop('hidden', true);
    });

    $(document).on('click', '.js-bill-remove-charge', function (event) {
        event.preventDefault();
        $(this).closest('.purchase-charge-row').remove();
        recalcSellDocTotals('bill', SELL_DOC_OPTS.bill);
        scheduleAdminTaxRefresh('bill');
    });

    $(document).on('input change', '.js-bill-charge-amount', function () {
        recalcSellDocTotals('bill', SELL_DOC_OPTS.bill);
        scheduleAdminTaxRefresh('bill');
    });

    $(document).on('change', '[name="vendor_id"], [name="warehouse_id"]', function () {
        var $form = $(this).closest('.js-bill-form');
        if (!$form.length) { return; }
        scheduleAdminFeeRefresh('bill');
        scheduleAdminTaxRefresh('bill');
    });

    if ($('.js-bill-form').length) {
        hydrateSellDocBatchRowsForExistingLines('bill');
        hydrateSellDocChargeLinesFromData('bill');
        recalcSellDocTotals('bill', SELL_DOC_OPTS.bill);
        scheduleAdminFeeRefresh('bill');
        scheduleAdminTaxRefresh('bill');
    }

    // Deliberately no client-side validation of line figures, and no minimum line count, on
    // submit. Both used to be checked here: "quantity must be greater than 0"/"price cannot be
    // negative" (a zero-quantity line is a placeholder or a soft note, standard practice; a
    // negative price is a credit — both legal, the server coerces only a negative quantity and
    // reports it through SalesDocumentLineWarnings' red cells), and "at least one line is
    // required" (#full-parity, 2026-09-13 — not a real rule either; the server accepts zero).
    // Neither check protected anything — they made the same order savable with JavaScript off and
    // refused with it on. Validation belongs on the server, which has it. Do not reintroduce
    // either check here.
    $(document).on('submit', '.js-order-form', function () {
        var $form = $(this);
        $form.find('.js-order-scroll-y').val(String(window.pageYOffset || document.documentElement.scrollTop || 0));

        if (window.sessionStorage) {
            window.sessionStorage.setItem(orderScrollKey, String(window.pageYOffset || document.documentElement.scrollTop || 0));
        }
    });

    function wrapTextareaSelection(textarea, before, after, placeholder) {
        if (!textarea) { return; }
        var start = textarea.selectionStart || 0;
        var end = textarea.selectionEnd || 0;
        var value = textarea.value || '';
        var selected = value.substring(start, end) || placeholder || '';
        var replacement = before + selected + after;
        textarea.value = value.substring(0, start) + replacement + value.substring(end);
        textarea.focus();
        textarea.selectionStart = start + before.length;
        textarea.selectionEnd = start + before.length + selected.length;
        $(textarea).trigger('input');
    }

    function prefixTextareaSelection(textarea, prefix, placeholder) {
        if (!textarea) { return; }
        var start = textarea.selectionStart || 0;
        var end = textarea.selectionEnd || 0;
        var value = textarea.value || '';
        var selected = value.substring(start, end) || placeholder || '';
        var lines = selected.split(/\r?\n/).map(function (line) {
            return prefix + line;
        }).join('\n');
        textarea.value = value.substring(0, start) + lines + value.substring(end);
        textarea.focus();
        textarea.selectionStart = start;
        textarea.selectionEnd = start + lines.length;
        $(textarea).trigger('input');
    }

    $(document).on('click', '.email-editor-toolbar button', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var action = $(this).data('editor-action');
        var textarea = $(this).closest('.email-editor-shell').find('textarea').get(0);

        if (action === 'bold') { wrapTextareaSelection(textarea, '<strong>', '</strong>', 'bold text'); }
        else if (action === 'italic') { wrapTextareaSelection(textarea, '<em>', '</em>', 'italic text'); }
        else if (action === 'strike') { wrapTextareaSelection(textarea, '<s>', '</s>', 'strikethrough text'); }
        else if (action === 'code') { wrapTextareaSelection(textarea, '<code>', '</code>', 'code'); }
        else if (action === 'paragraph') { wrapTextareaSelection(textarea, '<p>', '</p>', 'Paragraph text'); }
        else if (action === 'bullet') { prefixTextareaSelection(textarea, '- ', 'List item'); }
        else if (action === 'number') { prefixTextareaSelection(textarea, '1. ', 'List item'); }
        else if (action === 'indent') { prefixTextareaSelection(textarea, '    ', 'Indented text'); }
        else if (action === 'outdent') {
            var start = textarea.selectionStart || 0;
            var end = textarea.selectionEnd || 0;
            var value = textarea.value || '';
            var selected = value.substring(start, end);
            var changed = selected.replace(/^ {1,4}/gm, '');
            textarea.value = value.substring(0, start) + changed + value.substring(end);
            textarea.focus();
            textarea.selectionStart = start;
            textarea.selectionEnd = start + changed.length;
        }
        else if (action === 'link') { wrapTextareaSelection(textarea, '<a href="https://example.com">', '</a>', 'link text'); }
        else if (action === 'rule') { wrapTextareaSelection(textarea, '\n<hr>\n', '', ''); }
        else if (action === 'placeholder') { wrapTextareaSelection(textarea, '', '', '%placeholder%'); }
    });

    $(document).on('click', '[data-coming-soon]', function (event) {
        event.preventDefault();
        showToast('This feature is coming soon.');
    });
    function updateNestedMenuDirection(itemSelector, childSelector) {
        $(itemSelector).each(function () {
            var item = this;
            var child = item.querySelector(childSelector);

            if (!child) {
                item.classList.remove('opens-left');
                return;
            }

            item.classList.remove('opens-left');
            child.style.visibility = 'hidden';
            child.style.display = 'block';

            var rect = child.getBoundingClientRect();
            var shouldOpenLeft = rect.right > (window.innerWidth - 12);

            item.classList.toggle('opens-left', shouldOpenLeft);

            child.style.display = '';
            child.style.visibility = '';
        });
    }

    function refreshCustomerNestedMenus() {
        updateNestedMenuDirection('.site-customer .category-tree-item.has-children', '.category-tree-children');
        updateNestedMenuDirection('.site-customer .nav-category-tree-item.has-children', '.nav-category-tree-children');
    }

    $(document).on('mouseenter focusin', '.site-customer .category-tree-item.has-children, .site-customer .nav-category-tree-item.has-children', function () {
        refreshCustomerNestedMenus();
    });

    $(window).on('resize', function () {
        refreshCustomerNestedMenus();
    });

    /* ── Product image gallery (multi-upload, reorder, primary, remove) ──── */
    (function () {
        var $dropzone = $('#image-dropzone');
        var $fileInput = $('#product-image-input');
        var $grid = $('#image-gallery-grid');
        var $orderFields = $('#image-gallery-order-fields');
        var $primaryInput = $('#primary-image-id-input');

        if (!$dropzone.length) { return; }

        var stagedFilesById = {};
        var stagedCounter = 0;
        var removedIds = [];
        var DRAGGABLE_SELECTOR = '.image-gallery-item, .image-new-preview-card';

        // Primary is always whichever image is first in the grid overall — dragging
        // a photo (saved or newly staged) into the first spot makes it primary,
        // no separate control needed.
        function syncPrimaryDisplay() {
            $grid.children(DRAGGABLE_SELECTOR).each(function (index) {
                $(this).toggleClass('is-primary', index === 0);
            });
        }

        // New uploads are the source of truth in the DOM (so drag-reordering them
        // is reflected), so the file input is rebuilt from current card order.
        function syncFileInputFromDom() {
            var dt = new DataTransfer();
            $grid.find('.image-new-preview-card').each(function () {
                var file = stagedFilesById[$(this).data('staged-id')];
                if (file) { dt.items.add(file); }
            });
            $fileInput[0].files = dt.files;
        }

        function createPreviewCard(stagedId, file) {
            var $card = $('<div class="image-new-preview-card" draggable="true"></div>').attr('data-staged-id', stagedId);
            var $handle = $(
                '<div class="image-gallery-drag-handle" title="Drag to reorder" aria-hidden="true">' +
                '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><circle cx="4" cy="3" r="1.4"/><circle cx="12" cy="3" r="1.4"/><circle cx="4" cy="8" r="1.4"/><circle cx="12" cy="8" r="1.4"/><circle cx="4" cy="13" r="1.4"/><circle cx="12" cy="13" r="1.4"/></svg>' +
                '</div>'
            );
            var $img = $('<img alt="New image preview">');
            var $removeBtn = $('<button class="image-remove-btn" type="button" title="Remove selected file"><span aria-hidden="true">&times;</span></button>');
            var $flag = $(
                '<div class="image-gallery-primary-flag">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                'Primary photo</div>'
            );

            $removeBtn.on('click', function () {
                delete stagedFilesById[stagedId];
                $card.remove();
                syncFileInputFromDom();
                syncPrimaryDisplay();
            });

            var reader = new FileReader();
            reader.onload = function (e) { $img.attr('src', e.target.result); };
            reader.readAsDataURL(file);

            return $card.append($handle, $removeBtn, $img, $flag);
        }

        function addFiles(fileList) {
            if (!fileList || !fileList.length) { return; }
            for (var i = 0; i < fileList.length; i++) {
                var file = fileList[i];
                if (file.type.indexOf('image/') !== 0) { continue; }

                var stagedId = 'new-' + (stagedCounter++);
                stagedFilesById[stagedId] = file;
                // New uploads land after the last existing/saved image, not in a separate section.
                $grid.append(createPreviewCard(stagedId, file));
            }
            syncFileInputFromDom();
            syncPrimaryDisplay();
        }

        function removeImage(imageId) {
            imageId = imageId.toString();
            $grid.find('.image-gallery-item[data-image-id="' + imageId + '"]').remove();
            removedIds.push(imageId);
            syncPrimaryDisplay();
        }

        // File input change (adds to the staged batch rather than replacing it)
        $fileInput.on('change', function () {
            addFiles(this.files);
        });

        $grid.on('click', '.image-gallery-item .image-gallery-remove-btn', function () {
            removeImage($(this).closest('.image-gallery-item').data('image-id'));
        });

        // Drag-to-reorder using native HTML5 drag and drop. Saved images and staged
        // uploads only reorder amongst their own kind — new uploads always save
        // after existing ones, so letting them mix would be a visual lie about
        // what's about to become primary.
        var $dragging = null;
        $grid.on('dragstart', DRAGGABLE_SELECTOR, function (e) {
            $dragging = $(this);
            $(this).addClass('is-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', $(this).data('image-id') || $(this).data('staged-id') || '1');
        });

        $grid.on('dragend', DRAGGABLE_SELECTOR, function () {
            $(this).removeClass('is-dragging');
            $dragging = null;
        });

        $grid.on('dragover', DRAGGABLE_SELECTOR, function (e) {
            e.preventDefault();
            var $target = $(this);
            var sameType = $dragging && ($dragging.hasClass('image-gallery-item') === $target.hasClass('image-gallery-item'));
            if (!$dragging || $dragging.is($target) || !sameType) { return; }

            var rect = this.getBoundingClientRect();
            var isAfter = (e.originalEvent.clientX - rect.left) > rect.width / 2;
            if (isAfter) {
                $dragging.insertAfter($target);
            } else {
                $dragging.insertBefore($target);
            }
            syncPrimaryDisplay();
            if ($dragging.hasClass('image-new-preview-card')) { syncFileInputFromDom(); }
        });

        syncPrimaryDisplay();

        // Drag-and-drop visual feedback for new file uploads
        $dropzone.on('dragenter dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.addClass('is-dragover');
        }).on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.removeClass('is-dragover');
        }).on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.removeClass('is-dragover');
            addFiles(e.originalEvent.dataTransfer.files);
        });

        // Serialize order/removal/primary state into hidden fields right before submit
        $dropzone.closest('form').on('submit', function () {
            $orderFields.empty();

            $grid.find('.image-gallery-item').each(function () {
                $orderFields.append($('<input type="hidden" name="image_order[]">').val($(this).data('image-id')));
            });

            removedIds.forEach(function (id) {
                $orderFields.append($('<input type="hidden" name="remove_image_ids[]">').val(id));
            });

            // Only a saved image has a real id to set as primary. If a staged upload
            // is first, leave this blank — the server auto-assigns primary to the
            // first newly uploaded file when nothing else already is primary, and
            // the images[] order (kept in sync with the grid) already reflects it.
            var $first = $grid.children(DRAGGABLE_SELECTOR).first();
            $primaryInput.val($first.hasClass('image-gallery-item') ? $first.data('image-id') : '');
        });
    })();

    /* Row-action dropdowns — rendered via position:fixed so they escape
       overflow:hidden/auto table wrappers without being clipped. */
    /* â”€â”€ Product CSV import upload (drag-drop + preview) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    (function () {
        var $dropzone = $('#csv-dropzone');
        var $fileInput = $('#product-csv-input');
        var $newPreview = $('#csv-new-preview');
        var $newName = $('#csv-new-name');
        var $newSize = $('#csv-new-size');
        var $newRemove = $('#csv-new-remove');

        if (!$dropzone.length) { return; }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(2) + ' MB';
        }

        function showNewPreview(file) {
            if (!file) { return; }
            $newName.text(file.name || 'selected.csv');
            $newSize.text(typeof file.size === 'number' ? formatSize(file.size) : '');
            $newPreview.removeAttr('hidden');
            $dropzone.hide();
        }

        function clearNewPreview() {
            $newPreview.attr('hidden', '');
            $newName.text('');
            $newSize.text('');
            $dropzone.show();
        }

        $fileInput.on('change', function () {
            var file = this.files && this.files[0];
            if (file) {
                showNewPreview(file);
            } else {
                clearNewPreview();
            }
        });

        $newRemove.on('click', function () {
            $fileInput.val('');
            clearNewPreview();
        });

        $dropzone.on('dragenter dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.addClass('is-dragover');
        }).on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.removeClass('is-dragover');
        }).on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.removeClass('is-dragover');

            var files = e.originalEvent.dataTransfer.files;
            if (files && files.length) {
                $fileInput[0].files = files;
                $fileInput.trigger('change');
            }
        });
    })();

    var $fixedDrop = $('<div class="row-action-dropdown row-action-fixed-drop"></div>').appendTo('body');
    var $activeToggle = null;

    function positionFixedDrop($toggle) {
        var rect = $toggle[0].getBoundingClientRect();
        // Clamp width to viewport, then measure height at that width.
        $fixedDrop.css({ visibility: 'hidden', display: 'flex', width: 'max-content', maxHeight: '', overflowY: '' });
        var naturalW = $fixedDrop[0].scrollWidth;
        var maxW = Math.max(160, window.innerWidth - 8);
        var width = Math.min(naturalW, maxW);

        $fixedDrop.css({ width: width });
        var dropH = $fixedDrop[0].scrollHeight;
        $fixedDrop.css({ visibility: '', display: '' });

        var left = rect.right - width;
        if (left < 4) { left = 4; }
        if (left + width > window.innerWidth - 4) { left = window.innerWidth - width - 4; }

        var spaceBelow = window.innerHeight - rect.bottom - 8;
        var spaceAbove = rect.top - 8;

        // Prefer showing the full menu without scrolling by shifting within the viewport.
        var margin = 4;
        var gap = 8;
        var topBelow = rect.bottom + gap;
        var topAbove = rect.top - gap - dropH;

        var canFitBelow = (topBelow + dropH) <= (window.innerHeight - margin);
        var canFitAbove = topAbove >= margin;

        var top;
        var maxH = '';
        var overflowY = '';

        if (canFitBelow) {
            top = topBelow;
        } else if (canFitAbove) {
            top = topAbove;
        } else {
            // Doesn't fit fully either way: pick side with more room and enable internal scrolling.
            var placeBelow = spaceBelow >= spaceAbove;
            var available = placeBelow ? spaceBelow : spaceAbove;
            maxH = Math.max(120, Math.min(available, window.innerHeight - (margin * 2)));
            overflowY = 'auto';
            top = placeBelow ? topBelow : Math.max(margin, rect.top - gap - maxH);
        }

        // Clamp into viewport (also fixes "cutting" at edges on small screens).
        top = Math.max(margin, Math.min(top, window.innerHeight - margin - (maxH ? maxH : dropH)));

        $fixedDrop.css({ top: top, left: left, width: width, maxHeight: maxH, overflowY: overflowY });
    }

    function closeFixedDrop() {
        $fixedDrop.removeClass('is-open').empty();
        if ($activeToggle) {
            $activeToggle.attr('aria-expanded', 'false');
            $activeToggle = null;
        }
    }

    $(document).on('click', '.row-action-toggle', function (event) {
        event.stopPropagation();
        var $toggle = $(this);
        if ($activeToggle && $activeToggle.is($toggle)) {
            closeFixedDrop();
            return;
        }
        closeFixedDrop();
        var $src = $toggle.closest('.row-action-menu').find('.row-action-dropdown').not($fixedDrop);
        $fixedDrop.html($src.html()).addClass('is-open');
        $activeToggle = $toggle;
        $toggle.attr('aria-expanded', 'true');
        positionFixedDrop($toggle);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.row-action-fixed-drop').length) {
            closeFixedDrop();
        }
    });

    $(window).on('scroll resize', function () {
        if ($activeToggle) { positionFixedDrop($activeToggle); }
    });

    /* ── Order list quick actions ───────────────────────────────────────────── */
    function updateOrderStatus(orderId, status, notifyClient, $btn, onDone) {
        $.ajax({
            url: '/admin/order/update-status/' + orderId,
            method: 'POST',
            data: { status: status, notify_client: notifyClient ? 1 : 0 },
            success: function (res) {
                if (res && res.success) {
                    showNotification(res.message || 'Order status updated successfully', 'success');

                    var $row = $('tr[data-order-id=\"' + orderId + '\"]');
                    var $badge = $row.find('.order-status').first();
                    if ($badge.length) {
                        var cls = 'order-status order-status-' + status.toLowerCase().replace(/\\s+/g, '-').replace(/,/g, '');
                        $badge.text(status).attr('class', cls);
                    }

                    closeFixedDrop();
                } else {
                    showNotification((res && res.message) ? res.message : 'Error updating status', 'error');
                }
            },
            error: function () {
                showNotification('Could not connect to server. Please try again.', 'error');
            },
            complete: function () {
                if ($btn && $btn.length) $btn.prop('disabled', false);
                if (typeof onDone === 'function') onDone();
            }
        });
    }

    $(document).on('click', '.js-order-quick-status', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var orderId = String($btn.data('orderId') || $btn.attr('data-order-id') || '').trim();
        var status = String($btn.data('status') || '').trim();
        if (!orderId || !status) return;

        var confirmText = String($btn.data('confirm') || '').trim();
        if (confirmText && !window.confirm(confirmText)) return;

        $btn.prop('disabled', true);
        updateOrderStatus(orderId, status, false, $btn);
    });

    $(document).on('click', '.js-order-approve', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var orderId = String($btn.data('orderId') || $btn.attr('data-order-id') || '').trim();
        var status = String($btn.data('status') || '').trim();
        if (!orderId || !status) return;

        var $modal = $('#order-approve-modal');
        if (!$modal.length) return;

        $modal.attr('data-order-id', orderId);
        $modal.attr('data-status', status);
        $modal.addClass('is-visible');
        closeFixedDrop();
    });

    $(document).on('click', '.js-order-cancel', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var orderId = String($btn.data('orderId') || $btn.attr('data-order-id') || '').trim();
        var status = String($btn.data('status') || 'Void').trim() || 'Void';
        if (!orderId) return;

        var $modal = $('#order-cancel-modal');
        if (!$modal.length) return;

        $modal.attr('data-order-id', orderId);
        $modal.attr('data-status', status);
        $modal.addClass('is-visible');
        closeFixedDrop();
    });

    $(document).on('click', '#order-approve-modal .js-approve-send, #order-approve-modal .js-approve-nosend', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $modal = $('#order-approve-modal');
        if (!$modal.length) return;

        var orderId = String($modal.attr('data-order-id') || '').trim();
        var status = String($modal.attr('data-status') || '').trim();
        if (!orderId || !status) return;

        var notifyClient = $(this).hasClass('js-approve-send');
        var $btn = $(this);
        $btn.prop('disabled', true);

        updateOrderStatus(orderId, status, notifyClient, $btn, function () {
            $modal.removeClass('is-visible');
        });
    });

    $(document).on('click', '#order-cancel-modal .js-cancel-send, #order-cancel-modal .js-cancel-nosend', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $modal = $('#order-cancel-modal');
        if (!$modal.length) return;

        // "No" => do nothing (do not cancel)
        if ($(this).hasClass('js-cancel-nosend')) {
            $modal.removeClass('is-visible');
            return;
        }

        var orderId = String($modal.attr('data-order-id') || '').trim();
        var status = String($modal.attr('data-status') || 'Void').trim() || 'Void';
        if (!orderId) return;

        var $btn = $(this);
        $btn.prop('disabled', true);

        updateOrderStatus(orderId, status, true, $btn, function () {
            $modal.removeClass('is-visible');
        });
    });

    /* ── Admin user menu dropdown ────────────────────────────────────────────── */
    var $userMenu = $('#user-menu');
    if ($userMenu.length) {
        $userMenu.find('.user-menu-toggle').on('click', function (e) {
            e.stopPropagation();
            var isOpen = !$userMenu.hasClass('is-open');
            $userMenu.toggleClass('is-open', isOpen);
            $(this).attr('aria-expanded', String(isOpen));
        });

        $(document).on('click.usermenu', function (e) {
            if (!$(e.target).closest('#user-menu').length) {
                $userMenu.removeClass('is-open');
                $userMenu.find('.user-menu-toggle').attr('aria-expanded', 'false');
            }
        });
    }

    /* ── Company notes — inline delete ───────────────────────────────────── */
    var $activeNoteEntry = null;

    function getCompanyNotesList() {
        return $('.notes-list').first();
    }

    function openNoteModal(mode, text) {
        var editing = mode === 'edit';
        var $modal = $('.note-modal');
        var $title = $('#note-modal-title');
        var $form = $('.note-modal-card');
        var $save = $('.js-note-save');
        var $id = $('.note-modal-id');

        $title.text($title.data(editing ? 'edit-title' : 'create-title'));
        $save.text($save.data(editing ? 'edit-label' : 'create-label')).data('mode', mode);
        $form.attr('action', $form.data(editing ? 'update-action' : 'create-action'));
        $id.val(editing && $activeNoteEntry ? ($activeNoteEntry.data('id') || '') : '');
        var val = (text || '').toString();
        // Stored notes use literal "\n" sequences; show them as newlines in textarea.
        val = val.replace(/\\n/g, "\n");
        $('.note-modal-input').val(val);
        $modal.prop('hidden', false);
        setTimeout(function () { $('.note-modal-input').trigger('focus'); }, 20);
    }

    function closeNoteModal() {
        $('.note-modal').prop('hidden', true);
        $('.note-modal-input').val('');
        $('.note-modal-id').val('');
        $activeNoteEntry = null;
    }

    $(document).on('click', '.js-note-modal-open', function () {
        $activeNoteEntry = null;
        openNoteModal('create', '');
    });

    $(document).on('click', '.js-note-edit', function () {
        $activeNoteEntry = $(this).closest('.note-entry');
        var raw = $activeNoteEntry.attr('data-note') || $activeNoteEntry.data('note') || $activeNoteEntry.find('.note-entry-text').text();
        openNoteModal('edit', raw);
    });

    $(document).on('click', '.js-note-modal-close', closeNoteModal);

    $(document).on('click', '.note-modal', function (event) {
        if ($(event.target).is('.note-modal')) {
            closeNoteModal();
        }
    });

    $(document).on('click', '.note-modal-card', function (event) {
        event.stopPropagation();
    });

    $(document).on('submit', '.note-modal-card', function () {
        $(this).find('.js-note-save').prop('disabled', true);
    });

    // Document lock (PO, order, quote, invoice): same hidden-modal-backdrop shell as the note modal above, opened by JS
    // rather than sitting inline in the action bar. The form underneath is a real <form method=
    // "post">, reachable with scripting off exactly as it was before this moved into a modal.
    $(document).on('click', '.js-lock-modal-open', function () {
        $(this).closest('details').removeAttr('open');
        $('.lock-modal').prop('hidden', false);
        setTimeout(function () { $('.lock-modal input[name="reason"]').trigger('focus'); }, 20);
    });

    $(document).on('click', '.js-lock-modal-close', function () {
        $('.lock-modal').prop('hidden', true);
    });

    $(document).on('click', '.lock-modal', function (event) {
        if ($(event.target).is('.lock-modal')) {
            $('.lock-modal').prop('hidden', true);
        }
    });

    $(document).on('click', '.lock-modal .modal-card', function (event) {
        event.stopPropagation();
    });

    $(document).on('click', '.js-note-delete', function () {
        var $btn = $(this);
        var url = $btn.data('url');
        var id = $btn.data('id');
        var $entry = $btn.closest('.note-entry');

        $.post(url, { id: id })
            .done(function (res) {
                if (res && res.ok) {
                    // Nothing is renumbered. This used to walk every remaining entry and decrement
                    // its data-index, because a note was addressed by its position in a packed
                    // string — miss one and the next delete removed the wrong note. An id does not
                    // move when its neighbours do (#358).
                    $entry.fadeOut(180, function () {
                        $(this).remove();
                        if ($('.note-entry').length === 0) {
                            $('.notes-list').html('<p class="notes-empty">No notes yet.</p>');
                        }
                    });
                    showToast(res.message || 'Note was deleted successfully.', 'success');
                }
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Could not delete note. Please try again.';
                showToast(message, 'error');
            });
    });

    /* Expose for inline use */
    window.WC = window.WC || {};
    window.WC.showToast = showToast;

    /* The inline pencil-edit handlers that lived here (.btn-trigger-edit, .btn-save-edit and the
       .js-order-time-input auto-save) are gone with the order detail page's editable Order Time
       field. Order Date is edited on the order form now, and nothing else on any page used them —
       .btn-save-edit never even saved anything, it only faked the UI update. */

    /* ── Order Log / Message Actions ─────────────────────────── */
    $(document).on('change', '#log-type', function () {
        if ($(this).val() === 'For Client') {
            $('#log-notify-container').css('display', 'flex');
        } else {
            $('#log-notify-container').hide();
            $('#log-notify').prop('checked', false);
        }
    });

    $(document).on('click', '.js-add-log', function () {
        var $btn = $(this);
        var url = $btn.data('url');
        var message = $('#log-message').val();
        var type = $('#log-type').val();
        var notify = $('#log-notify').is(':checked') ? 1 : 0;

        if (!message) {
            showToast('Please type a message first.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Sending...');

        $.post(url, { message: message, type: type, notify_client: notify })
            .done(function (res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    setTimeout(function () {
                        location.reload();
                    }, 500);
                } else {
                    showToast(res.message, 'error');
                    $btn.prop('disabled', false).text('Send Message');
                }
            })
            .fail(function () {
                showToast('Error adding message.', 'error');
                $btn.prop('disabled', false).text('Send Message');
            });
    });

    $(document).on('click', '.js-delete-log', function () {
        var $btn = $(this);
        var url = $btn.data('url');
        var $row = $btn.closest('tr');

        $btn.prop('disabled', true);

        $.post(url)
            .done(function (res) {
                if (res.success) {
                    showToast(res.message || 'Log entry deleted successfully.', 'success');
                    $row.fadeOut(300, function () { $(this).remove(); });
                } else {
                    showToast(res.message || 'Could not delete log entry.', 'error');
                    $btn.prop('disabled', false);
                }
            })
            .fail(function () {
                showToast('Error deleting log entry.', 'error');
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '.js-estimate-reject', function () {
        var $btn = $(this);
        var url = $btn.data('url');

        if (!window.confirm('Reject this quote? This cannot be undone.')) {
            return;
        }

        $btn.prop('disabled', true).text('Rejecting...');

        $.post(url, { status: 'Rejected' })
            .done(function (res) {
                if (res.success) {
                    showToast('Quote rejected.', 'success');
                    setTimeout(function () {
                        location.reload();
                    }, 500);
                } else {
                    showToast(res.message || 'Could not reject quote.', 'error');
                    $btn.prop('disabled', false).text('Reject Quote');
                }
            })
            .fail(function () {
                showToast('Error rejecting quote.', 'error');
                $btn.prop('disabled', false).text('Reject Quote');
            });
    });

    /* Every notification is its own element, so two in the same tick — a page carrying two
     * server flashes, say — used to land as two independently `position: fixed` boxes pinned to
     * the same top/right corner, i.e. one printed on top of the other (#448). They go into a
     * shared flex column instead; the container owns the fixed positioning and the children
     * simply stack. */
    function notificationStack() {
        var $stack = $('.admin-notification-stack');

        return $stack.length ? $stack : $('<div class="admin-notification-stack"></div>').appendTo('body');
    }

    function showNotification(message, type) {
        var safeType = (type || '').toString().trim();
        var $noti = $('<div></div>').addClass('admin-notification');
        if (safeType) {
            $noti.addClass(safeType);
        }
        $noti.text(message == null ? '' : String(message));
        notificationStack().append($noti);
        setTimeout(function () { $noti.addClass('is-visible'); }, 10);
        setTimeout(function () {
            $noti.removeClass('is-visible');
            setTimeout(function () { $noti.remove(); }, 600);
        }, 3500);
    }

    function showToast(message, type) {
        showNotification(message, type);
    }

    /* ── Modals ────────────────────────────────────────────────────────────────── */
    $(document).on('click', '.js-trigger-status-modal', function () {
        $('#status-modal').addClass('is-visible');
    });

    var activeUserStatusTrigger = null;

    $(document).on('click', '.js-user-status-open', function () {
        activeUserStatusTrigger = this;

        var $trigger = $(this);
        var $modal = $('#user-status-modal');
        var currentStatus = String($trigger.data('user-status') || '').trim();
        var modalStatus = currentStatus === 'Active' ? 'Active' : 'Inactive';

        $('#user-status-modal-email').text($trigger.data('user-email') || 'Choose the account status for this user.');
        $('#user-status-modal-select').val(modalStatus);
        $modal.find('.js-user-status-save')
            .attr('data-status-url', $trigger.data('status-url') || '')
            .attr('data-status-token', $trigger.data('status-token') || '');
        $modal.addClass('is-visible');
    });

    $(document).on('click', '.js-modal-close, .admin-modal-overlay', function (e) {
        if (e.target === this || $(this).hasClass('js-modal-close')) {
            $('.admin-modal-overlay').removeClass('is-visible');
            // Audit detail is a sandboxed iframe pointed at a real URL; clearing it on close means
            // the next "View" click always loads fresh rather than briefly showing the last row.
            var $iframe = $('#audit-detail-iframe');
            if ($iframe.length) { $iframe.attr('src', 'about:blank'); }
        }
    });

    /* ── Audit log detail modal ──────────────────────────────────────────────── */
    // The link's href (admin_audit_detail) is a real, no-JavaScript-reachable page. With scripting
    // on, the click is redirected into a sandboxed iframe instead of a full navigation; the server
    // renders the same before/after data either way, already escaped by Twig.
    $(document).on('click', '.js-audit-view-detail', function (e) {
        e.preventDefault();
        $('#audit-detail-iframe').attr('src', $(this).attr('href'));
        $('#audit-detail-modal').addClass('is-visible');
    });

    $(document).on('click', '.js-status-update', function () {
        var $btn = $(this);
        var $modal = $('#status-modal');
        var orderId = $modal.data('order-id');
        var status = $modal.find('select').val();
        var notify = $modal.find('input[type="checkbox"]').is(':checked');

        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Updating...');

        $.ajax({
            url: '/admin/order/update-status/' + orderId,
            method: 'POST',
            data: {
                status: status,
                notify_client: notify ? 1 : 0
            },
            success: function (res) {
                if (res.success) {
                    $modal.removeClass('is-visible');
                    showNotification(res.message || 'Order status updated successfully', 'success');

                    // Show the value that was just saved, so the page does not keep displaying the
                    // old one until a manual refresh (#224). Both order screens are covered: the
                    // Edit Order pill and the Detail page badge. Each carries its own hook — the
                    // badge used to be found by its colour modifier, which a Pending order has none
                    // of, so the selector fell through to the "Paid" payment badge instead.
                    var newStatus = String(status || '');
                    var normalized = newStatus.toLowerCase().replace(/\s+/g, '-').replace(/,/g, '');

                    var $pill = $('.order-edit-status .order-status').first();
                    if ($pill.length) {
                        $pill.text(newStatus).attr('class', 'order-status order-status-' + normalized);
                    }

                    var $badge = $('.js-order-status-badge').first();
                    if ($badge.length) {
                        // Same modifier rule as admin/order/detail.html.twig renders it with.
                        var modifier = normalized === 'closed' ? ' success' : ((normalized === 'draft' || normalized === 'void') ? ' warn' : '');
                        $badge.text(newStatus).attr('class', 'badge js-order-status-badge' + modifier);
                    }

                    // The loud one in the page title row (queue item 41,
                    // admin/_partials/document_status.html.twig). It is the status a reader
                    // actually looks at, so leaving it showing the old value would be worse than
                    // the stale badge #224 was about. Classes are rebuilt exactly as the partial
                    // writes them.
                    var $heroStatus = $('[data-document-status]').first();
                    if ($heroStatus.length) {
                        $heroStatus
                            .text(newStatus)
                            .attr('class', 'order-status document-status order-status-' + normalized);
                    }

                    // The modal is reopened from the same markup, so its select has to agree with
                    // what the page now shows.
                    $modal.find('select').val(newStatus);
                } else {
                    showNotification(res.message || 'Error updating status', 'error');
                }
            },
            error: function () {
                showNotification('Could not connect to server. Please try again.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    $(document).on('click', '.js-user-status-save', function () {
        var $btn = $(this);
        var statusUrl = String($btn.attr('data-status-url') || '').trim();
        var selectedStatus = String($('#user-status-modal-select').val() || '').trim();

        if (!statusUrl) {
            showNotification('Status update URL is missing.', 'error');
            return;
        }

        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: statusUrl,
            method: 'POST',
            data: {
                status: selectedStatus,
                _token: String($btn.attr('data-status-token') || '')
            },
            success: function (res) {
                if (!res || !res.ok) {
                    showNotification((res && res.message) ? res.message : 'Could not update user status.', 'error');
                    return;
                }

                if (activeUserStatusTrigger) {
                    var $trigger = $(activeUserStatusTrigger);
                    $trigger
                        .text(res.status)
                        .attr('data-user-status', res.status)
                        .removeClass('warn danger-soft');

                    if (res.status === 'Inactive') {
                        $trigger.addClass('danger-soft');
                    }
                }

                $('#user-status-modal').removeClass('is-visible');
                showNotification(res.message || 'User status updated successfully.', 'success');
            },
            error: function (xhr) {
                var responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Could not update user status.';
                showNotification(responseMessage, 'error');
            },
            complete: function () {
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    /* ── Searchable Select (ss-*) ─────────────────────────────────────────── */
    function initSearchableSelect($select) {
        if (!$select || !$select.length) return;
        if ($select.data('ssInited')) return;
        $select.data('ssInited', true);

        var isRequired = $select.prop('required');
        if (isRequired) {
            $select.removeAttr('required');
            $select.data('originally-required', true);
        }

        var placeholder = String($select.attr('data-placeholder') || '').trim() || '-';
        // Product selects (#399) carry this: the full catalog is too large to sit in the DOM, so
        // `products` in the template only ever seeds the option(s) already selected, and the rest
        // is fetched here as the admin types instead of filtered client-side.
        var searchUrl = String($select.attr('data-search-url') || '').trim();
        var searchRequestTimer = null;
        // #399: the endpoint is not hit until this many characters have been typed — not on open,
        // and not on a 1-character term. Keep in step with the same floor enforced server-side in
        // OrderController::searchOrderProducts()/EstimateController::searchEstimateProducts().
        var MIN_SEARCH_CHARS = 2;
        // Tier 1's answer to App\Service\Product\ProductSearchFields::ORDER (#707): the extra
        // data-* attributes an option's search text is spread across, besides its own visible
        // label (always checked) — name is the label itself, sku and barcodes ride along as data
        // because there is no request tier 1 could ask a server to match them for. Kept in step by
        // hand: a plain <select> rendered once at page load has no runtime channel back to PHP's
        // own list.
        var PRODUCT_SEARCH_DATA_ATTRS = ['sku', 'barcodes'];
        // Paging state for the remote picker. searchSeq is the guard that lets only the newest
        // response write to the DOM; searchOffset is where the next page starts.
        var searchOffset = 0;
        var searchHasMore = false;
        var searchSeq = 0;
        var searchXhr = null;

        var $wrap = $('<div class="ss-wrap"></div>');
        var $trigger = $('<button type="button" class="ss-trigger"></button>');
        var $panel = $('<div class="ss-panel"></div>');
        var $searchWrap = $('<div class="ss-search-wrap"></div>');
        var $search = $('<input type="search" class="ss-search" placeholder="Search...">');
        var $list = $('<ul class="ss-list" role="listbox"></ul>');
        var $empty = $('<div class="ss-empty" style="display:none;">No results</div>');
        // Where the remote picker reports on itself, kept separate from $empty because "nothing
        // matched" and "nothing has been asked yet" are different answers. One node for all of its
        // states so two of them can never be on screen at once — showing "type at least 2
        // characters" while a two-character search was already in flight was exactly that bug.
        var $status = $('<div class="ss-empty" style="display:none;"></div>');
        var TYPE_MORE_TEXT = 'Type at least ' + MIN_SEARCH_CHARS + ' characters to search';

        $searchWrap.append($search);
        $panel.append($searchWrap, $list, $empty, $status);

        $select.wrap($wrap);
        $wrap = $select.parent();
        $wrap.prepend($trigger);
        $wrap.append($panel);

        $select.css({ position: 'absolute', left: '-9999px', width: '1px', height: '1px', opacity: 0, pointerEvents: 'none' });

        function optionLabel($opt) { return String($opt.text() || '').trim(); }

        // Keyboard navigation state: which visible row Enter would take. Deliberately separate from
        // .is-selected (what the <select> currently holds) — moving the highlight must not change
        // the posted value until Enter commits it.
        function visibleItems() { return $list.find('.ss-item').not('.is-hidden'); }

        // Which input owns the highlight right now. Arrowing sets this false so a pointer resting
        // over the list can't steal the highlight back mid-navigation.
        var pointerDrivesHighlight = true;

        function activeIndex() {
            var $items = visibleItems();
            var found = -1;
            $items.each(function (i) {
                if ($(this).hasClass('is-active')) { found = i; return false; }
            });
            return found;
        }

        // Scrolls the highlight back into view inside .ss-list, which is a max-height scroll box —
        // without this, holding ArrowDown walks the highlight straight out of sight.
        //
        // Measured with getBoundingClientRect rather than offsetTop: offsetTop is relative to the
        // nearest POSITIONED ancestor, and .ss-list sets no position, so it resolves against the
        // fixed .ss-panel and comes back inflated by the search box above the list. Comparing that
        // against scrollTop (which is relative to the list's own content box) scrolled by the wrong
        // amount on every move, which is why the highlight appeared to drift upwards while arrowing
        // down. Rects are in the same coordinate space, so the delta between them is always right.
        //
        // Only listEl.scrollTop is touched — deliberately not scrollIntoView(), which would scroll
        // ancestors too and trip the document scroll listener above into closing the panel.
        function scrollActiveIntoView($item) {
            if (!$item || !$item.length) { return; }
            var listEl = $list[0];
            var itemRect = $item[0].getBoundingClientRect();
            var listRect = listEl.getBoundingClientRect();

            if (itemRect.top < listRect.top) {
                listEl.scrollTop -= listRect.top - itemRect.top;
            } else if (itemRect.bottom > listRect.bottom) {
                listEl.scrollTop += itemRect.bottom - listRect.bottom;
            }
        }

        function setActive(index) {
            var $items = visibleItems();
            $items.removeClass('is-active');
            if (!$items.length) { return; }

            // Clamp at both ends rather than wrapping. A native select stops on the last option, and
            // wrapping round to the top mid-keypress reads as the highlight suddenly travelling
            // upwards while the admin is still pressing ArrowDown.
            if (index < 0) { index = 0; }
            if (index >= $items.length) { index = $items.length - 1; }

            var $item = $items.eq(index);
            $item.addClass('is-active');
            scrollActiveIntoView($item);
        }

        function moveActive(delta) {
            // Hand control to the keyboard until the pointer genuinely moves again — see the
            // mousemove/mouseenter pair further down for why.
            pointerDrivesHighlight = false;

            var $items = visibleItems();
            if (!$items.length) { return; }

            var current = activeIndex();
            if (current === -1) {
                // Nothing highlighted yet: ArrowDown starts at the first match, ArrowUp at the last.
                // Passed as a real index, since setActive() now clamps instead of wrapping.
                setActive(delta > 0 ? 0 : $items.length - 1);
                return;
            }

            setActive(current + delta);
        }

        // The highlighted row's own value, so it can be found again after the list is rebuilt — index
        // alone is no good once a page of results has been appended above or below it.
        function activeValue() {
            var $item = visibleItems().filter('.is-active').first();

            return $item.length ? String($item.attr('data-value') || '') : null;
        }

        function setActiveByValue(value) {
            if (value === null || value === '') { return; }
            var $items = visibleItems();
            var index = -1;
            $items.each(function (i) {
                if (String($(this).attr('data-value') || '') === value) { index = i; return false; }
            });
            if (index !== -1) { setActive(index); }
        }

        function commitActive() {
            var $item = visibleItems().filter('.is-active').first();
            if (!$item.length) { return false; }
            chooseItem($item);
            return true;
        }

        function rebuildList() {
            $list.empty();
            $select.find('option').each(function () {
                var $opt = $(this);
                var value = String($opt.attr('value') || '');
                var label = optionLabel($opt);
                if (value === '' && (label === '' || label === '-')) return;

                var $item = $('<li class="ss-item" role="option"></li>');
                $item.text(label || value);
                $item.attr('data-value', value);
                $item.attr('data-sku', String($opt.attr('data-sku') || ''));
                $item.attr('data-barcodes', String($opt.attr('data-barcodes') || ''));
                if (String($select.val() || '') === value) $item.addClass('is-selected');
                $list.append($item);
            });
        }

        function setTriggerText() {
            var selectedVal = String($select.val() || '');
            var $opt = $select.find('option:selected').first();
            var label = $opt.length ? optionLabel($opt) : '';
            if (selectedVal === '' || label === '' || label === '-') {
                $wrap.removeClass('has-selection');
                $trigger.text(placeholder);
                return;
            }
            $wrap.addClass('has-selection');
            $trigger.text(label);
        }

        function positionPanel() {
            var rect = $trigger[0].getBoundingClientRect();
            $panel.css({
                position: 'fixed',
                top: rect.bottom + 4,
                left: rect.left,
                width: rect.width,
                right: 'auto'
            });
        }

        // Throws away every option the last search added, keeping only the one actually selected,
        // so an emptied search box can't leave stale results behind to be picked by accident.
        function clearRemoteOptions() {
            var currentVal = String($select.val() || '');
            $select.find('option').each(function () {
                if (String($(this).attr('value') || '') !== currentVal) {
                    $(this).remove();
                }
            });
            // Paging restarts with the result set it was counting through, and any in-flight response
            // is disowned — otherwise a reply from the term just cleared could still land and repopulate.
            searchOffset = 0;
            searchHasMore = false;
            searchSeq += 1;
            rebuildList();
            $list.find('.ss-more').remove();
        }

        // Pass a message to show it, or nothing to clear the line entirely.
        function showStatus(message) {
            if (!message) {
                $status.hide().text('');
                return;
            }
            $status.text(message).show();
            $empty.hide();
        }

        function open() {
            $('.ss-wrap.is-open').not($wrap).removeClass('is-open');
            $wrap.addClass('is-open');
            positionPanel();
            $search.val('');
            if (searchUrl) {
                // #399: opening a remote picker fires NO request — it costs nothing until the admin
                // has actually typed MIN_SEARCH_CHARS. Any in-flight debounce from a previous open
                // is cancelled so it can't land after this reset and repopulate the list, and any
                // request already on the wire is dropped so it stops holding the session lock.
                clearTimeout(searchRequestTimer);
                if (searchXhr) { searchXhr.abort(); searchXhr = null; }
                clearRemoteOptions();
                showStatus(TYPE_MORE_TEXT);
            } else {
                filter('');
            }
            setTimeout(function () { $search.trigger('focus'); }, 0);
        }

        function close() { $wrap.removeClass('is-open'); }

        // Scroll events don't bubble, so a capture-phase listener on document catches scrolling
        // inside any ancestor (e.g. the order-lines table's horizontal scroll) as well as the
        // window itself. The panel is position:fixed and doesn't track scroll, so just close it
        // rather than leave it stranded at a stale position. Scrolling that originates INSIDE the
        // panel is excluded: .ss-list is itself a scroll box (max-height + overflow-y: auto), so
        // closing on its scroll made a long result list impossible to page through.
        document.addEventListener('scroll', function (e) {
            if (!$wrap.hasClass('is-open')) return;
            if (e.target === document || e.target === window) return;
            if (e.target && e.target.nodeType === 1 && $panel[0].contains(e.target)) return;
            close();
        }, true);

        function filter(q) {
            q = String(q || '').toLowerCase().trim();
            var visible = 0;
            $list.find('.ss-item').each(function () {
                var $it = $(this);
                var text = String($it.text() || '').toLowerCase();
                var match = q === '' || text.indexOf(q) !== -1;
                for (var i = 0; i < PRODUCT_SEARCH_DATA_ATTRS.length && !match; i++) {
                    var value = String($it.attr('data-' + PRODUCT_SEARCH_DATA_ATTRS[i]) || '').toLowerCase();
                    if (value.indexOf(q) !== -1) { match = true; }
                }
                $it.toggleClass('is-hidden', !match);
                if (match) visible++;
            });
            $empty.toggle(visible === 0);

            // Pre-highlight so Enter always has an obvious target: whatever is already selected if
            // it survived the filter, otherwise the top match.
            var $items = visibleItems();
            var selectedIdx = -1;
            $items.each(function (i) {
                if ($(this).hasClass('is-selected')) { selectedIdx = i; return false; }
            });
            setActive(selectedIdx === -1 ? 0 : selectedIdx);
        }

        // Debounced fetch of the matching products from the server (OrderController /
        // EstimateController searchProducts endpoints) — the DOM only ever holds the
        // already-selected option(s) plus whatever the last search turned up, never the
        // full catalog. company_id/fulfillment_region ride along so the returned prices match
        // the company's price list, exactly like the option data- attributes the server
        // renders inline for a pre-selected product.
        // Appends a page of results to the <select>. Returns how many options it actually added.
        function appendProductOptions(products, currentVal) {
            var added = 0;
            $.each(products, function (i, p) {
                var id = String(p.id || '');
                if (id === '' || id === currentVal) return;
                // Paging can hand back a row already on screen (the catalog shifted between requests),
                // and a duplicate option would show twice and be arrowed through twice.
                if ($select.find('option[value="' + id + '"]').length) return;
                $('<option></option>')
                    .attr('value', id)
                    .attr('data-sku', p.sku || '')
                    .attr('data-weight', p.weight || '')
                    .attr('data-unit', p.unit || '')
                    .attr('data-tax-code', p.taxCode || '')
                    .attr('data-cost', p.cost || '')
                    .attr('data-original-price', p.originalPrice || '')
                    .attr('data-price', p.price || '')
                    .text(p.name || '')
                    .appendTo($select);
                added += 1;
            });

            return added;
        }

        // The "Show more" row lives in the list but is NOT a .ss-item, which is what keeps it out of
        // visibleItems() — so the arrow keys skip it and Enter can never commit it as a product.
        function renderMoreRow() {
            $list.find('.ss-more').remove();
            if (!searchHasMore) { return; }
            $('<li class="ss-more" role="presentation">Show more</li>').appendTo($list);
        }

        function remoteSearch(q, options) {
            options = options || {};
            var append = !!options.append;

            // A "Show more" must not be debounced away by the timer the keystrokes share, and must not
            // cancel a keystroke's pending request either.
            if (!append) { clearTimeout(searchRequestTimer); }

            // The floor lives here as well as in the caller so no future call site can reintroduce
            // a request for a term the server is going to refuse anyway.
            if (String(q || '').trim().length < MIN_SEARCH_CHARS) { return; }

            // Every request carries a sequence number and only the newest one is allowed to touch the
            // DOM. Without it, typing while a "Show more" was in flight appended page 2 of the OLD
            // term onto the new results — an intermittent "wrong products appear" that is miserable to
            // reproduce, because it depends purely on which response lands last.
            searchSeq += 1;
            var seq = searchSeq;

            var fire = function () {
                var $form = $select.closest('form');
                var currentVal = String($select.val() || '');
                var params = {
                    q: q,
                    offset: append ? searchOffset : 0,
                    company_id: $form.find('[name="company_id"]').val() || '',
                    region: $form.find('[name="fulfillment_region"]').val() || ''
                };

                // Abandoning a superseded request is not just tidiness here. PHP holds the session
                // lock for the whole of a request, so a search the admin has already typed past keeps
                // the NEXT one waiting on that lock — which is what turned a burst of keystrokes into
                // visibly escalating response times. Aborting releases it immediately. The sequence
                // guard below stays regardless: an abort is not guaranteed to beat a reply already on
                // the wire.
                if (searchXhr) { searchXhr.abort(); }

                searchXhr = $.getJSON(searchUrl, params).done(function (data) {
                    if (seq !== searchSeq) { return; }

                    // Loading more must not move the admin's place in the list. rebuildList() throws
                    // the <ul> away and filter() re-picks a default, so the highlighted row has to be
                    // remembered by VALUE — its index is meaningless once a page has been appended.
                    var keepActive = append ? activeValue() : null;

                    var products = (data && data.products) || [];
                    if (!append) {
                        searchOffset = 0;
                        $select.find('option').each(function () {
                            if (String($(this).attr('value') || '') !== currentVal) {
                                $(this).remove();
                            }
                        });
                    }

                    appendProductOptions(products, currentVal);
                    // Advanced by what the server returned, not by what survived de-duplication, or the
                    // next page would re-request rows already skipped over.
                    searchOffset += products.length;
                    searchHasMore = !!(data && data.hasMore);

                    rebuildList();
                    renderMoreRow();
                    showStatus('');
                    filter('');

                    if (append) {
                        // The rows just added push "Show more" down, which slides a product row under
                        // a pointer that has not itself moved — and the browser reports that as the
                        // pointer entering that row. Left alone, the hover handler took the highlight
                        // off wherever the admin had it and dropped it wherever the mouse happened to
                        // be sitting. Hand control back only once the pointer really moves again.
                        pointerDrivesHighlight = false;
                        setActiveByValue(keepActive);
                    }
                }).fail(function (jqXHR, textStatus) {
                    // An abort is this code's own doing, not a failure — reporting it would flash an
                    // error on every keystroke that supersedes the last.
                    if (textStatus === 'abort' || seq !== searchSeq) { return; }
                    // Without this the panel sits on "Searching…" forever whenever the request fails,
                    // which looks identical to a slow search that is still coming.
                    showStatus(append ? 'Could not load more — try again' : 'Search failed — try again');
                }).always(function () {
                    if (seq === searchSeq) { searchXhr = null; }
                });
            };

            if (append) {
                fire();
                return;
            }

            searchRequestTimer = setTimeout(fire, 250);
        }

        $list.on('click', '.ss-more', function (e) {
            e.preventDefault();
            e.stopPropagation();
            // Clicking parks the pointer over the list while rows are about to be inserted under it,
            // so stop treating it as the thing driving the highlight until it moves again.
            pointerDrivesHighlight = false;
            showStatus('Searching…');
            remoteSearch(String($search.val() || ''), { append: true });
        });

        $trigger.on('click', function () { $wrap.hasClass('is-open') ? close() : open(); });
        // ArrowDown on the closed trigger opens it, the way a native <select> does. Enter and Space
        // already open it for free — the trigger is a real <button>, so they fire its click.
        $trigger.on('keydown', function (e) {
            if (e.key === 'ArrowDown' && !$wrap.hasClass('is-open')) {
                e.preventDefault();
                open();
            }
        });
        $search.on('input', function () {
            var q = $(this).val();
            if (!searchUrl) { filter(q); return; }

            // Below the floor: cancel any pending request, drop whatever the last search returned
            // and go back to the hint — deleting characters must not leave a stale result list.
            if (String(q || '').trim().length < MIN_SEARCH_CHARS) {
                clearTimeout(searchRequestTimer);
                clearRemoteOptions();
                showStatus(TYPE_MORE_TEXT);
                return;
            }

            // Said here rather than when the request actually goes out, because the debounce plus the
            // round trip is precisely the window the admin is waiting through — leaving "type at
            // least 2 characters" up for it answers a question they have already answered.
            showStatus('Searching…');
            remoteSearch(q);
        });
        // Commits a row into the real <select> — shared by the click handler and by Enter, so the
        // two paths can't drift apart.
        function chooseItem($item) {
            var value = String($item.attr('data-value') || '');
            $select.val(value).trigger('change');
            rebuildList();
            setTriggerText();
            close();
        }

        $list.on('click', '.ss-item', function (e) {
            e.preventDefault();
            e.stopPropagation();
            chooseItem($(this));
        });

        // Keyboard driving, on the search box because open() puts focus there. Bound here rather
        // than on document so a keystroke only ever reaches the picker the admin is actually in.
        $search.on('keydown', function (e) {
            if (!$wrap.hasClass('is-open')) { return; }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                // preventDefault stops the caret jumping to either end of the search text, which is
                // what an <input> does with the arrows otherwise.
                e.preventDefault();
                moveActive(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }

            if (e.key === 'Enter') {
                // Always swallow Enter while the panel is open: these pickers sit inside the order
                // and quote forms, and letting it through would submit the form instead of picking
                // the highlighted product.
                e.preventDefault();
                commitActive();
                return;
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                close();
                $trigger.trigger('focus');
                return;
            }

            // Tab leaves the picker; close it rather than stranding an open panel behind the
            // next field.
            if (e.key === 'Tab') {
                close();
            }
        });

        // Hovering has to move the highlight too, or the mouse and the keyboard end up disagreeing
        // about which row Enter would take.
        //
        // But mouseenter alone cannot be trusted while arrowing: scrolling the list slides rows
        // underneath a stationary pointer, and the browser reports that as the pointer entering a
        // new row. Acting on it dragged the highlight back to whatever sat under the cursor, so the
        // highlight bounced between the keyboard's target and the mouse's on every keypress — the
        // flicker. A real mousemove is the only proof the pointer, and not the list, moved.
        $list.on('mousemove', function () { pointerDrivesHighlight = true; });
        $list.on('mouseenter', '.ss-item', function () {
            if (!pointerDrivesHighlight) { return; }
            $list.find('.ss-item').removeClass('is-active');
            $(this).addClass('is-active');
        });
        $select.on('change', function () {
            rebuildList();
            setTriggerText();
            if ($select.val()) {
                $wrap.removeClass('is-invalid');
            }
        });
        $(document).on('click', function (e) { if ($wrap.hasClass('is-open') && $(e.target).closest($wrap).length === 0) close(); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape' && $wrap.hasClass('is-open')) close(); });

        rebuildList();
        setTriggerText();
    }

    /* ── Product Lookup Suggest (Inventory Depth) ────────────────────────────────────────────
       A pure enhancement over product_lookup.html.twig's GET search box: a live dropdown of
       matches as the admin types. Reuses the .ss-* classes above for a consistent look, but
       commits by NAVIGATING (each row is a real destination) rather than filling a hidden
       <select> — a different job from initSearchableSelect's, so this is its own small
       function rather than bent to fit that one's assumptions. The plain GET form underneath
       is the real, no-JS baseline; this only ever offers a faster way to reach the same
       destination, never replaces it. */
    function initProductLookupSuggest($input) {
        if (!$input || !$input.length) return;
        if ($input.data('suggestInited')) return;
        $input.data('suggestInited', true);

        var suggestUrl = String($input.attr('data-suggest-url') || '').trim();
        if (!suggestUrl) return;

        // Kept in step with MIN_SEARCH_CHARS above and the floor StockController::productLookupSuggest()
        // enforces server-side.
        var MIN_SEARCH_CHARS = 2;
        var requestTimer = null;
        var seq = 0;
        var xhr = null;

        var $wrap = $('<div class="ss-wrap"></div>');
        var $panel = $('<div class="ss-panel"></div>');
        var $list = $('<ul class="ss-list" role="listbox"></ul>');
        var $empty = $('<div class="ss-empty" style="display:none;"></div>');
        $panel.append($list, $empty);

        $input.wrap($wrap);
        $wrap = $input.parent();
        $wrap.append($panel);

        function open() { $wrap.addClass('is-open'); }
        function close() { $wrap.removeClass('is-open'); }

        function showEmpty(text) {
            $list.empty();
            if (text) { $empty.text(text).show(); } else { $empty.hide(); }
        }

        function render(results) {
            $list.empty();
            if (!results.length) { showEmpty('No matches'); return; }
            $empty.hide();
            results.forEach(function (r) {
                $('<li class="ss-item" role="option"></li>')
                    .attr('data-url', r.url)
                    .text((r.sku ? r.sku + ' — ' : '') + r.name)
                    .appendTo($list);
            });
        }

        function activeItem() { return $list.find('.ss-item.is-active'); }

        // Wraps in both directions, the way ArrowUp/Down on a native <select> does.
        function moveActive(delta) {
            var $items = $list.find('.ss-item');
            if (!$items.length) return;
            var $current = activeItem();
            var index = $current.length ? $items.index($current) : -1;
            index = (index + delta + $items.length) % $items.length;
            $items.removeClass('is-active');
            $items.eq(index).addClass('is-active');
        }

        function goTo(url) { if (url) { window.location.href = url; } }

        function search(q) {
            clearTimeout(requestTimer);
            q = String(q || '').trim();

            if (q.length < MIN_SEARCH_CHARS) {
                close();
                if (xhr) { xhr.abort(); xhr = null; }
                return;
            }

            requestTimer = setTimeout(function () {
                seq += 1;
                var thisSeq = seq;
                if (xhr) { xhr.abort(); }
                xhr = $.getJSON(suggestUrl, { q: q }).done(function (data) {
                    if (thisSeq !== seq) { return; }
                    render((data && data.results) || []);
                    open();
                }).always(function () {
                    if (thisSeq === seq) { xhr = null; }
                });
            }, 250);
        }

        $input.on('input', function () { search($(this).val()); });
        $input.on('focus', function () {
            if ($list.find('.ss-item').length) { open(); }
        });
        $input.on('keydown', function (e) {
            if (!$wrap.hasClass('is-open')) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); } else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); } else if (e.key === 'Escape') { close(); } else if (e.key === 'Enter') {
                var $active = activeItem();
                if ($active.length) {
                    // A row is highlighted: go there instead of submitting the form to the plain
                    // full-page search. No row highlighted (panel just opened, nothing arrowed to
                    // yet) falls through and lets Enter submit as normal — the no-JS path.
                    e.preventDefault();
                    goTo($active.attr('data-url'));
                }
            }
        });
        $list.on('mouseenter', '.ss-item', function () {
            $list.find('.ss-item').removeClass('is-active');
            $(this).addClass('is-active');
        });
        $list.on('click', '.ss-item', function () { goTo($(this).attr('data-url')); });
        $(document).on('click', function (e) { if ($wrap.hasClass('is-open') && $(e.target).closest($wrap).length === 0) close(); });
    }

    $(function () {
        $('.js-product-lookup-suggest').each(function () {
            initProductLookupSuggest($(this));
        });

        $('select.js-searchable-select').each(function () {
            initSearchableSelect($(this));
        });

        // $(document).on('submit', 'form', function (e) {
        //     if (!this.checkValidity()) {
        //         this.reportValidity();
        //         return;
        //     }
            
        //     var $form = $(this);
        //     var $searchableSelects = $form.find('select.js-searchable-select');
        //     var hasError = false;

        //     $searchableSelects.each(function () {
        //         var $select = $(this);
        //         if ($select.data('originally-required') && !$select.val()) {
        //             hasError = true;
        //             var $wrap = $select.closest('.ss-wrap');
        //             $wrap.addClass('is-invalid');
                    
        //             var placeholder = $select.attr('data-placeholder') || 'Select company';
        //             var fieldName = $select.attr('name') || '';
        //             var msg = 'Please select a value.';
        //             if (fieldName === 'company' || placeholder.toLowerCase().indexOf('company') !== -1) {
        //                 msg = 'Please select a company.';
        //             }
        //             showNotification(msg, 'error');
        //         }
        //     });

        //     if (hasError) {
        //         e.preventDefault();
        //         e.stopPropagation();
        //     }
        // });
    });

    function resizeEmailLogFrames() {
        $('.log-body-frame').each(function () {
            var frame = this;

            function resizeFrame() {
                try {
                    var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
                    if (!doc) { return; }

                    var html = doc.documentElement;
                    var body = doc.body;
                    var height = Math.max(
                        html ? html.scrollHeight : 0,
                        html ? html.offsetHeight : 0,
                        body ? body.scrollHeight : 0,
                        body ? body.offsetHeight : 0
                    );

                    if (height > 0) {
                        frame.style.height = Math.ceil(height + 12) + 'px';
                    }
                } catch (e) {
                    frame.style.height = '720px';
                }
            }

            $(frame)
                .off('load.emailLogFrame')
                .on('load.emailLogFrame', function () {
                    resizeFrame();
                    setTimeout(resizeFrame, 150);
                    setTimeout(resizeFrame, 500);
                });

            resizeFrame();
        });
    }

    $(function () {
        resizeEmailLogFrames();
        $window.on('resize.emailLogFrames', debounce(resizeEmailLogFrames, 150));
    });

    /* ── Admin: confirm notifying old email address on email change ────────── */
    function ensureEmailChangeModal() {
        var $modal = $('.js-email-change-modal').first();
        if ($modal.length) { return $modal; }

        $modal = $(''
            + '<div class="email-change-modal js-email-change-modal" hidden>'
            + '  <div class="email-change-modal__backdrop js-email-change-cancel"></div>'
            + '  <div class="email-change-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="email-change-modal-title">'
            + '    <button class="email-change-modal__close js-email-change-cancel" type="button" aria-label="Close">&times;</button>'
            + '    <h2 class="email-change-modal__title" id="email-change-modal-title">Email Address Changed</h2>'
            + '    <p class="email-change-modal__body">Do you want the user to be notified? Their previous email address will be emailed to let them know this account’s email has changed.</p>'
            + '    <div class="email-change-modal__actions">'
            + '      <button class="button outline js-email-change-cancel" type="button">Cancel</button>'
            + '      <button class="button outline js-email-change-no" type="button">No</button>'
            + '      <button class="button primary js-email-change-yes" type="button">Yes</button>'
            + '    </div>'
            + '  </div>'
            + '</div>');

        $('body').append($modal);
        return $modal;
    }

    function closeEmailChangeModal() {
        $('.js-email-change-modal').prop('hidden', true).removeData('form');
    }

    function resolveEmailChange($form, notify) {
        $form.find('input[name="notify_email_change"][value="' + notify + '"]').prop('checked', true);
        $form.data('email-change-confirmed', true);
        closeEmailChangeModal();

        if ($form[0].requestSubmit) {
            $form[0].requestSubmit();
        } else {
            $form.trigger('submit');
        }
    }

    $(function () {
        $('.js-notify-email-change-fieldset').hide();
    });

    $(document).on('submit', 'form.js-admin-user-form', function (e) {
        var $form = $(this);

        if ($form.data('email-change-confirmed')) { return; }

        var $email = $form.find('input[name="email"][data-original-email]');
        if (!$email.length) { return; }

        var original = String($email.attr('data-original-email') || '').trim();
        var current = String($email.val() || '').trim();
        if (current === original) { return; }

        e.preventDefault();
        ensureEmailChangeModal().data('form', $form).prop('hidden', false);
    });

    $(document).on('click', '.js-email-change-cancel', function () {
        closeEmailChangeModal();
    });

    $(document).on('click', '.js-email-change-yes', function () {
        var $form = $('.js-email-change-modal').first().data('form');
        if ($form) { resolveEmailChange($form, 'yes'); }
    });

    $(document).on('click', '.js-email-change-no', function () {
        var $form = $('.js-email-change-modal').first().data('form');
        if ($form) { resolveEmailChange($form, 'no'); }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('.js-email-change-modal').is(':visible')) {
            closeEmailChangeModal();
        }
    });

    /* ── AJAX Grids (Pagination & Search) ──────────────────────────────────────── */

    /* ── Resizable admin table columns ──────────────────────────────────────
       Drag a header cell's trailing edge to resize that column; widths persist
       per table (keyed by page path + column identity) in localStorage so a
       reload keeps them, but they never travel to another device/browser.

       A table's columns are only frozen into an explicit <colgroup> (and the
       table switched to table-layout: fixed) the first time it actually needs
       one — either because saved widths exist for it, or the user starts a
       drag. Until then the table renders exactly as it always has. Column
       identity is derived from data-sort-field (falling back to the header's
       text) rather than column position, so a table whose columns change
       (e.g. Inventory's per-warehouse columns) keeps widths for
       columns that still exist and lets any new column start fresh.

       Grouped/rowspan headers (Product Detail, Product Pricing, Inventory's
       per-warehouse bucket columns) are supported via a standard HTML-table
       occupancy grid: each <th>'s leaf-column span is computed by walking the
       header rows and tracking which grid cells a rowspan/colspan already
       covers, exactly as a browser would lay the table out. A handle on a
       grouped header resizes the rightmost leaf column in its span — visually
       that's where the mouse actually is. Tables that already ship their own
       <colgroup> (Product Pricing) have it adopted rather than replaced. */
    if ($body.hasClass('site-admin')) {
        var COLUMN_WIDTH_STORAGE_PREFIX = 'adminColumnWidths:';
        var MIN_COLUMN_WIDTH = 24;

        function columnWidthsStorageKey($table, tableIndex) {
            return COLUMN_WIDTH_STORAGE_PREFIX + window.location.pathname + '#' + tableIndex;
        }

        function columnKeyForHeader($th) {
            var sortField = $th.data('sort-field');
            if (sortField) { return 'sort:' + sortField; }
            return 'text:' + $th.text().replace(/\s+/g, ' ').trim().toLowerCase();
        }

        function loadColumnWidths(key) {
            try {
                var raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : {};
            } catch (e) {
                return {};
            }
        }

        function saveColumnWidths(key, widths) {
            try {
                localStorage.setItem(key, JSON.stringify(widths));
            } catch (e) {
                // ignore (private mode / storage full)
            }
        }

        // Walks the non-filter-row <thead> rows and places each <th> into a leaf-column
        // occupancy grid (same algorithm a browser uses for rowspan/colspan layout),
        // returning each cell's [colStart, colSpan) plus the table's total leaf columns.
        function computeHeaderGrid($table) {
            var grid = [];
            var cellPositions = [];

            function ensureRow(r) {
                while (grid.length <= r) { grid.push([]); }
            }

            function firstFreeCol(r) {
                ensureRow(r);
                var c = 0;
                while (grid[r][c]) { c++; }
                return c;
            }

            $table.find('> thead > tr').not('.filter-row').each(function (r) {
                ensureRow(r);
                // Hidden columns (e.g. unchecked in "Choose Columns") stay in the DOM as
                // display:none <th>/<td> so filters/sort links keep working, but the browser's
                // real table layout excludes them from the column grid entirely. Counting them
                // here would reserve <colgroup> slots nothing visible occupies, offsetting every
                // later column's width/resize by however many columns are hidden. Issue #123.1.
                $(this).children('th').filter(':visible').each(function () {
                    var $th = $(this);
                    var colSpan = parseInt($th.attr('colspan'), 10) || 1;
                    var rowSpan = parseInt($th.attr('rowspan'), 10) || 1;
                    var c = firstFreeCol(r);
                    for (var rr = r; rr < r + rowSpan; rr++) {
                        ensureRow(rr);
                        for (var cc = c; cc < c + colSpan; cc++) {
                            grid[rr][cc] = true;
                        }
                    }
                    cellPositions.push({ $th: $th, colStart: c, colSpan: colSpan });
                });
            });

            var totalCols = 0;
            grid.forEach(function (row) { totalCols = Math.max(totalCols, row.length); });
            return { cellPositions: cellPositions, totalCols: totalCols };
        }

        // Prefers the first real data row's <td> widths (always one per leaf column,
        // no col/rowspan math needed); falls back to single-span header cells for
        // whichever columns that row can't cover (e.g. an empty-state table).
        function measureNaturalWidths($table, grid) {
            var widths = new Array(grid.totalCols).fill(0);
            var $firstBodyRow = $table.find('> tbody > tr').filter(function () {
                return !$(this).find('.empty-table-cell').length;
            }).first();
            var $cells = $firstBodyRow.find('> td').filter(':visible');
            if ($cells.length === grid.totalCols) {
                $cells.each(function (i) { widths[i] = Math.round(this.getBoundingClientRect().width); });
                return widths;
            }

            grid.cellPositions.forEach(function (cp) {
                if (cp.colSpan === 1) {
                    widths[cp.colStart] = Math.round(cp.$th[0].getBoundingClientRect().width);
                }
            });
            for (var i = 0; i < widths.length; i++) {
                if (!widths[i]) { widths[i] = 100; }
            }
            return widths;
        }

        $('table').each(function (tableIndex) {
            var $table = $(this);
            var grid = computeHeaderGrid($table);
            if (!grid.totalCols || grid.cellPositions.length < 2) { return; }

            var storageKey = columnWidthsStorageKey($table, tableIndex);
            var savedWidths = loadColumnWidths(storageKey);
            var $colgroup = $table.find('> colgroup').first();
            var frozen = false;

            function ensureFrozen() {
                if (frozen) { return; }
                frozen = true;

                if (!$colgroup.length) {
                    $colgroup = $('<colgroup></colgroup>');
                    for (var i = 0; i < grid.totalCols; i++) { $colgroup.append('<col>'); }
                    $table.prepend($colgroup);
                }

                // Measure every column's current natural width BEFORE applying any of them —
                // setting one column's width first would shrink the auto-layout space left
                // for columns measured afterward, skewing their "natural" width reading.
                var naturalWidths = measureNaturalWidths($table, grid);
                var resolvedWidths = naturalWidths.slice();

                // A grouped header and its rightmost leaf sub-header can both terminate at
                // the same physical column (two different keys, one column) — fill the
                // natural baseline first, then let ANY cellPosition's saved width for that
                // column override it, regardless of which one gets processed last below.
                grid.cellPositions.forEach(function (cp) {
                    var rightCol = cp.colStart + cp.colSpan - 1;
                    var key = columnKeyForHeader(cp.$th);
                    if (savedWidths[key] != null) {
                        resolvedWidths[rightCol] = savedWidths[key];
                    }
                });

                var $cols = $colgroup.find('col');
                resolvedWidths.forEach(function (width, i) {
                    $cols.eq(i).css('width', (width || 100) + 'px');
                });
                $table.css('table-layout', 'fixed');
            }

            if (Object.keys(savedWidths).length) {
                ensureFrozen();
            }

            grid.cellPositions.forEach(function (cp) {
                var $th = cp.$th;
                var rightCol = cp.colStart + cp.colSpan - 1;
                var colKey = columnKeyForHeader($th);
                var $handle = $('<span class="col-resize-handle" aria-hidden="true"></span>');
                $th.append($handle);

                // The handle sits inside the sortable <th> (which contains the sort <a>). Without
                // this, a resize drag ends in a click that lands on the sort link and re-sorts the
                // table. Stop the mousedown from reaching the link, and swallow the click the browser
                // synthesises at the end of a drag. Issue #120.2.
                $handle.on('click', function (clickEvent) {
                    clickEvent.preventDefault();
                    clickEvent.stopPropagation();
                });

                $handle.on('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    ensureFrozen();
                    var $col = $colgroup.find('col').eq(rightCol);
                    var startX = e.pageX;
                    var startWidth = parseFloat($col[0].style.width);
                    var moved = false;

                    $body.addClass('is-resizing-column');

                    function onMouseMove(moveEvent) {
                        moved = true;
                        var next = Math.max(MIN_COLUMN_WIDTH, Math.round(startWidth + (moveEvent.pageX - startX)));
                        $col.css('width', next + 'px');
                    }

                    function onMouseUp() {
                        $(document).off('mousemove', onMouseMove).off('mouseup', onMouseUp);
                        $body.removeClass('is-resizing-column');
                        savedWidths[colKey] = parseFloat($col.css('width')) || MIN_COLUMN_WIDTH;
                        saveColumnWidths(storageKey, savedWidths);

                        if (moved) {
                            // Eat the click that fires right after the drag so it can't hit the sort link.
                            var swallow = function (clickEvent) {
                                clickEvent.preventDefault();
                                clickEvent.stopPropagation();
                                document.removeEventListener('click', swallow, true);
                            };
                            document.addEventListener('click', swallow, true);
                            setTimeout(function () { document.removeEventListener('click', swallow, true); }, 0);
                        }
                    }

                    $(document).on('mousemove', onMouseMove).on('mouseup', onMouseUp);
                });
            });
        });
    }
})(window.jQuery);

/* ── Global HTML5 Validation for Create/Edit Forms ───────────────── */
document.addEventListener('click', function (e) {
    const submitBtn = e.target.closest('.form-grid button[type="submit"]');

    if (!submitBtn) return;

    const form = submitBtn.closest('form');
    if (!form) return;

    if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();

        const invalid = form.querySelector(':invalid');

        if (invalid) {
            invalid.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            setTimeout(function () {
                invalid.focus();
                form.reportValidity();
            }, 150);
        }

        return false;
    }
}, true);

/* ── Cart Hold Countdown (CartHoldBundle) ────────────────────────────────── */
(function () {
    var banner = document.querySelector('.cart-hold-banner');
    if (!banner) { return; }

    var countdownEl = banner.querySelector('.js-cart-hold-countdown');
    var expiresAtRaw = banner.getAttribute('data-expires-at');
    var expiresAt = new Date(expiresAtRaw);
    if (!countdownEl || isNaN(expiresAt.getTime())) { return; }

    // Reloading is a one-shot nudge to let the server re-render post-sweep — it is not itself
    // proof the sweep happened. If the reloaded page still carries this exact same
    // data-expires-at (clock skew, a slow request eating the last second, or a server-side bug
    // that fails to clear it), reloading again would just repeat forever. sessionStorage
    // survives the full navigation a reload does, so it's the only way this script — which
    // starts with a clean slate on every load — can tell "already tried this one" from "new
    // expiry".
    var RELOAD_GUARD_KEY = 'cartHoldReloadedFor';
    var alreadyReloadedForThis = false;
    try {
        alreadyReloadedForThis = window.sessionStorage.getItem(RELOAD_GUARD_KEY) === expiresAtRaw;
    } catch (e) { /* sessionStorage unavailable (e.g. private mode) — fall back to one reload attempt */ }

    function tick() {
        var remainingMs = expiresAt.getTime() - Date.now();
        if (remainingMs <= 0) {
            countdownEl.textContent = '0:00';

            if (alreadyReloadedForThis) {
                // Already reloaded once for this exact expiry and it's still here — stop
                // forcing reloads and just wait for it to actually change server-side.
                window.setTimeout(tick, 2000);
                return;
            }

            try { window.sessionStorage.setItem(RELOAD_GUARD_KEY, expiresAtRaw); } catch (e) { /* ignore */ }
            // The hold has expired server-side too by now — reload so
            // CartHoldSweepSubscriber's sweep + release notice take over.
            window.location.reload();
            return;
        }

        var totalSeconds = Math.ceil(remainingMs / 1000);
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        countdownEl.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

        window.setTimeout(tick, 1000);
    }

    tick();
})();

/* ── Product Detail: Choose Columns chooser (issue #120.5) ───────────── */
(function () {
    var toggle = document.querySelector('.js-choose-columns-toggle');
    var chooser = document.getElementById('column-chooser');
    if (!toggle || !chooser) {
        return;
    }

    function close() { chooser.hidden = true; }

    toggle.addEventListener('click', function () {
        chooser.hidden = !chooser.hidden;
    });

    chooser.querySelectorAll('.js-choose-columns-close').forEach(function (btn) {
        btn.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); }
    });
})();

/* Close an open `.no-js-row-actions` <details> menu (the "..." button) when the click lands
   outside it, or on Escape. Native <details> only closes from its own summary. */
(function () {
    function closeMenus(except) {
        document.querySelectorAll('details.no-js-row-actions[open]').forEach(function (d) {
            if (d !== except) { d.removeAttribute('open'); }
        });
    }

    document.addEventListener('click', function (e) {
        closeMenus(e.target.closest ? e.target.closest('details.no-js-row-actions') : null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeMenus(null); }
    });
})();
