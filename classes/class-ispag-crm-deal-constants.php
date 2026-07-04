
<?php 

// Fichier : includes/crm/class-ispag-crm-deal-constants.php

if ( ! class_exists( 'ISPAG_Crm_Deal_Constants' ) ) :
class ISPAG_Crm_Deal_Constants {
    const TABLE_NAME                = 'wor9711_ispag_deals_list';
    const TABLE_DEALS_STAGES        = 'wor9711_ispag_deals_stages';
    const TABLE_DEAL_STAGES         = 'wor9711_ispag_deal_stages'; 
    const STATUS_OPEN               = 0; // Correspond à toutes les étapes "ouvertes" du Kanban
    const STATUS_CLOSED_WON         = 1; // Correspond à l'étape "Closed Won" (Gagné)
    const STATUS_CLOSED_LOST        = 2; // Correspond à l'étape "Closed Lost" (Perdu)

}
endif;