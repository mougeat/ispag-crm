<?php
/**
 * Gère les interactions avec la base de données pour les notes et tâches.
 * Responsable de la logique CRUD (Create, Read, Update, Delete).
 */
class ISPAG_Note_Repository {

    private $wpdb;
    private $table_notes;

    const TABLE_NOTES       = 'ispag_contact_notes'; 
    const CONTACTS_TABLE    = 'ispag_crm_contacts';
    const COMPANIES_TABLE   = ISPAG_Crm_Company_Constants::TABLE_NAME;
    const DEALS_TABLE       = ISPAG_Crm_Deal_Constants::TABLE_NAME;

    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    const META_LAST_CONTACT_SOURCE  = 'ispag_last_contact_source';

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        // Définir le nom de votre table de notes/tâches
        $this->table_notes = $wpdb->prefix . 'ispag_contact_notes'; 

        add_action( 'wp_ajax_ispag_load_task_details', array( $this, 'load_task_details_callback' ) );


    }

    /**
     * Récupère une activité (note ou tâche) par son ID.
     *
     * @param int $activity_id L'ID de l'activité.
     * @return object|null
     */
    public function get_activity_by_id( $activity_id ) {
        if ( ! is_numeric( $activity_id ) ) {
            return null;
        }

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_notes} WHERE id = %d",
            $activity_id
        );

        return $this->wpdb->get_row( $sql );
    }

    /**
     * Récupère toutes les activités associées à une entité (contact, deal, company).
     *
     * @param string $entity_type 'contact', 'deal', ou 'company'.
     * @param int    $entity_id   ID de l'entité.
     * @return array Tableau des objets d'activité (notes/tâches) ou un tableau vide.
     */
    public function get_activities_for_entity( $entity_type, $entity_id ) {

//         error_log('[DEBUG] in get_activities_for_entity ');


        $column_map = [
            'contact' => 'contact_id',
            'deal'    => 'deal_id',
            'company' => 'company_id',
        ];

        
        if ( ! isset( $column_map[ $entity_type ] ) ) {
//             error_log('[DEBUG] $column_map does not exist ');
            return [];
        }
        
        $safe_entity_id = ( $entity_id );

        
        $column = $column_map[ $entity_type ];
 
        // Si c'est un deal, on gère le cas hybride (Numérique vs OF-Ref)
        if ( $entity_type === 'deal' ) {
            // --- LOGIQUE HYBRIDE ---
            // On s'assure que $entity_id peut être un tableau ou une valeur unique
            $ids_to_search = is_array($entity_id) ? $entity_id : [$entity_id];
            
            // On construit dynamiquement les clauses FIND_IN_SET pour chaque ID
            $clauses = [];
            foreach($ids_to_search as $id) {
                $clauses[] = $this->wpdb->prepare("FIND_IN_SET(%s, REPLACE(deal_id, ' ', '')) > 0", $id);
            }
            $where_clause = implode(' OR ', $clauses);

            $sql = "SELECT * FROM {$this->table_notes} 
                    WHERE ({$where_clause})
                    ORDER BY created_at DESC";
        } else {
            // Logique standard pour contact et company
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$this->table_notes} 
                WHERE FIND_IN_SET(%s, REPLACE({$column}, ' ', '')) > 0
                ORDER BY created_at DESC",
                $entity_id
            );
        }

        $activities = $this->wpdb->get_results( $sql, OBJECT );

        // Si on regarde un Deal, on ajoute les événements système
        if ( $entity_type === 'deal' ) {
            $system_events = $this->get_deal_system_events( $entity_id );
            $activities = array_merge( $activities, $system_events );
            
            // On trie à nouveau par date après la fusion
            usort( $activities, function($a, $b) {
                return strtotime($b->created_at) - strtotime($a->created_at);
            });
        }
    
    
        // 2. Vérification et enrichissement des données (Bloc mis à jour)
        if ( ! empty( $activities ) ) {
            // Boucler sur le tableau d'OBJETS
            foreach ( $activities as $activity ) {
                
                // Enrichissement du contact
                // NOTE : La syntaxe pour assigner à une propriété d'objet est $object->property
                $activity->contact_name_html = !empty($activity->contact_id) ? 
                    $this->get_attendees_names( $activity->contact_id , false) : 
                    '';

                // Enrichissement de l'entreprise
                $activity->company_name_html = !empty($activity->company_id) ? 
                    $this->get_company_names_by_ids( $activity->company_id ) : 
                    '';

                // Enrichissement de l'affaire
                // NOTE : L'enrichissement d'une affaire peut retourner un tableau ou un HTML complexe.
                $activity->deal_name_html = !empty($activity->deal_id) ? 
                    $this->get_deal_names_array_by_ids( $activity->deal_id ) : 
                    '';

                // Gérer les attendees de MEETING
                if ($activity->type === 'MEETING' && !empty($activity->attendees)) {
                    $activity->meeting_attendees_html = $this->get_attendees_names( $activity->attendees , false);
                }
            }
        }
        
//         error_log(print_r($activities, true)); // Maintenant, ceci affichera Array( [0] => stdClass Object ( ... ) )
        return $activities;
    }

    /**
     * Récupère les événements historiques d'un Deal pour la timeline.
     */
    private function get_deal_system_events( $deal_group_ref ) {
        global $wpdb;
        $table_deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $events = [];

        // 1. Récupération de toutes les variantes du deal
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_deals} WHERE deal_group_ref = %s ORDER BY date_creation ASC",
            $deal_group_ref
        ));

        if ( empty( $results ) ) return [];

        foreach ( $results as $index => $deal ) {
            $type = strtoupper( $deal->process_type );
            $activity = new stdClass();
            $activity->id = 'deal-' . $deal->id; // ID virtuel
            $activity->created_at = $deal->date_creation . ' 08:00:00'; // Heure fictive
            $activity->user_id = $deal->created_by;
            
            // Logique de Titre et Contenu selon vos règles
            if ( $index === 0 ) {
                $activity->type = 'SYSTEM';
                $activity->title = 'Created';
                $activity->content = 'This deal was created';
                $activity->icon = 'dashicons-plus-alt';
            } elseif ( strpos( $type, 'SITUATION' ) !== false ) {
                $activity->type = 'SYSTEM'; // On réutilise une classe existante pour le style
                $activity->title = 'Situation';
                $activity->content = 'A situation was sent (' . esc_html($deal->offer_num) . ')';
                $activity->icon = 'dashicons-media-spreadsheet';
            } elseif ( $type === 'FACTURE' || $type === 'FACTURE INTERNE' ) {
                $activity->type = 'SYSTEM';
                $activity->title = 'Invoice';
                $activity->content = 'This deal was invoiced';
                $activity->icon = 'dashicons-cart';
            } else {
                $activity->type = 'SYSTEM';
                $activity->title = 'New update';
                $activity->content = 'This deal was updated: ' . esc_html($deal->offer_num);
                $activity->icon = 'dashicons-update';
            }

            $events[] = $activity;
        }

        return $events;
    }

    /**
     * Récupère la liste des tâches actives pour l'utilisateur, formatées pour le tableau de bord.
     * @return array Tableau d'objets standard, chacun représentant une tâche formatée.
     */
    public function get_active_tasks() {
        global $wpdb;
        $tasks_table = $wpdb->prefix . self::TABLE_NOTES; 
        $companies_table = self::COMPANIES_TABLE; 
        $deal_table = self::DEALS_TABLE; 
        $user_id = get_current_user_id();
        
        // 1. Requête SQL principale (Identique)
        $sql = $wpdb->prepare("SELECT t.*, u.display_name FROM {$tasks_table} AS t 
            LEFT JOIN {$wpdb->users} AS u ON t.contact_id = u.ID
            WHERE t.is_task = 1 AND t.user_id = %d AND t.is_completed = 0
            ORDER BY t.due_date ASC", $user_id);

        $raw_tasks = $wpdb->get_results( $sql );
        if ( empty( $raw_tasks ) ) return [];
        

        // --- OPTIMISATION : COLLECTE DES IDS ---
        $all_company_ids = [];
        $all_deal_group_refs = []; // ✅ Utilisez deal_group_ref au lieu de deal_id
        
        foreach ($raw_tasks as $task) {
            if (!empty($task->company_id)) {
                $company_ids = explode(',', $task->company_id);
                $deal_group_refs = explode(',', $task->deal_id); // ✅ Récupérez deal_group_ref
                $all_company_ids = array_merge($all_company_ids, $company_ids);
                $all_deal_group_refs = array_merge($all_deal_group_refs, $deal_group_refs);
            }
        }
        $all_company_ids = array_unique(array_filter(array_map('absint', $all_company_ids)));
        // ✅ Pas besoin de absint pour deal_group_ref (c'est une chaîne)
        $all_deal_group_refs = array_unique(array_filter($all_deal_group_refs));

        // --- OPTIMISATION : UNE SEULE REQUÊTE POUR TOUTES LES SOCIÉTÉS ---
        $company_cache = [];
        if (!empty($all_company_ids)) {
            $placeholders = implode(',', array_fill(0, count($all_company_ids), '%d'));
            $companies_results = $wpdb->get_results($wpdb->prepare(
                "SELECT viag_id, company_name FROM {$companies_table} WHERE viag_id IN ($placeholders)",
                $all_company_ids
            ));
            foreach ($companies_results as $co) {
                $company_cache[$co->viag_id] = $co->company_name;
            }
        }
        // --- OPTIMISATION : CACHE PERSISTANT POUR LES DEALS ---
        $deal_cache = [];
        if (!empty($all_deal_group_refs)) {
            $cache_key = 'ispag_deals_cache_' . md5(serialize($all_deal_group_refs));
            $deal_cache = get_transient($cache_key);

            if ($deal_cache === false) {
                $placeholders = implode(',', array_fill(0, count($all_deal_group_refs), '%s'));
                $deal_results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT deal_group_ref, project_name, id
                        FROM {$deal_table}
                        WHERE deal_group_ref IN ({$placeholders})
                        ORDER BY id DESC",
                        $all_deal_group_refs
                    ) 
                );

                // ✅ Stockez un tableau associatif avec id et project_name
                foreach ($deal_results as $deal) {
                    $deal_cache[$deal->deal_group_ref] = [
                        'id' => $deal->id,
                        'project_name' => $deal->project_name
                    ];
                }

                // ✅ Stocker en cache pour 5 minutes (ou plus selon vos besoins)
                set_transient($cache_key, $deal_cache, 5 * MINUTE_IN_SECONDS);
            }
        }

        $formatted_tasks = [];
        
        // 3. Post-traitement (Utilise maintenant le cache local)
        foreach ( $raw_tasks as $task ) {
            // Au lieu d'appeler get_company_names_by_ids (qui fait un SQL), 
            // on crée une version légère qui utilise $company_cache
            error_log(print_r($task, true));
            $is_checked = ( $task->is_completed == 1 ) ? 'checked' : '';
            $formatted_tasks[] = (object) [
                'id'           => absint( $task->id ),
                'title'        => esc_html( wp_strip_all_tags( $task->title ) ),
                'contact_name' => $this->get_attendees_names( $task->contact_id ), 
                'contact_id'   => $task->contact_id, 
                'company_name' => $this->get_company_display_fast( $task->company_id, $company_cache ),
                'company_id'   => $task->company_id,
                'deal_name'    => $this->get_deal_display_fast( $task->deal_id, $deal_cache ),
                'deal_id'      => $this->get_deal_id_fast($task->deal_id, $deal_cache),
                'due_date'     => $task->due_date, 
                'task_type'    => esc_html( $task->type ), 
                'is_completed' => $task->is_completed,
                'status_html'  => '<div class="task-checkbox">
                                        <input type="checkbox" id="check-' . $task->id . '" class="complete-task-btn task-input" data-activity-id="' . $task->id . '" ' . $is_checked . '>
                                        <label for="check-' . $task->id . '" class="task-circle">
                                            <span class="checkmark">✓</span>
                                        </label>
                                    </div>',

            ];
        }
        
        return $formatted_tasks;
    }

    /**
     * Version ultra rapide qui ne fait PAS de SQL car elle utilise le cache collecté au début
     */
    private function get_company_display_fast($ids_list, $cache) {
        $ids = array_filter(explode(',', $ids_list));
        $names = [];
        foreach ($ids as $id) {
            if (isset($cache[$id])) $names[] = $cache[$id];
        }
        
        if (empty($names)) return __('Not defined', 'ispag-crm');
        
        // Ici tu peux remettre ton HTML de popover si tu veux, 
        // l'important c'est qu'il n'y a plus de "SELECT" ici.
        return implode(', ', $names); 
    }

    /**
     * Récupère le nom d'un deal depuis le cache.
     * @param string $deal_group_ref Référence du groupe de deal (ex: 'OF99-99999').
     * @param array $deal_cache Cache des deals (format: [deal_group_ref => ['id' => ..., 'project_name' => ...]]).
     * @return string Nom du deal ou "Not defined".
     */
    private function get_deal_display_fast($deal_group_ref, $deal_cache) {
        if (empty($deal_group_ref)) {
            return __('Not defined', 'ispag-crm');
        }

        // ✅ Vérifiez si le deal est dans le cache et récupérez le project_name
        if (isset($deal_cache[$deal_group_ref])) {
            $deal_data = $deal_cache[$deal_group_ref];
            ISPAG_Workflow_Logger::debug(
                "Deal trouvé dans le cache: {$deal_group_ref} => ID={$deal_data['id']}, Name={$deal_data['project_name']}"
            );
            return $deal_data['project_name'];
        }

        ISPAG_Workflow_Logger::warning("Deal non trouvé dans le cache: {$deal_group_ref}");
        return __('Not defined', 'ispag-crm');
    }

    /**
     * Récupère l'ID d'un deal depuis le cache.
     * @param string $deal_group_ref Référence du groupe de deal (ex: 'OF99-99999').
     * @param array $deal_cache Cache des deals (format: [deal_group_ref => ['id' => ..., 'project_name' => ...]]).
     * @return int|string ID du deal ou null.
     */
    private function get_deal_id_fast($deal_group_ref, $deal_cache) {
        if (empty($deal_group_ref)) {
            return null;
        }

        if (isset($deal_cache[$deal_group_ref])) {
            $deal_data = $deal_cache[$deal_group_ref];
            ISPAG_Workflow_Logger::debug(
                "ID du deal trouvé dans le cache: {$deal_group_ref} => ID={$deal_data['id']}"
            );
            return $deal_data['id'];
        }

        ISPAG_Workflow_Logger::warning("ID du deal non trouvé dans le cache: {$deal_group_ref}");
        return null;
    }

    /**
     * Gère la requête AJAX pour charger les détails d'une tâche.
     */
    public function load_task_details_callback() {
        if ( ! current_user_can( 'manage_order' ) ) {
            wp_send_json_error( array( 'message' => 'Permissions insuffisantes.' ) );
        }

        $task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;

        if ( $task_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'ID de tâche invalide.' ) );
        }

        // --- UTILISATION DU REPOSITORY ---
        
        // 1. Récupération de l'objet d'activité
        $activity_object = $this->get_activity_by_id( $task_id );

        if ( $activity_object ) {
            
            // 2. Conversion de l'objet BDD en tableau JSON pour l'envoi
            // (Assurez-vous que les clés du tableau correspondent à celles attendues par le JS)
            $task_data = $this->format_activity_for_frontend( $activity_object );
            
            wp_send_json_success( $task_data );
            
        } else {
            wp_send_json_error( array( 'message' => 'Tâche non trouvée.' ) );
        }
    }
    /**
     * Formate l'objet de l'activité (résultat BDD) dans la structure attendue par le JS.
     * Ceci est une étape intermédiaire essentielle.
     */
    private function format_activity_for_frontend( $activity_object ) {
        // Convertir l'objet PHP en tableau pour une manipulation plus simple
        $activity = (array) $activity_object;
        
        // Si l'activité est une Note/Tâche et non une Réunion, les champs 'meeting_date' seront vides.
        // Cette étape permet d'ajouter des champs dérivés ou des liens vers d'autres entités.

        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        $task_due_timestamp = strtotime(  $activity['due_date'] );
        $display_due_date = date_i18n( 'j F Y', $task_due_timestamp );

        //Assigned To
        $user_info = get_userdata( $activity['user_id'] );
        $assigned_to = $user_info ? $user_info->display_name : __('Unknown User', 'ispag-crm');
         
        $is_checked = ( $activity['is_completed'] == 1 ) ? 'checked' : '';

        $attachments = [];

        // Traitement des pièces jointes
        if ( ! empty( $activity['media_ids'] ) ) {
            $media_ids = explode( ',', $activity['media_ids'] );
            foreach ( $media_ids as $media_id ) {
                $media_id = trim( $media_id );
                $url = wp_get_attachment_url( $media_id );
                
                if ( $url ) {
                    $attachments[] = [
                        'id'   => $media_id,
                        'url'  => $url,
                        'name' => get_the_title( $media_id ) ?: basename( $url )
                    ];
                }
            }
        }

        return [
            'id'                => $activity['id'],
            'assigned_to'       => $assigned_to,
            'title'             => $activity['type'] . ' by ' . $activity['user_id '],
            'content'           => $activity['content'], // Utilisez la bonne colonne BDD
            'note_title'        => $activity['title'], 
            'due_date'          => $display_due_date,
            'due_date_raw'      => $activity['due_date'],
            'created_date_raw'  => $activity['created_at'],
            'status'            => $activity['is_completed'],
            'status_text'       => $activity['is_completed'] == 1 ? __('Done', 'ispag-crm') : __('Open', 'ispag-crm'),
            'status_html'       => '<div class="task-checkbox">
                                        <input type="checkbox" id="check-' . $activity['id'] . '" class="complete-task-btn task-input" data-activity-id="' . $activity['id'] . '" ' . $is_checked . '>
                                        <label for="check-' . $activity['id'] . '" class="task-circle">
                                            <span class="checkmark">✓</span>
                                        </label>
                                    </div>',
            'type'              => $activity['type'],
            'is_completed'      => $activity['is_completed'],
            'is_task'           => $activity['is_task'],
            // Renseigner les champs de liaison (ceux-ci nécessiteront peut-être d'autres appels au repository)
            'contact_name'      => $this->get_attendees_names( $activity['contact_id'], false ),
            'contact_name_raw'  => $this->get_attendees_names( $activity['contact_id'], true ),
            'company_name'      => $this->get_company_names_by_ids( $activity['company_id'] ),
            'deal_name'         => $this->get_deal_names_array_by_ids( $activity['deal_id'] ),
            'contact_ids'       => $activity['contact_id'],
            'company_ids'       => $activity['company_id'],
            'deal_ids'          => $activity['deal_id'],
            
            // Renseigner les détails de réunion (si ce sont des colonnes séparées)
            'outcome'           => __($activity['outcome'], 'ispag-crm') ?? '',
            'attendees'         => $this->get_attendees_names( $activity['contact_id'] , false),
            
            'meeting_date'      => ( $activity['created_at'] ?? '' ) 
                                    ? mysql2date( $date_format, $activity['created_at'], true ) 
                                    : '',
            'meeting_date_raw'  => date('Y-m-d', strtotime($activity['created_at'])),
            'meeting_time'      => ( $activity['created_at'] ?? '' ) 
                                    ? mysql2date( $time_format, $activity['created_at'], true )
                                    : '',
            'meeting_time_raw'  => date('H:i', strtotime($activity['created_at'])),
            'attachments'       => $attachments
        ];
    }
 
    

    /**
     * Enregistre ou met à jour une note/tâche.
     *
     * @param array $data Les données à insérer/mettre à jour.
     * @param int|null $id ID de l'activité à mettre à jour, ou null pour une nouvelle.
     * @return int|false ID de l'activité insérée/mise à jour.
     */
    public function save_activity( $data, $id = null ) {
        // Ici, implémentez l'insertion (wpdb->insert) ou la mise à jour (wpdb->update)
        // ...
        return $id; // ou le nouvel ID si insertion
    }

    // Ajoutez d'autres méthodes (delete_activity, mark_task_complete, etc.)

    // /**
    //  * Construit le HTML pour l'affichage des participants (Attendees).
    //  *
    //  * @param string $user_ids_list Liste des IDs séparés par des virgules.
    //  * @return string Le HTML formaté pour affichage dans la sidebar.
    //  */
    // private function get_attendees_display_html( $user_ids_list ) {
    //     // 1. Nettoyage et préparation des IDs
    //     $ids = array_map( 'absint', explode( ',', $user_ids_list ) );
    //     $ids = array_filter( $ids ); // Retire les zéros et les valeurs vides

    //     if ( empty( $ids ) ) {
    //         return __('Not defined', 'ispag-crm');
    //     }

    //     $attendees = [];
    //     foreach ( $ids as $user_id ) {
    //         // Récupération de l'objet utilisateur (optimisé pour le cache)
    //         $user_info = get_userdata( $user_id );
    //         if ( $user_info ) {
    //             $attendees[] = $user_info->display_name;
    //         }
    //     }
        
    //     $count = count( $attendees );
    //     if ( $count === 0 ) {
    //         return __('Not defined', 'ispag-crm');
    //     }
        
    //     // --- Logique d'affichage conditionnel ---
        
    //     if ( $count === 1 ) {
    //         // Cas 1 : Un seul participant -> Afficher le nom directement
    //         return $attendees[0];
            
    //     } else {
    //         // Cas 2 : Plusieurs participants -> Affichage "X participants" avec liste au survol
            
    //         $attendees_list_html = '<ul class="ispag-attendee-list">';
    //         foreach ( $attendees as $name ) {
    //             // Échappement HTML pour la sécurité (important !)
    //             $attendees_list_html .= '<li>' . esc_html( $name ) . '</li>';
    //         }
    //         $attendees_list_html .= '</ul>';
            
    //         // Conteneur de survol (pour le JS/CSS)
    //         $html = sprintf(
    //             '<span class="attendee-count">%d %s</span>' .
    //             '<div class="attendee-popover">%s</div>',
    //             $count,
    //             __('associations', 'ispag-crm'), // Vous pouvez utiliser 'associations' si vous préférez
    //             $attendees_list_html
    //         );

    //         // Le JS devra insérer ce HTML dans le <span>#meeting-attendees-display</span>
    //         return '<div class="attendee-hover-container">' . $html . '</div>';
    //     }
    // }
    /**
     * Retourne soit le HTML formaté, soit une liste de noms séparés par des virgules.
     *
     * @param string $user_ids_list Liste des IDs séparés par des virgules.
     * @param bool $raw_list Si vrai, retourne uniquement "Nom1, Nom2, Nom3" (pour l'édition).
     * @return string Le HTML ou la liste texte.
     */
    private function get_attendees_names( $user_ids_list, $raw_list = false ) {
        // 1. Nettoyage et préparation des IDs
        $ids = array_map( 'absint', explode( ',', $user_ids_list ) );
        $ids = array_filter( $ids );

        if ( empty( $ids ) ) {
            return $raw_list ? '' : __('Not defined', 'ispag-crm');
        }

        $attendees = [];
        foreach ( $ids as $user_id ) {
            $user_info = get_userdata( $user_id );
            if ( $user_info ) {
                $attendees[] = $user_info->display_name;
            }
        }
        
        $count = count( $attendees );
        if ( $count === 0 ) {
            return $raw_list ? '' : __('Not defined', 'ispag-crm');
        }

        // --- NOUVEAU : Retour pour le mode EDITION (Select2) ---
        if ( $raw_list ) {
            return implode( ', ', $attendees );
        }

        // --- Logique d'affichage conditionnel pour la TIMELINE ---
        if ( $count === 1 ) {
            return esc_html( $attendees[0] );
        } else {
            $attendees_list_html = '<ul class="ispag-attendee-list">';
            foreach ( $attendees as $name ) {
                $attendees_list_html .= '<li>' . esc_html( $name ) . '</li>';
            }
            $attendees_list_html .= '</ul>';
            
            $html = sprintf(
                '<span class="attendee-count">%d %s</span>' .
                '<div class="attendee-popover">%s</div>',
                $count,
                __('associations', 'ispag-crm'),
                $attendees_list_html
            );

            return '<div class="attendee-hover-container">' . $html . '</div>';
        }
    }


    /**
     * Récupère les noms d'une ou plusieurs sociétés à partir de leurs IDs (ispag_id).
     *
     * @param string $ids_list Liste des IDs séparés par des virgules.
     * @return string Noms des sociétés séparés par des virgules ou 'Not defined'.
     */
    public function get_company_names_by_ids( $ids_list ) {
        $table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME; // Nom de votre table
        
        // 1. Nettoyage et préparation des IDs
        $ids = array_map( 'absint', explode( ',', $ids_list ) );
        $ids = array_filter( $ids ); 

        if ( empty( $ids ) ) {
            return __('Not defined', 'ispag-crm');
        }

        // Préparation de la clause IN (pour la sécurité)
        // %s est utilisé ici car la liste est déjà sanitizée via absint
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

        // 2. Construction et exécution de la requête
        $sql = "
            SELECT company_name 
            FROM {$table_companies} 
            WHERE viag_id IN ({$placeholders})
        ";

        // Prépare la requête en passant les IDs sanitizés
        $sql_prepared = $this->wpdb->prepare( $sql, $ids );
        
        // Récupération des résultats sous forme de colonne (array)
        $companies = $this->wpdb->get_col( $sql_prepared );

        if ( empty( $companies ) ) {
            return __('Not defined', 'ispag-crm');
        }
        $count = count( $companies );
        if ( $count === 1 ) {
            // Cas 1 : Une seule société -> Afficher le nom directement
            return esc_html( $companies[0] );
            
        } else {
            // Cas 2 : Plusieurs sociétés -> Affichage "X sociétés" avec liste au survol
            
            $companies_list_html = '<ul class="ispag-attendee-list">'; // Réutilisation de la classe CSS
            foreach ( $companies as $name ) {
                $companies_list_html .= '<li>' . esc_html( $name ) . '</li>';
            }
            $companies_list_html .= '</ul>';
            
            // Conteneur de survol (Réutilisation de la structure attendue par le CSS/JS)
            $html = sprintf(
                '<span class="attendee-count">%d %s</span>' .
                '<div class="attendee-popover">%s</div>',
                $count,
                // Utiliser la bonne traduction pour "sociétés"
                _n('company', 'companies', $count, 'ispag-crm'), 
                $companies_list_html
            );

            // Le JS insérera ce HTML dans le <a>#task-company-link</a>
            // Note: Nous mettons le <div> au lieu du <a>, ou nous enveloppons le contenu du <a>
            return '<div class="attendee-hover-container">' . $html . '</div>';
        }
    }


    /**
     * Récupère les noms des opportunités à partir de leurs IDs (ispag_id).
     *
     * @param string $ids_list Liste des IDs séparés par des virgules.
     * @return array Tableau des noms des opportunités (project_name), ou un tableau vide.
     */
    public function get_deal_names_array_by_ids( $ids_list ) {
        $table_deals = ISPAG_Crm_Deal_Constants::TABLE_NAME; // Nom de votre table
//         error_log('[DEBUG] get_deal_names_array_by_ids ' . $ids_list);
        // 1. Nettoyage et préparation des IDs
        $ids = array_map( 'sanitize_text_field', explode( ',', $ids_list ) );
        $ids = array_filter( array_map( 'trim', $ids ) ); 
//         error_log('[DEBUG] array_filter ' . print_r($ids, true));
 
        if ( empty( $ids ) ) {
//             error_log('[DEBUG] empty ' . print_r($ids, true));
            return [];
        }

        // 2. Préparation de la clause IN
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
//         error_log('[DEBUG] placeholders ' . $placeholders);
        // 3. Construction et exécution de la requête
        $sql = $this->wpdb->prepare(
            "SELECT t1.project_name 
            FROM {$table_deals} t1
            INNER JOIN (
                SELECT deal_group_ref, MAX(date_creation) as max_date 
                FROM {$table_deals} 
                WHERE deal_group_ref IN ({$placeholders})
                GROUP BY deal_group_ref
            ) t2 ON t1.deal_group_ref = t2.deal_group_ref AND t1.date_creation = t2.max_date
            WHERE t1.deal_group_ref IN ({$placeholders})",
            array_merge($ids, $ids) // On passe $ids deux fois car il y a deux sets de placeholders
        );
//         error_log('[DEBUG] sql ' . $sql);
        // get_col retourne un tableau simple des noms de projets
        $names = $this->wpdb->get_col( $sql );

        $count = count( $names );
        if ( $count === 0 ) {
            return __('Not defined', 'ispag-crm');
        }
        
        // --- Logique d'affichage conditionnel ---
        
        if ( $count === 1 ) {
            // Cas 1 : Une seule opportunité -> Afficher le nom directement
            return esc_html( $names[0] );
            
        } else {
            // Cas 2 : Plusieurs opportunités -> Affichage "X opportunités" avec liste au survol
            
            $deals_list_html = '<ul class="ispag-attendee-list">'; // Réutilisation de la classe CSS
            foreach ( $names as $name ) {
                $deals_list_html .= '<li>' . esc_html( $name ) . '</li>';
            }
            $deals_list_html .= '</ul>';
            
            // Conteneur de survol (Réutilisation de la structure CSS/JS)
            $html = sprintf(
                '<span class="attendee-count">%d %s</span>' .
                '<div class="attendee-popover">%s</div>',
                $count,
                // Utiliser la bonne traduction pour "opportunité(s)"
                _n('opportunity', 'opportunities', $count, 'ispag-crm'), 
                $deals_list_html
            );

            // Retourne le conteneur HTML pour le survol
            return '<div class="attendee-hover-container">' . $html . '</div>';
        }
    }


    
}

