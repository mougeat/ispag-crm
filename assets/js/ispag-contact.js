jQuery(document).ready(function($) {
    // console.log('ISPAG JS : Le script jQuery des contacts est bien chargé et exécuté.');

    var $card = $('.ispag-contact-card');
    // console.log('ISPAG JS : Nombre de cartes .ispag-contact-card trouvées :', $card.length);
    
    if ($card.length > 0) {
        var hubspotDealId = $card.data('deal-id');
        // console.log('ISPAG JS : Deal ID récupéré :', hubspotDealId);
        
        if (hubspotDealId) {
            // console.log('ISPAG JS : Lancement de la requête AJAX contacts avec le deal ID :', hubspotDealId);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ispag_load_contacts_datas',
                    hubspot_deal_id: hubspotDealId
                },
                success: function(response) {
                    // console.log('ISPAG JS : Réponse AJAX contacts reçue du serveur :', response);
                    
                    if (response.success && response.data.html) {
                        // console.log('ISPAG JS : Succès ! Injection du HTML des contacts dans la carte.');
                        $card.html(response.data.html);
                    } else {
                        console.warn('ISPAG JS : Erreur ou HTML vide renvoyé pour les contacts :', response);
                        var errorMsg = (response.data && response.data.message) ? response.data.message : 'No contact found.';
                        $card.html('<p class="error" style="padding: 10px; color: #666;">' + errorMsg + '</p>');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('ISPAG JS : Erreur AJAX contacts :', textStatus, errorThrown);
                    $card.html('<p class="error" style="padding: 10px; color: #e74c3c;">Erreur lors du chargement des contacts.</p>');
                }
            });
        } else {
            console.warn('ISPAG JS : La carte contact existe, mais data-deal-id est vide ou absent.');
        }
    } else {
        // console.log('ISPAG JS : Aucune carte .ispag-contact-card n’est présente sur cette page.');
    }
}); 