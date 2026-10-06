jQuery(document).ready(function($) {

    if (typeof ispag_ajax === 'undefined' || ispag_ajax === null) {
        console.error('ISPAG Kanban variables are not loaded.');
        return;
    }

    const $board = $('.ispag-kanban-board');
    if (!$board.length) return;

    const filters = $board.data('filters') || {};
    const fmt = n => Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, "'");

    // --- Toast discret (même look que les autres modules) ---
    function toast(message, type) {
        const $t = $('<div class="ispag-kanban-toast"></div>').addClass(type || 'success').text(message).appendTo('body');
        setTimeout(() => $t.addClass('visible'), 10);
        setTimeout(() => { $t.removeClass('visible'); setTimeout(() => $t.remove(), 300); }, 2500);
    }

    // --- Totaux / compteurs d'une colonne, recalculés depuis les cartes chargées ---
    // Les colonnes « paginées » gardent leur total serveur : on applique un delta.
    function adjustColumn($column, deltaCount, deltaAmount) {
        const $badge = $column.find('.kanban-count-badge');
        const count  = Math.max(0, parseInt($badge.text(), 10) + deltaCount);
        const total  = Math.max(0, ($column.data('total') ?? parseFloat($column.find('.kanban-column-footer .total-amount strong').text().replace(/'/g, ''))) + deltaAmount);
        const proba  = parseFloat($column.data('probability')) || 0;

        $column.data('total', total);
        $badge.text(count);
        $column.find('.kanban-column-subtotal').text(fmt(total) + ' CHF');
        $column.find('.total-amount strong').text(fmt(total) + ' CHF');
        $column.find('.weighted-amount strong').text(fmt(total * proba / 100) + ' CHF');
        $column.find('.no-deals-message').toggle($column.find('.kanban-deal-card').length === 0);
    }

    // --- DRAG AND DROP (délégué : fonctionne aussi pour les cartes chargées en AJAX) ---
    let dragged = null;

    $board.on('dragstart', '.kanban-deal-card', function(event) {
        dragged = this;
        event.originalEvent.dataTransfer.setData('text/plain', $(this).data('deal-id').toString());
        event.originalEvent.dataTransfer.effectAllowed = 'move';
        setTimeout(() => $(this).addClass('is-dragging'), 0);
        $board.addClass('is-dragging-active');
    });

    $board.on('dragend', '.kanban-deal-card', function() {
        $(this).removeClass('is-dragging');
        $board.removeClass('is-dragging-active');
        $('.ispag-deals-dropzone').removeClass('is-drag-over');
        dragged = null;
    });

    $board.on('dragover', '.ispag-deals-dropzone', function(event) {
        event.preventDefault();
        $(this).addClass('is-drag-over');
    });

    $board.on('dragleave', '.ispag-deals-dropzone', function(event) {
        if (!this.contains(event.originalEvent.relatedTarget)) $(this).removeClass('is-drag-over');
    });

    $board.on('drop', '.ispag-deals-dropzone', function(event) {
        event.preventDefault();
        const $zone = $(this).removeClass('is-drag-over');
        const dealId = event.originalEvent.dataTransfer.getData('text/plain');
        const newStage = $zone.data('stage-key');
        const $card = $(dragged || $('.kanban-deal-card[data-deal-id="' + dealId + '"]'));
        if (!dealId || !newStage || !$card.length) return;

        const $oldZone = $card.closest('.ispag-deals-dropzone');
        if ($oldZone[0] === $zone[0]) return;

        const amount = parseFloat($card.data('amount')) || 0;
        const $next  = $card.next();
        const $newCol = $zone.closest('.kanban-column'), $oldCol = $oldZone.closest('.kanban-column');
        const newColor = $newCol.find('.kanban-column-header').css('border-top-color');

        // Position serveur du « Voir plus » : une carte qui quitte la colonne décale la liste
        const adjustLoaded = (zone, d) => { const $b = $(zone).find('.kanban-load-more'); if ($b.length) $b.data('loaded', Math.max(0, (parseInt($b.data('loaded'), 10) || 0) + d)); };
        const wasMoved = $card.data('moved') === 1;
        if (!wasMoved) adjustLoaded($oldZone, -1);
        else $card.data('moved', 1);

        // Mise à jour optimiste, annulée si le serveur refuse
        $card.insertBefore($zone.find('.kanban-load-more, .no-deals-message').first()).css('border-left-color', newColor);
        adjustColumn($oldCol, -1, -amount);
        adjustColumn($newCol, +1, +amount);
        $card.addClass('is-saving').data('moved', 1);

        function revert(message) {
            if (!wasMoved) { adjustLoaded($oldZone, +1); $card.removeData('moved'); }
            if ($next.length) $card.insertBefore($next); else $oldZone.prepend($card);
            $card.css('border-left-color', $oldCol.find('.kanban-column-header').css('border-top-color'));
            adjustColumn($oldCol, +1, +amount);
            adjustColumn($newCol, -1, -amount);
            toast(message, 'error');
        }

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            data: { action: 'ispag_update_deal_stage', deal_id: dealId, stage: newStage, reason: '' }
        }).done(function(r) {
            if (r && r.success) toast('✓ ' + $newCol.find('.kanban-column-header h4').clone().children().remove().end().text().trim());
            else revert((r && r.data && r.data.message) || 'Error');
        }).fail(function() {
            revert('Network error');
        }).always(function() {
            $card.removeClass('is-saving');
        });
    });

    // --- « Voir plus » : charge la suite d'une colonne ---
    $board.on('click', '.kanban-load-more', function() {
        const $btn = $(this).prop('disabled', true).addClass('is-loading');
        const $zone = $btn.closest('.ispag-deals-dropzone');
        const $col  = $btn.closest('.kanban-column');
        const loaded = parseInt($btn.data('loaded'), 10) || 0; // position côté serveur, indépendante des cartes déplacées

        $.post(ispag_ajax.ajax_url, $.extend({}, filters, {
            action: 'ispag_kanban_load_more',
            nonce: ispag_ajax.nonce,
            stage_key: $zone.data('stage-key'),
            offset: loaded
        })).done(function(r) {
            if (!r || !r.success) { $btn.prop('disabled', false).removeClass('is-loading'); toast((r && r.data && r.data.message) || 'Error', 'error'); return; }
            $btn.before(r.data.html).data('loaded', r.data.loaded);
            if (r.data.remaining > 0) {
                $btn.prop('disabled', false).removeClass('is-loading').text($btn.text().replace(/\(\d+\)/, '(' + r.data.remaining + ')'));
            } else {
                $btn.remove();
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false).removeClass('is-loading');
            const m = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            toast(m || ('Network error' + (xhr && xhr.status ? ' (' + xhr.status + ')' : '')), 'error');
        });
    });
});
