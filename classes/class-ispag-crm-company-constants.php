
<?php 

// Fichier : includes/crm/class-ispag-crm-company-constants.php

if ( ! class_exists( 'ISPAG_Crm_Company_Constants' ) ) :
class ISPAG_Crm_Company_Constants {
    const TABLE_NAME                = 'wor9711_ispag_companies';
    const TABLE_COMPANY_OWNER       = 'wor9711_ispag_companies_owners';
    const TABLE_PRIORITIES_NAME     = 'wor9711_ispag_user_priorities';
    const LEAD_STATUS_TABLE_NAME    = 'wor9711_ispag_lead_statuses';

    // Méta-clés de l'entreprise (Post Meta)
    const META_COMPANY_ID          = 'ispag_company_id';
    const META_COMPANY_CITY        = 'ispag_company_city';
    const META_COMPANY_ADDRESS     = 'ispag_company_adress';
    const META_COMPANY_POSTAL_CODE = 'ispag_company_postal_code';
    const META_COMPANY_REGION      = 'ispag_company_region';
    const META_COMPANY_COUNTRY     = 'ispag_company_country';
    const META_COMPANY_INDUSTRY    = 'ispag_company_industry';
    const META_COMPANY_PHONE       = 'ispag_company_phone';
    const META_COMPANY_MAIL        = 'ispag_company_mail';
    const META_COMPANY_OWNER       = 'ispag_company_owner';
    const IS_COMPANY_ENGINEER      = 'ispag_company_is_engineer';
    const IS_COMPANY_SUPPLIER      = 'ispag_company_is_supplier';
    const PRIORITY_LEVEL           = 'priority_level';
    const COMPANY_TYPE             = 'ispag_company_type';
}
endif;