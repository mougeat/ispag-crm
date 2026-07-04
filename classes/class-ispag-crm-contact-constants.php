
<?php 

// Fichier : includes/crm/class-ispag-crm-company-constants.php

if ( ! class_exists( 'ISPAG_Crm_Contact_Constants' ) ) :
class ISPAG_Crm_Contact_Constants {
    const TABLE_NAME = '';
    const ACCOUNT_STATUS            = 'ispag_account_status';
    const LEAD_STATUS_TABLE_NAME    = 'wor9711_ispag_lead_statuses';
    const LIFECYCLE_TABLE_NAME      = 'wor9711_ispag_lifecycle_phases';
    const TABLE_PRIORITIES_NAME     = 'wor9711_ispag_user_priorities';
    const TABLE_CONTACT_OWNER       = 'wor9711_ispag_contacts_owners';
    
    // Méta-clés de l'entreprise (Post Meta)
    const META_LEAD_FUNCTION        = 'ispag_lead_function';
    const META_LEAD_STATUS          = 'ispag_lead_status';
    const META_LEAD_STATUS_REASON   = 'ispag_lead_status_reason';
    const META_LEAD_LINKEDIN_PAGE   = 'ispag_linkedin_page';
    const META_LIFECYCLE_PHASE      = 'ispag_contact_lifecycle_phase';
    const META_COMPANY_ID           = 'ispag_company_id';
    const META_OWNER                = 'ispag_owner';
    const META_OPPORTUNITY          = 'ispag_opportunity';
    const META_BUYING_GOAL          = 'ispag_buying_goal';
    const META_TRANSACTION_OPEN     = 'ispag_transaction_open';
    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    const META_LAST_CONTACT_SOURCE  = 'ispag_last_contact_source';
    const META_USER_ROLE            = 'wp_user_role';
    const META_HEALTH_CHECK_IGNORE  = 'ispag_ignore_health_check';
    const META_LEAD_PHONE           = 'billing_phone';
    const PRIORITY_LEVEL            = 'priority_level';
    const USER_DEPARTMENT           = 'ispag_user_department';
    const USER_BIRTHDAY             = 'billing_birthdate';
    const USER_AVATAR               = 'ispag_avatar_id';
    
}
endif;