// console.log('[ISPAG Activity ] fichier JS chargé');

jQuery(function ($) {
    'use strict';

    // 1. Chargement en arrière-plan dès que le DOM est prêt
    $(document).ready(function() {
        var $activityPane = $('#ispag-tab-activity');
        
        if ($activityPane.length > 0) {
            setTimeout(function() {
                if (!$activityPane.data('loaded') && !$activityPane.data('loading')) {
                    loadActivityTab($activityPane);
                }
            }, 200);
        }
    });

    // 2. Fonction de chargement AJAX
    function loadActivityTab($pane) {
        var dealId = $pane.data('deal-id');

        if (!dealId) {
            // console.warn('[ISPAG Activity Tracker] STOP : pas de deal-id sur le pane'); 
            return;
        }

        $pane.data('loading', true);
        
        // On n'affiche le squelette que si le contenu est vide (pour le chargement initial en arrière-plan)
        if (!$.trim($pane.html())) {
            $pane.html(ispag_texts.activity_squeleton);
        }

        $.post(ispagPhaseTracker.ajaxUrl, {
            action: 'ispag_render_activity_tab',
            _ajax_nonce: ispagPhaseTracker.nonce,
            hubspot_deal_id: dealId
        })
        .done(function (response) {
            // console.log('[ISPAG Activity Tracker] réponse AJAX reçue :', response);
            if (response.success) {
                $pane.html(response.data.html);
                $pane.data('loaded', true);
            } else {
                $pane.html('<p class="ispag-error-message">' + (response.data.message || 'Error.') + '</p>');
            }
        })
        .fail(function (xhr) {
            // console.error('[ISPAG Activity Tracker] échec AJAX :', xhr.status, xhr.responseText);
            $pane.html('<p class="ispag-error-message">Network error.</p>');
        })
        .always(function() {
            $pane.removeData('loading');
        });
    }

    // 3. Gestion du clic sur l'onglet
    $(document).on('click', '.ispag-tab-btn[data-tab="activity"]', function () {
        // console.log('[ISPAG Activity] clic détecté sur l\'onglet activity');
        var $pane = $('#ispag-tab-activity');
        
        if (!$pane.data('loaded') && !$pane.data('loading')) {
            loadActivityTab($pane);
        }
    });
});