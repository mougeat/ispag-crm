/* Suppression d'un contact / d'une entreprise (administrateurs) : aperçu des liens, puis confirmation. */
(function ($) {
    'use strict';
    if (typeof ispagDeleteEntity === 'undefined') return;
    var C = ispagDeleteEntity, T = C.i18n;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function post(action, data) {
        return $.post(C.ajax_url, $.extend({ action: action, nonce: C.nonce }, data));
    }
    function msg(r) { return (r && r.responseJSON && r.responseJSON.data && r.responseJSON.data.message) || T.error; }

    function close() { $('#ispag-del-overlay').remove(); }
    function open(html) {
        close();
        $('body').append(
            '<div id="ispag-del-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px">' +
            '<div role="dialog" aria-modal="true" style="background:#fff;border-radius:12px;max-width:440px;width:100%;padding:22px 24px;box-shadow:0 20px 50px rgba(0,0,0,.3);font-size:15px;line-height:1.45;color:#1f2937">' + html + '</div></div>'
        );
    }
    var btn = 'border:0;border-radius:8px;padding:9px 16px;font-weight:600;cursor:pointer;font-size:14px;';

    $(document).on('click', '.ispag-dropdown-item[data-action="delete"]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $b = $(this), entity = $b.data('entity') === 'company' ? 'company' : 'contact', id = parseInt($b.data('id'), 10);
        if (!id) return;
        open('<p>' + esc(T.loading) + '</p>');
        post('ispag_crm_delete_preview', { entity: entity, id: id }).done(function (res) {
            var d = res.data, h = '<h3 style="margin:0 0 6px;font-size:18px">' + esc(entity === 'company' ? T.title_company : T.title_contact) + '</h3><p style="margin:0 0 12px"><strong>' + esc(d.name) + '</strong></p>';
            if (d.blockers.length) {
                h += '<p style="margin:0 0 6px">' + esc(T.blocked) + '</p><ul style="margin:0 0 10px 18px">' + d.blockers.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
                if (d.blockers.length) h += '<p style="margin:0 0 14px;color:#6b7280">' + esc(T.detach_first) + '</p>';
                h += '<div style="text-align:right"><button type="button" id="ispag-del-cancel" style="' + btn + 'background:#e5e7eb;color:#111">' + esc(T.close) + '</button></div>';
            } else {
                if (d.removes.length) h += '<p style="margin:0 0 6px">' + esc(T.will_delete) + '</p><ul style="margin:0 0 10px 18px">' + d.removes.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
                h += '<p style="margin:0 0 16px;color:#b91c1c;font-weight:600">' + esc(T.irreversible) + '</p>' +
                    '<div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" id="ispag-del-cancel" style="' + btn + 'background:#e5e7eb;color:#111">' + esc(T.cancel) + '</button>' +
                    '<button type="button" id="ispag-del-ok" style="' + btn + 'background:#dc2626;color:#fff">' + esc(T.confirm) + '</button></div>';
            }
            open(h);
            $('#ispag-del-ok').on('click', function () {
                var $ok = $(this).prop('disabled', true).text(T.loading);
                post('ispag_crm_delete_entity', { entity: entity, id: id }).done(function (r) {
                    window.location.href = r.data.redirect || '/';
                }).fail(function (x) { $ok.prop('disabled', false).text(T.confirm); open('<p style="color:#b91c1c">' + esc(msg(x)) + '</p><div style="text-align:right"><button type="button" id="ispag-del-cancel" style="' + btn + 'background:#e5e7eb;color:#111">' + esc(T.close) + '</button></div>'); });
            });
        }).fail(function (x) {
            open('<p style="color:#b91c1c">' + esc(msg(x)) + '</p><div style="text-align:right"><button type="button" id="ispag-del-cancel" style="' + btn + 'background:#e5e7eb;color:#111">' + esc(T.close) + '</button></div>');
        });
    });
    $(document).on('click', '#ispag-del-cancel', close);
    $(document).on('click', '#ispag-del-overlay', function (e) { if (e.target === this) close(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') close(); });
})(jQuery);
