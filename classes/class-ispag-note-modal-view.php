<?php
/**
 * Gère le rendu HTML et le JavaScript de la modal latérale de note/tâche.
 */
class ISPAG_Note_Modal_View {

    public function __construct() {
        // Enqueuing des assets ici si vous ne le faites pas dans le Manager
        // ...
    }

    /****************************************** */
    // Fonction support
    /****************************************** */

    /**
     * Calcule une date future en excluant les week-ends.
     * @param int $days_to_add Nombre de jours ouvrables à ajouter.
     * @return string Date au format 'Y-m-d H:i:s' pour le jour d'échéance.
     */
    private function ispag_get_working_day_date( $days_to_add ) {
        $current_timestamp = time();
        $days_counted = 0;

        // Boucle tant que nous n'avons pas atteint le nombre de jours ouvrables requis
        while ( $days_counted < $days_to_add ) {
            // Avancer d'un jour
            $current_timestamp = strtotime( '+1 day', $current_timestamp );
            
            // Obtenir le jour de la semaine (1=Lundi, 7=Dimanche)
            $day_of_week = date( 'N', $current_timestamp );

            // Si ce n'est ni Samedi (6) ni Dimanche (7), on le compte
            if ( $day_of_week < 6 ) {
                $days_counted++;
            }
        }
        
        // On retourne le jour trouvé, formaté pour l'affichage (PHP 'l' donne le jour complet, ex: "Wednesday")
        return $current_timestamp;
    }

    /********************************************** */
    // Affichage
    /********************************************** */

    /**
     * Affiche le code HTML de la modale (appelé dans le wp_footer).
     */
    public function render_note_modal_html() {
        // ... (Vérification de sécurité et variables PHP inchangées) ...

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_order' ) ) {
            return;
        }

        $default_reminder_time = date( 'Y-m-d', time() ) . 'T09:00';
        ob_start();
        ?>
        <div id="ispag-note-modal" class="ispag-task-modal-overlay">
            <div class="ispag-task-modal-content">
                
                <div class="ispag-modal-header">
                    <h4 id="ispag-action-type"><?php esc_html_e( 'Note', 'ispag-crm' ); ?></h4>
                    <button class="ispag-close-modal ispag-btn ispag-btn-red-outlined" title="<?php esc_attr_e( 'Close', 'ispag-crm' ); ?>">×</button>
                </div>
                
                <div class="ispag-modal-body">
                    <input type="hidden" id="modal-contact-id" name="contact_id" value="0">
                    <input type="hidden" id="modal-company-id" name="company_id" value="0">
                    <input type="hidden" id="modal-deal-id" name="deal_id" value="0">
                    <input type="hidden" id="modal-activity-id" name="activity_id" value="0">
                    <input type="hidden" id="modal-action-type" name="action_type" value="note">

                    <h6><?php esc_html_e( 'Associated with:', 'ispag-crm' ); ?></h6>
                    <div class="ispag-meeting-field-row">
                        <div class="ispag-field-group">
                            <label for="meeting-attendees-select"><?php esc_html_e( 'Attendees', 'ispag-crm' ); ?></label>
                            <div class="ispag-select-participants">
                                <select id="meeting-attendees-select" name="meeting_attendees[]" multiple="multiple" style="width: 100%;">
                                    </select>
                            </div>
                        </div>

                        <div class="ispag-field-group">
                            <label for="meeting-companies-select"><?php esc_html_e( 'Companies', 'ispag-crm' ); ?></label>
                            <div class="ispag-select-company">
                                <select id="meeting-companies-select" name="meeting_companies[]" multiple="multiple" style="width: 100%;">
                                    </select>
                            </div>
                        </div>

                        <div class="ispag-field-group">
                            <label for="meeting-deals-select"><?php esc_html_e( 'Deals', 'ispag-crm' ); ?></label>
                            <div class="ispag-select-deals">
                                <select id="meeting-deals-select" name="meeting_deals[]" multiple="multiple" style="width: 100%;">
                                    </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ispag-call-fields" > 
                        <hr>
                        <div class="ispag-meeting-field-row ispag-date-time-outcome">
                            <div class="ispag-field-group">
                                <label for="meeting-date-call"><?php esc_html_e( 'Date', 'ispag-crm' ); ?></label>
                                <input type="date" id="meeting-date-call" name="meeting_date_call" value="<?php echo wp_date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="ispag-field-group">
                                <label for="meeting-time-call"><?php esc_html_e( 'Time', 'ispag-crm' ); ?></label>
                                <input type="time" id="meeting-time-call" name="meeting_time_call" value="<?php echo wp_date('H:i'); ?>">
                            </div>
                            <div class="ispag-field-group">
                                <label for="meeting-outcome-call"><?php esc_html_e( 'Outcome', 'ispag-crm' ); ?></label>
                                <select id="meeting-outcome-call" name="meeting_outcome_call">
                                    <option value="busy"><?php esc_html_e( 'Busy', 'ispag-crm' ); ?></option>
                                    <option value="connected"><?php esc_html_e( 'Connected', 'ispag-crm' ); ?></option>
                                    <option value="left_live_message"><?php esc_html_e( 'Left live message', 'ispag-crm' ); ?></option>
                                    <option value="left_voicemail"><?php esc_html_e( 'Left voicemail', 'ispag-crm' ); ?></option>
                                    <option value="no_answer"><?php esc_html_e( 'No answer', 'ispag-crm' ); ?></option>
                                    <option value="wrong_number"><?php esc_html_e( 'Wrong number', 'ispag-crm' ); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                        
                    <div class="ispag-meeting-fields" > 
                        <hr>
                        <div class="ispag-meeting-field-row ispag-date-time-outcome">
                            
                            <div class="ispag-field-group">
                                <label for="meeting-date"><?php esc_html_e( 'Date', 'ispag-crm' ); ?></label>
                                <input type="date" id="meeting-date" name="meeting_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="ispag-field-group">
                                <label for="meeting-time"><?php esc_html_e( 'Time', 'ispag-crm' ); ?></label>
                                <input type="time" id="meeting-time" name="meeting_time" value="<?php echo date('H:i'); ?>">
                            </div>
                            
                            <div class="ispag-field-group">
                                <label for="meeting-outcome"><?php esc_html_e( 'Outcome', 'ispag-crm' ); ?></label>
                                <select id="meeting-outcome" name="meeting_outcome">
                                    <option value="scheduled"><?php esc_html_e( 'Scheduled', 'ispag-crm' ); ?></option>
                                    <option value="completed"><?php esc_html_e( 'Completed', 'ispag-crm' ); ?></option>
                                    <option value="rescheduled"><?php esc_html_e( 'Rescheduled', 'ispag-crm' ); ?></option>
                                    <option value="no_show"><?php esc_html_e( 'No Show', 'ispag-crm' ); ?></option>
                                    <option value="canceled"><?php esc_html_e( 'Canceled', 'ispag-crm' ); ?></option>
                                </select>
                            </div>

                        </div>
                    </div>

                    <div id="ispag-note-template-wrapper" style="display: none; margin-bottom: 15px;">
                        <label for="ispag-note-template-select"><strong><?php esc_html_e( 'Email Template', 'ispag-crm' ); ?></strong></label>
                        <div style="display: flex; gap: 10px;">
                            <select id="ispag-note-template-select" style="flex: 1;">
                                <option value=""><?php esc_html_e( '-- Select a template --', 'ispag-crm' ); ?></option>
                                <?php 
                                // On récupère les dossiers et templates pour l'utilisateur
                                $repo = new ISPAG_Template_Repository();
                                $current_user_id = get_current_user_id();
                                $folders = $repo->get_folders($current_user_id);
                                $templates = $repo->get_templates_for_user($current_user_id, ''); // Langue par défaut

                                foreach ( $folders as $folder ) :
                                    echo '<optgroup label="' . esc_attr( $folder->name ) . '">';
                                    foreach ( $templates as $tpl ) {
                                        if ( $tpl->folder_id == $folder->id ) {
                                            echo '<option value="' . esc_attr( $tpl->id ) . '">' . esc_html( $tpl->name ) . '</option>';
                                        }
                                    }
                                    echo '</optgroup>';
                                endforeach;

                                // Templates sans dossier
                                echo '<optgroup label="' . esc_attr__( 'Other', 'ispag-crm' ) . '">';
                                foreach ( $templates as $tpl ) {
                                    if ( empty( $tpl->folder_id ) ) {
                                        echo '<option value="' . esc_attr( $tpl->id ) . '">' . esc_html( $tpl->name ) . '</option>';
                                    }
                                }
                                echo '</optgroup>';
                                ?>
                            </select>
                            <button type="button" id="ispag-apply-template" class="button button-secondary">
                                <?php esc_html_e( 'Apply', 'ispag-crm' ); ?>
                            </button>
                        </div>
                    </div>

                    <div class="ispag-form-group">
                        <label id="activity-title-label" for="activity-title-input">Titre de la note</label>
                        <input type="text" id="activity-title-input" name="activity_title" class="ispag-input" placeholder="<?php esc_html_e( 'Quick summary', 'ispag-crm' ); ?>...">
                    </div>

                    

                    <textarea id="note-text-area" placeholder="<?php esc_attr_e( 'Start writing to leave a note...', 'ispag-crm' ); ?>" rows="6"></textarea>

                    <?php /*
                    <div class="ispag-toolbar">
                        <span title="<?php esc_attr_e( 'Bold', 'ispag-crm' ); ?>">B</span> 
                        ...
                    </div>
                    */ ?>

                    <div class="ispag-task-toggle-section">
                        <label>
                            <input type="checkbox" id="create-task-checkbox">
                            <?php esc_html_e( 'Create a task', 'ispag-crm' ); ?> 
                        </label>  
                        <div class="ispag-reminder-field" style="display: none; margin-top: 10px;">
                            <label>
                                <strong><?php esc_html_e( 'To do', 'ispag-crm' ); ?></strong> <?php esc_html_e( 'for a follow-up in', 'ispag-crm' ); ?>
                                
                                <div class="task-due-date-group ispag-flex-row">
                                    <div class="ispag-field-group">
                                        <select id="task-due-offset" name="task_due_offset">
                                            <option value="0d"><?php esc_html_e( 'Today', 'ispag-crm' ); ?></option>
                                            <option value="1d"><?php esc_html_e( 'Tomorrow', 'ispag-crm' ); ?></option>
                                            <option value="2d"><?php esc_html_e( '3 business days', 'ispag-crm' ); ?></option>
                                            <option value="7d"><?php esc_html_e( '1 week', 'ispag-crm' ); ?></option>
                                            <option value="14d"><?php esc_html_e( '2 weeks', 'ispag-crm' ); ?></option>
                                            <option value="1m"><?php esc_html_e( '1 month', 'ispag-crm' ); ?></option>
                                            <option value="2m"><?php esc_html_e( '2 months', 'ispag-crm' ); ?></option>
                                            <option value="3m"><?php esc_html_e( '3 months', 'ispag-crm' ); ?></option>
                                            <option value="custom"><?php esc_html_e( 'Custom date', 'ispag-crm' ); ?> </option>
                                        </select>
                                    </div>

                                    <div class="ispag-field-group" id="container-due-date-custom" >
                                        <input type="date" 
                                            id="task-due-date-custom" 
                                            name="task_due_date_custom" 
                                            value="<?php echo date('Y-m-d'); ?>">
                                    </div>

                                    <div class="ispag-field-group">
                                        <select id="task-due-time" name="task_due_time">
                                            <?php
                                            $start_time = strtotime('today midnight');
                                            $end_time = strtotime('tomorrow midnight');
                                            $interval = 15 * 60;
                                            for ($time = $start_time; $time < $end_time; $time += $interval) {
                                                $time_format = date('H:i', $time);
                                                $selected = ($time_format === '08:00') ? 'selected' : '';
                                                echo '<option value="' . esc_attr($time_format) . '" ' . $selected . '>' . esc_html($time_format) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                
                            </label>
                        

                            <label for="task-reminder-offset">
                                <?php esc_html_e( 'Reminder before due date:', 'ispag-crm' ); ?>
                            </label>
                            <select id="task-reminder-offset" name="task_reminder_offset">
                                <option value=""><?php esc_html_e( 'No reminder', 'ispag-crm' ); ?></option>
                                <option value="-0 minutes" selected><?php esc_html_e( 'On time', 'ispag-crm' ); ?></option>
                                <option value="-30 minutes"><?php esc_html_e( '30 minutes before', 'ispag-crm' ); ?></option>
                                <option value="-1 hour"><?php esc_html_e( '1 hour before', 'ispag-crm' ); ?></option>
                                <option value="-1 day"><?php esc_html_e( '1 day before', 'ispag-crm' ); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="ispag-modal-footer">
                    <button id="ispag-create-note-btn" class="button button-primary">
                        <?php esc_html_e( 'Create a Note', 'ispag-crm' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }
    
    /**
     * Affiche le code HTML de la sidebar (appelé dans le wp_footer).
     */
    public function render_note_sidebar_html() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_order' ) ) {
            return;
        }
        ?>
        <div id="ispag-task-sidebar-modal">
            <div id="ispag-task-modal-overlay"></div>

            <div id="ispag-task-modal-content">
                <div class="ispag-modal-header-modern">
                    <div class="header-main-info">
                        <h2 id="task-title-main">Détails de la tâche</h2>
                        <div class="header-meta">
                            <span class="meta-item">
                                <i class="dashicons dashicons-admin-users"></i>
                                <?php _e('Assigned to', 'ispag-crm'); ?> : <strong id="assigned-value">-</strong>
                            </span>
                            <span class="meta-item" id="task-due-date-wrapper">
                                <i class="dashicons dashicons-calendar-alt"></i>
                                <?php _e('Échéance', 'ispag-crm'); ?> : <strong id="due-date">-</strong>
                            </span>
                        </div>
                    </div>
                    <button class="close-sidebar-x" id="close-task-sidebar-btn">&times;</button>
                </div>

                <div class="ispag-task-modal-body">
                    <div class="ispag-section-card">
                        <h3 class="section-label"><?php _e('Associations', 'ispag-crm'); ?></h3>
                        <div class="association-grid">
                            <a href="#" id="task-contact-link" class="assoc-pill">
                                <i class="dashicons dashicons-admin-users"></i>
                                <span class="label">Contact</span>
                                <span class="value" id="task-contact-name">Non défini</span>
                            </a>
                            <a href="#" id="task-company-link" class="assoc-pill">
                                <i class="dashicons dashicons-bank"></i>
                                <span class="label">Entreprise</span>
                                <span class="value" id="task-company-name">Non défini</span>
                            </a>
                            <a href="#" id="task-deal-link" class="assoc-pill">
                                <i class="dashicons dashicons-chart-bar"></i>
                                <span class="label">Affaire</span>
                                <span class="value" id="task-deal-name">Non défini</span>
                            </a>
                        </div>
                    </div>

                    <div class="ispag-section-inline">
                        <div class="status-badge-wrapper">
                            <span class="small-label"><?php _e('Statut', 'ispag-crm'); ?></span>
                            <div id="task-status-display" class="status-pill">À faire</div>
                        </div>
                        <div class="status-badge-wrapper">
                            <span class="small-label"><?php _e('Type', 'ispag-crm'); ?></span>
                            <div id="task-type-display" class="type-pill">Tâche</div>
                        </div>
                    </div>

                    <div class="ispag-section-card content-section">
                        <h3 class="section-label"><?php _e('Notes & Description', 'ispag-crm'); ?></h3>
                        <div id="task-content" class="ispag-sidebar-content-view">
                            </div>
                    </div>

                    <div class="ispag-section-card meeting-details" id="meeting-info-section">
                        <h3 class="section-label"><?php _e('Meeting information', 'ispag-crm'); ?></h3>
                        <div class="meeting-info-grid">
                            <div class="m-item"><strong>Issue :</strong> <span id="meeting-outcome-display">-</span></div>
                            <div class="m-item"><strong>Participants :</strong> <span id="meeting-attendees-display">-</span></div>
                            <div class="m-item"><strong>Horaire :</strong> <span id="meeting-time-display">-</span></div>
                        </div>
                    </div>

                    <div class="ispag-section-card" id="sidebar-attachments-wrapper">
                        <h3 class="section-label"><i class="dashicons dashicons-paperclip"></i> <?php _e('Attachments', 'ispag-crm'); ?></h3>
                        <div id="task-attachments-container" class="attachments-compact">
                            </div>
                    </div>
                </div>

                <div class="ispag-task-modal-footer">
                    <button class="ispag-btn ispag-btn-secondary edit-activity" data-activity-id=""><?php _e('Edit', 'ispag-crm'); ?></button>
                    
                    <button class="ispag-btn ispag-btn-grey" id="close-task-sidebar-footer-btn"><?php _e('Close', 'ispag-crm'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }



}
