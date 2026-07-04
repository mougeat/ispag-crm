<?php

/**
 * Classe ISPAG_Template_Repository
 * Gère l'accès aux données, la sauvegarde et l'enregistrement des scripts/styles pour les templates.
 */
class ISPAG_Template_Repository {

    // Définition des constantes pour les noms de tables
    const TABLE_TEMPLATES = 'wor9711_ispag_templates';
    const TABLE_FOLDERS   = 'wor9711_ispag_template_folders';

    public function __construct() {
        // On accroche le chargement des scripts et styles sur l'admin WordPress
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Enregistre et localise les fichiers JS et CSS pour les templates
     */
    public function enqueue_scripts($hook) {
        $plugin_root_url = plugin_dir_url(dirname(__FILE__)); 

        // Chargement du CSS
        wp_enqueue_style(
            'ispag-templates-css', 
            $plugin_root_url . 'assets/css/ispag-templates.css', 
            array(), 
            '1.2'
        );

        // Chargement du JS
        wp_enqueue_script(
            'ispag-templates-js', 
            $plugin_root_url . 'assets/js/ispag-templates.js', 
            array('jquery', 'jquery-ui-draggable'),
            '1.3', 
            true
        );

        // Transmission des variables et traductions vers le JS
        wp_localize_script('ispag-templates-js', 'ispag_crm_obj', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ispag_crm_nonce'),
            'current_contact_id' => isset($_GET['contact_id']) ? intval($_GET['contact_id']) : 0,
            'current_user_id' => get_current_user_id(), // Ajouté ici
            'i18n' => array(
                'save_success' => __('Template saved successfully!', 'ispag-crm'),
                'save_error'   => __('Error while saving the template.', 'ispag-crm'),
                'confirm_del'  => __('Are you sure you want to delete this template?', 'ispag-crm'),
                'loading'      => __('Loading...', 'ispag-crm')
            )
        ));
    }

    /**
     * Récupère un template par son ID
     */
    public function get_template($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::TABLE_TEMPLATES . " WHERE id = %d",
            $id
        ));
    }

    /**
     * Récupère les templates accessibles (Communs + Personnels)
     */
    public function get_templates_for_user($user_id, $lang = '') {
        global $wpdb;

        // FIX : On initialise $and pour éviter le "Undefined variable"
        $and = '';
        $params = [$user_id];

        if(!empty ($lang)){
            $and = ' AND t.language = %s';
            $params[] = $lang; // On ajoute la langue aux paramètres du prepare
        }
        
        $sql = "SELECT t.*, f.name as folder_name 
                FROM " . self::TABLE_TEMPLATES . " t
                LEFT JOIN " . self::TABLE_FOLDERS . " f ON t.folder_id = f.id
                WHERE (t.owner_id IS NULL OR t.owner_id = %d)
                {$and}
                ORDER BY f.name ASC, t.name ASC";

        // Utilisation dynamique de prepare avec l'étalement d'argument (...)
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Récupère tous les dossiers disponibles pour un utilisateur
     */
    public function get_folders($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::TABLE_FOLDERS . " 
             WHERE owner_id IS NULL OR owner_id = %d 
             ORDER BY name ASC",
            $user_id
        ));
    }

    /**
     * Sauvegarde ou mise à jour d'un template
     */
    public function save_template($data) {
        global $wpdb;
        
        $id = isset($data['id']) ? intval($data['id']) : 0;
        unset($data['id']); 

        if ($id > 0) {
            return $wpdb->update(self::TABLE_TEMPLATES, $data, array('id' => $id));
        } else {
            return $wpdb->insert(self::TABLE_TEMPLATES, $data);
        }
    }

    /**
     * Remplace les tags {{variable}} par les valeurs réelles
     */
    public function parse_tags($content, $vars = array()) {
        if (empty($vars)) return $content;

        foreach ($vars as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }
        
        return $content;
    }
}