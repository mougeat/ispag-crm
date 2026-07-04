<?php

if ( ! class_exists( 'ISPAG_Company_Repository' ) ) :

class ISPAG_Company_Repository {

    /**
     * Clés de métadonnées pour les entreprises stockées dans wp_postmeta.
     * @var string
     */
    const META_COMPANY_CITY         = 'ispag_company_city';
    const META_COMPANY_ADRESS       = 'ispag_company_adress';
    const META_COMPANY_POSTAL_CODE  = 'ispag_company_postal_code';
    const META_COMPANY_REGION       = 'ispag_company_region';
    const META_COMPANY_COUNTRY      = 'ispag_company_country';
    const META_COMPANY_INDUSTRY     = 'ispag_company_industry';

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * Nom de la table principale des fournisseurs/entreprises.
     * @var string
     */
    private $table_fournisseur;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        // La table principale des fournisseurs
        $this->table_fournisseur = ISPAG_Crm_Company_Constants::TABLE_NAME;

        
    }

    // ----------------------------------------------------------------------------------
    // --- 1. Méthodes de Récupération de Données (GET) ---
    // ----------------------------------------------------------------------------------

    /**
     * Effectue la récupération et l'enrichissement des métadonnées pour une Compagnie donnée.
     *
     * @param object $company L'objet compagnie brut (résultat de la requête wpdb).
     * @return object L'objet compagnie enrichi.
     */
    private function _enrich_company_with_meta( $company ) {
        
        // Si l'objet n'a pas la propriété 'ID', on ne peut pas continuer.
        if ( ! isset( $company->Id ) ) {
            return $company;
        }
        
        $company_id = absint( $company->Id );

        // --- 1. Renommage et nettoyage des propriétés de base ---
        // Les propriétés CPT (post_title, post_name, etc.) peuvent être renommées ici si nécessaire.
        // Par exemple, rendre le nom plus accessible :
        $company->name = $company->company_name; 
        unset($company->post_title);


        // --- 2. Définition et Récupération des métadonnées de Compagnie (Post Meta) ---
        // Mapping des clés meta (stockées en DB) vers les noms de propriétés de l'objet (Modèle).
        $meta_keys = [
            // Clés spécifiques aux adresses et informations de l'entreprise
            'ispag_company_city'           => 'city',
            'ispag_company_adress'         => 'address',
            'ispag_company_postal_code'    => 'postal_code',
            'ispag_company_region'         => 'region',
            'ispag_company_country'        => 'country',
            'ispag_company_industry'       => 'industry',
            
            // Autres clés CRM non liées à l'adresse (à ajuster selon vos besoins)
            'ispag_company_phone'          => 'phone',
            'ispag_company_email'          => 'email',
            'ispag_company_website'        => 'website',
            'ispag_company_linkedin_page'  => 'linkedin_page',
            'ispag_owner'                  => 'crm_owner_id',
            'ispag_last_contact_date'      => 'last_contact_date',
        ];

        foreach ( $meta_keys as $meta_key => $property_name ) {
            
            // Le troisième argument 'true' garantit une valeur unique au lieu d'un tableau.
            $company->$property_name = get_post_meta( $company_id, $meta_key, true );
        }
        
        // Fallback/formatage pour la date (si l'objet est censé avoir cette propriété)
        if ( empty( $company->last_contact_date ) ) {
            $company->last_contact_date = __( 'N/A', 'ispag-crm' );
        }
        
        return $company;
    }

    /**
     * Récupère toutes les entreprises ainsi que leurs métadonnées associées 
     * stockées dans la table wp_postmeta.
     * * @return array Map (Id => CompanyObject) Liste des entreprises enrichies.
     */
    public function get_all_companies_with_meta() {
        
        $meta_table_name = $this->wpdb->postmeta; 
        
        $sql = "
            SELECT 
                f.viag_id AS Id, 
                f.company_name AS Fournisseur, 
                f.compagnyDomain, 
                
                -- Les colonnes META sont jointes et renommées :
                meta_city.meta_value AS city,
                meta_adress.meta_value AS adress,
                meta_postal_code.meta_value AS postalCode,
                meta_region.meta_value AS region,
                meta_country.meta_value AS country,
                meta_industry.meta_value AS industry
            FROM 
                {$this->table_fournisseur} f
            
            -- Jointure pour la Ville (META_COMPANY_CITY)
            LEFT JOIN {$meta_table_name} meta_city 
                ON (f.viag_id = meta_city.post_id AND meta_city.meta_key = '" . self::META_COMPANY_CITY . "')
            
            -- Jointure pour l'Adresse (META_COMPANY_ADRESS)
            LEFT JOIN {$meta_table_name} meta_adress 
                ON (f.viag_id = meta_adress.post_id AND meta_adress.meta_key = '" . self::META_COMPANY_ADRESS . "')
                
            -- Jointure pour le Code Postal (META_COMPANY_POSTAL_CODE)
            LEFT JOIN {$meta_table_name} meta_postal_code 
                ON (f.viag_id = meta_postal_code.post_id AND meta_postal_code.meta_key = '" . self::META_COMPANY_POSTAL_CODE . "')
            
            -- Jointure pour la Région (META_COMPANY_REGION)
            LEFT JOIN {$meta_table_name} meta_region 
                ON (f.viag_id = meta_region.post_id AND meta_region.meta_key = '" . self::META_COMPANY_REGION . "')
            
            -- Jointure pour le Pays (META_COMPANY_COUNTRY)
            LEFT JOIN {$meta_table_name} meta_country 
                ON (f.viag_id = meta_country.post_id AND meta_country.meta_key = '" . self::META_COMPANY_COUNTRY . "')
            
            -- Jointure pour l'Industrie (META_COMPANY_INDUSTRY)
            LEFT JOIN {$meta_table_name} meta_industry 
                ON (f.viag_id = meta_industry.post_id AND meta_industry.meta_key = '" . self::META_COMPANY_INDUSTRY . "')
            
            ORDER BY f.company_name ASC
        ";
        
        // OBJECT_K pour retourner un tableau associatif avec Id comme clé
        return $this->wpdb->get_results( $sql, OBJECT_K ); 
    }

    // ----------------------------------------------------------------------------------
    // --- 2. Autres méthodes utiles pour le Repository (à développer) ---
    // ----------------------------------------------------------------------------------

    /**
     * Récupère une seule entreprise par son ID.
     * @param int $company_id L'ID de l'entreprise à récupérer.
     * @return object|null L'objet entreprise ou null si non trouvé.
     */
    public function get_company_by_id( $company_id ) {
        // Logique pour récupérer une seule entreprise (avec ou sans méta, selon le besoin)
        $company_id = absint($company_id);
        if ( $company_id === 0 ) {
            return null;
        }

        // On pourrait réutiliser la méthode get_all_companies_with_meta et filtrer, 
        // ou faire une requête ciblée pour plus d'efficacité sur une grosse base.
        $sql = $this->wpdb->prepare(
            "SELECT viag_id AS Id, company_name AS Fournisseur, compagnyDomain FROM {$this->table_fournisseur} WHERE viag_id = %d", 
            $company_id
        );
        return $this->wpdb->get_row($sql);
    }
    /**
     * Récupère une seule entreprise par son ID.
     * @param int $company_id L'ID de l'entreprise à récupérer.
     * @return object|null L'objet entreprise ou null si non trouvé.
     */
    public function get_company_by_ids( array $companies_ids ) {
        global $wpdb; 

        if ( empty( $companies_ids ) ) {
            return [];
        }

        $safe_ids = array_map( 'absint', $companies_ids );
        $safe_ids = array_filter( $safe_ids );

        if ( empty( $safe_ids ) ) {
            return [];
        }

        // 1. Création de la requête SQL pour la table wp_users
        $id_placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );

        $query ="SELECT viag_id AS Id, company_name FROM {$this->table_fournisseur} WHERE viag_id IN ({$id_placeholders})";
        
        // Exécution de la requête avec la liste des IDs
        $results = $wpdb->get_results( 
            $wpdb->prepare( $query, ...$safe_ids ) 
        );

        if ( empty( $results ) ) {
            return [];
        }

        // 2. Hydratation (Enrichissement) des résultats avec les métadonnées
        foreach ($results as $key => $company) {
            $results[$key] = $this->_enrich_company_with_meta( $company );
        }
        
        return $results;
    }
    
    // Vous pouvez ajouter ici des méthodes comme :
    // public function create_company($data) { ... }
    // public function update_company($id, $data) { ... }
    // public function search_company_by_name($name) { ... }
}

endif;