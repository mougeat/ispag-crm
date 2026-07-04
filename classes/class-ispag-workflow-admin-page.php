<?php
/**
 * Class ISPAG_Workflow_Admin_Page
 * Ajoute une page d'administration pour lister les séquences actives.
 */

if (!class_exists('ISPAG_Workflow_Admin_Page')) {
    class ISPAG_Workflow_Admin_Page {
        private $execution_repository;

        public function __construct() {
            $this->execution_repository = new ISPAG_Workflow_Execution();
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        }

        /**
         * Ajoute la page d'administration.
         */
        public function add_admin_menu() {
            add_submenu_page(
                'edit.php?post_type=ispag_workflow', // Page parente (Workflows)
                __('Active Sequences', 'ispag-crm'), // Titre de la page
                __('Active Sequences', 'ispag-crm'), // Titre du menu
                'manage_options', // Capacité requise
                'ispag-active-sequences', // Slug de la page
                [$this, 'render_admin_page'] // Fonction de rendu
            );
        }

        /**
         * Charge les scripts et styles admin.
         */
        public function enqueue_admin_scripts($hook) {
            if ($hook !== 'ispag_page_ispag-active-sequences') {
                return;
            }

            wp_enqueue_style(
                'ispag-workflow-admin-page-css',
                ISPAG_CRM_PLUGIN_URL . 'assets/css/ispag-workflow-admin-page.css',
                [],
                '1.0'
            );
        }

        /**
         * Récupère un deal par son group_ref.
         */
        private function get_deal_by_group_ref($group_ref) {
            global $wpdb;
            $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE deal_group_ref = %s", $group_ref)
            );
        }

        /**
         * Affiche la page d'administration.
         */
        public function render_admin_page() {
            ?>
            <div class="wrap">
                <h1><?php _e('Active Sequences', 'ispag-crm'); ?></h1>
                <table class="wp-list-table widefat fixed striped ispag-workflow-table">
                    <thead>
                        <tr>
                            <th><?php _e('Deal Group Ref', 'ispag-crm'); ?></th>
                            <th><?php _e('Deal Name', 'ispag-crm'); ?></th>
                            <th><?php _e('Workflow', 'ispag-crm'); ?></th>
                            <th><?php _e('Current Step', 'ispag-crm'); ?></th>
                            <th><?php _e('Status', 'ispag-crm'); ?></th>
                            <th><?php _e('Started At', 'ispag-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $executions = $this->execution_repository->get_active_executions();
                        if (empty($executions)) {
                            echo '<tr><td colspan="6">' . __('No active sequences found.', 'ispag-crm') . '</td></tr>';
                        } else {
                            foreach ($executions as $execution) {
                                $deal = $this->get_deal_by_group_ref($execution->deal_id); // ✅ Utiliser group_ref
                                $workflow = get_post($execution->workflow_id);
                                $steps = $this->get_workflow_steps($execution->workflow_id);
                                $current_step = isset($steps[$execution->current_step_index]) ? $steps[$execution->current_step_index] : null;
                                ?>
                                <tr>
                                    <td><?php echo esc_html($execution->deal_id); ?></td> <!-- group_ref -->
                                    <td>
                                        <?php if ($deal) : ?>
                                            <?php echo esc_html($deal->project_name); ?>
                                        <?php else : ?>
                                            <?php _e('Deal not found', 'ispag-crm'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($workflow) : ?>
                                            <a href="<?php echo esc_url(get_edit_post_link($execution->workflow_id, '')); ?>">
                                                <?php echo esc_html($workflow->post_title); ?>
                                            </a>
                                        <?php else : ?>
                                            <?php _e('Workflow not found', 'ispag-crm'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($current_step) : ?>
                                            <?php echo esc_html($current_step['title'] ?? __('Step', 'ispag-crm') . ' ' . ($execution->current_step_index + 1)); ?>
                                        <?php else : ?>
                                            <?php _e('Unknown step', 'ispag-crm'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ispag-status-badge ispag-status-<?php echo esc_attr($execution->status); ?>">
                                            <?php echo esc_html(ucfirst($execution->status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($execution->started_at))); ?></td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        /**
         * Récupère un deal par son ID.
         */
        private function get_deal_by_id($deal_id) {
            global $wpdb;
            $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE id = %d",
                    $deal_id
                )
            );
        }

        /**
         * Récupère les étapes d'un workflow.
         */
        private function get_workflow_steps($workflow_id) {
            $steps = get_post_meta($workflow_id, '_ispag_workflow_steps', true);
            return is_array($steps) ? $steps : [];
        }
    }
}