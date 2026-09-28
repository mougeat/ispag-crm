<?php
/**
 * Classe dédiée au rendu des notifications (cloche, sidebar, liste, badge)
 * Utilise Dashicons pour l'icône de cloche.
 */
class ISPAG_Notifications_Renderer {

    /**
     * Initialise les hooks WordPress pour le rendu
     */
    public static function init() {
        // Ajouter la cloche dans la navigation GeneratePress
        add_action('generate_inside_navigation', [__CLASS__, 'add_notification_bell_to_menu']);

        // Charger les assets (CSS/JS)
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Localiser les scripts pour AJAX
        add_action('wp_enqueue_scripts', [__CLASS__, 'localize_scripts']);

        // Afficher le HTML de la sidebar dans le footer
        add_action('wp_footer', [__CLASS__, 'render_notification_sidebar']);

        // Enregistrer les actions AJAX
        self::register_ajax_actions();
    }

    /**
     * Enregistre les actions AJAX
     */
    private static function register_ajax_actions() {
        add_action('wp_ajax_ispag_get_unread_notifications', [__CLASS__, 'get_unread_notifications_ajax']);
        add_action('wp_ajax_nopriv_ispag_get_unread_notifications', [__CLASS__, 'get_unread_notifications_ajax']);
        add_action('wp_ajax_ispag_get_unread_notification_count', [__CLASS__, 'get_unread_notification_count_ajax']);
        add_action('wp_ajax_nopriv_ispag_get_unread_notification_count', [__CLASS__, 'get_unread_notification_count_ajax']);
        

        // Actions AJAX pour la configuration des notifications
        add_action('wp_ajax_ispag_get_notification_settings_form', [__CLASS__, 'get_notification_settings_form_ajax']);
        add_action('wp_ajax_nopriv_ispag_get_notification_settings_form', [__CLASS__, 'get_notification_settings_form_ajax']);
        add_action('wp_ajax_ispag_save_notification_settings', [__CLASS__, 'save_notification_settings_ajax']);
        add_action('wp_ajax_nopriv_ispag_save_notification_settings', [__CLASS__, 'save_notification_settings_ajax']);

        add_action('wp_ajax_ispag_get_notifications_by_tab', [__CLASS__, 'get_notifications_by_tab_ajax']);
        add_action('wp_ajax_nopriv_ispag_get_notifications_by_tab', [__CLASS__, 'get_notifications_by_tab_ajax']);
    }

    /**
     * Ajoute la cloche de notification dans le menu GeneratePress
     */
    public static function add_notification_bell_to_menu() {
        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            return;
        }

        $unread_count = self::get_unread_notification_count($current_user_id);

        echo '<div class="ispag-notification-bell-container">';
        echo '<li class="menu-item menu-item-notifications menu-icon-bell">';
        echo '<a href="#" id="ispag-notification-bell" class="notification-bell">';
        // Le Dashicon a été retiré ici, l'image passera entièrement par le CSS ::before
        if ($unread_count > 0) {
            echo '<span id="ispag-notification-badge" class="notification-badge">' . esc_html($unread_count) . '</span>';
        } else {
            echo '<span id="ispag-notification-badge" class="notification-badge" style="display: none;">0</span>';
        }
        echo '</a>';
        echo '</li>';
        echo '</div>';
    }

    /**
     * Récupère le formulaire de configuration des notifications groupées (appelée via AJAX)
     */
    public static function get_notification_settings_form_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            wp_send_json_error(['message' => 'Utilisateur non connecté.']);
        }

        $available_types = ISPAG_Notifications_Manager::get_available_notification_types();
        $available_channels = ISPAG_Notifications_Manager::get_available_channels();

        $grouped_types = [];
        foreach ($available_types as $type_key => $type_info) {
            if (!user_can($current_user_id, $type_info['capability'])) {
                continue;
            }
            $group_key = isset($type_info['group']) ? $type_info['group'] : __('General', 'ispag-crm');
            $grouped_types[$group_key][$type_key] = $type_info;
        }

        // Récupérer les préférences de déconnexion depuis user_meta
        $allow_weekend = (bool) get_user_meta($current_user_id, 'ispag_allow_weekend_notifications', true);
        $holiday_periods_json = get_user_meta($current_user_id, 'ispag_holiday_periods', true);
        $holiday_periods = json_decode($holiday_periods_json, true) ?: [];

        // Vérifier si c'est la première fois que l'utilisateur voit la modale
        $has_prefs = ISPAG_Notifications_Manager::has_notification_preferences($current_user_id);

        ob_start();
        ?>
        <form id="ispag-notification-settings-form-submit">
            <?php if (!$has_prefs): ?>
                <div class="ispag-welcome-message">
                    <p>
                        <strong><?php _e('🔔 Welcome to ISPAG Notifications!', 'ispag-crm'); ?></strong>
                    </p>
                    <p>
                        <?php _e('Notifications help you stay updated on important events such as:', 'ispag-crm'); ?>
                    </p>
                    <ul style="margin-left: 20px; padding: 5px 0;">
                        <li><?php _e('Project status changes (e.g., new deals, updates, closures).', 'ispag-crm'); ?></li>
                        <li><?php _e('Task assignments and follow-ups.', 'ispag-crm'); ?></li>
                        <li><?php _e('Purchase orders and supplier updates.', 'ispag-crm'); ?></li>
                        <li><?php _e('New document uploads.', 'ispag-crm'); ?></li>
                        <li><?php _e('Automation and workflow alerts.', 'ispag-crm'); ?></li>
                    </ul>
                    <p>
                        <?php _e('Please select below how you would like to receive these notifications (e.g., via email, CRM bell, mobile push, etc.).', 'ispag-crm'); ?>
                    </p>
                    <p style="margin-top: 10px; font-style: italic; color: #64748b;">
                        <small>
                            <?php _e('⚙️ You can modify these preferences at any time by clicking the bell icon (🔔) in the top menu.', 'ispag-crm'); ?>
                        </small>
                    </p>
                </div>
            <?php endif; ?> 

            <!-- Section pour les canaux de notification -->
            <p class="ispag-notification-description"><?php _e('Choose the method(s) by which you wish to receive each type of notification.', 'ispag-crm'); ?></p>

            <div class="ispag-notification-table-container">
                <table class="ispag-notification-table">
                    <thead class="ispag-notification-table-header">
                        <tr>
                            <th class="ispag-notification-type-header"><?php _e('Notification type', 'ispag-crm'); ?></th>
                            <?php foreach ($available_channels as $channel_key => $channel_label): ?>
                                <th class="ispag-notification-channel-header"><?php echo esc_html($channel_label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_types as $group_name => $types): ?>
                            <tr class="ispag-notification-group-header">
                                <td colspan="<?php echo count($available_channels) + 1; ?>">
                                    <strong><?php echo esc_html($group_name); ?></strong>
                                </td>
                            </tr>
                            <?php foreach ($types as $type_key => $type_info):
                                $current_channels = ISPAG_Notifications_Manager::get_user_channel_preferences($current_user_id, $type_key);
                            ?>
                                <tr class="ispag-notification-type-row">
                                    <td class="ispag-notification-type-label"><?php echo esc_html($type_info['label']); ?></td>
                                    <?php foreach ($available_channels as $channel_key => $channel_label):
                                        $is_checked = in_array($channel_key, $current_channels);
                                    ?>
                                        <td class="ispag-notification-channel-cell">
                                            <input type="checkbox"
                                                name="ispag_notif_prefs[<?php echo esc_attr($type_key); ?>][]"
                                                value="<?php echo esc_attr($channel_key); ?>"
                                                <?php checked($is_checked, true); ?> />
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Section pour le droit à la déconnexion -->
            <div class="ispag-disconnection-settings">
                <h4><?php _e('Right to Disconnect', 'ispag-crm'); ?></h4>

                <div class="ispag-disconnection-option">
                    <label>
                        <input type="checkbox"
                            name="allow_weekend_notifications"
                            value="1"
                            <?php checked($allow_weekend, true); ?> />
                        <?php _e('Receive notifications on weekends (Saturday and Sunday)', 'ispag-crm'); ?>
                    </label>
                </div>

                <div class="ispag-holiday-periods">
                    <h5><?php _e('Holiday Periods', 'ispag-crm'); ?></h5>
                    <p class="description"><?php _e('Add periods during which you do not want to receive notifications.', 'ispag-crm'); ?></p>
                    <div id="ispag-holiday-periods-container">
                        <?php if (!empty($holiday_periods)): ?>
                            <?php foreach ($holiday_periods as $index => $period): ?>
                                <div class="ispag-holiday-period" data-index="<?php echo $index; ?>">
                                    <div class="ispag-holiday-period-fields">
                                        <label>
                                            <?php _e('Start Date:', 'ispag-crm'); ?>
                                            <input type="date"
                                                name="holiday_periods[<?php echo $index; ?>][start]"
                                                value="<?php echo esc_attr($period['start']); ?>" />
                                        </label>
                                        <label>
                                            <?php _e('End Date:', 'ispag-crm'); ?>
                                            <input type="date"
                                                name="holiday_periods[<?php echo $index; ?>][end]"
                                                value="<?php echo esc_attr($period['end']); ?>" />
                                        </label>
                                        <button type="button" class="ispag-remove-holiday-period button button-secondary"><?php _e('Remove', 'ispag-crm'); ?></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="ispag-holiday-period" data-index="0">
                                <div class="ispag-holiday-period-fields">
                                    <label>
                                        <?php _e('Start Date:', 'ispag-crm'); ?>
                                        <input type="date" name="holiday_periods[0][start]" />
                                    </label>
                                    <label>
                                        <?php _e('End Date:', 'ispag-crm'); ?>
                                        <input type="date" name="holiday_periods[0][end]" />
                                    </label>
                                    <button type="button" class="ispag-remove-holiday-period ispag-btn ispag-btn-secondary"><?php _e('Remove', 'ispag-crm'); ?></button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" id="ispag-add-holiday-period" class="ispag-btn ispag-btn-primary"><?php _e('Add Holiday Period', 'ispag-crm'); ?></button>
                </div>
            </div>

            <p class="ispag-notification-submit-wrapper">
                <button type="submit" id="ispag-save-notification-settings" class="ispag-btn ispag-btn-primary"><?php _e('Save preferences', 'ispag-crm'); ?></button>
            </p>
        </form>

        <script>
        jQuery(document).ready(function($) {
            // Ajouter une nouvelle période de vacances
            $('#ispag-add-holiday-period').on('click', function() {
                const index = $('#ispag-holiday-periods-container .ispag-holiday-period').length;
                const newPeriodHtml = `
                    <div class="ispag-holiday-period" data-index="${index}">
                        <div class="ispag-holiday-period-fields">
                            <label>
                                <?php _e('Start Date:', 'ispag-crm'); ?>
                                <input type="date" name="holiday_periods[${index}][start]" />
                            </label>
                            <label>
                                <?php _e('End Date:', 'ispag-crm'); ?>
                                <input type="date" name="holiday_periods[${index}][end]" />
                            </label>
                            <button type="button" class="ispag-remove-holiday-period button button-secondary"><?php _e('Remove', 'ispag-crm'); ?></button>
                        </div>
                    </div>
                `;
                $('#ispag-holiday-periods-container').append(newPeriodHtml);
            });

            // Supprimer une période de vacances
            $(document).on('click', '.ispag-remove-holiday-period', function() {
                $(this).closest('.ispag-holiday-period').remove();
                // Réindexer les périodes
                $('#ispag-holiday-periods-container .ispag-holiday-period').each(function(i) {
                    $(this).attr('data-index', i);
                    $(this).find('input[name^="holiday_periods"]').each(function() {
                        const name = $(this).attr('name');
                        $(this).attr('name', name.replace(/holiday_periods\[\d+\]/, `holiday_periods[${i}]`));
                    });
                });
            });
        });
        </script>
        <?php
        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }

    /**
     * Sauvegarde les préférences de notification (appelée via AJAX)
     */
    public static function save_notification_settings_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            wp_send_json_error(['message' => 'Utilisateur non connecté.']);
        }

        // Sauvegarder les préférences de canaux
        if (isset($_POST['ispag_notif_prefs']) && is_array($_POST['ispag_notif_prefs'])) {
            $clean_prefs = [];
            $available_types = ISPAG_Notifications_Manager::get_available_notification_types();
            $available_channels = ISPAG_Notifications_Manager::get_available_channels();

            foreach ($_POST['ispag_notif_prefs'] as $type => $channels) {
                if (isset($available_types[$type]) && is_array($channels)) {
                    if (user_can($current_user_id, $available_types[$type]['capability'])) {
                        $clean_prefs[$type] = array_intersect($channels, array_keys($available_channels));
                    }
                }
            }
            update_user_meta($current_user_id, 'ispag_notif_prefs', $clean_prefs);
        }

        // ⬇️ Sauvegarder les préférences de déconnexion (week-end et périodes de vacances)
        $allow_weekend = isset($_POST['allow_weekend_notifications']) ? 1 : 0;
        $holiday_periods = isset($_POST['holiday_periods']) ? array_values($_POST['holiday_periods']) : [];

        update_user_meta($current_user_id, 'ispag_allow_weekend_notifications', $allow_weekend);
        update_user_meta($current_user_id, 'ispag_holiday_periods', json_encode($holiday_periods));

        $msg = __('Preferences successfully saved.', 'ispag-crm');
        wp_send_json_success(['message' => $msg]);
    }

    /**
     * Récupère le nombre de notifications non lues pour un utilisateur
     */
    public static function get_unread_notification_count($user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND is_read = 0 AND is_deleted = 0 AND type != 'conceptual_window' ",
                $user_id
            )
        );
    }

    /**
     * Charge les assets (CSS/JS)
     */
    public static function enqueue_assets() {
        // Charger la bibliothèque favicon-badge
        wp_enqueue_script(
            'favicon-badge-js',
            'https://cdn.jsdelivr.net/npm/favicon-badge@1.0.2/dist/favicon-badge.min.js',
            [],
            '1.0.2',
            true
        );

        // JavaScript pour la sidebar
        wp_enqueue_script(
            'ispag-notifications-js',
            plugin_dir_url(dirname(__FILE__)) . '/assets/js/ispag-notifications.js',
            ['jquery'],
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/ispag-notifications.js'),
            true
        );

        $app_id = defined('CRM_ONE_SIGNAL_APP_ID') ? CRM_ONE_SIGNAL_APP_ID : getenv('CRM_ONE_SIGNAL_APP_ID');

        // Localiser le script pour AJAX
        wp_localize_script('ispag-notifications-js', 'ispag_notifications_obj', [
            'nonce'             => wp_create_nonce('ispag_nonce'),
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'app_id'            => $app_id,
            'current_user_id'   => get_current_user_id(),
        ]);

        // Ajouter un script pour charger le formulaire de configuration
        wp_add_inline_script('ispag-notifications-js', '
            function loadNotificationSettingsForm() {
                jQuery.ajax({
                    url: ispag_notifications_obj.ajaxurl,
                    type: "POST",
                    data: {
                        action: "ispag_get_notification_settings_form",
                        _ajax_nonce: ispag_notifications_obj.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.html) {
                            jQuery("#ispag-notification-settings-form").html(response.data.html);
                        }
                    }
                });
            }

            // Ouvrir la modale si elle a la classe "active"
            jQuery(document).ready(function($) {
                if ($("#ispag-notification-settings-modal").hasClass("active")) {
                    loadNotificationSettingsForm();
                }
            });
        ');
    }

    /**
     * Localise les scripts pour AJAX
     */
    public static function localize_scripts() {
        wp_localize_script('ispag-notifications-js', 'ispag_notifications_obj', [
            'nonce' => wp_create_nonce('ispag_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    /**
     * Affiche le HTML de la sidebar dans le footer
     */
    public static function render_notification_sidebar() {
        ?>
        <!-- Overlay pour la sidebar -->
        <div id="ispag-notification-overlay"></div>

        <!-- Sidebar des notifications -->
        <div id="ispag-notification-sidebar">
            <!-- En-tête avec titre et boutons -->
            <div class="notification-header">
                <h3><?php _e('Notifications', 'ispag-crm'); ?></h3>
                <div class="notification-header-actions">
                    <!-- Bouton de fermeture -->
                    <button class="close-sidebar ispag-close-modal ispag-btn ispag-btn-red-outlined ispag-close-croix" id="ispag-close-notification-sidebar">×</button>
                </div>
            </div>

            <!-- Onglets pour filtrer les notifications -->
            <div class="notification-tabs">
                <button class="notification-tab active" data-tab="unread">
                    <?php _e('Unread', 'ispag-crm'); ?> <span id="unread-count" class="notification-tab-count">0</span>
                </button>
                <button class="notification-tab" data-tab="all">
                    <?php _e('All', 'ispag-crm'); ?>
                </button>
                <button class="notification-tab" data-tab="trash">
                    <?php _e('Trash', 'ispag-crm'); ?>
                </button>
                <!-- Icône d'engrenage pour ouvrir la modale de configuration -->
                <button id="ispag-notification-settings" class="notification-settings-button">
                    <span class="dashicons dashicons-admin-generic"></span>
                </button>
            </div>

            <!-- Liste des notifications -->
            <div class="notification-list" id="ispag-notification-list">
                <p class="notification-loading"><?php _e('Loading', 'ispag-crm'); ?>...</p>
            </div>
        </div>

        <!-- Modale de configuration des canaux -->
        <div id="ispag-notification-settings-modal">
            <div id="ispag-notification-settings-content">
                <!-- En-tête de la modale avec titre et croix -->
                <div class="ispag-modal-header">
                    <h2><?php _e('Notification preferences', 'ispag-crm'); ?></h2>
                    <button id="ispag-close-settings-modal" class="ispag-close-modal ispag-btn ispag-btn-red-outlined ispag-close-croix">&times;</button>
                </div>
                <!-- Contenu de la modale -->
                <div class="ispag-modal-body">
                    <div id="ispag-notification-settings-form">
                        <p class="notification-loading"><?php _e('Loading', 'ispag-crm'); ?>...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fenêtre conceptuelle (modale globale) -->
        <div id="ispag-conceptual-window-modal">
            <div class="ispag-modal-content">
                <button id="ispag-close-conceptual-window" class="ispag-modal-close ispag-close-croix">&times;</button>
                <h3 id="ispag-conceptual-window-title"></h3>
                <div id="ispag-conceptual-window-body"></div>
                <p id="ispag-conceptual-window-link-wrapper" class="ispag-modal-link-wrapper">
                    <a id="ispag-conceptual-window-link" href="#" class="ispag-modal-link"><?php _e('See details →', 'ispag-crm'); ?></a>
                </p>
            </div>
        </div>

        <div id="ispag-bulk-message" class="bulk_message"></div>

        <!-- Script pour ouvrir la modale si l'utilisateur n'a pas de préférences -->
        <script>
        jQuery(document).ready(function($) {
            <?php
            $current_user_id = get_current_user_id();
            $user = wp_get_current_user();
            $allowed_roles = ['administrator', 'vente_ispag', 'membre_ispag', 'achat_ispag', 'ispag_commercial'];
            $has_prefs = ISPAG_Notifications_Manager::has_notification_preferences($current_user_id);

            // Vérifier si l'utilisateur a un rôle autorisé et n'a pas de préférences
            if ($current_user_id && array_intersect($allowed_roles, $user->roles) && !$has_prefs) {
                echo "jQuery('#ispag-notification-settings-modal').addClass('active').show();";
                echo "if (typeof loadNotificationSettingsForm === 'function') { loadNotificationSettingsForm(); }";
            }
            ?>
        });
        </script>
        <?php
    }

    /**
     * Récupère les notifications en fonction de l'onglet sélectionné
     */
    public static function get_notifications_by_tab_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            wp_send_json_error(['message' => 'Utilisateur non connecté.']);
        }

        $tab = isset($_POST['tab']) ? sanitize_text_field($_POST['tab']) : 'unread';
        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        // Construire la requête en fonction de l'onglet
        switch ($tab) {
            case 'all':
                $where = "user_id = %d AND is_deleted = 0";
                break;
            case 'trash':
                $where = "user_id = %d AND is_deleted = 1";
                break;
            case 'unread':
            default:
                $where = "user_id = %d AND is_read = 0 AND is_deleted = 0";
                break;
        }

        $notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where AND type != 'conceptual_window' ORDER BY sent_at DESC",
                $current_user_id
            )
        );

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE $where AND type != 'conceptual_window'",
                $current_user_id
            )
        );

        if (empty($notifications)) {
            if ($tab === 'unread') {
                $img = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-gradient-shield" style="margin-bottom: 15px;">
                    <defs>
                        <linearGradient id="redToGrey" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" style="stop-color:#e11d48;stop-opacity:1" />
                            <stop offset="100%" style="stop-color:#64748b;stop-opacity:1" />
                        </linearGradient>
                    </defs>
                    <!-- Application du dégradé sur le contour (stroke) -->
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="url(#redToGrey)"></path>
                    <polyline points="9 12 11 14 15 10" stroke="url(#redToGrey)"></polyline>
                </svg>';
                $title = __('Hooray! You’re all caught up on your unread notifications.', 'ispag-crm');
                $desc  = __('You can still access notifications from the last 30 days by checking the "All" and "Trash" tabs.', 'ispag-crm');
            } else {
                $img   = '';
                $title = __('No notifications.', 'ispag-crm');
                $desc  = __('There are no notifications to display here at the moment.', 'ispag-crm');
            }

            $html  = '<div style="text-align: center; padding: 20px;">';
            $html .= $img;
            $html .= '<h3 style="font-size: 16px; font-weight: bold; color: #1e293b; margin-bottom: 8px;">' . $title . '</h3>';
            $html .= '<p style="font-size: 14px; color: #64748b; margin: 0;">' . $desc . '</p>';
            $html .= '</div>';

            wp_send_json_success([
                'html'  => $html, 
                'count' => 0
            ]);
        }

        $html = '';
        foreach ($notifications as $notification) {
            $html .= self::render_notification_item($notification);
        }

        wp_send_json_success(['html' => $html, 'count' => $count]);
    }

    /**
     * Récupère les notifications non lues pour l'utilisateur actuel (appelée via AJAX)
     */
    public static function get_unread_notifications_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            wp_send_json_error(['message' => 'Utilisateur non connecté.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_notifications';

        $notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE user_id = %d AND is_read = 0 ORDER BY sent_at DESC",
                $current_user_id
            )
        );

       if (empty($notifications)) {
            $title = __('Hooray! You’re all caught up on your unread notifications.', 'ispag-crm');
            $desc  = __('You can still access notifications from the last 30 days by checking the "All" and "Trash" tabs.', 'ispag-crm');

            $html = '<div style="text-align: center; padding: 20px;">';
            $html .= '<h3 style="font-size: 16px; font-weight: bold; color: #1e293b; margin-bottom: 8px;">' . $title . '</h3>';
            $html .= '<p style="font-size: 14px; color: #64748b; margin: 0;">' . $desc . '</p>';
            $html .= '</div>';

            wp_send_json_success(['html' => $html]);
        }

        $html = '';
        foreach ($notifications as $notification) {
            $html .= self::render_notification_item($notification);
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Récupère le nombre de notifications non lues (appelée via AJAX)
     */
    public static function get_unread_notification_count_ajax() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            wp_send_json_error(['message' => 'Utilisateur non connecté.']);
        }

        $count = self::get_unread_notification_count($current_user_id);
        wp_send_json_success(['count' => $count]);
    }

    
    // /**
    //  * Génère le HTML pour une notification
    //  */
    // private static function render_notification_item($notification) {
    //     $target_url = '';
    //     if (!empty($notification->url)) {
    //         $target_url = (strpos($notification->url, 'http') === 0)
    //             ? $notification->url
    //             : home_url('/' . ltrim($notification->url, '/'));
    //     }

    //     $open_button = '';
    //     if (!empty($target_url)) {
    //         $open_button = sprintf(
    //             '<a href="%s" target="_blank" class="ispag-btn ispag-btn-grey notification-open-button">%s</a>',
    //             esc_url($target_url),
    //             __('Open', 'ispag-crm')
    //         );
    //     }

    //     $mark_as_read_button = sprintf(
    //         '<a href="#" class="ispag-btn ispag-btn-grey notification-mark-as-read"  data-notification-id="%d" data-url="%s" data-onesignal-id="%s">%s</a>',
            
    //         esc_attr($notification->id),
    //         esc_url($target_url),
    //         esc_attr($notification->onesignal_id),
    //         __('Mark as read', 'ispag-crm')
    //     );

    //     return sprintf(
    //         '<div class="notification-item unread" data-notification-id="%d" data-url="%s" data-onesignal-id="%s">
    //             <div class="notification-title">%s</div>
    //             <div class="notification-content">%s</div>
    //             <div class="notification-time">%s</div>
    //             <div class="notification-actions">
    //                 %s %s
    //             </div>
    //         </div>',
    //         esc_attr($notification->id),
    //         esc_url($target_url),
    //         esc_attr($notification->onesignal_id), // Ajout de l'onesignal_id
    //         esc_html($notification->title),
    //         esc_html($notification->content),
    //         esc_html(date_i18n('d/m/Y H:i', strtotime($notification->sent_at))),
    //         $mark_as_read_button,
    //         $open_button
    //     );
    // }

    /**
     * Génère le HTML pour une notification (Style HubSpot + 100% SVG)
     */
    private static function render_notification_item($notification) {
        $target_url = '';
        if (!empty($notification->url)) {
            $target_url = (strpos($notification->url, 'http') === 0)
                ? $notification->url
                : home_url('/' . ltrim($notification->url, '/'));
        }

        $is_read = (int)$notification->is_read === 1;
        $is_deleted = (int)$notification->is_deleted === 1;
        $item_class = $is_read ? 'notification-item read' : 'notification-item unread';

        // 1. Icônes SVG centralisées
        $external_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>';
        
        $envelope_closed = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>';
        
        $envelope_open = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.2 8.4l-8.6 5.7c-.4.3-1 .3-1.4 0L2.8 8.4"></path><path d="M2 6h20v12H2z"></path></svg>';
        
        $trash_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
        
        $restore_icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"></path></svg>';

        // 2. Bouton Lien externe (Voir)
        $open_link = '';
        if (!empty($target_url)) {
            $open_link = sprintf(
                '<a href="%s" target="_blank" class="notification-action-btn no-external-icon" title="%s">%s</a>',
                esc_url($target_url),
                __('View', 'ispag-crm'),
                $external_icon
            );
        }

        // 3. Bouton Marquer comme lu / non lu
        $mark_as_read_btn = '';
        if (!$is_read) {
            // Non lue -> Enveloppe ouverte (pour la marquer comme lue)
            $mark_as_read_btn = sprintf(
                '<button type="button" class="notification-action-btn notification-mark-as-read" data-notification-id="%d" data-onesignal-id="%s" title="%s">%s</button>',
                esc_attr($notification->id),
                esc_attr($notification->onesignal_id),
                __('Mark as read', 'ispag-crm'),
                $envelope_open
            );
        } else {
            // Lue -> Enveloppe fermée (pour la remettre non lue)
            $mark_as_read_btn = sprintf(
                '<button type="button" class="notification-action-btn notification-mark-as-read" data-notification-id="%d" data-onesignal-id="%s" title="%s">%s</button>',
                esc_attr($notification->id),
                esc_attr($notification->onesignal_id),
                __('Mark as unread', 'ispag-crm'),
                $envelope_closed
            );
        }

        // 4. Bouton Suppression / Restauration
        $delete_btn = '';
        if ($is_deleted) {
            $delete_btn = sprintf(
                '<button type="button" class="notification-action-btn notification-delete" data-notification-id="%d" title="%s">%s</button>',
                esc_attr($notification->id),
                __('Restore', 'ispag-crm'),
                $restore_icon
            );
        } else {
            $delete_btn = sprintf(
                '<button type="button" class="notification-action-btn notification-delete" data-notification-id="%d" title="%s">%s</button>',
                esc_attr($notification->id),
                __('Delete', 'ispag-crm'),
                $trash_icon
            );
        }

        $formatted_date = date_i18n('d M à H:i', strtotime($notification->sent_at));

        return sprintf(
            '<div class="%s" data-notification-id="%d" data-onesignal-id="%s">
                <div class="notification-indicator"></div>
                <div class="notification-body">
                    <div class="notification-header-line">
                        <span class="notification-title">%s</span>
                        <span class="notification-time">%s</span>
                    </div>
                    <div class="notification-content">%s</div>
                </div>
                <div class="notification-actions">
                    %s %s %s
                </div>
            </div>',
            esc_attr($item_class),
            esc_attr($notification->id),
            esc_attr($notification->onesignal_id),
            esc_html($notification->title),
            esc_html($formatted_date),
            esc_html($notification->content),
            $open_link,
            $mark_as_read_btn,
            $delete_btn
        );
    }
}

// Initialiser la classe
ISPAG_Notifications_Renderer::init();