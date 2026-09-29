<?php
defined('ABSPATH') || exit;

/**
 * Création d'une entreprise du CRM.
 *
 * Une seule logique, deux points d'entrée :
 *  - le formulaire d'administration (« CRM ISPAG → Add Company », ISPAG_Entreprise_Manager) ;
 *  - le panneau « Créer une entreprise » de la page publique (action AJAX ispag_create_company).
 *
 * Règles : nom obligatoire ; pas de doublon (même domaine ou même viag_id) ; sans viag_id, un identifiant provisoire
 * (plage 90000-99999, comme la création d'un contact) est attribué ; la ville est écrite aux trois endroits où le CRM la lit.
 */
class ISPAG_Crm_Company_Creator {

    const NONCE_ACTION = 'ispag_new_company_nonce';

    public function __construct() {
        add_action('wp_ajax_ispag_create_company', array(self::class, 'ajax_create'));
    }

    /**
     * Qui peut créer une entreprise depuis la page publique : le droit CRM add_company, ou le droit « équipe ISPAG »
     * manage_order (qui permet déjà de créer des contacts, donc des entreprises par leur domaine).
     * Modifiable : add_filter('ispag_crm_can_create_company', fn($ok) => ..., 10, 1).
     */
    public static function can_create() {
        return (bool) apply_filters('ispag_crm_can_create_company', is_user_logged_in() && (current_user_can('add_company') || current_user_can('manage_order')));
    }

    /** « https://www.Exemple.ch/page », « info@exemple.ch » ou « exemple.ch » → « exemple.ch ». */
    public static function normalize_domain($raw) {
        $d = strtolower(trim((string) $raw));
        if ($d === '') return '';
        if (strpos($d, '@') !== false) $d = substr($d, strrpos($d, '@') + 1);
        $d = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $d);
        $d = preg_replace('#^www\.#', '', $d);
        $parts = preg_split('#[/?\\#\s]#', $d);
        return sanitize_text_field($parts[0]);
    }

    /** Prochain viag_id provisoire (plage 90000-99999). */
    public static function next_provisional_viag_id() {
        global $wpdb;
        $last = $wpdb->get_var("SELECT MAX(viag_id) FROM {$wpdb->prefix}ispag_companies WHERE viag_id >= 90000 AND viag_id < 100000");
        return $last ? (int) $last + 1 : 90001;
    }

    /** Écrit une méta d'entreprise dans ispag_companies_meta (met à jour la ligne existante, sinon l'ajoute). */
    public static function save_company_meta($company_id, $key, $value) {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'ispag_companies_meta';
        $meta_id = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$meta_table} WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $company_id, $key));
        if ($meta_id) {
            $wpdb->update($meta_table, array('meta_value' => $value), array('meta_id' => $meta_id));
        } else {
            $wpdb->insert($meta_table, array('company_id' => $company_id, 'meta_key' => $key, 'meta_value' => $value));
        }
    }

    /** La ville est lue selon l'écran dans la colonne city, dans ispag_companies_meta ou dans les postmeta : on écrit aux trois. */
    public static function save_city($company_id, $city) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'ispag_companies', array('city' => $city), array('Id' => $company_id));
        self::save_company_meta($company_id, 'ispag_company_city', $city);
        update_post_meta($company_id, 'ispag_company_city', $city);
    }

    /**
     * @param array $fields company_name*, compagny_domain, city, phone, email, viag_id, isSupplier, isIngenieur, is_active (données brutes de $_POST acceptées)
     * @return array ['status' => created|exists|error_name|error_email|error_db, 'id'?, 'viag_id'?, 'existing_id'?]
     */
    public static function create(array $fields) {
        global $wpdb;
        $table = $wpdb->prefix . 'ispag_companies';

        $name   = sanitize_text_field(wp_unslash(isset($fields['company_name']) ? $fields['company_name'] : ''));
        $domain = self::normalize_domain(wp_unslash(isset($fields['compagny_domain']) ? $fields['compagny_domain'] : ''));
        $city   = sanitize_text_field(wp_unslash(isset($fields['city']) ? $fields['city'] : ''));
        $phone  = sanitize_text_field(wp_unslash(isset($fields['phone']) ? $fields['phone'] : ''));
        $email  = sanitize_email(wp_unslash(isset($fields['email']) ? $fields['email'] : ''));
        $viag_id = isset($fields['viag_id']) ? absint($fields['viag_id']) : 0;

        if ($name === '') return array('status' => 'error_name');
        if (!empty($fields['email']) && $email === '') return array('status' => 'error_email');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT Id, viag_id FROM {$table} WHERE (%d > 0 AND viag_id = %d) OR (%s <> '' AND compagny_domain = %s) LIMIT 1",
            $viag_id, $viag_id, $domain, $domain
        ));
        if ($existing) return array('status' => 'exists', 'existing_id' => (int) $existing->Id, 'viag_id' => (int) $existing->viag_id);

        $viag_id = $viag_id > 0 ? $viag_id : self::next_provisional_viag_id();
        $inserted = $wpdb->insert($table, array(
            'viag_id'         => $viag_id,
            'company_name'    => $name,
            'compagny_domain' => $domain,
            'phone'           => $phone,
            'email'           => $email,
            'isSupplier'      => !empty($fields['isSupplier']) ? 1 : 0,
            'isIngenieur'     => !empty($fields['isIngenieur']) ? 1 : 0,
            'is_active'       => isset($fields['is_active']) ? absint($fields['is_active']) : 1,
            'created_at'      => current_time('mysql'),
        ));
        if ($inserted === false) return array('status' => 'error_db');

        $id = (int) $wpdb->insert_id;
        self::save_city($id, $city);
        return array('status' => 'created', 'id' => $id, 'viag_id' => $viag_id);
    }

    /** Action AJAX du panneau « Créer une entreprise » de la page publique. */
    public static function ajax_create() {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'ispag-crm')), 403);
        }
        if (!self::can_create()) {
            wp_send_json_error(array('message' => __('You are not allowed to create companies.', 'ispag-crm')), 403);
        }

        // La page publique ne gère que les clients : la fiche publique masque les fournisseurs (isSupplier = 1).
        $fields = $_POST;
        $fields['isSupplier'] = 0;
        $result = self::create($fields);

        switch ($result['status']) {
            case 'created':
                wp_send_json_success(array(
                    'message'      => __('Company created.', 'ispag-crm'),
                    'redirect_url' => home_url('/company/' . $result['viag_id'] . '/'),
                ));
            case 'exists':
                wp_send_json_error(array(
                    'message'      => __('A company with this domain or Viag ID already exists.', 'ispag-crm'),
                    'existing_url' => home_url('/company/' . $result['viag_id'] . '/'),
                ));
            case 'error_name':
                wp_send_json_error(array('message' => __('The company name is required.', 'ispag-crm')));
            case 'error_email':
                wp_send_json_error(array('message' => __('This email address is not valid.', 'ispag-crm')));
            default:
                wp_send_json_error(array('message' => __('The company could not be saved.', 'ispag-crm')));
        }
    }
}
