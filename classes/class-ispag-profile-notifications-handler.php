<?php
defined('ABSPATH') || exit;
/**
 * Classe dédiée à la gestion des préférences de notifications dans la page de profil utilisateur.
 * Réutilise la logique de ISPAG_Notifications_Manager pour l'affichage et l'enregistrement.
 */
class ISPAG_Profile_Notifications_Handler
{
    /**
     * Initialise les hooks pour la page de profil.
     */
    public static function init()
    {
        // Ajouter un shortcode pour afficher le formulaire de préférences (optionnel)
        add_shortcode('ispag_profile_notifications', [__CLASS__, 'render_notifications_form']);

        // Hook pour traiter la soumission du formulaire de préférences
        add_action('admin_post_update_notification_prefs', [__CLASS__, 'handle_update_notification_prefs']);
        add_action('admin_post_nopriv_update_notification_prefs', [__CLASS__, 'handle_update_notification_prefs']);
    }

    /**
     * Affiche le formulaire de préférences de notifications (pour un shortcode ou une inclusion directe).
     *
     * @param array $atts Attributs du shortcode (non utilisés ici).
     * @return string HTML du formulaire.
     */
    public static function render_notifications_form($atts = [])
    {
        if (!is_user_logged_in()) {
            return '<p>' . __('You must be logged in to view this section.', 'ispag-crm') . '</p>';
        }

        $user_id = get_current_user_id();
        $available_types = ISPAG_Notifications_Manager::get_available_notification_types();
        $available_channels = ISPAG_Notifications_Manager::get_available_channels();
        $notification_prefs = get_user_meta($user_id, 'ispag_notif_prefs', true);

        // Regrouper les types par groupe
        $grouped_types = [];
        foreach ($available_types as $type_key => $type_info) {
            if (!user_can($user_id, $type_info['capability'])) {
                continue;
            }
            $group_key = isset($type_info['group']) ? $type_info['group'] : __('General', 'ispag-crm');
            $grouped_types[$group_key][$type_key] = $type_info;
        }

        // Récupérer les préférences de déconnexion
        $allow_weekend = (bool) get_user_meta($user_id, 'ispag_allow_weekend_notifications', true);
        $holiday_periods_json = get_user_meta($user_id, 'ispag_holiday_periods', true);
        $holiday_periods = json_decode($holiday_periods_json, true) ?: [];

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="profile-form">
            <?php wp_nonce_field('update_notification_prefs', 'notification_prefs_nonce'); ?>
            <input type="hidden" name="action" value="update_notification_prefs">

            <h2><?php _e('Notification Preferences', 'ispag-crm'); ?></h2>

            <!-- Description -->
            <p class="ispag-notification-description">
                <?php _e('Choose the method(s) by which you wish to receive each type of notification.', 'ispag-crm'); ?>
            </p>

            <!-- Tableau des préférences -->
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
                                $current_channels = ISPAG_Notifications_Manager::get_user_channel_preferences($user_id, $type_key);
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
                            name="ispag_allow_weekend_notifications"
                            value="1"
                            <?php checked($allow_weekend, true); ?> />
                        <?php _e('Receive notifications on weekends (Saturday and Sunday)', 'ispag-crm'); ?>
                    </label>
                </div>

                <div class="ispag-holiday-periods">
                    <h5><?php _e('Holiday Periods', 'ispag-crm'); ?></h5>
                    <p class="description">
                        <?php _e('Add periods during which you do not want to receive notifications.', 'ispag-crm'); ?>
                    </p>
                    <div id="ispag-holiday-periods-container">
                        <?php if (!empty($holiday_periods)): ?>
                            <?php foreach ($holiday_periods as $index => $period): ?>
                                <div class="ispag-holiday-period" data-index="<?php echo $index; ?>">
                                    <div class="ispag-holiday-period-fields">
                                        <label>
                                            <?php _e('Start Date:', 'ispag-crm'); ?>
                                            <input type="date"
                                                name="ispag_holiday_periods[<?php echo $index; ?>][start]"
                                                value="<?php echo esc_attr($period['start']); ?>" />
                                        </label>
                                        <label>
                                            <?php _e('End Date:', 'ispag-crm'); ?>
                                            <input type="date"
                                                name="ispag_holiday_periods[<?php echo $index; ?>][end]"
                                                value="<?php echo esc_attr($period['end']); ?>" />
                                        </label>
                                        <button type="button" class="ispag-remove-holiday-period button button-secondary">
                                            <?php _e('Remove', 'ispag-crm'); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="ispag-holiday-period" data-index="0">
                                <div class="ispag-holiday-period-fields">
                                    <label>
                                        <?php _e('Start Date:', 'ispag-crm'); ?>
                                        <input type="date" name="ispag_holiday_periods[0][start]" />
                                    </label>
                                    <label>
                                        <?php _e('End Date:', 'ispag-crm'); ?>
                                        <input type="date" name="ispag_holiday_periods[0][end]" />
                                    </label>
                                    <button type="button" class="ispag-remove-holiday-period button button-secondary">
                                        <?php _e('Remove', 'ispag-crm'); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" id="ispag-add-holiday-period" class="button button-primary">
                        <?php _e('Add Holiday Period', 'ispag-crm'); ?>
                    </button>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php _e('Save preferences', 'ispag-crm'); ?>
                </button>
            </p>
        </form>

        <!-- JavaScript pour gérer les périodes de vacances -->
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
                                <input type="date" name="ispag_holiday_periods[${index}][start]" />
                            </label>
                            <label>
                                <?php _e('End Date:', 'ispag-crm'); ?>
                                <input type="date" name="ispag_holiday_periods[${index}][end]" />
                            </label>
                            <button type="button" class="ispag-remove-holiday-period button button-secondary">
                                <?php _e('Remove', 'ispag-crm'); ?>
                            </button>
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
                    $(this).find('input[name^="ispag_holiday_periods"]').each(function() {
                        const name = $(this).attr('name');
                        $(this).attr('name', name.replace(/ispag_holiday_periods\[\d+\]/, `ispag_holiday_periods[${i}]`));
                    });
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Traite la soumission du formulaire de préférences de notifications.
     */
    public static function handle_update_notification_prefs()
    {
        // Vérifier le nonce
        if (!isset($_POST['notification_prefs_nonce']) || !wp_verify_nonce($_POST['notification_prefs_nonce'], 'update_notification_prefs')) {
            wp_die(__('Security: Unauthorized action.', 'ispag-crm'));
        }

        // Vérifier que l'utilisateur est connecté
        if (!is_user_logged_in()) {
            wp_die(__('You must be logged in.', 'ispag-crm'));
        }

        $user_id = get_current_user_id();

        // Save les préférences de canaux
        if (isset($_POST['ispag_notif_prefs']) && is_array($_POST['ispag_notif_prefs'])) {
            $clean_prefs = [];
            $available_types = ISPAG_Notifications_Manager::get_available_notification_types();
            $available_channels = ISPAG_Notifications_Manager::get_available_channels();

            foreach ($_POST['ispag_notif_prefs'] as $type => $channels) {
                if (isset($available_types[$type]) && is_array($channels)) {
                    if (user_can($user_id, $available_types[$type]['capability'])) {
                        $clean_prefs[$type] = array_intersect($channels, array_keys($available_channels));
                    }
                }
            }
            update_user_meta($user_id, 'ispag_notif_prefs', $clean_prefs);
        }

        // Save les préférences de déconnexion
        $allow_weekend = isset($_POST['ispag_allow_weekend_notifications']) ? 1 : 0;
        update_user_meta($user_id, 'ispag_allow_weekend_notifications', $allow_weekend);

        $holiday_periods = isset($_POST['ispag_holiday_periods']) ? array_values($_POST['ispag_holiday_periods']) : [];
        update_user_meta($user_id, 'ispag_holiday_periods', json_encode($holiday_periods));

        // Rediriger avec un message de succès
        wp_redirect(add_query_arg('updated', 'true', wp_get_referer()));
        exit;
    }
}

// Initialiser la classe
ISPAG_Profile_Notifications_Handler::init();