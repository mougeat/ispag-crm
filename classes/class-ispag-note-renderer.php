<?php
/**
 * Gère le rendu HTML des éléments d'activité.
 */
class ISPAG_Note_Renderer {

    /**
     * Construit le HTML de la liste complète des activités pour une entité donnée,
     * en priorisant une section pour les tâches en retard.
     *
     * @param array $activities Tableau des objets d'activité (notes/tâches).
     * @return string Le code HTML complet de la liste, ou un message si vide.
     */
    public function render_activities_list( $activities, $nb_display = 999) {
        if ( empty( $activities ) ) {
            return '<div class="ispag-activities-timeline"><p class="no-activities-found">' . __( 'No registered activity', 'ispag-crm' ) . '</p></div>';
        }

        $now_timestamp = current_time( 'timestamp' );
        $today_date = date('Y-m-d', $now_timestamp);
        
        $upcoming_and_overdue = [];
        $history_activities = [];
        $i = 0;

        // 1. SÉPARATION : Actions à faire (is_task) VS Historique
        foreach ( $activities as $item ) {
            // CORRECTION LOGS : Utilisation de ?? 0 pour éviter le Warning si la propriété n'existe pas
            $is_task_val      = isset($item->is_task) ? (int)$item->is_task : 0;
            $is_completed_val = isset($item->is_completed) ? (int)$item->is_completed : 0;

            $needs_action = $is_task_val === 1;
            $is_completed = $is_completed_val === 1;

            if ( $needs_action && ! $is_completed ) {
                // Toutes les actions non terminées (Retard + Futur)
                $upcoming_and_overdue[] = $item;
            } else {
                // Tout ce qui est terminé ou purement informatif (Notes)
                $history_activities[] = $item;
            }

            if($i >= $nb_display){
                break;
            }
            $i++;
        }

        $output = '<div class="ispag-activities-timeline">';

        // 2. SECTION "UPCOMING & OVERDUE"
        if ( ! empty( $upcoming_and_overdue ) ) {
            $output .= sprintf(
                '<h3 class="timeline-month-separator upcoming-separator">%s <span class="task-count">(%d)</span></h3>',
                __( 'Upcoming & Overdue', 'ispag-crm' ),
                count($upcoming_and_overdue)
            );

            // TRI : Par date d'échéance (due_date)
            usort($upcoming_and_overdue, function($a, $b) {
                $date_a = !empty($a->due_date) ? strtotime($a->due_date) : 0;
                $date_b = !empty($b->due_date) ? strtotime($b->due_date) : 0;
                return $date_a - $date_b;
            });

            foreach ( $upcoming_and_overdue as $item ) {
                $is_late = false;
                if ( ! empty( $item->due_date ) ) {
                    $due_ts = strtotime( $item->due_date );
                    if ( $due_ts < $now_timestamp && date('Y-m-d', $due_ts) !== $today_date ) {
                        $is_late = true;
                    }
                }
                $output .= $this->render_activity_card( $item, $is_late );
            }
        }

        // 3. SECTION HISTORIQUE
        $current_month_year = null; 
        foreach ( $history_activities as $item ) {
            // CORRECTION LOGS : Sécurité sur created_at
            $date_ts = !empty($item->created_at) ? strtotime($item->created_at) : time();
            $activity_month_year = date_i18n( 'F Y', $date_ts );
            
            if ( $activity_month_year !== $current_month_year ) {
                $output .= sprintf(
                    '<h3 class="timeline-month-separator">%s</h3>',
                    esc_html( $activity_month_year )
                );
                $current_month_year = $activity_month_year;
            }

            $output .= $this->render_activity_card( $item, false );
        }

        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Tronque une chaîne HTML sans casser les balises.
     */
    private function limit_html_content( $text, $limit = 200 ) {
        if ( mb_strlen( strip_tags($text) ) <= $limit ) {
            return $text;
        }
        
        // On coupe à la limite
        $text = mb_substr($text, 0, $limit);
        
        // On s'assure de ne pas couper un mot en deux et on ferme les balises
        return force_balance_tags( $text ) . '...';
    }

    /**
     * Construit le HTML pour afficher une seule carte d'activité (Note ou Tâche).
     *
     * @param object $item L'objet de note/tâche récupéré de la BDD.
     * @param bool $is_overdue Si la tâche est en retard.
     * @return string Le code HTML de la carte.
     */
    public function render_activity_card( $item, $is_overdue = false ) {
        // error_log(print_r($item, true));
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );
        
        // 1. Préparation du titre et du contenu
        $title_html = esc_html( $item->title );
        $raw_text = strip_tags( $item->content );
        $clean_html = wp_kses_post( $raw_text );
        $trimmed_content = $this->limit_html_content( $clean_html, 124 ); 
        $content_html = sprintf( '<div class="ispag-activity-content">%s</div>', wpautop( $trimmed_content ) );

        // 2. Gestion de l'icône et du type
        $type = strtolower($item->type);
        $is_meeting = ($type === 'meeting');
        $is_call    = ($type === 'call');
        $is_task    = ((int) $item->is_task === 1);
        $is_completed = (isset($item->is_completed) && $item->is_completed == 1);
        
        $icons = [
            'task'                => 'dashicons-list-view',
            'meeting'             => 'dashicons-calendar-alt',
            'email'               => 'dashicons-email',
            'log_email'           => 'dashicons-email',
            'call'                => 'dashicons-phone',
            'note'                => 'dashicons-text-page',
            'email_campaign'      => 'dashicons-megaphone',
            'email_transactional' => 'dashicons-external',
            'christmas_present'   => 'dashicons-tickets-alt',
            'whatsapp'            => 'dashicons-share',
            'sms'                 => 'dashicons-smartphone',
            'stage'               => 'dashicons-awards',
            'system'              => 'dashicons-awards'
        ];
        $icon = $icons[$type] ?? 'dashicons-text-page';

        // 3. NOUVEAU : Checkbox de complétion rapide (Uniquement pour les tâches)
        $quick_complete_html = '<span class="ispag-toggle-icon">❯</span>'; // Par défaut l'icône de flèche
        // error_log('BEFORE IS TASK');
        if ( $is_task ) {
            // error_log('IN IS TASK');
            if ( $is_completed ) {
                $quick_complete_html = '<div class="ispag-quick-complete"><span class="dashicons dashicons-yes-alt is-completed-check"></span></div>';
            } else {
                $quick_complete_html = sprintf(
                    '<div class="ispag-quick-complete">
                        <input type="checkbox" class="ispag-task-quick-check" data-id="%d" title="%s">
                    </div>',
                    $item->id,
                    __( 'Mark as completed', 'ispag-crm' )
                );
            }
        }

        // 4. Gestion des pièces jointes
        $attachments_html = '';
        if ( ! empty( $item->media_ids ) ) {
            $media_ids = explode( ',', $item->media_ids );
            $count = count( $media_ids );
            if ( $count === 1 ) {
                $file_url = wp_get_attachment_url( $media_ids[0] );
                $attachments_html = sprintf(
                    '<div class="ispag-activity-attachments">
                        <a href="%s" target="_blank" class="ispag-attachment-link single-attachment" title="%s">
                            <span class="dashicons dashicons-paperclip"></span>
                        </a>
                    </div>',
                    esc_url( $file_url ),
                    __( '1 Attachment', 'ispag-crm' )
                );
            } else {
                $attachments_html = sprintf(
                    '<div class="ispag-activity-attachments ispag-attachments-dropdown">
                        <span class="ispag-attachment-trigger" title="%d %s">
                            <span class="dashicons dashicons-paperclip"></span> <small>%d</small>
                        </span>
                    </div>',
                    $count, __( 'Attachments', 'ispag-crm' ), $count
                );
            }
        }

        // 5. Menu Actions
        $action_buttons = '';
        $restricted_types = ['stage', 'system', 'email_campaign', 'email_transactional', 'christmas_present', 'health_reminder'];
        if ( ! in_array( $type, $restricted_types ) ) {
            $action_buttons = sprintf(
                '<div class="ispag-dropdown-actions">
                    <button class="ispag-action-btn action-menu-toggle">
                        <span class="dashicons dashicons-ellipsis"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><button class="ispag-action-btn edit-activity" data-activity-id="%d" data-type="%s">%s</button></li>
                        <li><button class="ispag-action-btn delete-activity" data-activity-id="%d">%s</button></li>
                    </ul>
                </div>',
                $item->id, esc_attr($type), __( 'Edit', 'ispag-crm' ),
                $item->id, __( 'Delete', 'ispag-crm' )
            );
        }

        // 6. Détails Meeting/Call
        $meeting_details_html = '';
        if ( $is_meeting || $is_call ) {
            $meeting_outcome = !empty($item->outcome) ? esc_html($item->outcome) : __('No outcome', 'ispag-crm');
            $m_date = ($item->created_at) ? mysql2date( $date_format, $item->created_at, true ) : '';
            $meeting_details_html = sprintf(
                '<div class="ispag-meeting-footer">
                    <div class="ispag-meeting-summary"> 
                        <span><strong>%s:</strong> %s</span> | <span><strong>Date:</strong> %s</span>
                    </div>
                </div>',
                esc_html__( 'Outcome', 'ispag-crm' ), $meeting_outcome, $m_date
            );
        }

        // 7. Classes et Date
        $meta_display = date_i18n( $date_format, strtotime( $item->created_at ) );
        $extra_classes = ( $is_completed ) ? ' is-completed' : '';
        $extra_classes .= ( $is_overdue ) ? ' ispag-overdue-task' : '';

        // 8. Assemblage Final
        return sprintf(
            '<div class="ispag-timeline-entry" data-activity-id="%d">
                <div class="ispag-timeline-left">
                    <span class="activity-icon dashicons %s"></span>
                    <div class="ispag-timeline-line"></div>
                </div>
                <div class="ispag-activity-item %s %s" data-task-id="%d" data-activity-id="%d">
                    <div class="ispag-activity-card">
                        <div class="ispag-activity-header">
                            <div class="ispag-activity-title-group">
                                %s
                                <div class="ispag-activity-title open-task-sidebar">%s</div>
                            </div>
                            <div class="ispag-activity-meta-group">
                                <div class="ispag-activity-meta">%s</div>
                                <div class="ispag-activity-header-actions">
                                    %s %s
                                </div>
                            </div>
                        </div>
                        <div class="ispag-activity-body open-task-sidebar">
                            %s
                            %s
                        </div>
                    </div>
                </div>
            </div>', 
            $item->id,                      // entry data-id
            esc_attr( $icon ),              // icône gauche
            esc_attr( $type ),              // classe type (task, note...)
            esc_attr( $extra_classes ),     // completed / overdue
            $item->id,                      // item data-task-id
            $item->id,                      // item data-activity-id
            $quick_complete_html,           // Checkbox ou Flèche
            $title_html,                    // Titre
            esc_html( $meta_display ),      // Date
            $attachments_html,              // Pièces jointes
            $action_buttons,                // Bouton ellipsis
            $content_html,                  // Corps du texte
            $meeting_details_html           // Footer meeting
        );
    }
    public function render_task_table_row( $item, $is_overdue = false ) {
        // 1. Préparation des variables
        $task_id = absint( $item->id );
        $due_ts  = strtotime( $item->due_date );
        $display_due_date = date_i18n( 'j M Y', $due_ts );
        
        $overdue_class = $is_overdue ? 'row-overdue' : '';
        $late_wrapper_class = $is_overdue ? 'is-late' : '';

        // Début du buffering ou construction de la chaîne
        $output = '<tr id="task-' . $task_id . '" class="task-row ' . $overdue_class . '">';

        // Colonne Checkbox
        $output .= '<td class="col-check">
                        <div class="custom-checkbox">
                            <input type="checkbox" id="check-' . $task_id . '" class="complete-task-btn" data-activity-id="' . $task_id . '">
                            <label for="check-' . $task_id . '"></label>
                        </div>
                    </td>';

        // Colonne Titre
        $output .= '<td class="col-task">
                        <span class="task-title-link open-task-sidebar" data-task-id="' . $task_id . '">
                            ' . esc_html( $item->title ) . '
                        </span>
                    </td>';

        // Colonne Relations (Contact & Entreprise)
        $output .= '<td class="col-rel">
                        <div class="rel-box">';
        
        if ( ! empty( $item->contact_name ) ) {
            $contact_url = home_url( '/contact/' . absint( $item->contact_id ) . '/' );
            $output .= '<a href="' . esc_url( $contact_url ) . '" class="rel-item contact">
                            <i class="dashicons dashicons-admin-users"></i> ' . esc_html( $item->contact_name ) . '
                        </a>';
        }

        if ( ! empty( $item->company_name ) ) {
            $company_url = home_url( '/company/' . absint( $item->company_id ) . '/' );
            $output .= '<a href="' . esc_url( $company_url ) . '" class="rel-item company">
                            <i class="dashicons dashicons-bank"></i> ' . esc_html( $item->company_name ) . '
                        </a>';
        }

        $output .= '    </div>
                    </td>';

        // Colonne Type
        $output .= '<td class="col-type">
                        <span>' . esc_html( $item->task_type ) . '</span>
                    </td>';

        // Colonne Date
        $output .= '<td class="col-date">
                        <div class="due-date-wrapper ' . $late_wrapper_class . '">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            ' . esc_html( $display_due_date ) . '
                        </div>
                    </td>';

        // Colonne Actions
        $output .= '<td class="col-actions">
                        <div class="btn-group">
                            <button class="icon-btn edit-activity" data-activity-id="' . $task_id . '" title="' . esc_attr__( 'Edit', 'ispag-crm' ) . '">
                                <i class="dashicons dashicons-edit"></i>
                            </button>
                        </div>
                    </td>';

        $output .= '</tr>';

        return $output;
    }
    // Ajoutez ici des méthodes pour d'autres éléments d'affichage si nécessaire
}
