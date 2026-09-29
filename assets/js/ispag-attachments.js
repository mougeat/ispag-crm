jQuery(document).ready(function($) {
    // console. log('[attachments] -> initialisé');
    // Clic sur "See all attachments"
    $(document).on('click', '.ispag-docu-card__view-all', function(e) {
        e.preventDefault();

        // console. log('[attachments] -> Clic ' + ispagAttachmentsAjax.texts.loading);
        $('.edit-activity').css('display', 'none');
        window.openTaskSidebar('attachement', null, $(this));
        
    });
});

jQuery(document).ready(function($) {
    console.log('ISPAG Debug - Début du script d\'attachements');

    var $cards = $('.ispag-docu-card');
    console.log('ISPAG Debug - Nombre de cartes .ispag-docu-card trouvées :', $cards.length);

    if ($cards.length > 0) {
        // Chargement différé pour laisser la page s'afficher en priorité
        setTimeout(function() {
            $cards.each(function() {
                var $card = $(this);
                var entity_id = $card.data('entity-id');
                var entity_type = $card.data('entity-type');
                const view = $card.data('view') || 'card';

                console.log('ISPAG Debug - Traitement carte - entity_id :', entity_id, '/ entity_type :', entity_type);

                if (!entity_id) {
                    console.warn('ISPAG Debug - ATTENTION : entity_id est vide ou undefined sur cette carte !');
                    return;
                }

                var nonceValue = (typeof ISPAG_TANK !== 'undefined' && ISPAG_TANK.nonce) ? ISPAG_TANK.nonce : '';

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ispag_refresh_attachments',
                        entity_id: entity_id,
                        entity_type: entity_type,
                        view: view,
                        security: nonceValue
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.html) {
                            if (response.data.view === 'list') {
                                $card.html(response.data.html);
                            } else {
                                $card.replaceWith(response.data.html);
                            }
                        } else {
                            console.warn('ISPAG Debug - Réponse AJAX en échec :', response);
                            var errorMsg = (response.data && response.data.message) ? response.data.message : 'No attachment found.';
                            $card.html('<p class="error" style="padding: 10px; color: #666;">' + errorMsg + '</p>');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error('ISPAG Debug - Erreur technique AJAX :', textStatus, errorThrown);
                        $card.html('<p class="error" style="padding: 10px; color: #e74c3c;">Erreur lors du chargement des attachements.</p>');
                    }
                });
            });
        }, 200);

    } else {
        console.log('ISPAG Debug - Aucune carte .ispag-docu-card trouvée sur cette page.');
    }
});