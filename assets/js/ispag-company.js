window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
jQuery(document).ready(function($) {
    var $card = $('.ispag-company-card');
    
    if ($card.length > 0) {
        var hubspotDealId = $card.data('deal-id');
        
        if (hubspotDealId) {
            $.ajax({
                url: ajaxurl, 
                type: 'POST',
                data: {
                    action: 'ispag_load_companies_datas', 
                    hubspot_deal_id: hubspotDealId
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $card.html(response.data.html);
                    } else {
                        var errorMsg = (response.data && response.data.message) ? response.data.message : ispagT('Loading error');
                        $card.html('<p class="error" style="padding: 10px; color: #666;">' + errorMsg + '</p>');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Droit manquant (403) : la carte n'a pas à s'afficher
                    if (jqXHR && jqXHR.status === 403) { $card.remove(); return; }
                    $card.html('<p class="error" style="padding: 10px; color: #e74c3c;">Error while loading data.</p>');
                }
            });
        }
    }
});