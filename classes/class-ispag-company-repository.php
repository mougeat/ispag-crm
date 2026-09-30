<?php

if ( ! class_exists( 'ISPAG_Company_Repository' ) ) :

class ISPAG_Company_Repository {

    /**
     * Clés de métadonnées pour les entreprises.
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
     * Nom de la table principale des entreprises/fournisseurs.
     * @var string
     */
    private $table_fournisseur;

    /**
     * Nom de la table des métadonnées des entreprises.
     * @var string
     */
    private $table_meta;

    public function __construct() {
        global $wpdb;
        $this->wpdb              = $wpdb;
        $this->table_fournisseur = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $this->table_meta        = ISPAG_Crm_Company_Constants::TABLE_COMPANY_META;
    }

    // ----------------------------------------------------------------------------------
    // --- 1. Méthodes de Gestion des Métadonnées (GET, UPDATE, DELETE) ---
    // ----------------------------------------------------------------------------------

    /**
     * Récupère une métadonnée d'une entreprise dans la table personnalisée.
     *
     * @param int    $company_id L'ID de l'entreprise.
     * @param string $meta_key   La clé de métadonnée.
     * @param bool   $single     Si true, renvoie la valeur brute/désérialisée ; sinon renvoie un tableau.
     * @return mixed
     */
    public function get_company_meta( $company_id, $meta_key, $single = true ) {
        $company_id = absint( $company_id );
        if ( ! $company_id || empty( $meta_key ) ) {
            return $single ? '' : [];
        }

        $sql = $this->wpdb->prepare(
            "SELECT meta_value FROM {$this->table_meta} WHERE company_id = %d AND meta_key = %s",
            $company_id,
            $meta_key
        );

        if ( $single ) {
            $value = $this->wpdb->get_var( $sql );
            return maybe_unserialize( $value );
        }

        $results = $this->wpdb->get_col( $sql );
        return array_map( 'maybe_unserialize', $results );
    }

    /**
     * Récupère TOUTES les métadonnées d'une entreprise sous forme de tableau associatif [meta_key => meta_value].
     *
     * @param int $company_id
     * @return array
     */
    public function get_all_company_meta( $company_id ) {
        $company_id = absint( $company_id );
        if ( ! $company_id ) {
            return [];
        }

        $sql = $this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->table_meta} WHERE company_id = %d",
            $company_id
        );

        $results = $this->wpdb->get_results( $sql, OBJECT );
        $meta    = [];

        foreach ( $results as $row ) {
            $meta[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
        }

        return $meta;
    }

    /**
     * Insère ou met à jour une métadonnée d'une entreprise.
     *
     * @param int    $company_id
     * @param string $meta_key
     * @param mixed  $meta_value
     * @return bool|int ID de la métadonnée ou true si mise à jour réussie, false en cas d'erreur.
     */
    public function update_company_meta( $company_id, $meta_key, $meta_value ) {
        $company_id = absint( $company_id );
        if ( ! $company_id || empty( $meta_key ) ) {
            return false;
        }

        if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
            $meta_value = maybe_serialize( $meta_value );
        }

        $existing_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT meta_id FROM {$this->table_meta} WHERE company_id = %d AND meta_key = %s",
                $company_id,
                $meta_key
            )
        );

        if ( $existing_id ) {
            $updated = $this->wpdb->update(
                $this->table_meta,
                array( 'meta_value' => $meta_value ),
                array(
                    'company_id' => $company_id,
                    'meta_key'   => $meta_key,
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );

            return $updated !== false;
        } else {
            $inserted = $this->wpdb->insert(
                $this->table_meta,
                array(
                    'company_id' => $company_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $meta_value,
                ),
                array( '%d', '%s', '%s' )
            );

            return $inserted ? $this->wpdb->insert_id : false;
        }
    }

    /**
     * Supprime une métadonnée pour une entreprise donnée.
     *
     * @param int    $company_id
     * @param string $meta_key
     * @return bool
     */
    public function delete_company_meta( $company_id, $meta_key ) {
        $company_id = absint( $company_id );
        if ( ! $company_id || empty( $meta_key ) ) {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->table_meta,
            array(
                'company_id' => $company_id,
                'meta_key'   => $meta_key,
            ),
            array( '%d', '%s' )
        );

        return $deleted !== false;
    }

    // ----------------------------------------------------------------------------------
    // --- 2. Méthodes d'Enrichissement et de Récupération (GET) ---
    // ----------------------------------------------------------------------------------

    /**
     * Effectue la récupération et l'enrichissement des métadonnées pour une Compagnie donnée.
     *
     * @param object $company L'objet compagnie brut.
     * @return object L'objet compagnie enrichi.
     */
    private function _enrich_company_with_meta( $company ) {
        if ( ! isset( $company->Id ) ) {
            return $company;
        }

        $company_id = absint( $company->Id );

        // Renommage/nettoyage de propriétés si présent
        if ( isset( $company->company_name ) ) {
            $company->name = $company->company_name;
        }
        unset( $company->post_title );

        // Récupération globale des métadonnées en 1 seule requête pour de meilleures performances
        $all_meta = $this->get_all_company_meta( $company_id );

        // Mapping [ meta_key => property_name ]
        $meta_keys = [
            'ispag_company_city'         => 'city',
            'ispag_company_adress'       => 'address',
            'ispag_company_postal_code'  => 'postal_code',
            'ispag_company_region'       => 'region',
            'ispag_company_country'      => 'country',
            'ispag_company_industry'     => 'industry',
            'ispag_company_phone'        => 'phone',
            'ispag_company_email'        => 'email',
            'ispag_company_website'      => 'website',
            'ispag_company_linkedin_page'=> 'linkedin_page',
            'ispag_owner'                => 'crm_owner_id',
            'ispag_last_contact_date'    => 'last_contact_date',
        ];

        foreach ( $meta_keys as $meta_key => $property_name ) {
            $company->$property_name = isset( $all_meta[ $meta_key ] ) ? $all_meta[ $meta_key ] : '';
        }

        // Fallback/formatage pour la date
        if ( empty( $company->last_contact_date ) ) {
            $company->last_contact_date = __( 'N/A', 'ispag-crm' );
        }

        return $company;
    }

    /**
     * Récupère toutes les entreprises ainsi que leurs métadonnées associées (via JOIN).
     *
     * @return array Map (Id => CompanyObject)
     */
    public function get_all_companies_with_meta() {
        $sql = "
            SELECT 
                f.Id, 
                f.company_name AS Fournisseur, 
                f.compagny_domain AS compagnyDomain, 
                
                meta_city.meta_value AS city,
                meta_adress.meta_value AS adress,
                meta_postal_code.meta_value AS postalCode,
                meta_region.meta_value AS region,
                meta_country.meta_value AS country,
                meta_industry.meta_value AS industry
            FROM 
                {$this->table_fournisseur} f
            
            LEFT JOIN {$this->table_meta} meta_city 
                ON (f.Id = meta_city.company_id AND meta_city.meta_key = '" . self::META_COMPANY_CITY . "')
            
            LEFT JOIN {$this->table_meta} meta_adress 
                ON (f.Id = meta_adress.company_id AND meta_adress.meta_key = '" . self::META_COMPANY_ADRESS . "')
                
            LEFT JOIN {$this->table_meta} meta_postal_code 
                ON (f.Id = meta_postal_code.company_id AND meta_postal_code.meta_key = '" . self::META_COMPANY_POSTAL_CODE . "')
            
            LEFT JOIN {$this->table_meta} meta_region 
                ON (f.Id = meta_region.company_id AND meta_region.meta_key = '" . self::META_COMPANY_REGION . "')
            
            LEFT JOIN {$this->table_meta} meta_country 
                ON (f.Id = meta_country.company_id AND meta_country.meta_key = '" . self::META_COMPANY_COUNTRY . "')
            
            LEFT JOIN {$this->table_meta} meta_industry 
                ON (f.Id = meta_industry.company_id AND meta_industry.meta_key = '" . self::META_COMPANY_INDUSTRY . "')
            
            ORDER BY f.company_name ASC
        ";

        return $this->wpdb->get_results( $sql, OBJECT_K ); 
    }

    /**
     * Récupère une seule entreprise par son ID.
     *
     * @param int $company_id
     * @return object|null
     */
    public function get_company_by_id( $company_id ) {
        $company_id = absint( $company_id );
        if ( $company_id === 0 ) {
            return null;
        }

        $sql = $this->wpdb->prepare(
            "SELECT Id, company_name AS Fournisseur, compagny_domain AS compagnyDomain FROM {$this->table_fournisseur} WHERE Id = %d", 
            $company_id
        );

        $company = $this->wpdb->get_row( $sql );

        if ( $company ) {
            $company = $this->_enrich_company_with_meta( $company );
        }

        return $company;
    }

    /**
     * Récupère une liste d'entreprises par leurs IDs.
     *
     * @param array $companies_ids
     * @return array
     */
    public function get_company_by_ids( array $companies_ids ) {
        if ( empty( $companies_ids ) ) {
            return [];
        }

        $safe_ids = array_filter( array_map( 'absint', $companies_ids ) );

        if ( empty( $safe_ids ) ) {
            return [];
        }

        $id_placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );
        $query           = "SELECT Id, company_name FROM {$this->table_fournisseur} WHERE Id IN ({$id_placeholders})";
        
        $results = $this->wpdb->get_results( 
            $this->wpdb->prepare( $query, ...$safe_ids ) 
        );

        if ( empty( $results ) ) {
            return [];
        }

        foreach ( $results as $key => $company ) {
            $results[ $key ] = $this->_enrich_company_with_meta( $company );
        }
        
        return $results;
    }
}

endif;