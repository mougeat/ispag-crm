<?php

if ( ! class_exists( 'ISPAG_Crm_Deals_Repository' ) ) :

class ISPAG_Crm_Deals_Repository {

    private $wpdb;
    private $table_name;
    private $stages_table_name;

    // Caches statiques pour éviter les requêtes N+1 sur les listes
    private static $stages_cache = null;
    private static $activities_cache = null;

    private static $log_file = WP_CONTENT_DIR . '/ispag_deal_repository.log';

    /**
     * Initialise la connexion à la base de données.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $this->stages_table_name = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;

        add_action( 'wp_ajax_ispag_load_gemini_deal_summary', array( $this, 'ajax_load_deal_ai_summary' ) );
    }

    // ==========================================================
    // MÉTHODES INTERNES & OPTIMISATION
    // ==========================================================

    /**
     * Extrait le numéro d'offre racine
     */
    private function get_root_offer_number($offer_number) {
        if (strpos($offer_number, '.') !== false) {
            return explode('.', $offer_number)[0];
        }
        return $offer_number;
    }

    /**
     * PRÉ-CHARGEMENT : Remplit le cache statique pour éviter 4000 requêtes SQL
     */
    private function _prime_caches( $projects ) {
        $group_refs = array_filter( array_unique( array_map( function( $p ) {
            return !empty($p->deal_group_ref) ? $p->deal_group_ref : $this->get_root_offer_number($p->offer_number);
        }, $projects ) ) );

        if ( empty( $group_refs ) ) return;

        $placeholders = implode( ',', array_fill( 0, count( $group_refs ), '%s' ) );

        // CORRECTION : On joint la table de liaison (dst) avec la table de config (st)
        $stages_table = ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES; // La table avec labels/colors
        $link_table   = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES; // La table de liaison wor9711_ispag_deals_stages

        $stages_data = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT dst.deal_group_ref, st.stage_key, st.stage_label, st.stage_color 
            FROM {$link_table} dst
            JOIN {$stages_table} st 
                ON dst.current_stage_key = st.stage_key COLLATE utf8mb4_unicode_ci
            WHERE dst.deal_group_ref IN ($placeholders)",
            $group_refs
        ) );

        self::$stages_cache = [];
        foreach ( $stages_data as $row ) {
            self::$stages_cache[$row->deal_group_ref] = $row;
        }

        // (La partie sur les activités/notes reste inchangée...)
    }

    /**
     * ENRICHISSEMENT : Transforme les données brutes en Modèles ISPAG_Crm_Deal_Model
     */
    private function _enrich_project_data( $results ) {
        if ( empty( $results ) ) return $results;

        $is_single = is_object( $results );
        $projects = $is_single ? [ $results ] : $results;
        $enriched_projects = [];
        
        // Optimisation si liste
        if ( ! $is_single && count( $projects ) > 1 ) {
            $this->_prime_caches( $projects );
        }

        $stage_repo = class_exists( 'ISPAG_Crm_Deal_Stages_Repository' ) ? new ISPAG_Crm_Deal_Stages_Repository() : null;
        $note_manager = class_exists( 'ISPAG_Note_Manager' ) ? new ISPAG_Note_Manager() : null;

        foreach ( $projects as $project_raw_data ) {
            $deal_model = new ISPAG_Crm_Deal_Model( $project_raw_data );
            $group_ref = !empty($project_raw_data->deal_group_ref) ? $project_raw_data->deal_group_ref : $this->get_root_offer_number($project_raw_data->offer_number ?? '');

            // Injection Stage
            // 1. Récupération des détails (Cache ou Repo)
            $stage_details = null;
            if ( isset( self::$stages_cache[$group_ref] ) ) {
                $stage_details = self::$stages_cache[$group_ref];
            } elseif ( $stage_repo ) {
                // Si pas en cache, on force la lecture via le repo
                $stage_details = $stage_repo->get_stage_by_deal_group_ref( $group_ref );
            }

            // 2. Injection dans le modèle
            if ( $stage_details ) {
                // Note : On vérifie les deux sources possibles (objet SQL ou objet Model du repo)
                $deal_model->stage_key   = $stage_details->current_stage_key ?? $stage_details->stage_key ?? '';
                $deal_model->stage_label = $stage_details->stage_label ?? $stage_details->label ?? '';
                $deal_model->stage_color = $stage_details->stage_color ?? $stage_details->color ?? '';
            }

            $group_ref = !empty($project_raw_data->deal_group_ref) ? $project_raw_data->deal_group_ref : $this->get_root_offer_number($project_raw_data->offer_number ?? '');

            if ( $note_manager && !empty($group_ref) ) {
                // On passe bien la référence (ex: OF26-11102)
                $last_activity = $note_manager->get_last_contact('deal', $group_ref);
                $deal_model->last_activity_date = $last_activity->created_at ?? null;
            }
            
            $enriched_projects[] = $deal_model;
        }

        return $is_single ? $enriched_projects[0] : $enriched_projects;
    }

    /**
     * Clause SELECT de base avec jointures (Compagnies + Contacts)
     */
    private function _build_base_select_query() {
        $users_table = $this->wpdb->users;
        $company_table = $this->wpdb->prefix . 'ispag_companies'; 

        return "
            SELECT 
                T.*, 
                C.company_name AS associated_company_name,
                (
                    SELECT GROUP_CONCAT(U.display_name SEPARATOR ', ')
                    FROM {$users_table} AS U
                    WHERE FIND_IN_SET(U.ID, T.associated_contact_ids) > 0
                ) AS associated_contact_names
            FROM {$this->table_name} AS T
            LEFT JOIN {$company_table} AS C 
                ON C.Id = T.associated_company_id
        ";
    }

    // ==========================================================
    // MÉTHODES DE SÉLECTION (LECTURE)
    // ==========================================================

    // ==========================================================
    // MÉTHODES DE SÉLECTION (LECTURE)
    // ==========================================================

    public function get_deal_id_by_of( $of_number = null ) {
        if ( empty( $of_number ) ) {
            return null;
        }

        // On trie par date de création (ou closing_date) décroissante pour avoir le plus récent en premier
        $sql = "
            SELECT id 
            FROM {$this->table_name} 
            WHERE deal_group_ref = %s 
            ORDER BY date_creation DESC, id DESC 
            LIMIT 1
        ";

        $deal_id = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $of_number ) );

        return $deal_id ? absint( $deal_id ) : null;
    }

    
    public function get_deal_by_id( $deal_id ) {
        return $this->get_project_by_id($deal_id);
    }

    public function get_project_by_id( $project_id ) {
        if ( ! is_numeric( $project_id ) || $project_id <= 0 ) return null;
        $sql = $this->_build_base_select_query() . " WHERE T.id = %d";
        $result = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $project_id ) );
        return $this->_enrich_project_data( $result );
    }

    public function get_project_by_project_num( $project_num ) {
        if ( ! is_numeric( $project_num ) || $project_num <= 0 ) return null;
        $sql = $this->_build_base_select_query() . " WHERE T.project_num = %d";
        $result = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $project_num ) );
        return $this->_enrich_project_data( $result );
    }

    // public function get_all_deals_grouped_by_stage( $filters = [] ) {
    //     $current_user_id = ! current_user_can( 'administrator' ) ? get_current_user_id() : 'all';
    //     $defaults = [
    //         'status'       => 'open',
    //         'owner'        => ($current_user_id > 0) ? $current_user_id : 'all',
    //         'closing_date' => 'all',
    //         'create_date'  => 'all',
    //         'search'       => '',
    //         'limit'        => 4000,   // ← pagination par défaut
    //         'offset'       => 0,
    //     ];
    //     $filters = array_merge($defaults, $filters);

    //     // ── 1. Construction du WHERE ──────────────────────────────────────────
    //     $where_conditions = [];
    //     $params           = [];

    //     $get_date_range = function($filter_key) { /* ... inchangé ... */ };

    //     $where_conditions[] = "T.process_type IN (%s, %s)";
    //     $params[] = 'Offre';
    //     $params[] = 'Commande';

    //     if ($filters['owner'] !== 'all') {
    //         $where_conditions[] = "T.deal_owner = %d";
    //         $params[] = absint($filters['owner']);
    //     }

    //     if (!empty($filters['search'])) {
    //         $search_term = sanitize_text_field($filters['search']);
    //         if (strpos($search_term, 'user-') === 0) {
    //             $where_conditions[] = "T.associated_contact_ids LIKE %s";
    //             $params[] = '%' . $this->wpdb->esc_like(absint(str_replace('user-', '', $search_term))) . '%';
    //         } elseif (strpos($search_term, 'company-') === 0) {
    //             $where_conditions[] = "T.associated_company_id = %d";
    //             $params[] = absint(str_replace('company-', '', $search_term));
    //         } else {
    //             $s = '%' . $this->wpdb->esc_like($search_term) . '%';
    //             $where_conditions[] = "(T.project_name LIKE %s OR C.company_name LIKE %s OR T.offer_num LIKE %s)";
    //             $params = array_merge($params, [$s, $s, $s]);
    //         }
    //     }

    //     if ($filters['status'] === 'open') {
    //         $where_conditions[] = "(T.project_db_status = " . ISPAG_Crm_Deal_Constants::STATUS_OPEN . " 
    //                             OR (T.project_db_status = 1 AND T.database_status = 11))";
    //     }

    //     foreach (['closing_date', 'create_date'] as $dt) {
    //         if ($filters[$dt] !== 'all') {
    //             [$start, $end] = $get_date_range($filters[$dt]);
    //             if ($start && $end) {
    //                 $col = ($dt === 'closing_date') ? 'T.closing_date' : 'T.date_creation';
    //                 $where_conditions[] = "$col BETWEEN %s AND %s";
    //                 $params[] = $start;
    //                 $params[] = $end;
    //             }
    //         }
    //     }

    //     $where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    //     // ── 2. Requête principale — sans sous-requête corrélée pour les contacts ──
    //     // On retire le GROUP_CONCAT du _build_base_select_query et on le fait
    //     // en batch après. Le SELECT de base devient léger.
    //     $users_table   = $this->wpdb->users;
    //     $company_table = $this->wpdb->prefix . 'ispag_companies';

    //    $sql = "
    //         SELECT 
    //             T.*,
    //             C.Id                AS associated_company_row_id,
    //             C.company_name      AS associated_company_name,
    //             C.favicon           AS associated_company_favicon,
    //             C.compagny_domain   AS associated_company_domain
    //         FROM {$this->table_name} AS T
    //         LEFT JOIN {$company_table} AS C ON C.Id = T.associated_company_id
    //         {$where_sql}
    //         ORDER BY T.closing_date DESC
    //         LIMIT %d OFFSET %d
    //     ";

    //     $params[] = absint($filters['limit']);
    //     $params[] = absint($filters['offset']);

    //     $raw_deals = $this->wpdb->get_results(
    //         $this->wpdb->prepare($sql, $params)
    //     );

    //     if (empty($raw_deals)) return [];

    //     // ── 3. Pré-chargement en batch de TOUT ce dont on a besoin ──────────
    //     $group_refs = array_unique(array_filter(array_map(
    //         fn($d) => !empty($d->deal_group_ref)
    //             ? $d->deal_group_ref
    //             : $this->get_root_offer_number($d->offer_number),
    //         $raw_deals
    //     )));

    //     // 3a. Stages en une seule requête
    //     $stages_map = $this->_load_stages_batch($group_refs);

    //     // 3b. Dernière activité en une seule requête
    //     $activities_map = $this->_load_last_activities_batch($group_refs);

    //     // 3c. Noms + avatars des contacts en une seule requête
    //     $contacts_data = $this->_load_contact_names_batch($raw_deals);
    //     $contacts_map      = $contacts_data['names'];
    //     $contacts_full_map = $contacts_data['contacts'];

    //     // 3d. Favicon / initiales des sociétés (une résolution par société unique)
    //     $companies_map = $this->_load_companies_batch($raw_deals);

    //     // ── 4. Enrichissement sans requête SQL supplémentaire ────────────────
    //     $grouped = [];

    //     foreach ($raw_deals as $raw) {
    //         $deal_model = new ISPAG_Crm_Deal_Model($raw);

    //         $group_ref = !empty($raw->deal_group_ref)
    //             ? $raw->deal_group_ref
    //             : $this->get_root_offer_number($raw->offer_number);

    //         // Stage
    //         if (isset($stages_map[$group_ref])) {
    //             $s = $stages_map[$group_ref];
    //             $deal_model->stage_key   = $s->stage_key   ?? '';
    //             $deal_model->stage_label = $s->stage_label ?? '';
    //             $deal_model->stage_color = $s->stage_color ?? '';
    //         }

    //         // Dernière activité
    //         $deal_model->last_activity_date = $activities_map[$group_ref] ?? null;

    //         // Noms et avatar des contacts
    //         $deal_model->associated_contact_names = $contacts_map[$raw->id] ?? '';
    //         $deal_model->associated_contacts      = $contacts_full_map[$raw->id] ?? [];

    //         // Favicon ou initiales de la société
    //         $company_row_id = $raw->associated_company_row_id ?? null;
    //         $company_visual  = $companies_map[$company_row_id] ?? ['favicon' => null, 'initials' => null];
    //         $deal_model->associated_company_favicon  = $company_visual['favicon'];
    //         $deal_model->associated_company_initials = $company_visual['initials'];

    //         // Groupement par stage
    //         $key = !empty($deal_model->stage_key) ? $deal_model->stage_key : 'submission_received';
    //         $grouped[$key][] = $deal_model;
    //     }

    //     return $grouped;
    // }
    public function get_all_deals_grouped_by_stage( $filters = [] ) {
        $current_user_id = ! current_user_can( 'administrator' ) ? get_current_user_id() : 'all';
        $defaults = [
            'status'       => 'open',
            'owner'        => ($current_user_id > 0) ? $current_user_id : 'all',
            'closing_date' => 'all',
            'create_date'  => 'all',
            'search'       => '',
            'limit'        => 4000,
            'offset'       => 0,
        ];
        $filters = array_merge($defaults, $filters);

        // ── 1. Construction du WHERE ──────────────────────────────────────────
        $where_conditions = [];
        $params           = [];

        $get_date_range = [ $this, 'get_date_range' ];

        $where_conditions[] = "T.process_type IN (%s, %s)";
        $params[] = 'Offre';
        $params[] = 'Commande';

        if ($filters['owner'] !== 'all') {
            $where_conditions[] = "T.deal_owner = %d";
            $params[] = absint($filters['owner']);
        }

        if (!empty($filters['search'])) {
            $search_term = sanitize_text_field($filters['search']);
            if (strpos($search_term, 'user-') === 0) {
                $where_conditions[] = "T.associated_contact_ids LIKE %s";
                $params[] = '%' . $this->wpdb->esc_like(absint(str_replace('user-', '', $search_term))) . '%';
            } elseif (strpos($search_term, 'company-') === 0) {
                $where_conditions[] = "T.associated_company_id = %d";
                $params[] = absint(str_replace('company-', '', $search_term));
            } else {
                $s = '%' . $this->wpdb->esc_like($search_term) . '%';
                $where_conditions[] = "(T.project_name LIKE %s OR C.company_name LIKE %s OR T.offer_num LIKE %s)";
                $params = array_merge($params, [$s, $s, $s]);
            }
        }

        if ($filters['status'] === 'open') {
            $where_conditions[] = "(T.project_db_status = " . ISPAG_Crm_Deal_Constants::STATUS_OPEN . " 
                                OR (T.project_db_status = 1 AND T.database_status = 11))";
        }

        foreach (['closing_date', 'create_date'] as $dt) {
            if ($filters[$dt] !== 'all') {
                [$start, $end] = $get_date_range($filters[$dt]);
                if ($start && $end) {
                    $col = ($dt === 'closing_date') ? 'T.closing_date' : 'T.date_creation';
                    $where_conditions[] = "$col BETWEEN %s AND %s";
                    $params[] = $start;
                    $params[] = $end;
                }
            }
        }

        $where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // ── 2. Requête principale ─────────────────────────────────────────────
        $company_table = $this->wpdb->prefix . 'ispag_companies';

        $sql = "
            SELECT 
                T.*,
                C.Id                AS associated_company_row_id,
                C.company_name      AS associated_company_name,
                C.favicon           AS associated_company_favicon,
                C.compagny_domain   AS associated_company_domain
            FROM {$this->table_name} AS T
            LEFT JOIN {$company_table} AS C ON C.Id = T.associated_company_id
            {$where_sql}
            ORDER BY T.closing_date DESC
            LIMIT %d OFFSET %d
        ";

        $params[] = absint($filters['limit']);
        $params[] = absint($filters['offset']);

        $raw_deals = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $params)
        );

        if (empty($raw_deals)) return [];

        // ── 3. Pré-chargement en batch ────────────────────────────────────────
        $group_refs = array_unique(array_filter(array_map(
            fn($d) => !empty($d->deal_group_ref)
                ? $d->deal_group_ref
                : $this->get_root_offer_number($d->offer_number),
            $raw_deals
        )));

        $stages_map      = $this->_load_stages_batch($group_refs);
        $activities_map  = $this->_load_last_activities_batch($group_refs);
        $contacts_data   = $this->_load_contact_names_batch($raw_deals);
        $companies_map   = $this->_load_companies_batch($raw_deals);

        $contacts_map      = $contacts_data['names'];
        $contacts_full_map = $contacts_data['contacts'];

        // ── 4. Enrichissement ────────────────────────────────────────────────
        $grouped = [];

        foreach ($raw_deals as $raw) {
            $deal_model = new ISPAG_Crm_Deal_Model($raw, false);

            $group_ref = !empty($raw->deal_group_ref)
                ? $raw->deal_group_ref
                : $this->get_root_offer_number($raw->offer_number);

            $stage = $stages_map[$group_ref] ?? null;

            // Stage
            if ($stage) {
                $deal_model->stage_key   = $stage->stage_key   ?? '';
                $deal_model->stage_label = $stage->stage_label ?? '';
                $deal_model->stage_color = $stage->stage_color ?? '';
            }

            // Activités & Contacts
            $deal_model->last_activity_date       = $activities_map[$group_ref] ?? null;
            $deal_model->associated_contact_names = $contacts_map[$raw->id] ?? '';
            $deal_model->associated_contacts      = $contacts_full_map[$raw->id] ?? [];

            // Favicon / Initiales
            $company_row_id = $raw->associated_company_row_id ?? null;
            $company_visual  = $companies_map[$company_row_id] ?? ['favicon' => null, 'initials' => null];

            $deal_model->associated_company_favicon  = $company_visual['favicon'];
            $deal_model->associated_company_initials = $company_visual['initials'];

            // Groupement
            $key = !empty($deal_model->stage_key) ? $deal_model->stage_key : 'submission_received';
            $grouped[$key][] = $deal_model;
        }

        return $grouped;
    }




    // ─────────────────────────────────────────────────────────────────────────
    // KANBAN : chargement allégé + pagination par colonne
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convertit un filtre de date (today, this_week, last_month…) en intervalle [début, fin].
     * Retourne [null, null] pour 'all' ou une valeur inconnue (= pas de filtre).
     */
    public function get_date_range( $key ) {
        $now   = current_datetime();
        $today = $now->setTime( 0, 0, 0 );
        $fmt   = fn( $d, $end = false ) => $d->format( 'Y-m-d' ) . ( $end ? ' 23:59:59' : ' 00:00:00' );
        $monday = $today->modify( 'monday this week' );

        switch ( $key ) {
            case 'today':     return [ $fmt( $today ), $fmt( $today, true ) ];
            case 'yesterday': $d = $today->modify( '-1 day' ); return [ $fmt( $d ), $fmt( $d, true ) ];
            case 'this_week': return [ $fmt( $monday ), $fmt( $monday->modify( '+6 days' ), true ) ];
            case 'last_week': $d = $monday->modify( '-7 days' ); return [ $fmt( $d ), $fmt( $d->modify( '+6 days' ), true ) ];
            case 'next_week': $d = $monday->modify( '+7 days' ); return [ $fmt( $d ), $fmt( $d->modify( '+6 days' ), true ) ];
            case 'this_month':
            case 'last_month':
            case 'next_month':
                $offset = [ 'this_month' => '0 month', 'last_month' => '-1 month', 'next_month' => '+1 month' ][ $key ];
                $first  = $today->modify( 'first day of this month' )->modify( $offset );
                return [ $fmt( $first ), $fmt( $first->modify( 'last day of this month' ), true ) ];
            case 'last_year':
                $y = (int) $today->format( 'Y' ) - 1;
                return [ "$y-01-01 00:00:00", "$y-12-31 23:59:59" ];
            case 'older_than_last_year':
                $y = (int) $today->format( 'Y' ) - 2;
                return [ '1970-01-01 00:00:00', "$y-12-31 23:59:59" ];
        }
        return [ null, null ];
    }

    /**
     * Données du Kanban : les totaux sont calculés sur TOUS les deals, mais seuls les
     * $per_stage premiers de chaque colonne sont enrichis (contacts, avatars, activité).
     *
     * @return array [ stage_key => [ 'count' => int, 'total' => float, 'deals' => ISPAG_Crm_Deal_Model[] ] ]
     */
    public function get_kanban_data( $filters = [], $per_stage = 20, $only_stage = null, $offset = 0 ) {
        $light = $this->_kanban_light_rows( $filters );
        if ( empty( $light ) ) return [];

        $result = [];
        foreach ( $light as $stage_key => $bucket ) {
            if ( $only_stage !== null && $only_stage !== $stage_key ) continue;
            $slice = array_slice( $bucket['rows'], $only_stage !== null ? $offset : 0, $per_stage );
            $result[ $stage_key ] = [
                'count' => count( $bucket['rows'] ),
                'total' => $bucket['total'],
                'deals' => $this->_enrich_kanban_rows( $slice ),
            ];
        }
        return $result;
    }

    /**
     * Requête unique, triée, groupée par étape (sans enrichissement).
     */
    private function _kanban_light_rows( $filters ) {
        $current_user_id = ! current_user_can( 'administrator' ) ? get_current_user_id() : 'all';
        $filters = array_merge( [
            'status'       => 'open',
            'owner'        => ( $current_user_id > 0 ) ? $current_user_id : 'all',
            'closing_date' => 'all',
            'create_date'  => 'all',
            'search'       => '',
            'limit'        => 4000,
        ], $filters );

        $where  = [ 'T.process_type IN (%s, %s)' ];
        $params = [ 'Offre', 'Commande' ];

        if ( $filters['owner'] !== 'all' ) {
            $where[]  = 'T.deal_owner = %d';
            $params[] = absint( $filters['owner'] );
        }

        if ( ! empty( $filters['company_id'] ) ) {
            $where[]  = 'T.associated_company_id = %d';
            $params[] = absint( $filters['company_id'] );
        }
        if ( ! empty( $filters['contact_id'] ) ) {
            $where[]  = 'FIND_IN_SET(%d, REPLACE(T.associated_contact_ids, \' \', \'\')) > 0';
            $params[] = absint( $filters['contact_id'] );
        }

        if ( ! empty( $filters['search'] ) ) {
            $term = sanitize_text_field( $filters['search'] );
            if ( strpos( $term, 'user-' ) === 0 ) {
                $where[]  = 'T.associated_contact_ids LIKE %s';
                $params[] = '%' . $this->wpdb->esc_like( absint( str_replace( 'user-', '', $term ) ) ) . '%';
            } elseif ( strpos( $term, 'company-' ) === 0 ) {
                $where[]  = 'T.associated_company_id = %d';
                $params[] = absint( str_replace( 'company-', '', $term ) );
            } else {
                $s        = '%' . $this->wpdb->esc_like( $term ) . '%';
                $where[]  = '(T.project_name LIKE %s OR C.company_name LIKE %s OR T.offer_num LIKE %s)';
                $params   = array_merge( $params, [ $s, $s, $s ] );
            }
        }

        if ( $filters['status'] === 'open' ) {
            $where[] = '(T.project_db_status = ' . (int) ISPAG_Crm_Deal_Constants::STATUS_OPEN . '
                        OR (T.project_db_status = 1 AND T.database_status = 11))';
        }

        foreach ( [ 'closing_date' => 'T.closing_date', 'create_date' => 'T.date_creation' ] as $key => $col ) {
            if ( $filters[ $key ] !== 'all' ) {
                [ $start, $end ] = $this->get_date_range( $filters[ $key ] );
                if ( $start && $end ) {
                    $where[]  = "$col BETWEEN %s AND %s";
                    $params[] = $start;
                    $params[] = $end;
                }
            }
        }

        $company_table = $this->wpdb->prefix . 'ispag_companies';
        $params[]      = absint( $filters['limit'] );

        $raw_deals = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT T.*,
                    C.Id              AS associated_company_row_id,
                    C.company_name    AS associated_company_name,
                    C.favicon         AS associated_company_favicon
             FROM {$this->table_name} AS T
             LEFT JOIN {$company_table} AS C ON C.Id = T.associated_company_id
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY T.closing_date DESC
             LIMIT %d",
            $params
        ) );

        if ( empty( $raw_deals ) ) return [];

        $group_refs = [];
        foreach ( $raw_deals as $raw ) {
            $raw->_group_ref = ! empty( $raw->deal_group_ref ) ? $raw->deal_group_ref : $this->get_root_offer_number( $raw->offer_number );
            $group_refs[]    = $raw->_group_ref;
        }
        $stages_map = $this->_load_stages_batch( array_unique( array_filter( $group_refs ) ) );

        $grouped = [];
        foreach ( $raw_deals as $raw ) {
            $stage     = $stages_map[ $raw->_group_ref ] ?? null;
            $stage_key = ( $stage && ! empty( $stage->stage_key ) ) ? $stage->stage_key : 'submission_received';
            $raw->_stage = $stage;

            if ( ! isset( $grouped[ $stage_key ] ) ) {
                $grouped[ $stage_key ] = [ 'rows' => [], 'total' => 0.0 ];
            }
            $grouped[ $stage_key ]['rows'][] = $raw;
            $grouped[ $stage_key ]['total'] += (float) $raw->total_excl_vat;
        }
        return $grouped;
    }

    /**
     * Enrichit uniquement les lignes affichées (batch : 1 requête par type de donnée).
     */
    private function _enrich_kanban_rows( array $rows ) {
        if ( empty( $rows ) ) return [];

        $activities_map = $this->_load_last_activities_batch( array_unique( array_column( $rows, '_group_ref' ) ) );
        $contacts_data  = $this->_load_contact_names_batch( $rows );
        $companies_map  = $this->_load_companies_batch( $rows );

        $models = [];
        foreach ( $rows as $raw ) {
            $m = new ISPAG_Crm_Deal_Model( $raw, false );
            if ( $raw->_stage ) {
                $m->stage_key   = $raw->_stage->stage_key   ?? '';
                $m->stage_label = $raw->_stage->stage_label ?? '';
                $m->stage_color = $raw->_stage->stage_color ?? '';
            }
            $m->last_activity_date       = $activities_map[ $raw->_group_ref ] ?? null;
            $m->associated_contact_names = $contacts_data['names'][ $raw->id ] ?? '';
            $m->associated_contacts      = $contacts_data['contacts'][ $raw->id ] ?? [];

            $visual = $companies_map[ $raw->associated_company_row_id ?? null ] ?? [ 'favicon' => null, 'initials' => null ];
            $m->associated_company_favicon  = $visual['favicon'];
            $m->associated_company_initials = $visual['initials'];
            $models[] = $m;
        }
        return $models;
    }

    /**
     * AJAX : charge la suite des cartes d'une colonne (bouton « Voir plus »).
     */
    public function ajax_kanban_load_more() {
        check_ajax_referer( 'ispag_crm_nonce', 'nonce' );

        $stage_key = sanitize_text_field( $_POST['stage_key'] ?? '' );
        $offset    = absint( $_POST['offset'] ?? 0 );
        $per_page  = 20;
        $filters   = [];
        foreach ( [ 'owner', 'closing_date', 'create_date', 'search' ] as $k ) {
            if ( isset( $_POST[ $k ] ) && $_POST[ $k ] !== '' ) $filters[ $k ] = sanitize_text_field( $_POST[ $k ] );
        }
        foreach ( [ 'company_id', 'contact_id' ] as $k ) {
            if ( ! empty( $_POST[ $k ] ) ) $filters[ $k ] = absint( $_POST[ $k ] );
        }
        if ( isset( $filters['owner'] ) && $filters['owner'] !== 'all' ) $filters['owner'] = absint( $filters['owner'] );

        $data   = $this->get_kanban_data( $filters, $per_page, $stage_key, $offset );
        $bucket = $data[ $stage_key ] ?? [ 'count' => 0, 'deals' => [] ];

        $stage_color = '';
        $html = '';
        foreach ( $bucket['deals'] as $deal ) {
            $stage_color = $deal->stage_color ?: '#ccc';
            $html .= ispag_get_template( 'kanban-card', [ 'deal' => $deal, 'stage_color' => esc_attr( $stage_color ) ] );
        }

        wp_send_json_success( [
            'html'      => $html,
            'loaded'    => $offset + count( $bucket['deals'] ),
            'remaining' => max( 0, $bucket['count'] - $offset - count( $bucket['deals'] ) ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOADERS BATCH (1 requête chacun, peu importe le nombre de deals)
    // ─────────────────────────────────────────────────────────────────────────

    private function _load_stages_batch(array $group_refs): array {
        if (empty($group_refs)) return [];

        $placeholders = implode(',', array_fill(0, count($group_refs), '%s'));
        $stages_table = ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES;
        $link_table   = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT dst.deal_group_ref, st.stage_key, st.stage_label, st.stage_color
            FROM {$link_table} dst
            JOIN {$stages_table} st
                ON dst.current_stage_key = st.stage_key COLLATE utf8mb4_unicode_ci
            WHERE dst.deal_group_ref IN ($placeholders)",
            $group_refs
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[$row->deal_group_ref] = $row;
        }
        return $map;
    }

    private function _load_last_activities_batch(array $group_refs): array {
        if (empty($group_refs)) return [];

        $placeholders = implode(',', array_fill(0, count($group_refs), '%s'));
        $note_table   = ISPAG_Note_Manager::TABLE_NOTE;

        // On récupère la dernière note par deal_group_ref en une seule passe
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT deal_id, MAX(created_at) AS last_activity
            FROM {$note_table}
            WHERE deal_id IN ($placeholders)
            GROUP BY deal_id",
            $group_refs
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[$row->deal_id] = $row->last_activity;
        }
        return $map;
    }

    private function _load_contact_names_batch(array $raw_deals): array {
        if (empty($raw_deals)) return ['names' => [], 'contacts' => []];

        $all_user_ids = [];
        $deal_contact_map = []; // deal->id => [user_id, user_id, ...]

        foreach ($raw_deals as $deal) {
            if (empty($deal->associated_contact_ids)) continue;
            $ids = array_filter(array_map('absint', explode(',', $deal->associated_contact_ids)));
            $deal_contact_map[$deal->id] = $ids;
            $all_user_ids = array_merge($all_user_ids, $ids);
        }

        if (empty($all_user_ids)) return ['names' => [], 'contacts' => []];

        $unique_ids   = array_unique($all_user_ids);
        $placeholders = implode(',', array_fill(0, count($unique_ids), '%d'));

        // 1. Noms des users
        $users = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ID, display_name FROM {$this->wpdb->users} WHERE ID IN ($placeholders)",
            $unique_ids
        ));

        // 2. Avatars en une seule requête usermeta
        //    ⚠️ adapte ISPAG_User_Constants::USER_AVATAR si la constante vit ailleurs
        $avatar_meta_key = ISPAG_Crm_Contact_Constants::USER_AVATAR; // 'ispag_avatar_id'

        $avatars_rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT user_id, meta_value FROM {$this->wpdb->usermeta}
            WHERE meta_key = %s AND user_id IN ($placeholders)",
            array_merge([$avatar_meta_key], $unique_ids)
        ));

        $avatar_attachment_map = [];
        foreach ($avatars_rows as $row) {
            $avatar_attachment_map[(int) $row->user_id] = (int) $row->meta_value;
        }

        // 3. Résolution des attachment IDs -> URLs (une seule fois par ID unique)
        $unique_attachment_ids = array_unique(array_filter($avatar_attachment_map));
        $attachment_url_map = [];
        foreach ($unique_attachment_ids as $att_id) {
            $url = wp_get_attachment_image_url($att_id, 'thumbnail');
            if ($url) {
                $attachment_url_map[$att_id] = $url;
            }
        }

        // 4. Index users par ID avec nom + avatar (fallback gravatar/default si pas d'avatar custom)
        $users_index = [];
        foreach ($users as $u) {
            $uid           = (int) $u->ID;
            $attachment_id = $avatar_attachment_map[$uid] ?? 0;
            $avatar_url = ($attachment_id && isset($attachment_url_map[$attachment_id]))
                ? $attachment_url_map[$attachment_id]
                :  get_stylesheet_directory_uri() . '/assets/img/contact_avatar.jpg' ;

            $users_index[$uid] = [
                'id'         => $uid,
                'name'       => $u->display_name,
                'avatar_url' => $avatar_url,
            ];
        }

        // 5. Reconstruction par deal
        $names_map    = [];
        $contacts_map = [];
        foreach ($deal_contact_map as $deal_id => $contact_ids) {
            $contacts = array_values(array_filter(array_map(
                fn($id) => $users_index[$id] ?? null,
                $contact_ids
            )));
            $contacts_map[$deal_id] = $contacts; // [{id, name, avatar_url}, ...]
            $names_map[$deal_id]    = implode(', ', array_column($contacts, 'name'));
        }

        return ['names' => $names_map, 'contacts' => $contacts_map];
    }

    // private function _load_companies_batch(array $raw_deals): array {
    //     if (empty($raw_deals)) return [];

    //     // Dédoublonnage par Id : on ne veut résoudre le favicon qu'une seule fois par société
    //     $companies_by_id = [];
    //     foreach ($raw_deals as $deal) {
    //         $row_id = $deal->associated_company_row_id ?? null;
    //         if (empty($row_id) || isset($companies_by_id[$row_id])) continue;

    //         $companies_by_id[$row_id] = (object) [
    //             'Id'              => $row_id,
    //             'company_name'    => $deal->associated_company_name ?? '',
    //             'favicon'         => $deal->associated_company_favicon ?? '',
    //             'compagny_domain' => $deal->associated_company_domain ?? '',
    //         ];
    //     }

    //     if (empty($companies_by_id)) return [];

    //     $company_repo = class_exists('ISPAG_Crm_Company_Repository') ? new ISPAG_Crm_Company_Repository() : null;
    //     $map = [];

    //     foreach ($companies_by_id as $row_id => $company) {
    //         $favicon = $company->favicon;

    //         // Même logique que _enrich_company_data : on tente de résoudre le favicon
    //         // si absent mais qu'un domaine est connu, sinon on prépare les initiales.
    //         if (empty($favicon) && !empty($company->compagny_domain) && $company_repo) {
    //             $favicon = $company_repo->update_company_favicon($row_id, $company->compagny_domain);
    //         }

    //         $initials = null;
    //         if (empty($favicon)) {
    //             $name = trim($company->company_name);
    //             $space_pos = strpos($name, ' ');
    //             $initials = strtoupper(
    //                 substr($name, 0, 1) . ($space_pos !== false ? substr($name, $space_pos + 1, 1) : '')
    //             );
    //         }

    //         $map[$row_id] = [
    //             'favicon'  => $favicon ?: null,
    //             'initials' => $initials,
    //         ];
    //     }

    //     return $map;
    // }
    private function _load_companies_batch(array $raw_deals): array {
        if (empty($raw_deals)) return [];

        $companies_by_id = [];
        foreach ($raw_deals as $deal) {
            $row_id = $deal->associated_company_row_id ?? null;
            if (empty($row_id) || isset($companies_by_id[$row_id])) continue;

            $companies_by_id[$row_id] = (object) [
                'company_name' => $deal->associated_company_name ?? '',
                'favicon'      => $deal->associated_company_favicon ?? '',
            ];
        }

        if (empty($companies_by_id)) return [];

        $map = [];
        foreach ($companies_by_id as $row_id => $company) {
            $favicon = $company->favicon;
            $initials = null;

            // Suppression de la tentative de téléchargement HTTP synchrone dans la requête
            if (empty($favicon)) {
                $name = trim($company->company_name);
                $space_pos = strpos($name, ' ');
                $initials = strtoupper(
                    substr($name, 0, 1) . ($space_pos !== false ? substr($name, $space_pos + 1, 1) : '')
                );
            }

            $map[$row_id] = [
                'favicon'  => $favicon ?: null,
                'initials' => $initials,
            ];
        }

        return $map;
    }
    public function get_projects_by_company( $company_id, $limit = 0, $include_lost = false ) {
        if ( ! is_numeric( $company_id ) || $company_id <= 0 ) return [];
        $sql = $this->_build_base_select_query() . " WHERE T.id IN (
            SELECT sub_id FROM (
                SELECT id as sub_id, deal_group_ref, ROW_NUMBER() OVER (PARTITION BY deal_group_ref ORDER BY CASE WHEN process_type = 'Commande' THEN 1 ELSE 2 END ASC, CASE WHEN project_db_status = 2 THEN 2 ELSE 1 END ASC, date_creation DESC) as rank_priority
                FROM {$this->table_name} WHERE associated_company_id = %d
            ) as p WHERE rank_priority = 1
        )";
        $params = [ $company_id ];
        if ( ! $include_lost ) $sql .= " AND T.project_db_status != 2"; 
        $sql .= " ORDER BY T.date_creation DESC"; 
        if ( $limit > 0 ) { $sql .= " LIMIT %d"; $params[] = $limit; }
        return $this->_enrich_project_data( $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) ) );
    }

    public function get_projects_by_contact( $contact_id, $limit = 0, $include_lost = false ) {
        if ( ! is_numeric( $contact_id ) || $contact_id <= 0 ) return [];

        $sql_base = $this->_build_base_select_query();
        
        $sql = " {$sql_base} WHERE T.id IN (
            SELECT sub_id FROM (
                SELECT 
                    id as sub_id, 
                    deal_group_ref, 
                    ROW_NUMBER() OVER (
                        PARTITION BY deal_group_ref 
                        ORDER BY CASE WHEN process_type = 'Commande' THEN 1 ELSE 2 END ASC, 
                        date_creation DESC, 
                        id DESC
                    ) as rank_priority
                FROM {$this->table_name} 
                WHERE deal_group_ref IN (
                    SELECT DISTINCT deal_group_ref 
                    FROM {$this->table_name} 
                    WHERE FIND_IN_SET(%d, associated_contact_ids) > 0
                )
            ) as p WHERE rank_priority = 1
        )";

        $params = [ $contact_id ];
        if ( ! $include_lost ) $sql .= " AND T.project_db_status != 2"; 
        $sql .= " ORDER BY T.date_creation DESC"; 
        if ( $limit > 0 ) { 
            $sql .= " LIMIT %d"; 
            $params[] = $limit; 
        }

        // --- LOG DE LA REQUETE ---
        $final_sql = $this->wpdb->prepare( $sql, $params );
        // error_log( "[ISPAG DEBUG] SQL Query: " . $final_sql );

        $results = $this->wpdb->get_results( $final_sql );

        // --- LOG DES RESULTATS BRUTS (pour vérifier deal_group_ref) ---
        if ( ! empty( $results ) ) {
            // error_log( "[ISPAG DEBUG] Premier résultat brut : " . print_r( $results[0], true ) );
        }

        $enriched = $this->_enrich_project_data( $results );

        // --- LOG DES DONNÉES ENRICHIES (pour vérifier le stage) ---
        if ( ! empty( $enriched ) ) {
            // error_log( "[ISPAG DEBUG] Premier résultat enrichi : " . print_r( $enriched[0], true ) );
        }

        return $enriched;
    }

    /**
     * Fait le pont entre le CRM et le plugin de fabrication (Réservoirs)
     * Récupère l'ID Hubspot à partir du nom du projet.
     * * @param string $project_name Le nom exact du projet côté CRM
     * @return int|null L'ID Hubspot ou null si non trouvé
     */
    public function get_hubspot_id_by_project_name( $project_name ) {
        if ( empty( $project_name ) ) return null;

        global $wpdb;
        $table_achats = "wor9711_achats_liste_commande";

        // On utilise TRIM pour éviter les erreurs dues à des espaces invisibles
        $query = $wpdb->prepare(
            "SELECT hubspot_deal_id 
            FROM {$table_achats} 
            WHERE TRIM(ObjetCommande) = TRIM(%s) 
            LIMIT 1",
            $project_name
        );

        $hubspot_id = $wpdb->get_var( $query );

        return $hubspot_id ? (int) $hubspot_id : null;
    }

    /**
     * Fait le pont entre le CRM et le plugin de fabrication (Réservoirs)
     * Récupère l'ID Hubspot à partir du numéro du projet.
     * * @param string $project_num Le numéro exact du projet côté CRM
     * @return int|null L'ID Hubspot ou null si non trouvé
     */
    public function get_hubspot_id_by_project_num( $project_num ) {
        if ( empty( $project_num ) ) return null;

        global $wpdb;
        $table_achats = "wor9711_achats_liste_commande";

        // On utilise TRIM pour éviter les erreurs dues à des espaces invisibles
        $query = $wpdb->prepare(
            "SELECT hubspot_deal_id 
            FROM {$table_achats} 
            WHERE TRIM(NumCommande) = TRIM(%s) 
            LIMIT 1",
            $project_num
        );

        $hubspot_id = $wpdb->get_var( $query );

        return $hubspot_id ? (int) $hubspot_id : null;
    }

    public function find_projects( $where_clause = '', $limit = 0, $include_lost = false ) {
        $where_conditions = [];
        if ( ! empty( $where_clause ) ) $where_conditions[] = $where_clause;
        if ( ! $include_lost ) $where_conditions[] = "T.project_db_status != 2";
        $sql = $this->_build_base_select_query();
        if ( ! empty( $where_conditions ) ) $sql .= " WHERE " . implode( ' AND ', $where_conditions );
        $sql .= " ORDER BY T.date_creation DESC"; 
        if ( $limit > 0 ) $sql .= " LIMIT " . absint( $limit );
        return $this->_enrich_project_data( $this->wpdb->get_results( $sql ) );
    }

    // ==========================================================
    // MÉTHODES DE MISE À JOUR (UPDATE) & AJAX
    // ==========================================================

    /**
     * Met à jour le statut d'un deal et déclenche les workflows associés.
     *
     * @param string $group_ref Référence du groupe de deal.
     * @param string $new_stage_key Nouvelle clé de statut (ex: 'submission_received', 'proposal_sent').
     * @param string|null $reason Raison de la perte (si le deal est marqué comme perdu).
     * @return bool True si la mise à jour a réussi, false sinon.
     */
    public function update_deal_stage($group_ref, $new_stage_key, $reason = null)
    {
        ISPAG_Workflow_Logger::info(
            "Début de la mise à jour du statut du deal. group_ref: {$group_ref}, new_stage_key: {$new_stage_key}",
            ['group_ref' => $group_ref, 'new_stage_key' => $new_stage_key, 'reason' => $reason]
        );

        if (empty($group_ref)) {
            ISPAG_Workflow_Logger::error("Tentative de mise à jour du statut avec une référence de groupe vide");
            return false;
        }

        global $wpdb;

        // 1. Récupérer l'ancien statut avant la mise à jour
        ISPAG_Workflow_Logger::debug("Récupération de l'ancien statut pour le deal_group_ref: {$group_ref}");
        $old_stage_key = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT current_stage_key FROM {$this->stages_table_name} WHERE deal_group_ref = %s",
                $group_ref
            )
        );

        // Si aucun ancien statut n'est trouvé, on considère que c'est une création
        if (empty($old_stage_key)) {
            $old_stage_key = null;
            ISPAG_Workflow_Logger::debug("Aucun ancien statut trouvé pour le deal_group_ref: {$group_ref}. Considéré comme une création.");
        } else {
            ISPAG_Workflow_Logger::debug("Ancien statut trouvé: {$old_stage_key}");
        }

        // 2. Mise à jour ou insertion dans la table des étapes (Historique/Statut actuel)
        ISPAG_Workflow_Logger::debug("Mise à jour de la table des étapes pour le deal_group_ref: {$group_ref}");
        $query_stage = $wpdb->prepare(
            "INSERT INTO {$this->stages_table_name} (deal_group_ref, current_stage_key, last_updated, updated_by)
            VALUES (%s, %s, NOW(), %d)
            ON DUPLICATE KEY UPDATE current_stage_key = VALUES(current_stage_key), last_updated = NOW(), updated_by = VALUES(updated_by)",
            $group_ref, $new_stage_key, get_current_user_id()
        );
        $result = $this->wpdb->query($query_stage);
        ISPAG_Workflow_Logger::debug("Requête SQL exécutée pour la table des étapes. Résultat: " . ($result ? 'Succès' : 'Échec'));

        // 3. Si le projet est perdu, on met à jour la table principale des deals
        if ($new_stage_key === 'closed_lost') {
            ISPAG_Workflow_Logger::debug("Mise à jour du deal comme 'closed_lost' avec raison: {$reason}");
            $update_result = $this->wpdb->update(
                ISPAG_Crm_Deal_Constants::TABLE_NAME, // wor9711_ispag_deals_list
                [
                    'reason_for_rejection' => $reason,
                    'current_stage_key'    => 'closed_lost'
                ],
                ['deal_group_ref' => $group_ref],
                ['%s', '%s'],
                ['%s']
            );
            ISPAG_Workflow_Logger::debug("Mise à jour de la table principale des deals pour closed_lost. Résultat: " . ($update_result ? 'Succès' : 'Échec'));
        } else {
            // Mettre à jour le current_stage_key dans la table principale des deals
            ISPAG_Workflow_Logger::debug("Mise à jour du current_stage_key dans la table principale des deals");
            $update_result = $this->wpdb->update(
                ISPAG_Crm_Deal_Constants::TABLE_NAME, // wor9711_ispag_deals_list
                ['current_stage_key' => $new_stage_key],
                ['deal_group_ref' => $group_ref],
                ['%s'],
                ['%s']
            );
            ISPAG_Workflow_Logger::debug("Mise à jour du current_stage_key. Résultat: " . ($update_result ? 'Succès' : 'Échec'));
        }

        // 4. Récupérer l'ID du deal depuis la table principale (pour le hook)
        ISPAG_Workflow_Logger::debug("Récupération de l'ID du deal pour le deal_group_ref: {$group_ref}");
        $deal_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . ISPAG_Crm_Deal_Constants::TABLE_NAME . " WHERE deal_group_ref = %s",
                $group_ref
            )
        );

        if (!empty($deal_id)) {
            ISPAG_Workflow_Logger::debug("ID du deal trouvé: {$deal_id}");
        } else {
            ISPAG_Workflow_Logger::warning("Aucun ID de deal trouvé pour le deal_group_ref: {$group_ref}");
        }

        // 5. Déclencher le hook pour les workflows
        ISPAG_Workflow_Logger::info(
            "Prêt à déclencher le hook ispag_deal_status_changed pour le group_ref: {$group_ref}, old_stage_key: {$old_stage_key}, new_stage_key: {$new_stage_key}",
            ['group_ref' => $group_ref, 'old_stage_key' => $old_stage_key, 'new_stage_key' => $new_stage_key]
        );

        if (!empty($deal_id)) {
            do_action('ispag_deal_status_changed', $group_ref, $old_stage_key, $new_stage_key);
            ISPAG_Workflow_Logger::info(
                "Hook ispag_deal_status_changed déclenché avec succès pour le group_ref: {$group_ref}",
                ['group_ref' => $group_ref, 'old_stage_key' => $old_stage_key, 'new_stage_key' => $new_stage_key]
            );
        } else {
            ISPAG_Workflow_Logger::error(
                "Impossible de déclencher le hook ispag_deal_status_changed: deal_id est vide pour le group_ref: {$group_ref}",
                ['group_ref' => $group_ref]
            );
        }

        ISPAG_Workflow_Logger::info("Fin de la mise à jour du statut du deal. group_ref: {$group_ref}");
        return true;
    }

    /**
     * Gère la mise à jour du stage d'un deal via AJAX.
     */
    public function ispag_ajax_handle_deal_stage_update() {
        ISPAG_Workflow_Logger::info(
            "Début de la gestion de la mise à jour du stage via AJAX",
            ['_POST' => $_POST]
        );

        // Contrôle des autorisations
        if (!current_user_can('manage_order')) {
            ISPAG_Workflow_Logger::warning("Insufficient rights to update the stage");
            wp_send_json_error(['message' => 'Droits insuffisants']);
        }

        // Récupération et nettoyage des données de la requête
        $deal_id = isset($_POST['deal_id']) ? absint($_POST['deal_id']) : 0;
        $new_stage_key = isset($_POST['stage']) ? sanitize_text_field($_POST['stage']) : (isset($_POST['new_stage']) ? sanitize_text_field($_POST['new_stage']) : null);
        $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';

        if (empty($deal_id) || empty($new_stage_key)) {
            ISPAG_Workflow_Logger::warning(
                "Données manquantes pour mettre à jour le stage via AJAX",
                ['has_deal_id' => !empty($deal_id), 'has_new_stage' => !empty($new_stage_key)]
            );
            wp_send_json_error(['message' => 'Missing or invalid data']);
        }

        // Appel de la logique métier
        $result = $this->ispag_handle_deal_stage_update($deal_id, $new_stage_key, $reason);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Traite la logique métier de mise à jour du stage d'un deal.
     * 
     * @param int    $deal_id       ID du deal en BDD.
     * @param string $new_stage_key Clé du nouveau stage.
     * @param string $reason        Raison éventuelle (optionnelle).
     * @return array|\WP_Error      Retourne les données du deal mis à jour ou une instance WP_Error.
     */
    public function ispag_handle_deal_stage_update($deal_id, $new_stage_key, $reason = '') {
        $deal_id = absint($deal_id);
        ISPAG_Workflow_Logger::debug("ID du deal traité dans la logique métier: {$deal_id}");
        ISPAG_Workflow_Logger::debug("Raison de rejet récupérée: {$reason}");

        // Récupération de la référence du groupe de deal
        $group_ref = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT deal_group_ref FROM {$this->table_name} WHERE id = %d", $deal_id)
        );

        if (!$group_ref) {
            ISPAG_Workflow_Logger::error(
                "Deal introuvable pour l'ID: {$deal_id}",
                ['deal_id' => $deal_id]
            );
            return new WP_Error('deal_not_found', 'Deal introuvable');
        }

        ISPAG_Workflow_Logger::debug("Référence du groupe de deal récupérée: {$group_ref}");

        $stage_repo = new ISPAG_Crm_Deal_Stages_Repository();
        $old_stage_obj = $stage_repo->get_stage_by_deal_group_ref($group_ref);
        $old_key = $old_stage_obj->stage_key ?? $stage_repo->get_first_stage_key();

        ISPAG_Workflow_Logger::debug(
            "Ancien stage récupéré: {$old_key}",
            ['old_stage_key' => $old_key]
        );

        // 1. Mise à jour du stage (et de la raison si nécessaire)
        ISPAG_Workflow_Logger::info(
            "Appel de update_deal_stage pour group_ref: {$group_ref}, new_stage_key: {$new_stage_key}",
            ['group_ref' => $group_ref, 'new_stage_key' => $new_stage_key]
        );

        if (!$this->update_deal_stage($group_ref, $new_stage_key, $reason)) {
            ISPAG_Workflow_Logger::error(
                "Stage update failed pour group_ref: {$group_ref}",
                ['group_ref' => $group_ref, 'new_stage_key' => $new_stage_key]
            );
            return new WP_Error('update_failed', 'Stage update failed');
        }

        ISPAG_Workflow_Logger::info(
            "Mise à jour du stage réussie pour group_ref: {$group_ref}",
            ['group_ref' => $group_ref, 'new_stage_key' => $new_stage_key]
        );

        $user = wp_get_current_user();
        ISPAG_Workflow_Logger::debug(
            "Utilisateur actuel récupéré: ID={$user->ID}, Display Name={$user->display_name}"
        );

        // 2. Construction du message de la note
        $content = sprintf(
            '%s moved deal from %s to %s',
            $user->display_name ?: 'System',
            str_replace('_', ' ', $old_key),
            str_replace('_', ' ', $new_stage_key)
        );

        // Si une raison est fournie, on l'ajoute à la note d'activité
        if (!empty($reason)) {
            $content .= sprintf(' | Reason: %s', $reason);
            ISPAG_Workflow_Logger::debug("Raison ajoutée à la note: {$reason}");
        }

        ISPAG_Workflow_Logger::debug(
            "Contenu de la note construit: {$content}",
            ['content' => $content]
        );

        // 3. Insertion du log / note
        $note_data = [
            'deal_id'    => $group_ref,
            'user_id'    => $user->ID ?? 0,
            'type'       => 'STAGE',
            'title'      => 'Deal activity',
            'content'    => $content,
            'created_at' => current_time('mysql')
        ];

        ISPAG_Workflow_Logger::debug(
            "Insertion de la note dans la base de données",
            ['note_data' => $note_data]
        );

        $insert_result = $this->wpdb->insert(ISPAG_Note_Manager::TABLE_NOTE, $note_data);

        if ($insert_result) {
            ISPAG_Workflow_Logger::info(
                "Note d'activité insérée avec succès pour le deal: {$group_ref}",
                ['note_id' => $this->wpdb->insert_id]
            );
        } else {
            ISPAG_Workflow_Logger::error(
                "Échec de l'insertion de la note d'activité pour le deal: {$group_ref}",
                ['note_data' => $note_data]
            );
        }

        return [
            'deal_id'   => $group_ref,
            'new_stage' => $new_stage_key,
            'reason'    => $reason
        ];
    }


    public function ispag_handle_bulk_deal_update() {
        // Récupération des données POST
        $ids          = isset($_POST['deal_ids']) ? (array) $_POST['deal_ids'] : [];
        $new_stage    = sanitize_text_field($_POST['stage']);
        $last_contact = !empty($_POST['last_contact']) ? sanitize_text_field($_POST['last_contact']) : null;
        $reason       = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
        $user_id      = get_current_user_id();

        if (empty($ids)) {
            wp_send_json_error(['message' => 'No project selected']);
        }

        $success_count = 0;
        
        foreach ($ids as $id) {
            $deal_id = absint($id);
            
            // 1. Récupération des infos du deal pour la note
            $deal_data = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT deal_group_ref, associated_contact_ids, associated_company_id, project_name 
                FROM {$this->table_name} WHERE id = %d", 
                $deal_id
            ));

            if ($deal_data) {
                $updated = false;

                // 2. Mise à jour de l'ÉTAPE (si modifiée)
                if (!empty($new_stage)) {
                    $this->update_deal_stage($deal_data->deal_group_ref, $new_stage, $reason);
                    $updated = true;
                }

                // 3. Mise à jour de la DATE DE CONTACT (si modifiée)
                if ($last_contact) {
                    
                    // --- 4. CRÉATION DE LA NOTE D'ACTIVITÉ ---
                    $note_data = new stdClass();
                    $note_data->contact_id    = $deal_data->associated_contact_ids;
                    $note_data->user_id       = $user_id; 
                    $note_data->company_id    = $deal_data->associated_company_id;
                    $note_data->deal_id       = $deal_data->deal_group_ref; 
                    $note_data->activity_type = 'LOG_EMAIL'; // Type d'activité pour le CRM
                    $note_data->is_task       = 0;
                    $note_data->title         = __('Bulk update of contact date', 'ispag-crm');
                    $note_data->content       = sprintf(
                        __('Last contact date manually modified to %s for project %s.', 'ispag-crm'),
                        date_i18n('d.m.Y', strtotime($last_contact)),
                        $deal_data->project_name
                    );
                    $note_repository = new ISPAG_Note_Manager();
                    // Enregistrement via ton repository de notes
                    $note_repository->create_note($note_data);
                    
                    $updated = true;
                }

                if ($updated) {
                    $success_count++;
                }
            }
        }

        wp_send_json_success(['updated' => $success_count]);
    }

    /**
     * Point de terminaison AJAX pour charger le résumé IA de la société.
     */
    public function ajax_load_deal_ai_summary() {
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
        $company = $repository->get_company_by_id($company_id);

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
        if ( $ai_error = ISPAG_Crm_Mistral::ajax_error($ai_response) ) {
            wp_send_json_error(['message' => $ai_error]);
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
}
endif;