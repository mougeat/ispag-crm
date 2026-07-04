<?php

// Fichier: classes/class-ispag-crm-deal-model.php

if ( ! class_exists( 'ISPAG_Crm_Deal_Model' ) ) :

/**
 * Modèle de données pour une transaction (Deal/Projet) ISPAG.
 * Le modèle est hydraté directement par le Repository.
 */
class ISPAG_Crm_Deal_Model {

    // --- PROPRIÉTÉS DU MODÈLE (DOIVENT CORRESPONDRE AUX NOMS DE COLONNES DB) ---
    public $id;
    public $date_creation;
    public $date_update; // AJOUTÉ : souvent utilisée pour le tri
    public $closing_date;
    public $offer_num;  
    public $project_num;            // Numéro de projet / NumCommande
    public $project_name;        // Nom/Objet de l'offre
    public $num_commande;          // Numéro de commande (si Gagné)
    public $associated_company_id;
    public $associated_contact_ids= ''; // Liste d'IDs séparés par des virgules
    public $project_db_status;     // Statut du projet (0:Open, 1:Won, 2:Lost)
    public $database_status;       // Statut de la base (1: En travail, 4: Facturé, etc.)
    public $reason_for_rejection;  // Motif de rejet (si Lost)
    public $project_owner_id;      // Propriétaire du projet (ID Utilisateur)
    public $total_excl_vat;        // Montant du deal
    public $deal_owner;            // Proprietaire de la transaction (ID Utilisateur)
    public $record_source;
    public $current_stage_key;
    public $deal_group_ref;
    public $is_copie;
    
    // --- PROPRIÉTÉS ENRICHIES (Ajoutées par le Model ou le Repository) ---
    public $stage_key; // Clé de l'étape Kanban (ex: 'proposal_sent')
    public $stage_label; // Libellé texte du statut
    public $stage_color; // Couleur CSS/UI associée
    public $project_db_status_label; // Libellé du statut DB (ex: 'Open (Pipeline)')
    public $reason_for_rejection_label;
    public $associated_company_name;
    public $associated_contact_names;
    public $last_activity_date = null;

    /**
     * Constructeur CORRIGÉ. Hydrate le modèle avec les données brutes.
     * Le Repository DOIT passer les données brutes ici.
     * @param object $data L'objet stdClass contenant les données brutes du deal (résultat de $wpdb->get_row).
     */
    public function __construct( $data = null ) {
        if ( is_object( $data ) || is_array( $data ) ) {
            $data_array = (array) $data;

            // Boucle d'hydratation : Si la clé existe dans $data, elle est affectée si la propriété existe.
            foreach ( $data_array as $key => $value ) {
                if ( property_exists( $this, $key ) ) {
                    $this->$key = $value;
                    
                }
            }
            
            // Si l'ID est rempli, on peut enrichir le modèle (calculer les labels/couleurs)
            if ( ! empty( $this->id ) ) {
                // $this->_enrich_model_for_display();
                $this->last_activity_date = $this->calculate_last_activity_date();
            }
        }
    }
    
    /**
     * Remplir l'objet avec les données brutes d'un objet DB. (Gardée pour compatibilité, mais le constructeur fait le travail)
     * @param object $data L'objet (stdClass) retourné par le Repository.
     */
    public function hydrate( $data ) {
         $this->__construct( $data ); // Simplement appeler le constructeur.
    }

    /**
     * Charge le deal depuis la base de données en utilisant le Repository.
     * Cette méthode est gardée pour le cas d'une instanciation via ID.
     * @param int $deal_id ID du deal (colonne 'id').
     * @return bool VRAI si le deal a été chargé, FAUX sinon.
     */
    public function load( $deal_id ) {
        if ( ! class_exists( 'ISPAG_Crm_Deals_Repository' ) ) {
            return false;
        }

        $repository = new ISPAG_Crm_Deals_Repository();
        $db_deal = $repository->get_project_by_id( $deal_id ); // Utiliser une méthode générique

        if ( $db_deal ) {
            // L'objet $db_deal est déjà un ISPAG_Crm_Deal_Model si le Repository est bien fait.
            // On copie les propriétés de l'objet enrichi.
            $this->copy_properties_from_model( $db_deal );
            return true;
        }

        return false;
    }
    
    // ==========================================================
    // MÉTHODES UTILITAIRES INTERNES ET D'AFFICHAGE
    // ==========================================================

    /**
     * Copie les propriétés d'un autre objet Model (utilisé par load()).
     * @param ISPAG_Crm_Deal_Model $source_model
     */
    private function copy_properties_from_model( $source_model ) {
        if ( $source_model instanceof self ) {
            foreach ( get_object_vars( $source_model ) as $key => $value ) {
                $this->$key = $value;
            }
        }
    }

    // /**
    //  * Enrichit le modèle avec des données calculées nécessaires pour l'affichage (labels, couleurs, etc.).
    //  */
    // private function _enrich_model_for_display() {
    //     // Fallbacks par défaut
    //     $this->stage_label = __('Non défini', 'ispag-crm');
    //     $this->stage_color = '#cccccc'; 
    //     $this->project_db_status_label = __('Status Inconnu', 'ispag-crm');

    //     $stage_key = $this->current_stage_key;
        
    //     // --- 1. Récupération des données de Stage dynamiques ---
    //     if ( ! empty( $stage_key ) && class_exists( 'ISPAG_Crm_Deal_Stages_Repository' ) ) {
            
    //         try {
    //             // Utilisation du Repository existant et de la nouvelle méthode
    //             $repository = new ISPAG_Crm_Deal_Stages_Repository();
    //             $stage_data = $repository->get_stage_data_by_key( $stage_key ); // Utiliser la méthode simple

    //             if ( $stage_data ) {
    //                 // Assignation des valeurs trouvées en BDD
    //                 $this->stage_label = $stage_data->stage_label;
    //                 $this->stage_color = $stage_data->stage_color;
    //             }
                
    //         } catch ( Exception $e ) {
    //             error_log( "Erreur de chargement de stage '{$stage_key}' : " . $e->getMessage() );
    //         }
    //     }


    // }
    
    /**
     * Récupère le nom affiché (display_name) de l'utilisateur associé au deal_owner.
     * CORRIGÉ: Utilise get_user_by pour un accès plus simple.
     * @return string Le nom affiché de l'utilisateur ou une chaîne de secours.
     */
    public function get_deal_owner_display_name() {
        if ( ! function_exists( 'get_user_by' ) ) {
            return 'WP function unavailable';
        }

        $user_id = absint( $this->deal_owner );

        if ( $user_id > 0 ) {
            $user = get_user_by( 'id', $user_id );
            
            if ( $user instanceof WP_User ) {
                return $user->display_name;
            }
        }
        
        return 'Owner Unknown';
    }

    /**
     * Récupère TOUS les contacts ayant été listés dans n'importe quelle variante de ce deal.
     * Cherche via le deal_group_ref pour consolider l'historique complet des contacts.
     * * @return array Tableau d'objets Contact enrichis.
     */
    public function get_associated_contacts_list() {
        global $wpdb;

        // 1. Vérification de la référence de groupe
        if ( empty( $this->deal_group_ref ) || ! class_exists( 'ISPAG_Crm_Contacts_Repository' ) ) {
            return [];
        }

        // 2. Récupérer toutes les colonnes associated_contact_ids pour ce groupe de deal
        $table_deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $all_contact_strings = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT associated_contact_ids FROM {$table_deals} WHERE deal_group_ref = %s",
            $this->deal_group_ref
        ));

        if ( empty( $all_contact_strings ) ) {
            return [];
        }

        // 3. Fusion et nettoyage des IDs
        $all_ids = [];
        foreach ( $all_contact_strings as $csv_string ) {
            if ( empty( $csv_string ) ) continue;
            
            // Nettoyage et transformation en tableau
            $ids = explode( ',', str_replace( ' ', '', $csv_string ) );
            $all_ids = array_merge( $all_ids, $ids );
        }

        // Sécuriser : valeurs uniques, entiers positifs, suppression des zéros
        $safe_ids = array_filter( array_unique( array_map( 'absint', $all_ids ) ) );

        if ( empty( $safe_ids ) ) {
            return [];
        }

        // 4. Délégation au Repository pour l'affichage (Nom, Email, Photo, etc.)
        try {
            $repository = new ISPAG_Crm_Contacts_Repository();
            return $repository->get_contacts_by_ids( $safe_ids ); 
            
        } catch ( Exception $e ) {
            // error_log( "[ISPAG] Erreur contacts consolidés (Deal Group: {$this->deal_group_ref}): " . $e->getMessage() );
            return [];
        }
    }
    /**
     * Récupère la liste des contacts associés à ce deal en utilisant le Contacts Repository.
     *
     * Cette méthode gère la conversion de la chaîne d'IDs (ex: "1,5,12") en tableau d'objets Contacts enrichis.
     * * @return array Tableau d'objets Contact enrichis. Retourne un tableau vide si aucun ID n'est trouvé.
     */
    public function get_associated_company_list() {
    
        // 1. Vérification de la propriété et du nouveau Repository
        if ( empty( $this->associated_company_id ) || ! class_exists( 'ISPAG_Crm_Company_Repository' ) ) {
            return [];
        }

        // 2. Préparation des IDs (Transformation de la chaîne CSV "101, 102" en tableau [101, 102])
        $companies_ids_string = $this->associated_company_id;
        $companies_ids_array  = explode( ',', str_replace( ' ', '', $companies_ids_string ) );
        
        // Sécurisation : uniquement des entiers positifs et on retire les doublons/vides
        $safe_ids = array_unique( array_filter( array_map( 'absint', $companies_ids_array ) ) );

        if ( empty( $safe_ids ) ) {
            return [];
        }

        // 3. Récupération via le nouveau Repository
        $companies_list = [];
        try {
            $repository = new ISPAG_Crm_Company_Repository();

            foreach ( $safe_ids as $viag_id ) {
                // On utilise la méthode spécifique que tu as fournie
                $company = $repository->get_company_by_viag_id( $viag_id );
                
                if ( $company ) {
                    $companies_list[] = $company;
                }
            }
            
            return $companies_list;
            
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // error_log( "[ISPAG CRM] Erreur récupération entreprises associées : " . $e->getMessage() );
            }
            return [];
        }
    }
    

    /**
     * Calcule la date de la dernière activité (Note, Call, Email, etc.) associée à ce deal.
     * * Utilise FIND_IN_SET pour interroger la colonne deal_id qui est une chaîne CSV.
     *
     * @return string|null La date de la dernière activité au format 'Y-m-d H:i:s', ou null si aucune activité n'est trouvée.
     */
    public function calculate_last_activity_date() {
        global $wpdb;
        
        $deal_id =  $this->deal_group_ref;


        // 1. Définition du nom de la table d'activités (basée sur votre schéma fourni)
        $activities_table = $wpdb->prefix . 'ispag_contact_notes'; 
        
        // 2. Construction de la requête pour trouver la date la plus récente
        
        // Nous cherchons l'activité la plus récente qui est :
        // a) Liée à notre Deal ID (grâce à FIND_IN_SET).
        // b) Une activité 'terminée' (`is_completed = 1`) ou un simple log (`type` in ('EMAIL', 'CALL', 'NOTE', 'MEETING')).
        //    Si c'est une 'TASK' (`is_task = 1`), nous regardons seulement la date si elle est complétée (`is_completed = 1`).
        //    S'il n'y a pas d'is_completed=1, nous considérons le created_at.
        
        // Simplification : On cherche la date la plus récente parmi `created_at` 
        // pour toutes les entrées loguées (non-tâche non-complétée) ou `completed_at` pour les tâches complétées.

        // Requête pour trouver la date d'activité la plus récente (MAX de created_at)
        // Nous utilisons created_at comme date de l'activité elle-même.
        $query = $wpdb->prepare(
            "SELECT 
                MAX(created_at) 
            FROM 
                {$activities_table} 
            WHERE 
                -- Recherche de l'ID du deal dans la chaîne CSV
                FIND_IN_SET(%s, REPLACE(deal_id, ' ', '')) > 0
            AND
                -- Exclure les tâches futures/ouvertes. 
                -- On ne veut que les événements enregistrés (EMAIL, CALL, NOTE, MEETING) 
                -- OU les tâches qui ont été complétées.
                (
                    is_task = 0 OR is_completed = 1
                )", 
            $deal_id
        );

        // 3. Exécution de la requête
        $last_date = $wpdb->get_var( $query );

        // 4. Retour
        // Si la date est '0000-00-00 00:00:00', retournons null
        if ( $last_date === '0000-00-00 00:00:00' ) {
            return null;
        }
        
        return $last_date; 
    }

    /**
     * Construit le lien URL vers la page de détail du Deal (Transaction).
     * * Utilise la règle de réécriture '/deal/{ID}' que nous avons configurée.
     * * @return string L'URL complète de la page de détail, ou '#' si l'ID est manquant.
     */
    public function get_deal_detail_link() {
        
        $deal_id = absint( $this->id );
        
        if ( $deal_id === 0 ) {
            return '#';
        }

        // Le format de l'URL est : URL_DU_SITE/deal/{ID}/
        // WordPress gère automatiquement l'URL de base (home_url()).
        $link = home_url( '/deal/' . $deal_id . '/' );
        
        return esc_url( $link );
    }

    /**
     * Construit le lien URL vers la page de détail de la compagnie associée.
     *
     * Le lien est basé sur l'ID stocké dans $this->associated_company_id.
     *
     * @return string L'URL complète de la page de détail, ou '#' si l'ID est manquant.
     */
    public function get_company_detail_link() {
        
        $company_id = absint( $this->associated_company_id );
        
        if ( $company_id === 0 ) {
            return '#';
        }

        // --- 1. Détermination de l'URL de base ---

        // Option A (Recommandée) : Utiliser un nom de page connu (Slug)
        // Vous devez vous assurer qu'une page avec ce slug existe.
        $company_page_slug = 'details-entreprise'; 
        
        // Tentative de récupération de l'objet Page par son slug
        $company_page = get_page_by_path( $company_page_slug );

        if ( $company_page ) {
            // Récupérer le permalien de la page
            $base_url = get_permalink( $company_page );
        } else {
            // Option de repli si la page n'est pas trouvée
            $base_url = home_url( '/' . $company_page_slug . '/' );
        }
        
        // --- 2. Construction du lien final ---

        // Utilisation de add_query_arg pour ajouter un paramètre GET à l'URL.
        // Assurez-vous que votre template de détail d'entreprise utilise 'company_id' pour charger les données.
        $link = add_query_arg( 
            'company_id', 
            $company_id, 
            $base_url 
        );

        return esc_url( $link );
    }
}

endif;