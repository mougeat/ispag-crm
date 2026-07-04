<?php

// Fichier : includes/crm/repositories/class-ispag-crm-company-repository.php

if ( ! class_exists( 'ISPAG_Crm_Company_Repository' ) ) :
class ISPAG_Crm_Company_Repository {
    
    private $wpdb;
    private $table_companies;
    private $table_priorities;
    private $table_postmeta;
    private $table_usermeta;
    private $table_transactions;
    private $table_note;
    

    private static $log_file = WP_CONTENT_DIR . '/ispag_company_repository.log';
    

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb; 
        $this->table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME; 
        $this->table_postmeta = $wpdb->postmeta;
        $this->table_usermeta = $wpdb->usermeta;
        $this->table_priorities = ISPAG_Crm_Company_Constants::TABLE_PRIORITIES_NAME; 
        $this->table_note = ISPAG_Note_Manager::TABLE_NOTE;
        // J'utilise le nom de table que vous avez défini précédemment dans votre shortcode.
        $this->table_transactions = ISPAG_Crm_Deal_Constants::TABLE_NAME; 

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ispag_assets' ) );
        add_action( 'wp_ajax_save_company_field', array( $this, 'handle_ajax_save_company_field' ) );

        add_action( 'wp_ajax_ispag_load_gemini_company_summary', array( $this, 'ajax_load_company_ai_summary' ) );
        add_action('wp_ajax_ispag_export_deals', array( $this, 'ispag_handle_deals_export') );

        add_action('wp_ajax_save_company_favicon', array($this, 'ispag_save_company_favicon'));
    }

    /**
     * Enqueue les styles et scripts nécessaires, incluant le script d'édition en ligne.
     */
    public function enqueue_ispag_assets() {
        
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        wp_enqueue_style( 'ispag-crm-styles', $plugin_url . 'assets/css/ispag-crm-styles.css', array(), '1.0.0' );


        wp_enqueue_script( 'ispag-crm-bulk-edit-js', $plugin_url . 'assets/js/ispag-bulk-edit.js', array( 'jquery' ), '1.0.0', true );

        wp_enqueue_script( 
            'ispag-contact-detail-edit-js', 
            $plugin_url . 'assets/js/ispag-contact-detail-edit.js', 
            array( 'jquery' ), 
            '1.0.0', 
            true 
        );
        
        // // Passage de la variable AJAX pour que le JS sache où envoyer les requêtes
        // wp_localize_script( 
        //     'ispag-contact-detail-edit-js', 
        //     'ispag_ajax', 
        //     array( 
        //         'ajax_url' => admin_url( 'admin-ajax.php' ),
        //         // L'action 'ispag_nonce' DOIT correspondre à l'action utilisée dans check_ajax_referer()
        //         'nonce'    => wp_create_nonce( 'ispag_crm_nonce' ) 
        //     )
        // );
    
    }
    public function get_distinct_cities() {
        
        return $this->wpdb->get_col("
            SELECT DISTINCT city 
            FROM {$this->table_companies} 
            WHERE city IS NOT NULL AND city != '' AND isSupplier = 0 
            ORDER BY city ASC
        ");
    }

    /**
     * Récupère la liste des entreprises avec filtres, recherche, tri et Responsable actuel.
     */
    public function get_companies_list( $args ) {
        $defaults = [
            'orderby'  => 'company_name', 
            'order'    => 'ASC', 
            'search'   => '', 
            'priority' => '', 
            'city'     => '', 
            'owner_id' => 0, 
            'limit'    => 50, 
            'offset'   => 0
        ];
        $args = wp_parse_args( $args, $defaults );
        $current_user_id = get_current_user_id();
        
        // Noms des tables
        $table_owners = ISPAG_Crm_Company_Constants::TABLE_COMPANY_OWNER;
        $table_users  = $this->wpdb->users;

        // Types d'activités pour le dernier contact
        $contact_types = ['MEETING', 'CALL', 'EMAIL', 'EMAIL_CAMPAIGN', 'EMAIL_TRANSACTIONAL', 'CHRISTMAS_PRESENT', 'WHATSAPP', 'SMS'];
        $types_string = "'" . implode("','", $contact_types) . "'";

        // 1. Validation du tri
        $valid_orderby_cols = ['Id', 'company_name', 'viag_id', 'priority_level', 'last_contact_date', 'city', 'nb_contacts', 'nb_transactions']; 
        $orderby = in_array( $args['orderby'], $valid_orderby_cols ) ? $args['orderby'] : 'company_name';
        
        if ($orderby === 'priority_level') {
            $orderby_sql = 'up.priority_level';
        } elseif (in_array($orderby, ['last_contact_date', 'nb_contacts', 'nb_transactions'])) {
            $orderby_sql = $orderby; 
        } else {
            $orderby_sql = 'f.' . $orderby;
        }

        $order = (strtoupper($args['order'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

        // 2. Construction des clauses WHERE
        $where_clauses = ["f.isSupplier = 0"];
        $sql_args_where = []; 

        // Filtre Recherche textuelle
        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
            $where_clauses[] = "(f.company_name LIKE %s OR f.city LIKE %s OR f.email LIKE %s)"; 
            $sql_args_where[] = $search_term; 
            $sql_args_where[] = $search_term; 
            $sql_args_where[] = $search_term;
        }

        // Filtre Priorité
        if ( ! empty( $args['priority'] ) ) {
            $where_clauses[] = "up.priority_level = %s";
            $sql_args_where[] = sanitize_text_field( $args['priority'] );
        }

        // Filtre Ville
        if ( ! empty( $args['city'] ) ) {
            $where_clauses[] = "f.city = %s";
            $sql_args_where[] = sanitize_text_field( $args['city'] );
        }

        // FILTRE PAR RESPONSABLE (Owner)
        if ( ! empty( $args['owner_id'] ) ) {
            $where_clauses[] = "EXISTS (
                SELECT 1 FROM {$table_owners} ow 
                WHERE CAST(ow.company_id AS CHAR) = CAST(f.viag_id AS CHAR) COLLATE utf8mb4_unicode_ci
                AND ow.user_id = %d 
                AND ow.status = 'active'
            )";
            $sql_args_where[] = absint( $args['owner_id'] );
        }
        
        $where_sql = ' WHERE ' . implode( ' AND ', $where_clauses );

        // 3. Requête principale
        $sql_data_select = "
            SELECT 
                f.*,
                up.priority_level,
                -- Récupération du nom de l'owner actif
                (SELECT u.display_name 
                FROM {$table_users} u
                JOIN {$table_owners} ow ON u.ID = ow.user_id
                WHERE CAST(ow.company_id AS CHAR) = CAST(f.viag_id AS CHAR) COLLATE utf8mb4_unicode_ci
                AND ow.status = 'active' 
                LIMIT 1) AS current_owner_name,
                -- Dernier contact
                (SELECT MAX(n.created_at) FROM {$this->table_note} n 
                WHERE n.company_id = CAST(f.viag_id AS CHAR) COLLATE utf8mb4_unicode_ci
                AND n.type IN ($types_string)) AS last_contact_date,
                -- Nombre de contacts
                (SELECT COUNT(*) FROM `{$this->table_usermeta}` um 
                WHERE um.meta_key = 'ispag_company_id' 
                AND um.meta_value = CAST(f.viag_id AS CHAR) COLLATE utf8mb4_unicode_ci) AS nb_contacts,
                -- Nombre de transactions ouvertes
                (SELECT COUNT(*) FROM `{$this->table_transactions}` t 
                WHERE t.associated_company_id = f.viag_id 
                AND t.project_db_status = 0) AS nb_transactions
            FROM {$this->table_companies} f
            LEFT JOIN {$this->table_priorities} AS up 
                ON f.viag_id = up.entity_id 
                AND up.entity_type = 'company' 
                AND up.user_id = %d
            {$where_sql}
            ORDER BY {$orderby_sql} {$order}
            LIMIT %d OFFSET %d
        ";

        // ASSEMBLAGE DES ARGUMENTS (L'ordre est vital !)
        // 1. %d pour le LEFT JOIN (up.user_id = %d)
        // 2. Les arguments du WHERE (search, priority, city, owner_id)
        // 3. %d pour LIMIT
        // 4. %d pour OFFSET
        $final_args = array_merge( 
            [ $current_user_id ], 
            $sql_args_where, 
            [ absint($args['limit']), absint($args['offset']) ] 
        );

        $companies = $this->wpdb->get_results( $this->wpdb->prepare( $sql_data_select, $final_args ) );

        // 4. Calcul du total pour la pagination
        $total_sql = "SELECT COUNT(*) FROM {$this->table_companies} f 
                    LEFT JOIN {$this->table_priorities} up ON f.viag_id = up.entity_id AND up.user_id = %d
                    $where_sql";
        
        // Même chose pour le total : l'utilisateur du LEFT JOIN d'abord, puis les filtres
        $total_args = array_merge([ $current_user_id ], $sql_args_where);

        $total_companies = $this->wpdb->get_var( $this->wpdb->prepare( $total_sql, $total_args ) );

        return [
            'companies' => $companies,
            'total'     => absint( $total_companies ),
        ];
    }


    /**
     * Récupère les données d'une seule entreprise par son ID VIAG.
     *
     * @param int|string $viag_id L'ID externe (VIAG) de l'entreprise.
     * @return stdClass|null L'objet entreprise, ou null si non trouvé.
     */
    public function get_company_by_viag_id( $viag_id ) {
        
        if ( empty( $viag_id ) ) {
            return null;
        }

        $viag_id = absint( $viag_id );
        if ( 0 === $viag_id ) {
            return null;
        }
        
        // --- 1. Construction de la requête SQL (sans pagination/tri) ---
        // Nous réutilisons la structure SELECT principale pour obtenir toutes les infos nécessaires au détail.

        $table_c = $this->table_companies;
        $table_t = $this->table_transactions;
        $table_um = $this->table_usermeta;
        $current_user_id = get_current_user_id();

        // NOTE : Nous ne faisons pas de LEFT JOIN ici sur les postmeta car 
        // l'objet de détail sera généralement chargé avec des fonctions spécifiques 
        // qui lisent toutes les métadonnées pour l'édition. 
        // Cependant, pour la cohérence, on peut inclure les champs de base.
        
        // Pour un repository standard, on retourne généralement les données de la table principale. 
        // Je vais garder les counts pour la cohérence avec le template de détail.

        $sql = "
            SELECT 
                f.*,
                -- Récupération des méta-données de base pour un affichage rapide
                (SELECT um.meta_value FROM {$this->table_postmeta} um WHERE um.post_id = f.viag_id AND um.meta_key = '" . ISPAG_Crm_Company_Constants::META_COMPANY_CITY . "') AS city,
                (SELECT um.meta_value FROM {$this->table_postmeta} um WHERE um.post_id = f.viag_id AND um.meta_key = '" . ISPAG_Crm_Company_Constants::META_COMPANY_ADDRESS . "') AS address,
                (SELECT um.meta_value FROM {$this->table_postmeta} um WHERE um.post_id = f.viag_id AND um.meta_key = '" . ISPAG_Crm_Company_Constants::META_COMPANY_POSTAL_CODE . "') AS postal_code,
                (SELECT um.meta_value FROM {$this->table_postmeta} um WHERE um.post_id = f.viag_id AND um.meta_key = '" . ISPAG_Crm_Company_Constants::META_COMPANY_COUNTRY . "') AS country,
                (SELECT um.meta_value FROM {$this->table_postmeta} um WHERE um.post_id = f.viag_id AND um.meta_key = '" . ISPAG_Crm_Company_Constants::COMPANY_TYPE . "') AS type,
                -- Priorité personnalisée (Corrigée avec LIMIT 1 et typage propre)
                (SELECT up.priority_level 
                FROM {$this->table_priorities} up 
                WHERE up.entity_id = f.viag_id 
                AND up.entity_type = 'company' 
                AND up.user_id = $current_user_id 
                LIMIT 1) AS priority_level,

                -- Compte des contacts associés (viag_id dans usermeta)
                (SELECT COUNT(um.user_id) FROM {$table_um} um
                WHERE um.meta_key = '" . ISPAG_Crm_Company_Constants::META_COMPANY_ID . "' AND um.meta_value = f.viag_id) AS nb_contacts,
                
                -- Compte des transactions ouvertes (project_db_status = 0)
                (SELECT COUNT(t.Id) FROM {$table_t} t
                WHERE t.associated_company_id = f.viag_id AND t.project_db_status = 0) AS nb_transactions
                
            FROM {$table_c} f
            WHERE f.viag_id = %d AND f.isSupplier = 0
            LIMIT 1
        ";

        // --- 2. Préparation et exécution de la requête ---
        $prepared_query = $this->wpdb->prepare( $sql, $viag_id );



        $company = $this->wpdb->get_row( $prepared_query );
 
        if ( $company ) {
            // --- Appel à l'enrichissement ---
            $company = $this->_enrich_company_data( $company );
            // -----------------------------
        }
        
        return $company;
    }

    /**
     * Enrichit les données brutes d'une ou plusieurs entreprises avec des métriques dérivées.
     * Inclut le dernier contact s'il est disponible.
     *
     * @param array|object $results Les résultats bruts de la base de données (stdClass ou tableau de stdClass).
     * @return array|object Les résultats enrichis.
     */
    private function _enrich_company_data( $results ) {
        if ( empty( $results ) ) {
            return $results;
        }

        $is_single = is_object( $results );
        $companies = $is_single ? array( $results ) : $results;
        
        $note_manager_exists = class_exists( 'ISPAG_Note_Manager' );
        $note_manager = null;
        if ( $note_manager_exists ) {
            $note_manager = new ISPAG_Note_Manager();
        }

        foreach ( $companies as &$company ) { // Utilisation de &$company pour modifier l'objet original
            // L'ID utilisé pour lier l'entreprise dans les notes est viag_id
            $company_id = $company->viag_id ?? null;
            
            if($company->compagny_domain AND !$company->favicon){
                $company->favicon = $this->update_company_favicon($company->viag_id, $company->compagny_domain);
            }
            if(! $company->favicon ){
                $company->initials = strtoupper( substr( $company->company_name, 0, 1 ) . substr( $company->company_name, strpos($company->company_name, ' ') + 1, 1 ) );
            }

            if ( $note_manager_exists && $company_id ) { 
                
                // Récupération de la dernière note/activité de contact pour cette entreprise
                // Assurez-vous que la méthode get_last_contact existe dans ISPAG_Note_Manager
                $last_contact_note = $note_manager->get_last_contact( 'company', $company_id );
                // error_log('last_contact_note ' . print_r($last_contact_note, true));
                
                if ( $last_contact_note && ! empty( $last_contact_note->created_at ) ) {
                    // Si la note existe, on stocke la date brute
                    $company->last_contact_date = $last_contact_note->created_at; 
                    // Optionnel : stocker le type (MEETING, CALL, EMAIL)
                    $company->last_contact_type = $last_contact_note->type; 
                } else {
                    // Valeurs par défaut si aucun contact n'est trouvé
                    $company->last_contact_date = null;
                    $company->last_contact_type = null;
                }
            } else {
                // S'il n'y a pas de manager, les champs existent mais sont nuls
                $company->last_contact_date = null;
                $company->last_contact_type = null;
            }

            if ( class_exists( 'ISPAG_Crm_Contacts_Repository' ) && $company_id ) {
                $contact_repo = new ISPAG_Crm_Contacts_Repository();
                $company->associated_contacts_list_full = $contact_repo->get_users_by_company($company_id);
            }
            else{
                $company->associated_contacts_list_full = [];
            }
        }
        
        return $is_single ? $companies[0] : $companies;
    }

    /**
     * Tente de trouver l'URL du favicon d'un domaine donné.
     *
     * @param string $domain Le domaine cible (ex: "ispag.ch").
     * @param int $timeout Le délai d'expiration pour les requêtes cURL.
     * @return string|false L'URL du favicon ou false si non trouvé.
     */
    function get_favicon_url($domain, $timeout = 5) {
        // 1. Nettoyage et normalisation du domaine
        $domain = trim($domain);
        $domain = preg_replace('/^https?:\/\//', '', $domain); // Supprimer http/https
        $domain = rtrim($domain, '/'); // Supprimer le slash final

        if (empty($domain)) {
            return false;
        }
        
        // Définition de l'URL de base pour l'analyse
        $base_url = 'https://' . $domain; 
        
        // --- Fonction utilitaire pour effectuer les requêtes cURL ---
        $make_request = function($url) use ($timeout) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // À désactiver en production si possible
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (FaviconFinder)');
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            return [
                'code' => $http_code,
                'content_type' => $content_type,
                'body' => $response
            ];
        };

        // -----------------------------------------------------------------
        // ÉTAPE 1 : Vérification des emplacements standards (Convention)
        // -----------------------------------------------------------------

        $standard_paths = [
            $base_url . '/favicon.ico',
            $base_url . '/apple-touch-icon.png',
            $base_url . '/apple-touch-icon-precomposed.png',
        ];

        foreach ($standard_paths as $path) {
            $response = $make_request($path);

            // Si la requête est un succès (200) et qu'il s'agit d'une image
            if ($response['code'] === 200 && str_contains($response['content_type'], 'image')) {
                return $path;
            }
        }

        // -----------------------------------------------------------------
        // ÉTAPE 2 : Analyse de la page d'accueil (Méthode robuste)
        // -----------------------------------------------------------------
        
        // Récupérer le code HTML de la page d'accueil
        $html_response = $make_request($base_url);

        if ($html_response['code'] === 200) {
            // Utiliser une expression régulière pour trouver les liens de favicon dans le <head>
            // Recherche des rel="icon", rel="shortcut icon", rel="apple-touch-icon"
            $pattern = '/<link[^>]+rel=["\'](icon|shortcut icon|apple-touch-icon)["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i';
            
            if (preg_match_all($pattern, $html_response['body'], $matches)) {
                // $matches[2] contient tous les chemins href trouvés
                $potential_paths = array_unique($matches[2]);

                // Tenter de valider le chemin trouvé (en privilégiant les chemins absolus ou en testant l'accès)
                foreach ($potential_paths as $path) {
                    // Si le chemin est absolu (commence par http/https)
                    if (preg_match('/^https?:\/\//i', $path)) {
                        $favicon_url = $path;
                    } 
                    // Si le chemin est relatif (commence par / ou non)
                    else {
                        $favicon_url = $base_url . '/' . ltrim($path, '/');
                    }

                    // Vérification finale si le lien est valide (facultatif mais recommandé)
                    $check = $make_request($favicon_url);
                    if ($check['code'] === 200 && str_contains($check['content_type'], 'image')) {
                        return $favicon_url;
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // ÉTAPE 3 : Solution de secours (Service tiers)
        // -----------------------------------------------------------------
        
        // Utilisation du service Google S2 pour la fiabilité maximale
        // Attention: ceci dépend d'un service externe et peut changer
        $fallback_url = 'https://t0.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=1&url=' . urlencode($base_url) . '&size=64';
        
        // Tenter de valider le fallback (pour éviter une URL S2 cassée)
        $fallback_check = $make_request($fallback_url);
        if ($fallback_check['code'] === 200 && str_contains($fallback_check['content_type'], 'image')) {
            return $fallback_url;
        }
        
        // Aucune icône trouvée
        return false;
    }

    /**
     * Récupère le favicon et met à jour la base de données si trouvé.
     */
    public function update_company_favicon($id, $domain) {
        if (empty($domain)) return false;

        // 1. Chercher l'URL via votre fonction cURL
        $favicon_url = $this->get_favicon_url($domain);

        if ($favicon_url) {
            // 2. Mettre à jour la table wor9711_ispag_companies
            $this->wpdb->update(
                $this->table_companies,
                array('favicon' => $favicon_url), // Colonne à modifier
                array('viag_id' => $id),              // Condition (ID de la ligne)
                array('%s'),                     // Format de la valeur
                array('%d')                      // Format de l'ID
            );
            return $favicon_url;
        }

        return false;
    }

    /**
     * Point de terminaison AJAX pour charger le résumé IA de la société.
     */
    public function ajax_load_company_ai_summary() {
        $log_file = self::$log_file;
        $timestamp = date('Y-m-d H:i:s');
        
        // error_log("[$timestamp] --- DEBUT AJAX LOAD COMPANY SUMMARY ---", 3, $log_file);

        // 1. Sécurité et validation des droits
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            // error_log("[$timestamp] ERREUR : Droits insuffisants", 3, $log_file);
            wp_send_json_error(['message' => __('Access denied.', 'ispag-crm')]);
        }

        $company_id = (int) filter_input(INPUT_POST, 'company_id', FILTER_SANITIZE_NUMBER_INT);
        if (!$company_id) {
            // error_log("[$timestamp] ERREUR : ID Société manquant", 3, $log_file);
            wp_send_json_error(['message' => __('Missing company ID.', 'ispag-crm')]);
        }

        // 2. Récupération des données via le repository
        $repository = new ISPAG_Crm_Company_Repository();
        $company = $repository->get_company_by_viag_id($company_id);

        if (!$company) {
            // error_log("[$timestamp] ERREUR : Société $company_id introuvable", 3, $log_file);
            wp_send_json_error(['message' => __('Company not found.', 'ispag-crm')]);
        }

        // --- PRÉPARATION DES DONNÉES (Texte brut pour l'IA) ---
        $prepared_data = "COMPANY DATA:\n";
        $prepared_data .= "- Name: {$company->company_name}\n";
        $prepared_data .= "- Priority: {$company->priority_level}\n";
        
        // Ajout des contacts associés
        if (!empty($company->associated_contacts_list_full)) {
            $prepared_data .= "\nKEY CONTACTS:\n";
            foreach ($company->associated_contacts_list_full as $c) {
                $prepared_data .= "- {$c->display_name} ({$c->lead_function})\n";
            }
        }

        // Ajout des transactions (Deals)
        if (class_exists('ISPAG_Crm_Deals_Repository')) {
            $deals_repo = new ISPAG_Crm_Deals_Repository();
            $deals = $deals_repo->get_projects_by_company($company_id);
            if (!empty($deals)) {
                $prepared_data .= "\nDEALS/PROJECTS:\n";
                foreach ($deals as $deal) {
                    $prepared_data .= "- {$deal->project_name} | Stage: {$deal->stage_label} | Amount: {$deal->total_excl_vat} CHF\n";
                }
            }
        }

        // Ajout des notes et activités (Nettoyage HTML impératif)
        if (class_exists('ISPAG_Note_Repository')) {
            $note_repo = new ISPAG_Note_Repository();
            $activities = $note_repo->get_activities_for_entity('company', $company_id);
            if (!empty($activities)) {
                $prepared_data .= "\nRECENT ACTIVITY LOG:\n";
                foreach (array_slice($activities, 0, 10) as $act) {
                    $clean_note = wp_strip_all_tags($act->content);
                    $prepared_data .= "- [{$act->created_at}] {$act->type}: {$clean_note}\n";
                }
            }
        }

        // error_log("[$timestamp] ENVOI AU FILTRE MISTRAL pour {$company->company_name}", 3, $log_file);

        // 3. Appel du filtre (qui utilise ta classe ISPAG_Crm_Mistral)
        $ai_response = apply_filters(
            'ispag_send_to_crm_mistral', 
            null, 
            $company->company_name, 
            'Company Profile', 
            $prepared_data, 
            'company' 
        );

        // Vérification de la réponse
        if (null === $ai_response || !isset($ai_response['summary'])) {
            // error_log("[$timestamp] ERREUR : L'IA a renvoyé une réponse vide ou invalide.", 3, $log_file);
            wp_send_json_error(['message' => 'AI processing failed.']);
        }

        // 4. Préparation du HTML pour le retour AJAX
        $summary_html = sprintf(
            '<div class="ispag-card ispag-ai-summary-card">
                <h5><span class="dashicons dashicons-building"></span> %s (%s)</h5>
                <div class="ai-content">%s</div>
            </div>',
            __('AI Company Summary', 'ispag-crm'),
            esc_html($company->company_name),
            $ai_response['summary']
        );

        $actions_html = sprintf(
            '<div class="ispag-card ispag-ai-actions-card">
                <h5><span class="dashicons dashicons-lightbulb"></span> %s</h5>
                <div class="ai-content">%s</div>
            </div>',
            __('AI Recommended Actions', 'ispag-crm'),
            $ai_response['actions']
        );

        // error_log("[$timestamp] --- FIN AJAX LOAD COMPANY (SUCCESS) ---", 3, $log_file);

        // Sécurité anti-parasitage du JSON
        if (ob_get_length()) ob_clean();

        wp_send_json_success([
            'html'    => $summary_html,
            'actions' => $actions_html,
            'profil'  => $ai_response['profil'] ?? ''
        ]);
    }

    /**
     * Gère la sauvegarde AJAX des champs éditables.
     */
    public function handle_ajax_save_company_field() {
        global $wpdb;
         
        $table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $table_owners    = ISPAG_Crm_Company_Constants::TABLE_COMPANY_OWNER; 
        $table_priorities = $this->table_priorities;

        $company_id    = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
        $field_name    = isset( $_POST['field_name'] ) ? sanitize_text_field( $_POST['field_name'] ) : '';
        $new_value     = isset( $_POST['new_value'] ) ? wp_unslash( $_POST['new_value'] ) : '';
        $department_id = isset( $_POST['department_id'] ) ? sanitize_key( $_POST['department_id'] ) : '';

        if ( $company_id === 0 || empty( $field_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing ID or field name.', 'ispag-crm' ) ) );
        }

        $updated_successfully = false;
        $db_value_to_return = $new_value; 
 
        // --- 1. LOGIQUE SPÉCIFIQUE : OWNERS PAR DÉPARTEMENT ---
        if ( $field_name === 'department_owner' && !empty( $department_id ) ) {
            $new_owner_id = absint( $new_value );
            $now = current_time('mysql');

            // --- ÉTAPE A : Archiver l'ancien owner actif ---
            // On passe le statut de 'active' à 'inactive' et on note la date de fin
            $wpdb->update(
                $table_owners,
                array( 
                    'status'        => 'inactive', 
                    'unassigned_at' => $now 
                ),
                array( 
                    'company_id'     => $company_id, 
                    'department_key' => $department_id,
                    'status'         => 'active' // Important : on ne touche qu'à celui qui est actif
                ),
                array( '%s', '%s' ),
                array( '%d', '%s', '%s' )
            );

            // --- ÉTAPE B : Insérer le nouveau si nécessaire ---
            if ( !empty( $new_owner_id ) ) {
                $result = $wpdb->insert(
                    $table_owners,
                    array(
                        'company_id'     => $company_id,
                        'user_id'        => $new_owner_id,
                        'department_key' => $department_id,
                        'assigned_at'    => $now,
                        'status'         => 'active'
                    ),
                    array( '%d', '%d', '%s', '%s', '%s' )
                );
            } else {
                // Si la nouvelle valeur est vide, on a juste supprimé (archivé) l'owner actuel
                $result = true; 
            }

            if ( $result !== false ) {
                $updated_successfully = true;
                $db_value_to_return = $new_owner_id;
            }
        }

        // --- 2. PRIORITÉ ---
        elseif ( $field_name === ISPAG_Crm_Company_Constants::PRIORITY_LEVEL ) {
            $user_id = get_current_user_id();
            $result = $wpdb->replace(
                $table_priorities,
                array(
                    'user_id'        => $user_id,
                    'entity_id'      => $company_id,
                    'entity_type'    => 'company',
                    'priority_level' => $new_value
                ),
                array( '%d', '%d', '%s', '%s' )
            );

            if ( $result !== false ) {
                $updated_successfully = true;
                $db_value_to_return = $new_value;
            }
        }
        
        // --- 3. TABLE PRINCIPALE ---
        elseif ( in_array( $field_name, array( 'company_name', 'compagny_domain', 'viag_id', 'isIngenieur', 'isSupplier', 'phone', 'email', 'is_active', 'favicon' ) ) ) {
            if ( in_array( $field_name, array( 'isIngenieur', 'isSupplier' ) ) ) {
                $db_value = filter_var($new_value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $format = '%d';
            } else {
                if ($field_name === 'email') {
                    $db_value = sanitize_email($new_value);
                } elseif ($field_name === 'company_domain' || $field_name === 'compagny_domain') {
                    $domain = strtolower(trim($new_value));
                    if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                        $db_value = $domain;
                    } else {
                        // Si le domaine est invalide, on peut soit le rejeter, 
                        // soit le nettoyer par défaut
                        $db_value = sanitize_text_field($domain);
                    }
                } else {
                    $db_value = sanitize_text_field($new_value);
                }
                $format = '%s';
            }

            $result = $wpdb->update( 
                $table_companies, 
                array( $field_name => $db_value ), 
                array( 'Id' => $company_id ), 
                array( $format ), 
                array( '%d' ) 
            );

            if ( $result !== false ) {
                $updated_successfully = true;
                $db_value_to_return = $db_value;
            }
        }

        // --- 4. MÉTA ---
        else {
            $db_value_to_return = ($field_name === ISPAG_Crm_Company_Constants::META_COMPANY_OWNER) ? absint($new_value) : sanitize_text_field($new_value);
            $updated = update_post_meta( $company_id, $field_name, $db_value_to_return );
            
            // update_post_meta renvoie true ou l'ID de la meta, mais false si la valeur est identique
            if ( $updated !== false || get_post_meta( $company_id, $field_name, true ) == $db_value_to_return ) {
                $updated_successfully = true;
            }
        }

        // --- 5. RETOUR JSON ---
        if ( $updated_successfully ) {
            // IMPORTANT : S'assurer que get_display_html renvoie bien l'icône ✏️
            $display_html = $this->get_display_html( $field_name, $db_value_to_return );

            wp_send_json_success( array(
                'display_value' => $display_html,
                'new_value'     => $db_value_to_return, 
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Database update failed or no changes made.', 'ispag-crm' ) ) );
        }
    }
    /**
     * Fonction d'aide sécurisée pour lire un champ de la table Fournisseur 
     * (utilisée pour vérifier si la valeur a réellement changé).
     */
    private function get_company_field_value( $company_id, $field_name ) {
        global $wpdb;
        $table_name_fournisseur = ISPAG_Crm_Company_Constants::TABLE_NAME;

        // NOTE SUR LA SÉCURITÉ : Le nom de colonne ($field_name) est inséré directement 
        // dans la requête car il a été nettoyé par sanitize_key() avant l'appel.
        $sql = $wpdb->prepare( 
            "SELECT {$field_name} FROM {$table_name_fournisseur} WHERE Id = %d", 
            $company_id 
        );

        return $wpdb->get_var( $sql );
    }
    private function get_display_html( $field_name, $value ) {
        $default_style = 'background-color: #f2f2f2; color: #333; padding: 2px 8px; border-radius: 4px;';
        $display_label = $value; // Valeur par défaut

        switch ($field_name) {
            // 1. Gestion des cases à cocher (Booleens)
            case 'isIngenieur':
            case 'isSupplier':
                // Retourne directement l'émoji sans badge
                return ( $value == 1 ) ? '✅ <span class="edit-icon">✏️</span>' : '❌ <span class="edit-icon">✏️</span>';

            // 2. Gestion des Responsables (Meta ou Département spécifique)
            case 'department_owner':
            case ISPAG_Crm_Company_Constants::META_COMPANY_OWNER:
                if ( ! empty( $value ) ) {
                    $user = get_user_by( 'id', $value );
                    if ( $user && ! is_wp_error( $user ) ) {
                        $display_label = $user->display_name;
                    } else {
                        $display_label = __( 'Unknown user', 'ispag-crm' );
                    }
                } else {
                    $display_label = __( 'Not assigned', 'ispag-crm' );
                }
                // On affiche l'owner dans un petit badge
                return esc_html( $display_label ) . '<span class="edit-icon">✏️</span>';

            // 3. Cas par défaut (Nom, Email, etc.)
            default:
                $display_label = ! empty( $value ) ? $value : __( 'Non défini', 'ispag-crm' );
                break;
        }
        
        // Retour standard pour les textes
        return esc_html( $display_label ) . ' <span class="edit-icon">✏️</span>';
    }

    public function ispag_handle_deals_export() {
        // check_ajax_referer('ispag_crm_nonce');

        $ids      = isset($_GET['ids']) ? array_map('intval', $_GET['ids']) : [];
        $format   = $_GET['format'] ?? 'csv';
        $filename = sanitize_title($_GET['filename']) . '.' . ($format === 'excel' ? 'xlsx' : $format);

        if (empty($ids)) {
            wp_die(__('No data to export', 'ispag-crm'));
        }

        // Récupérer les données réelles via ton Repository
        $repo = new ISPAG_Crm_Deals_Repository();
        $data_to_export = [];
        foreach($ids as $id) {
            $data_to_export[] = $repo->get_deal_by_id($id);
        }

        // --- ICI LOGIQUE DE GÉNÉRATION ---
        // Si CSV (simple exemple)
        if ($format === 'csv') {
            if (ob_get_length()) ob_clean();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

            $output = fopen('php://output', 'w');

            // BOM UTF-8 pour la gestion des accents
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            // Utilisation du point-virgule (;) pour Excel Suisse/France
            $separator = ';';

            // En-têtes corrigés
            fputcsv($output, [
                __('Project', 'ispag-crm'),
                __('Offer number', 'ispag-crm'),
                __('Company', 'ispag-crm'),
                __('Contact', 'ispag-crm'),
                __('Amount (CHF)', 'ispag-crm'),
                __('Stage', 'ispag-crm'),
                __('Create  date', 'ispag-crm'),
                __('Close date', 'ispag-crm')
            ], $separator);

            foreach ($ids as $id) {
                $deal = $repo->get_deal_by_id($id);
                if ($deal) {
                    fputcsv($output, [
                        $deal->project_name,
                        $deal->deal_group_ref,
                        $deal->associated_company_name,
                        $deal->associated_contact_names,
                        number_format((float)($deal->total_excl_vat ?? 0), 2, '.', ''),
                        $deal->stage_label,
                        !empty($deal->date_creation) ? date('d.m.Y', strtotime($deal->date_creation)) : '-',
                        !empty($deal->closing_date) ? date('d.m.Y', strtotime($deal->closing_date)) : '-'
                    ], $separator);
                }
            }

            fclose($output);
            exit;
        }

        // Pour PDF et Excel, tu appelleras tes classes spécialisées ici.
        wp_die();
    }

    /**
     * Sauvegarde le favicon d'une entreprise via AJAX
     */
    public function ispag_save_company_favicon() {
        global $wpdb;

        // 1. Vérification de sécurité (Nonce)
        // On utilise le même nonce que pour les autres champs du CRM
        check_ajax_referer('ispag_new_contact_nonce', 'nonce');

        // 2. Récupération et assainissement des données
        $company_id    = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

        if (!$company_id || !$attachment_id) {
            wp_send_json_error(['message' => 'Données manquantes (ID entreprise ou média).']);
        }

        // 3. Récupération de l'URL du média WordPress
        $favicon_url = wp_get_attachment_url($attachment_id);

        if (!$favicon_url) {
            wp_send_json_error(['message' => 'Impossible de récupérer l\'URL du média sélectionné.']);
        }

        // 4. Mise à jour de la table personnalisée ISPAG
        $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;

        // On met à jour la colonne 'favicon' là où l'Id correspond
        $updated = $wpdb->update(
            $table_name,
            array('favicon' => $favicon_url), // Donnée à mettre à jour
            array('Id'      => $company_id),  // Condition WHERE (Attention à la casse de 'Id')
            array('%s'),                      // Format de la valeur (string)
            array('%d')                       // Format du WHERE (integer)
        );

        // 5. Réponse à l'appel AJAX
        // $updated renvoie le nombre de lignes modifiées. 
        // Si l'URL était déjà la même, il renvoie 0, ce qui n'est pas une erreur.
        if ($updated !== false) {
            wp_send_json_success([
                'message'       => 'Favicon mis à jour avec succès.',
                'url'           => $favicon_url
            ]);
        } else {
            wp_send_json_error([
                'message'  => 'Erreur lors de la mise à jour en base de données.',
                'db_error' => $wpdb->last_error // Pour le debug si besoin
            ]);
        }
    }
}
endif;