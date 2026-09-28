<?php
/**
 * Classe ISPAG_Mail_Template_API
 * Expose les templates de ISPAG_Template_Repository pour l'add-in Outlook.
 */
class ISPAG_Mail_Template_API {

    private $wpdb;
    private $table_templates;
    private $table_folders;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_templates = $wpdb->prefix . 'ispag_templates';
        $this->table_folders   = $wpdb->prefix . 'ispag_template_folders';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('rest_api_init', [self::$instance, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('ispag/v1', '/mail-templates', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_templates'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route('ispag/v1', '/mail-template-folders', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_folders'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route('ispag/v1', '/deals-search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'search_deals'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route('ispag/v1', '/deal-context', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_deal_context'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route('ispag/v1', '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [$this, 'login'],
            'permission_callback' => '__return_true', // public, c'est le login lui-même
        ]);

        register_rest_route('ispag/v1', '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_current_user'],
            'permission_callback' => [$this, 'check_auth'],
        ]); 
    }

    /**
     * Auth : on résout l'utilisateur ISPAG à partir d'une clé API personnelle,
     * pour pouvoir filtrer les templates privés (owner_id) correctement.
     * (Alternative envisageable plus tard : SSO Office + JWT WordPress)
     */
    public function check_auth($request) {
        $sent_key = $request->get_header('x-ispag-api-key');
        if (empty($sent_key)) return false;

        $user = $this->get_user_by_api_key($sent_key);
        if (!$user) return false;

        // On stocke l'utilisateur résolu pour la suite de la requête
        $request->set_param('_ispag_user_id', $user->ID);
        return true;
    }

    private function get_user_by_api_key($key) {
        // Simplification : clé stockée en usermeta 'ispag_outlook_api_key'
        $users = get_users([
            'meta_key'   => 'ispag_outlook_api_key',
            'meta_value' => $key,
            'number'     => 1,
        ]);
        return $users ? $users[0] : null;
    }

    public function get_templates($request) {
        $lang      = sanitize_text_field($request->get_param('lang') ?: 'fr');
        $folder_id = $request->get_param('folder_id');
        $user_id   = intval($request->get_param('_ispag_user_id'));

        $sql = "SELECT t.id, t.folder_id, t.owner_id, t.language, t.name, t.subject, t.content, f.name AS folder_name
                FROM {$this->table_templates} t
                LEFT JOIN {$this->table_folders} f ON f.id = t.folder_id
                WHERE t.language = %s
                AND (t.owner_id IS NULL OR t.owner_id = %d)";
        $params = [$lang, $user_id];

        if (!empty($folder_id)) {
            $sql .= " AND t.folder_id = %d";
            $params[] = intval($folder_id);
        }

        $sql .= " ORDER BY f.name ASC, t.name ASC";

        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params));
        return rest_ensure_response($results);
    }

    public function get_folders($request) {
        $user_id = intval($request->get_param('_ispag_user_id'));

        $sql = "SELECT id, name, owner_id FROM {$this->table_folders}
                WHERE owner_id IS NULL OR owner_id = %d
                ORDER BY name ASC";

        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $user_id));
        return rest_ensure_response($results);
    }

    public function search_deals($request) {
        $query = sanitize_text_field($request->get_param('q'));
        if (strlen($query) < 2) {
            return rest_ensure_response([]);
        }

        global $wpdb;
        $table_projects = $wpdb->prefix . 'achats_liste_commande';
        $like = '%' . $wpdb->esc_like($query) . '%';

        $sql = $wpdb->prepare("
            SELECT hubspot_deal_id, NumCommande, ObjetCommande
            FROM {$table_projects}
            WHERE ObjetCommande LIKE %s OR NumCommande LIKE %s
            ORDER BY TimestampDateCommande DESC
            LIMIT 15
        ", $like, $like);

        $results = $wpdb->get_results($sql);
        return rest_ensure_response($results);
    }

    public function get_deal_context($request) {
        $deal_id = intval($request->get_param('deal_id'));
        if (!$deal_id) {
            return new WP_Error('missing_deal_id', 'deal_id manquant', ['status' => 400]);
        }

        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        if (!$project) {
            return new WP_Error('not_found', 'Projet introuvable', ['status' => 404]);
        }

        // Contact principal — à adapter selon où ISPAG stocke le contact lié au deal
        $contact = apply_filters('ispag_get_deal_main_contact', null, $deal_id);

        $context = [
            'deal_name'           => $project->ObjetCommande ?? '',
            'deal_offer_num'      => $project->NumCommande ?? '',
            'deal_total'          => $project->Total ?? '',
            'contact_first_name'  => $contact->first_name ?? '',
            'contact_last_name'   => $contact->last_name ?? '',
            'contact_full_name'   => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'contact_email'       => $contact->email ?? '',
            'contact_phone'       => $contact->phone ?? '',
            'company_name'        => $contact->company_name ?? '',
        ];

        return rest_ensure_response($context);
    }

    public function login($request) {
        $username = sanitize_text_field($request->get_param('username'));
        $password = $request->get_param('password'); // ne pas sanitize un mot de passe

        if (empty($username) || empty($password)) {
            return new WP_Error('missing_credentials', 'Identifiants manquants', ['status' => 400]);
        }

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return new WP_Error('invalid_credentials', 'Identifiants incorrects', ['status' => 401]);
        }

        // Génère un token s'il n'existe pas encore, sinon réutilise l'existant
        $token = get_user_meta($user->ID, 'ispag_outlook_api_key', true);
        if (empty($token)) {
            $token = wp_generate_password(40, false);
            update_user_meta($user->ID, 'ispag_outlook_api_key', $token);
        }

        return rest_ensure_response([
            'token'        => $token,
            'display_name' => $user->display_name,
            'user_id'      => $user->ID,
        ]);
    }

    public function get_current_user($request) {
        $user_id = intval($request->get_param('_ispag_user_id'));
        $user = get_userdata($user_id);

        return rest_ensure_response([
            'display_name' => $user->display_name,
            'user_id'      => $user->ID,
        ]);
    }
}

// function fillTemplate(content, context) {
//     return content.replace(/\{\{(\w+)\}\}/g, (match, key) => {
//         return context[key] !== undefined ? context[key] : match; // garde {{xxx}} si pas trouvé
//     });
// }