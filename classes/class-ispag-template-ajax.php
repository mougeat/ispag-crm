<?php

/**
 * Classe ISPAG_Template_AJAX
 * Gère toutes les requêtes asynchrones pour les templates ISPAG.
 */
class ISPAG_Template_AJAX {

    public function __construct() {
        // Hook pour récupérer un template (édition)
        add_action('wp_ajax_ispag_get_template_raw', array($this, 'get_template_raw'));
        
        // Hook pour sauvegarder/créer un template
        add_action('wp_ajax_ispag_save_template', array($this, 'save_template'));

        // Hook pour supprimer un template
        add_action('wp_ajax_ispag_delete_template', array($this, 'delete_template'));

        // Hook pour ajouter un folder
        add_action('wp_ajax_ispag_save_folder', array($this, 'save_folder'));
    }

    /**
     * Récupère les données brutes d'un template pour les charger dans la modal d'édition
     */
    public function get_template_raw() {
        check_ajax_referer('ispag_crm_nonce', 'security');

        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        
        if (!$template_id) {
            wp_send_json_error(__('Invalid Template ID.', 'ispag-crm'));
        }

        $repo = new ISPAG_Template_Repository();
        $template = $repo->get_template($template_id);

        if ($template) {
            wp_send_json_success($template);
        } else {
            wp_send_json_error(__('Template not found.', 'ispag-crm'));
        }
    }

    /**
     * Sauvegarde ou met à jour un template
     */
    public function save_template() {
        check_ajax_referer('ispag_crm_nonce', 'security');

        $repo = new ISPAG_Template_Repository();
        $current_user_id = get_current_user_id();

        // Récupération et nettoyage des données
        $id          = isset($_POST['id']) ? intval($_POST['id']) : 0;
        // On retire les slashs ajoutés par WordPress avant le nettoyage
        $name        = sanitize_text_field(wp_unslash($_POST['name']));
        $subject     = sanitize_text_field(wp_unslash($_POST['subject']));
        $content     = wp_kses_post(wp_unslash($_POST['content']));
        $folder_id   = !empty($_POST['folder_id']) ? intval($_POST['folder_id']) : null;
        $language    = sanitize_text_field($_POST['language']);
        $is_personal = isset($_POST['is_personal']) ? true : false;

        // --- SÉCURITÉ : Vérification des droits d'édition ---
        if ($id > 0) {
            $existing = $repo->get_template($id);
            if (!$existing) {
                wp_send_json_error(__('Template to update not found.', 'ispag-crm'));
            }

            // Si c'est un template commun (owner_id NULL) et que l'user n'est pas admin
            if (is_null($existing->owner_id) && !current_user_can('administrator')) {
                wp_send_json_error(__('Permission denied: Only administrators can modify common templates.', 'ispag-crm'));
            }
            
            // Si c't un template privé d'un autre utilisateur
            if (!is_null($existing->owner_id) && $existing->owner_id != $current_user_id && !current_user_can('administrator')) {
                wp_send_json_error(__('Permission denied: This is a private template.', 'ispag-crm'));
            }
        }

        // Préparation des données pour l'insertion/maj
        $data = array(
            'id'         => $id,
            'name'       => $name,
            'folder_id'  => $folder_id,
            'language'   => $language,
            'subject'    => $subject,
            'content'    => $content,
            'owner_id'   => $is_personal ? $current_user_id : null,
            'updated_at' => current_time('mysql')
        );

        if ($id === 0) {
            $data['created_at'] = current_time('mysql');
        }

        $result = $repo->save_template($data);

        if ($result !== false) {
            wp_send_json_success(__('Template saved successfully.', 'ispag-crm'));
        } else {
            wp_send_json_error(__('Database error during save.', 'ispag-crm'));
        }
    }

    /**
     * Supprime un template
     */
    public function delete_template() {
        check_ajax_referer('ispag_crm_nonce', 'security');

        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $repo = new ISPAG_Template_Repository();
        $existing = $repo->get_template($template_id);

        if (!$existing) {
            wp_send_json_error(__('Template not found.', 'ispag-crm'));
        }

        // Vérification des droits (Admin ou Propriétaire)
        if (!current_user_can('administrator') && $existing->owner_id != get_current_user_id()) {
            wp_send_json_error(__('You do not have permission to delete this template.', 'ispag-crm'));
        }

        global $wpdb;
        $deleted = $wpdb->delete($repo::TABLE_TEMPLATES, array('id' => $template_id), array('%d'));

        if ($deleted) {
            wp_send_json_success(__('Template deleted.', 'ispag-crm'));
        } else {
            wp_send_json_error(__('Error during deletion.', 'ispag-crm'));
        }
    }


    public function save_folder() {
        check_ajax_referer('ispag_crm_nonce', 'security');

        $folder_name = sanitize_text_field($_POST['folder_name']);
        $is_personal = isset($_POST['is_personal']) && $_POST['is_personal'] == '1';
        $current_user_id = get_current_user_id();

        if (empty($folder_name)) {
            wp_send_json_error(__('Name is required', 'ispag-crm'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ispag_template_folders';

        $result = $wpdb->insert(
            $table,
            array(
                'name'       => $folder_name,
                'owner_id'   => $is_personal ? $current_user_id : null, // NULL si l'admin a décoché
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s') // %d pour l'ID (integer ou null)
        );

        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(__('Database error', 'ispag-crm'));
        }
    }
}