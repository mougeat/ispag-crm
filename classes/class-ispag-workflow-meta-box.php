<?php
/**
 * Class ISPAG_Workflow_Meta_Box
 * Gère les meta boxes pour les workflows dans l'admin WordPress.
 */

if (!class_exists('ISPAG_Workflow_Meta_Box')) {
    class ISPAG_Workflow_Meta_Box
    {
        public function __construct()
        {
            add_action('add_meta_boxes_ispag_workflow', [$this, 'add_workflow_meta_boxes']);
            add_action('save_post_ispag_workflow', [$this, 'save_workflow_meta_boxes'], 10, 2);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
            add_action('wp_ajax_get_deal_stages', [$this, 'ajax_get_deal_stages']);
        }

        /**
         * Ajoute les meta boxes pour les workflows.
         */
        public function add_workflow_meta_boxes()
        {
            add_meta_box(
                'ispag_workflow_settings',
                __('Workflow Settings', 'ispag-crm'),
                [$this, 'render_workflow_settings_meta_box'],
                'ispag_workflow',
                'normal',
                'high'
            );

            add_meta_box(
                'ispag_workflow_steps',
                __('Workflow Steps', 'ispag-crm'),
                [$this, 'render_workflow_steps_meta_box'],
                'ispag_workflow',
                'normal',
                'high'
            );

            add_meta_box(
                'ispag_workflow_triggers',
                __('Workflow Triggers', 'ispag-crm'),
                [$this, 'render_workflow_triggers_meta_box'],
                'ispag_workflow',
                'normal',
                'high'
            );
        }

        /**
         * Récupère les statuts disponibles pour les deals depuis la table wor9711_ispag_deal_stages.
         */
        private function get_deal_stages()
        {
            global $wpdb;
            $table_name = 'wor9711_ispag_deal_stages';
            $stages = $wpdb->get_results(
                "SELECT stage_key, stage_label FROM {$table_name} ORDER BY stage_order ASC"
            );
            return $stages;
        }

        /**
         * Affiche la meta box des paramètres du workflow.
         */
        public function render_workflow_settings_meta_box($post)
        {
            wp_nonce_field('ispag_workflow_settings_nonce', 'ispag_workflow_settings_nonce');

            $workflow_type = get_post_meta($post->ID, '_ispag_workflow_type', true);
            $is_active = get_post_meta($post->ID, '_ispag_workflow_is_active', true);
            $working_hours = get_post_meta($post->ID, '_ispag_workflow_working_hours', true);
            ?>
            <div class="ispag-field-group">
                <label for="ispag_workflow_type"><?php _e('Workflow Type:', 'ispag-crm'); ?></label>
                <select name="ispag_workflow_type" id="ispag_workflow_type" class="widefat">
                    <option value="contact" <?php selected($workflow_type, 'contact'); ?>><?php _e('Contact', 'ispag-crm'); ?></option>
                    <option value="deal" <?php selected($workflow_type, 'deal'); ?>><?php _e('Deal', 'ispag-crm'); ?></option>
                </select>
                <p class="description"><?php _e('Select whether this workflow applies to contacts or deals.', 'ispag-crm'); ?></p>
            </div>

            <div class="ispag-field-group">
                <label for="ispag_workflow_is_active">
                    <input type="checkbox" name="ispag_workflow_is_active" id="ispag_workflow_is_active" value="1" <?php checked($is_active, '1'); ?>>
                    <?php _e('Active Workflow', 'ispag-crm'); ?>
                </label>
                <p class="description"><?php _e('Enable this workflow to start executing its steps.', 'ispag-crm'); ?></p>
            </div>

            <div class="ispag-field-group">
                <label for="ispag_workflow_working_hours"><?php _e('Working Hours (e.g., 08:00-18:00):', 'ispag-crm'); ?></label>
                <input type="text" name="ispag_workflow_working_hours" id="ispag_workflow_working_hours" class="widefat" value="<?php echo esc_attr($working_hours); ?>">
                <p class="description"><?php _e('Define the working hours for sending emails or executing tasks.', 'ispag-crm'); ?></p>
            </div>
            <?php
        }

        /**
         * Affiche la meta box des étapes du workflow.
         */
        public function render_workflow_steps_meta_box($post)
        {
            wp_nonce_field('ispag_workflow_steps_nonce', 'ispag_workflow_steps_nonce');

            $steps = get_post_meta($post->ID, '_ispag_workflow_steps', true);
            if (!is_array($steps)) {
                $steps = [];
            }
            ?>
            <div id="ispag-workflow-steps-container">
                <?php foreach ($steps as $index => $step) : ?>
                    <div class="ispag-workflow-step" data-index="<?php echo $index; ?>">
                        <h4><?php _e('Step', 'ispag-crm'); ?> <?php echo $index + 1; ?></h4>
                        <div class="ispag-field-group">
                            <label><?php _e('Step Type:', 'ispag-crm'); ?></label>
                            <select name="ispag_workflow_steps[<?php echo $index; ?>][type]" class="widefat">
                                <option value="email" <?php selected($step['type'], 'email'); ?>><?php _e('Email', 'ispag-crm'); ?></option>
                                <option value="task" <?php selected($step['type'], 'task'); ?>><?php _e('Task', 'ispag-crm'); ?></option>
                                <option value="call" <?php selected($step['type'], 'call'); ?>><?php _e('Call', 'ispag-crm'); ?></option>
                            </select>
                        </div>

                        <div class="ispag-field-group">
                            <label><?php _e('Delay (e.g., 1 day, 2 hours):', 'ispag-crm'); ?></label>
                            <input type="text" name="ispag_workflow_steps[<?php echo $index; ?>][delay]" class="widefat" value="<?php echo esc_attr($step['delay'] ?? ''); ?>">
                        </div>

                        <div class="ispag-field-group">
                            <label><?php _e('Title/Subject:', 'ispag-crm'); ?></label>
                            <input type="text" name="ispag_workflow_steps[<?php echo $index; ?>][title]" class="widefat" value="<?php echo esc_attr($step['title'] ?? ''); ?>">
                        </div>

                        <div class="ispag-field-group">
                            <label><?php _e('Content/Description:', 'ispag-crm'); ?></label>
                            <textarea name="ispag_workflow_steps[<?php echo $index; ?>][content]" class="widefat" rows="5"><?php echo esc_textarea($step['content'] ?? ''); ?></textarea>
                        </div>

                        <button type="button" class="button ispag-remove-step"><?php _e('Remove Step', 'ispag-crm'); ?></button>
                        <hr>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="ispag-add-step" class="button button-primary"><?php _e('Add Step', 'ispag-crm'); ?></button>
            <?php
        }

        /**
         * Affiche la meta box des déclencheurs du workflow.
         */
        public function render_workflow_triggers_meta_box($post)
        {
            wp_nonce_field('ispag_workflow_triggers_nonce', 'ispag_workflow_triggers_nonce');

            $triggers = get_post_meta($post->ID, '_ispag_workflow_triggers', true);
            if (!is_array($triggers)) {
                $triggers = [];
            }

            // Récupérer les statuts disponibles pour les deals
            $deal_stages = $this->get_deal_stages();
            ?>
            <div id="ispag-workflow-triggers-container">
                <?php foreach ($triggers as $index => $trigger) : ?>
                    <div class="ispag-workflow-trigger" data-index="<?php echo $index; ?>">
                        <h4><?php _e('Trigger', 'ispag-crm'); ?> <?php echo $index + 1; ?></h4>
                        <div class="ispag-field-group">
                            <label><?php _e('Trigger Type:', 'ispag-crm'); ?></label>
                            <select name="ispag_workflow_triggers[<?php echo $index; ?>][type]" class="widefat ispag-trigger-type">
                                <option value="status_change" <?php selected($trigger['type'], 'status_change'); ?>><?php _e('Status Change', 'ispag-crm'); ?></option>
                                <option value="email_response" <?php selected($trigger['type'], 'email_response'); ?>><?php _e('Email Response', 'ispag-crm'); ?></option>
                                <option value="task_completed" <?php selected($trigger['type'], 'task_completed'); ?>><?php _e('Task Completed', 'ispag-crm'); ?></option>
                            </select>
                        </div>

                        <!-- Champs pour le déclencheur "status_change" -->
                        <div class="ispag-status-change-fields" style="<?php echo ($trigger['type'] ?? '') === 'status_change' ? '' : 'display: none;'; ?>">
                            <div class="ispag-field-group">
                                <label><?php _e('From Status:', 'ispag-crm'); ?></label>
                                <select name="ispag_workflow_triggers[<?php echo $index; ?>][from_status]" class="widefat ispag-from-status">
                                    <option value=""><?php _e('Any Status', 'ispag-crm'); ?></option>
                                    <?php foreach ($deal_stages as $stage) : ?>
                                        <option value="<?php echo esc_attr($stage->stage_key); ?>" <?php selected($trigger['from_status'] ?? '', $stage->stage_key); ?>>
                                            <?php echo esc_html($stage->stage_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ispag-field-group">
                                <label><?php _e('To Status:', 'ispag-crm'); ?></label>
                                <select name="ispag_workflow_triggers[<?php echo $index; ?>][to_status]" class="widefat ispag-to-status">
                                    <option value=""><?php _e('Any Status', 'ispag-crm'); ?></option>
                                    <?php foreach ($deal_stages as $stage) : ?>
                                        <option value="<?php echo esc_attr($stage->stage_key); ?>" <?php selected($trigger['to_status'] ?? '', $stage->stage_key); ?>>
                                            <?php echo esc_html($stage->stage_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Champs pour les autres types de déclencheurs -->
                        <div class="ispag-other-trigger-fields" style="<?php echo ($trigger['type'] ?? '') !== 'status_change' ? '' : 'display: none;'; ?>">
                            <p><?php _e('No additional settings for this trigger type.', 'ispag-crm'); ?></p>
                        </div>

                        <button type="button" class="button ispag-remove-trigger"><?php _e('Remove Trigger', 'ispag-crm'); ?></button>
                        <hr>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="ispag-add-trigger" class="button button-primary"><?php _e('Add Trigger', 'ispag-crm'); ?></button>
            <?php
        }

        /**
         * Récupère les statuts via AJAX.
         */
        public function ajax_get_deal_stages()
        {
            check_ajax_referer('ispag_workflow_nonce', 'nonce');
            $stages = $this->get_deal_stages();
            wp_send_json_success($stages);
        }

        /**
         * Charge les scripts et styles admin.
         */
        public function enqueue_admin_scripts($hook)
        {
            if ($hook !== 'post.php' && $hook !== 'post-new.php') {
                return;
            }

            global $post_type;
            if ($post_type !== 'ispag_workflow') {
                return;
            }

            wp_enqueue_script(
                'ispag-workflow-admin-js',
                ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-workflow-admin.js',
                ['jquery'],
                '1.0',
                true
            );

            wp_localize_script(
                'ispag-workflow-admin-js',
                'ispag_workflow_admin',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ispag_workflow_nonce'),
                    'any_status' => __('Any Status', 'ispag-crm'),
                    'step_label' => __('Step', 'ispag-crm'),
                    'step_type_label' => __('Step Type:', 'ispag-crm'),
                    'email_label' => __('Email', 'ispag-crm'),
                    'task_label' => __('Task', 'ispag-crm'),
                    'call_label' => __('Call', 'ispag-crm'),
                    'delay_label' => __('Delay (e.g., 1 day, 2 hours):', 'ispag-crm'),
                    'title_label' => __('Title/Subject:', 'ispag-crm'),
                    'content_label' => __('Content/Description:', 'ispag-crm'),
                    'remove_step_label' => __('Remove Step', 'ispag-crm'),
                    'trigger_label' => __('Trigger', 'ispag-crm'),
                    'trigger_type_label' => __('Trigger Type:', 'ispag-crm'),
                    'status_change_label' => __('Status Change', 'ispag-crm'),
                    'email_response_label' => __('Email Response', 'ispag-crm'),
                    'task_completed_label' => __('Task Completed', 'ispag-crm'),
                    'from_status_label' => __('From Status:', 'ispag-crm'),
                    'to_status_label' => __('To Status:', 'ispag-crm'),
                    'no_settings_label' => __('No additional settings for this trigger type.', 'ispag-crm'),
                    'remove_trigger_label' => __('Remove Trigger', 'ispag-crm'),
                    'loading_label' => __('Loading...', 'ispag-crm'),
                    'error_label' => __('Error loading stages', 'ispag-crm'),
                ]
            );

            wp_enqueue_style(
                'ispag-workflow-admin-css',
                ISPAG_CRM_PLUGIN_URL . 'assets/css/ispag-workflow-admin.css',
                [],
                '1.0'
            );
        }

        /**
         * Sauvegarde les meta boxes.
         */
        public function save_workflow_meta_boxes($post_id, $post)
        {
            if (!isset($_POST['ispag_workflow_settings_nonce']) || !wp_verify_nonce($_POST['ispag_workflow_settings_nonce'], 'ispag_workflow_settings_nonce')) {
                return;
            }

            if (!isset($_POST['ispag_workflow_steps_nonce']) || !wp_verify_nonce($_POST['ispag_workflow_steps_nonce'], 'ispag_workflow_steps_nonce')) {
                return;
            }

            if (!isset($_POST['ispag_workflow_triggers_nonce']) || !wp_verify_nonce($_POST['ispag_workflow_triggers_nonce'], 'ispag_workflow_triggers_nonce')) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            // Sauvegarde des paramètres
            if (isset($_POST['ispag_workflow_type'])) {
                update_post_meta($post_id, '_ispag_workflow_type', sanitize_text_field($_POST['ispag_workflow_type']));
            }

            if (isset($_POST['ispag_workflow_is_active'])) {
                update_post_meta($post_id, '_ispag_workflow_is_active', '1');
            } else {
                delete_post_meta($post_id, '_ispag_workflow_is_active');
            }

            if (isset($_POST['ispag_workflow_working_hours'])) {
                update_post_meta($post_id, '_ispag_workflow_working_hours', sanitize_text_field($_POST['ispag_workflow_working_hours']));
            }

            // Sauvegarde des étapes
            if (isset($_POST['ispag_workflow_steps'])) {
                $steps = [];
                foreach ($_POST['ispag_workflow_steps'] as $step) {
                    if (!empty($step['type'])) {
                        $steps[] = [
                            'type' => sanitize_text_field($step['type']),
                            'delay' => sanitize_text_field($step['delay']),
                            'title' => sanitize_text_field($step['title']),
                            'content' => wp_kses_post($step['content']),
                        ];
                    }
                }
                update_post_meta($post_id, '_ispag_workflow_steps', $steps);
            } else {
                delete_post_meta($post_id, '_ispag_workflow_steps');
            }

            // Sauvegarde des déclencheurs
            if (isset($_POST['ispag_workflow_triggers'])) {
                $triggers = [];
                foreach ($_POST['ispag_workflow_triggers'] as $trigger) {
                    if (!empty($trigger['type'])) {
                        $triggers[] = [
                            'type' => sanitize_text_field($trigger['type']),
                            'from_status' => sanitize_text_field($trigger['from_status'] ?? ''),
                            'to_status' => sanitize_text_field($trigger['to_status'] ?? ''),
                        ];
                    }
                }
                update_post_meta($post_id, '_ispag_workflow_triggers', $triggers);
            } else {
                delete_post_meta($post_id, '_ispag_workflow_triggers');
            }
        }
    }
}