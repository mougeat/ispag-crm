<?php
/**
 * Gère le rendu HTML et le JavaScript de la modal latérale de note/tâche.
 */
class ISPAG_Note_Modal_View {

    public function __construct() {
        // Charger le CSS externe
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    /**
     * Charge le CSS externe pour les modales et sidebars.
     */
    public function enqueue_styles() {
        // wp_enqueue_style(
        //     'ispag-note-modal-css',
        //     plugins_url('css/ispag-note-modal.css', __FILE__), // Chemin vers votre fichier CSS
        //     [],
        //     filemtime(plugin_dir_path(__FILE__) . 'css/ispag-note-modal.css') // Version basée sur la date de modification
        // );
    }

    /****************************************** */
    // Fonction support
    /****************************************** */

    /**
     * Calcule une date future en excluant les week-ends.
     * @param int $days_to_add Nombre de jours ouvrables à ajouter.
     * @return string Date au format 'Y-m-d H:i:s' pour le jour d'échéance.
     */
    private function ispag_get_working_day_date($days_to_add) {
        $current_timestamp = time();
        $days_counted = 0;

        while ($days_counted < $days_to_add) {
            $current_timestamp = strtotime('+1 day', $current_timestamp);
            $day_of_week = date('N', $current_timestamp);

            if ($day_of_week < 6) {
                $days_counted++;
            }
        }

        return $current_timestamp;
    }

    /********************************************** */
    // Affichage
    /********************************************** */

    /**
     * Affiche le code HTML de la modale (appelé dans le wp_footer).
     */
    public function render_note_modal_html() {
        if (!is_user_logged_in() || !current_user_can('manage_order')) {
            return;
        }

        $default_reminder_time = date('Y-m-d') . 'T09:00';
        ob_start();
        ?>
        <!-- Modal pour créer/éditer une note/tâche -->
        <div id="ispag-note-modal" class="ispag-modal-overlay">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <h4 id="ispag-action-type"><?php esc_html_e('Note', 'ispag-crm'); ?></h4>
                    <button class="ispag-close-modal ispag-btn ispag-btn-red-outlined ispag-close-croix" title="<?php esc_attr_e('Close', 'ispag-crm'); ?>">×</button>
                </div>

                <div class="ispag-modal-body">
                    <input type="hidden" id="modal-contact-id" name="contact_id" value="0">
                    <input type="hidden" id="modal-company-id" name="company_id" value="0">
                    <input type="hidden" id="modal-deal-id" name="deal_id" value="0">
                    <input type="hidden" id="modal-activity-id" name="activity_id" value="0">
                    <input type="hidden" id="modal-action-type" name="action_type" value="note">

                    <h6><?php esc_html_e('Associated with:', 'ispag-crm'); ?></h6>
                    <div class="ispag-meeting-field-row">
                        <div class="ispag-field-group">
                            <label for="meeting-attendees-select"><?php esc_html_e('Attendees', 'ispag-crm'); ?></label>
                            <div class="ispag-select-participants">
                                <select id="meeting-attendees-select" name="meeting_attendees[]" multiple="multiple" style="width: 100%;">
                                </select>
                            </div>
                        </div>

                        <div class="ispag-field-group">
                            <label for="meeting-companies-select"><?php esc_html_e('Companies', 'ispag-crm'); ?></label>
                            <div class="ispag-select-company">
                                <select id="meeting-companies-select" name="meeting_companies[]" multiple="multiple" style="width: 100%;">
                                </select>
                            </div>
                        </div>

                        <div class="ispag-field-group">
                            <label for="meeting-deals-select"><?php esc_html_e('Deals', 'ispag-crm'); ?></label>
                            <div class="ispag-select-deals">
                                <select id="meeting-deals-select" name="meeting_deals[]" multiple="multiple" style="width: 100%;">
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Rest of the modal HTML remains unchanged -->
                    <div class="ispag-call-fields">
                        <hr>
                        <div class="ispag-meeting-field-row ispag-date-time-outcome">
                            <div class="ispag-field-group">
                                <label for="meeting-date-call"><?php esc_html_e('Date', 'ispag-crm'); ?></label>
                                <input type="date" id="meeting-date-call" name="meeting_date_call" value="<?php echo wp_date('Y-m-d'); ?>">
                            </div>

                            <div class="ispag-field-group">
                                <label for="meeting-time-call"><?php esc_html_e('Time', 'ispag-crm'); ?></label>
                                <input type="time" id="meeting-time-call" name="meeting_time_call" value="<?php echo wp_date('H:i'); ?>">
                            </div>
                            <div class="ispag-field-group">
                                <label for="meeting-outcome-call"><?php esc_html_e('Outcome', 'ispag-crm'); ?></label>
                                <select id="meeting-outcome-call" name="meeting_outcome_call">
                                    <option value="busy"><?php esc_html_e('Busy', 'ispag-crm'); ?></option>
                                    <option value="connected" selected><?php esc_html_e('Connected', 'ispag-crm'); ?></option>
                                    <option value="left_live_message"><?php esc_html_e('Left live message', 'ispag-crm'); ?></option>
                                    <option value="left_voicemail"><?php esc_html_e('Left voicemail', 'ispag-crm'); ?></option>
                                    <option value="no_answer"><?php esc_html_e('No answer', 'ispag-crm'); ?></option>
                                    <option value="wrong_number"><?php esc_html_e('Wrong number', 'ispag-crm'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="ispag-meeting-fields">
                        <hr>
                        <div class="ispag-meeting-field-row ispag-date-time-outcome">
                            <div class="ispag-field-group">
                                <label for="meeting-date"><?php esc_html_e('Date', 'ispag-crm'); ?></label>
                                <input type="date" id="meeting-date" name="meeting_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <div class="ispag-field-group">
                                <label for="meeting-time"><?php esc_html_e('Time', 'ispag-crm'); ?></label>
                                <input type="time" id="meeting-time" name="meeting_time" value="<?php echo date('H:i'); ?>">
                            </div>

                            <div class="ispag-field-group">
                                <label for="meeting-outcome"><?php esc_html_e('Outcome', 'ispag-crm'); ?></label>
                                <select id="meeting-outcome" name="meeting_outcome">
                                    <option value="scheduled"><?php esc_html_e('Scheduled', 'ispag-crm'); ?></option>
                                    <option value="completed"><?php esc_html_e('Completed', 'ispag-crm'); ?></option>
                                    <option value="rescheduled"><?php esc_html_e('Rescheduled', 'ispag-crm'); ?></option>
                                    <option value="no_show"><?php esc_html_e('No Show', 'ispag-crm'); ?></option>
                                    <option value="canceled"><?php esc_html_e('Canceled', 'ispag-crm'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="ispag-note-template-wrapper" style="display: none; margin-bottom: 15px;">
                        <label for="ispag-note-template-select"><strong><?php esc_html_e('Email Template', 'ispag-crm'); ?></strong></label>
                        <div style="display: flex; gap: 10px;">
                            <select id="ispag-note-template-select" style="flex: 1;">
                                <option value=""><?php esc_html_e('-- Select a template --', 'ispag-crm'); ?></option>
                                <?php
                                $repo = new ISPAG_Template_Repository();
                                $current_user_id = get_current_user_id();
                                $folders = $repo->get_folders($current_user_id);
                                $templates = $repo->get_templates_for_user($current_user_id, '');

                                foreach ($folders as $folder) :
                                    echo '<optgroup label="' . esc_attr($folder->name) . '">';
                                    foreach ($templates as $tpl) {
                                        if ($tpl->folder_id == $folder->id) {
                                            echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                                        }
                                    }
                                    echo '</optgroup>';
                                endforeach;

                                echo '<optgroup label="' . esc_attr__('Other', 'ispag-crm') . '">';
                                foreach ($templates as $tpl) {
                                    if (empty($tpl->folder_id)) {
                                        echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                                    }
                                }
                                echo '</optgroup>';
                                ?>
                            </select>
                            <button type="button" id="ispag-apply-template" class="button button-secondary">
                                <?php esc_html_e('Apply', 'ispag-crm'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="ispag-form-group">
                        <label id="activity-title-label" for="activity-title-input"><?php esc_html_e('Note Title', 'ispag-crm'); ?></label>
                        <input type="text" id="activity-title-input" name="activity_title" class="ispag-input" placeholder="<?php esc_html_e('Quick summary', 'ispag-crm'); ?>...">
                    </div>

                    <textarea id="note-text-area" placeholder="<?php esc_attr_e('Start writing to leave a note...', 'ispag-crm'); ?>" rows="6"></textarea>

                    <div class="ispag-task-toggle-section">
                        <label>
                            <input type="checkbox" id="create-task-checkbox">
                            <?php esc_html_e('Create a task', 'ispag-crm'); ?>
                        </label>
                        <div class="ispag-reminder-field" style="display: none; margin-top: 10px;">
                            <label>
                                <strong><?php esc_html_e('To do', 'ispag-crm'); ?></strong> <?php esc_html_e('for a follow-up in', 'ispag-crm'); ?>

                                <div class="task-due-date-group ispag-flex-row">
                                    <div class="ispag-field-group">
                                        <select id="task-due-offset" name="task_due_offset">
                                            <option value="0d"><?php esc_html_e('Today', 'ispag-crm'); ?></option>
                                            <option value="1d"><?php esc_html_e('Tomorrow', 'ispag-crm'); ?></option>
                                            <option value="2d"><?php esc_html_e('3 business days', 'ispag-crm'); ?></option>
                                            <option value="7d"><?php esc_html_e('1 week', 'ispag-crm'); ?></option>
                                            <option value="14d"><?php esc_html_e('2 weeks', 'ispag-crm'); ?></option>
                                            <option value="1m"><?php esc_html_e('1 month', 'ispag-crm'); ?></option>
                                            <option value="2m"><?php esc_html_e('2 months', 'ispag-crm'); ?></option>
                                            <option value="3m"><?php esc_html_e('3 months', 'ispag-crm'); ?></option>
                                            <option value="custom"><?php esc_html_e('Custom date', 'ispag-crm'); ?> </option>
                                        </select>
                                    </div>

                                    <div class="ispag-field-group" id="container-due-date-custom">
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
                                <?php esc_html_e('Reminder before due date:', 'ispag-crm'); ?>
                            </label>
                            <select id="task-reminder-offset" name="task_reminder_offset">
                                <option value=""><?php esc_html_e('No reminder', 'ispag-crm'); ?></option>
                                <option value="-0 minutes" selected><?php esc_html_e('On time', 'ispag-crm'); ?></option>
                                <option value="-30 minutes"><?php esc_html_e('30 minutes before', 'ispag-crm'); ?></option>
                                <option value="-1 hour"><?php esc_html_e('1 hour before', 'ispag-crm'); ?></option>
                                <option value="-1 day"><?php esc_html_e('1 day before', 'ispag-crm'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="ispag-modal-footer">
                    <button id="ispag-create-note-btn" class="ispag-btn ispag-btn-primary"> 
                        <?php esc_html_e('Create a Note', 'ispag-crm'); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php
        echo ob_get_clean();
    }
}