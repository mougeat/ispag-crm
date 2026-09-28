<?php
if (!defined('ABSPATH')) {
    exit;
}

class Ispag_User_Display_Name_Regenerator {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_submenu'));
        add_action('wp_ajax_ispag_regen_users', array($this, 'ajax_process_users'));
    }

    public function add_admin_submenu() {
        add_submenu_page(
            'ispag-entreprises',
            __('Regenerate Display Names', 'ispag-crm'),
            __('Regenerate Names', 'ispag-crm'),
            'manage_options',
            'ispag-regen-display-names',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        $count_users = count_users();
        $total_users = $count_users['total_users'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Regenerate User Display Names', 'ispag-crm'); ?></h1>
            <p><?php esc_html_e('This tool updates user display names in bulk to the First Name Last Name format using batches to prevent timeout errors on large volumes.', 'ispag-crm'); ?></p>
            
            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <p><strong><?php esc_html_e('Total Users:', 'ispag-crm'); ?></strong> <span id="total-users"><?php echo esc_html($total_users); ?></span></p>
                
                <div id="progress-container" style="background: #f1f1f1; border: 1px solid #ccc; height: 25px; width: 100%; margin-bottom: 15px; border-radius: var(--ispag-btn-border-radius); overflow: hidden; position: relative;">
                    <div id="progress-bar" style="background: #007cba; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                    <div id="progress-text" style="position: absolute; width: 100%; text-align: center; top: 3px; font-weight: bold; color: #333;">0%</div>
                </div>
                
                <p><span id="status-text"><?php esc_html_e('Ready to start.', 'ispag-crm'); ?></span></p>
                <button id="start-regen-btn" class="button button-primary button-hero"><?php esc_html_e('Start Regeneration', 'ispag-crm'); ?></button>
            </div>
        </div>

        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('start-regen-btn');
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const statusText = document.getElementById('status-text');
            const totalUsers = parseInt(document.getElementById('total-users').innerText, 10);

            if (!btn) return;

            btn.addEventListener('click', function() {
                if (totalUsers === 0) {
                    alert('<?php echo esc_js(__('No users to process.', 'ispag-crm')); ?>');
                    return;
                }

                btn.disabled = true;
                statusText.innerText = '<?php echo esc_js(__('Processing...', 'ispag-crm')); ?>';
                
                let offset = 0;
                const batchSize = 50;

                function processBatch() {
                    const formData = new FormData();
                    formData.append('action', 'ispag_regen_users');
                    formData.append('offset', offset);
                    formData.append('batch_size', batchSize);
                    formData.append('security', '<?php echo wp_create_nonce('ispag_regen_nonce'); ?>');

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            offset = data.data.next_offset;
                            let processed = data.data.processed;
                            let percent = Math.min(100, Math.round((processed / totalUsers) * 100));
                            
                            progressBar.style.width = percent + '%';
                            progressText.innerText = percent + '%';
                            statusText.innerText = `<?php echo esc_js(__('Processed:', 'ispag-crm')); ?> ${processed} / ${totalUsers}`;

                            if (data.data.done) {
                                statusText.innerText = '<?php echo esc_js(__('Regeneration completed successfully!', 'ispag-crm')); ?>';
                                btn.disabled = false;
                            } else {
                                processBatch();
                            }
                        } else {
                            statusText.innerText = '<?php echo esc_js(__('Error:', 'ispag-crm')); ?> ' + (data.data.message || '<?php echo esc_js(__('Unknown', 'ispag-crm')); ?>');
                            btn.disabled = false;
                        }
                    })
                    .catch(error => {
                        statusText.innerText = '<?php echo esc_js(__('Network or server error.', 'ispag-crm')); ?>';
                        console.error(error);
                        btn.disabled = false;
                    });
                }

                processBatch();
            });
        });
        </script>
        <?php
    }

    public function ajax_process_users() {
        check_ajax_referer('ispag_regen_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Access denied.', 'ispag-crm')));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;

        $args = array(
            'number' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'all_with_meta'
        );

        $user_query = new WP_User_Query($args);
        $users = $user_query->get_results();

        if (empty($users)) {
            wp_send_json_success(array(
                'next_offset' => $offset,
                'processed' => $offset,
                'done' => true
            ));
        }

        foreach ($users as $user) {
            $first_name = get_user_meta($user->ID, 'first_name', true);
            $last_name = get_user_meta($user->ID, 'last_name', true);

            if (!empty($first_name) && !empty($last_name)) {
                $display_name = trim($first_name . ' ' . $last_name);
                
                if ($user->display_name !== $display_name) {
                    wp_update_user(array(
                        'ID' => $user->ID,
                        'display_name' => $display_name
                    ));
                }
            }
        }

        $processed = $offset + count($users);
        $total_users = count_users()['total_users'];
        $done = ($processed >= $total_users || count($users) < $batch_size);

        // Si le traitement par lot est complètement terminé, on déclenche le notifier
        if ($done && class_exists('ISPAG_Notifications_Manager')) {
            $current_user_id = get_current_user_id();
            
            // Utilisation du type de notification 'datas_export' ou un type existant pertinent pour signaler la fin du traitement
            ISPAG_Notifications_Manager::send(
                [$current_user_id],
                'datas_export', 
                __('Display Names Regeneration Completed', 'ispag-crm'),
                sprintf(__('The bulk regeneration of user display names has been successfully completed. Total processed: %d users.', 'ispag-crm'), $processed),
                admin_url('admin.php?page=ispag-regen-display-names'),
                0
            );
        }

        wp_send_json_success(array(
            'next_offset' => $processed,
            'processed' => $processed,
            'done' => $done
        ));
    }
}

// new Ispag_User_Display_Name_Regenerator();