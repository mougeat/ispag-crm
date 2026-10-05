window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
/**
 * Page projet : ajout / retrait de l'entreprise et des contacts du projet (cartes de droite).
 * Boutons : .ispag-proj-assoc-add (data-type, data-deal-id) et .ispag-proj-assoc-remove (data-type, data-id, data-deal-id).
 */
jQuery(function ($) {
    if (typeof ispagProjAssoc === 'undefined') return;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    function post(data) {
        return $.ajax({ url: ispagProjAssoc.ajax_url, type: 'POST', dataType: 'json', data: $.extend({ security: ispagProjAssoc.nonce }, data) });
    }

    function failMsg(r) {
        return ispagT('Error: ') + ((r && r.responseJSON && r.responseJSON.data && r.responseJSON.data.message) || (r && r.data && r.data.message) || '');
    }

    // ---------------------------------------------------------------- retrait
    $(document).on('click', '.ispag-proj-assoc-remove', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $b = $(this);
        const type = $b.data('type');
        if (!confirm(type === 'company' ? ispagT('Remove this company from the project?') : ispagT('Remove this contact from the project?'))) return;
        $b.css('opacity', 0.4);
        post({ action: 'ispag_project_assoc_remove', type: type, id: $b.data('id'), deal_id: $b.data('deal-id') })
            .done(function (r) { r && r.success ? location.reload() : (alert(failMsg(r)), $b.css('opacity', 1)); })
            .fail(function (x) { alert(failMsg(x)); $b.css('opacity', 1); });
    });

    // ---------------------------------------------------------------- ajout (fenêtre de recherche)
    let $overlay = null, timer = null, current = null;

    function close() {
        if ($overlay) { $overlay.remove(); $overlay = null; }
        $(document).off('keydown.ispagAssoc');
    }

    function search() {
        const term = $overlay.find('.ia-term').val();
        const only = $overlay.find('.ia-only').is(':checked') ? 1 : 0;
        $overlay.find('.ia-list').html('<p class="ia-muted">…</p>');
        post({ action: 'ispag_project_assoc_search', type: current.type, deal_id: current.deal, term: term, company_only: only })
            .done(function (r) {
                if (!r || !r.success) { $overlay.find('.ia-list').html('<p class="ia-muted">' + esc(failMsg(r)) + '</p>'); return; }
                const d = r.data;
                $overlay.find('.ia-only-wrap').toggle(current.type === 'contact' && d.has_company);
                $overlay.find('.ia-note').toggle(current.type === 'company' && d.has_company && d.company_single);
                if (!d.results.length) { $overlay.find('.ia-list').html('<p class="ia-muted">' + esc(ispagT('No result.')) + '</p>'); return; }
                $overlay.find('.ia-list').html(d.results.map(function (x) {
                    return '<div class="ia-row"><div><strong>' + esc(x.name) + '</strong><br><small>' + esc(x.sub) + '</small></div>' +
                        '<button type="button" class="ia-pick" data-id="' + x.id + '">' + esc(ispagT('Add')) + '</button></div>';
                }).join(''));
            })
            .fail(function (x) { $overlay.find('.ia-list').html('<p class="ia-muted">' + esc(failMsg(x)) + '</p>'); });
    }

    $(document).on('click', '.ispag-proj-assoc-add', function (e) {
        e.preventDefault();
        e.stopPropagation();
        close();
        current = { type: $(this).data('type'), deal: $(this).data('deal-id') };
        const title = current.type === 'company' ? ispagT('Add a company to the project') : ispagT('Add a contact to the project');
        $overlay = $(
            '<div class="ia-overlay"><div class="ia-box" role="dialog" aria-modal="true">' +
            '<div class="ia-head"><h4>' + esc(title) + '</h4><button type="button" class="ia-close" aria-label="' + esc(ispagT('Close')) + '">&times;</button></div>' +
            '<input type="search" class="ia-term" placeholder="' + esc(ispagT('Search…')) + '" autocomplete="off">' +
            '<label class="ia-only-wrap" style="display:none"><input type="checkbox" class="ia-only" checked> ' + esc(ispagT('Only the contacts of the project company')) + '</label>' +
            '<p class="ia-note" style="display:none">' + esc(ispagT('This replaces the current company of the project.')) + '</p>' +
            '<div class="ia-list"></div></div></div>'
        ).appendTo('body');
        $overlay.find('.ia-term').trigger('focus');
        $(document).on('keydown.ispagAssoc', function (ev) { if (ev.key === 'Escape') close(); });
        search();
    });

    $(document).on('click', '.ia-close', close);
    $(document).on('mousedown', '.ia-overlay', function (e) { if (e.target === this) close(); });
    $(document).on('input', '.ia-term', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
    $(document).on('change', '.ia-only', search);

    $(document).on('click', '.ia-pick', function () {
        const $b = $(this).prop('disabled', true);
        post({ action: 'ispag_project_assoc_add', type: current.type, id: $b.data('id'), deal_id: current.deal })
            .done(function (r) { r && r.success ? location.reload() : (alert(failMsg(r)), $b.prop('disabled', false)); })
            .fail(function (x) { alert(failMsg(x)); $b.prop('disabled', false); });
    });

    // ---------------------------------------------------------------- styles de la fenêtre
    $('<style>').text(
        '.ia-overlay{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:flex-start;justify-content:center;padding:8vh 16px}' +
        '.ia-box{background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.3);width:100%;max-width:520px;max-height:80vh;display:flex;flex-direction:column;padding:16px}' +
        '.ia-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.ia-head h4{margin:0}' +
        '.ia-close{background:none;border:0;font-size:26px;line-height:1;cursor:pointer}' +
        '.ia-term{width:100%;padding:10px;font-size:15px;border:1px solid #d1d5db;border-radius:8px}' +
        '.ia-only-wrap{display:block;margin:8px 0;font-size:13px}.ia-note{font-size:13px;color:#b45309;margin:8px 0}' +
        '.ia-list{overflow:auto;margin-top:8px}.ia-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 2px;border-bottom:1px solid #eef0f2}' +
        '.ia-row small,.ia-muted{color:#6b7280}.ia-pick{background:#c80000;color:#fff;border:0;border-radius:8px;padding:6px 14px;cursor:pointer;font-weight:600}.ia-pick:disabled{opacity:.5}'
    ).appendTo('head');
});
