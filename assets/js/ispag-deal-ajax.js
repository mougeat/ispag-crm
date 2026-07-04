// On définit la fonction à l'extérieur pour qu'elle soit globale
window.sendUpdateStage = function(dealId, newStageKey, reason = '') {
    const $ = jQuery; 

    $.ajax({
        url: ispag_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'ispag_update_deal_stage',
            deal_id: dealId,
            stage: newStageKey,
            reason: reason
        },
        success: function(r) { 
            if(r.success) {
                // console.log("✅ Mise à jour réussie");
            } else {
                alert("Erreur: " + (r.data ? r.data.message : 'Inconnue')); 
            }
        },
        error: function() {
            console.error("❌ Erreur réseau lors de l'envoi");
        }
    });
};

/**
 * Mise à jour groupée incluant l'étape, la date de contact et le motif de perte
 */
window.executeBulkAjax = function(ids, stageKey, contactDate, reason) { // Ajout de contactDate ici
    const $ = jQuery;

    $.ajax({
        url: ispag_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'ispag_bulk_update_deals',
            deal_ids: ids,
            stage: stageKey,
            last_contact: contactDate, // On envoie la date au serveur
            reason: reason
        },
        success: function(r) {
            if(r.success) {
                location.reload(); 
            } else {
                alert("Erreur lors de la mise à jour groupée");
            }
        },
        error: function() {
            console.error("❌ Erreur réseau lors de l'envoi Bulk");
        }
    });
};