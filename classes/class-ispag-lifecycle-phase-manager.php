<?php
/**
 * Classe ISPAG_Lifecycle_Phase_Manager (Version UI Utilisateurs uniquement)
 * Gère l'affichage des phases sur les profils et les listes d'utilisateurs.
 */

class ISPAG_Lifecycle_Phase_Manager {

    public function __construct() {
        // --- 1. Interface Profil Utilisateur (Individuel) ---
        add_action('show_user_profile', array($this, 'add_lifecycle_phase_field'));
        add_action('edit_user_profile', array($this, 'add_lifecycle_phase_field'));
        add_action('personal_options_update', array($this, 'save_lifecycle_phase_field'));
        add_action('edit_user_profile_update', array($this, 'save_lifecycle_phase_field'));

        // --- 2. Liste des Utilisateurs (Tableau WordPress) ---
        add_filter('manage_users_columns', array($this, 'add_user_list_column'));
        add_filter('manage_users_custom_column', array($this, 'display_user_list_column'), 10, 3);
        
        // --- 3. Modification Rapide (Quick Edit) ---
        add_action('quick_edit_custom_box', array($this, 'add_quick_edit_field'), 10, 2);
        add_action('admin_footer', array($this, 'add_quick_edit_javascript'));
    }

    /**
     * Affiche le champ "Phase" dans la page d'édition du profil utilisateur.
     */
    public function add_lifecycle_phase_field($user) {
        if (!current_user_can('edit_user', $user->ID)) return;

        $current_phase = get_user_meta($user->ID, ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE, true);
        $phases = ISPAG_Cron_Lifecycle::get_phases_for_select();
        ?>
        <h3><?php _e('ISPAG Lifecycle', 'ispag-crm'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="<?php echo ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE; ?>"><?php _e('Lifecycle phase', 'ispag-crm'); ?></label></th>
                <td>
                    <select name="<?php echo ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE; ?>" id="<?php echo ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE; ?>">
                        <option value="">— <?php _e('Select Phase', 'ispag-crm'); ?> —</option>
                        <?php foreach ($phases as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($current_phase, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php _e('Phase manually defined or updated by the daily CRON job.', 'ispag-crm'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Sauvegarde la phase sélectionnée manuellement dans le profil.
     */
    public function save_lifecycle_phase_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) return;

        if (isset($_POST[ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE])) {
            update_user_meta($user_id, ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE, sanitize_key($_POST[ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE]));
        }
    }

    /**
     * Ajoute la colonne "Phase" dans le tableau de la liste des utilisateurs.
     */
    public function add_user_list_column($columns) {
        $columns['ispag_lifecycle_phase'] = __('Phase', 'ispag-crm');
        return $columns;
    }

    /**
     * Affiche la valeur de la phase pour chaque utilisateur dans la liste.
     */
    public function display_user_list_column($output, $column_name, $user_id) {
        if ($column_name !== 'ispag_lifecycle_phase') return $output;

        $key = get_user_meta($user_id, ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE, true);
        $phases = ISPAG_Cron_Lifecycle::get_phases_for_select();

        if (isset($phases[$key])) {
            // On ajoute un ID et un data-attribute pour que le JS du Quick Edit puisse lire la valeur
            return sprintf(
                '<span data-phase-key="%s" id="ispag-phase-val-%d">%s</span>',
                esc_attr($key),
                absint($user_id),
                esc_html($phases[$key])
            );
        }

        return '<span data-phase-key="" id="ispag-phase-val-'.absint($user_id).'">—</span>';
    }

    /**
     * Ajoute le champ de sélection dans l'interface de Modification Rapide (Quick Edit).
     */
    public function add_quick_edit_field($column_name, $post_type) {
        if ($column_name !== 'ispag_lifecycle_phase') return;
        
        $phases = ISPAG_Cron_Lifecycle::get_phases_for_select();
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="alignleft">
                    <span class="title"><?php _e('Phase', 'ispag-crm'); ?></span>
                    <select name="<?php echo ISPAG_Cron_Lifecycle::META_LIFECYCLE_PHASE; ?>" class="ispag-phase-quick-edit">
                        <option value="">— <?php _e('No change / None', 'ispag-crm'); ?> —</option>
                        <?php foreach ($phases as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Script pour injecter la valeur actuelle de la phase dans le champ Quick Edit lors du clic sur "Modifier".
     */
    public function add_quick_edit_javascript() {
        global $current_screen;
        if (!$current_screen || $current_screen->id !== 'users') return;
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Au clic sur "Modifier" (Quick Edit)
                $('#the-list').on('click', '.editinline', function() {
                    // On récupère l'ID de l'utilisateur depuis la ligne du tableau
                    var user_id = $(this).closest('tr').attr('id').replace('user-', '');
                    // On récupère la clé de la phase stockée dans la colonne
                    var current_key = $('#ispag-phase-val-' + user_id).data('phase-key');
                    
                    // On définit la valeur du select dans le formulaire de modification rapide
                    $('.ispag-phase-quick-edit').val(current_key || '');
                });
            });
        </script>
        <?php
    }
}