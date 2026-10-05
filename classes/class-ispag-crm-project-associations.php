<?php
defined('ABSPATH') || exit;

/**
 * Entreprise et contacts d'un projet : ajout / retrait depuis les cartes de la page projet.
 *
 *  - Entreprise : colonne AssociatedCompanyID de achats_liste_commande (un seul identifiant ; si la colonne est de type texte,
 *    plusieurs identifiants séparés par des virgules sont acceptés).
 *  - Contacts : colonne AssociatedContactIDs (identifiants séparés par des virgules). Le PREMIER est le contact principal
 *    (déjà la convention du reste du code : listes de projets, e-mails…) ; « principal » = placé en tête de liste.
 *  Droit requis : manage_order ; nonce : ispag_crm_nonce.
 */
class ISPAG_Crm_Project_Associations {

    public function __construct() {
        add_action('wp_ajax_ispag_project_assoc_search', [$this, 'ajax_search']);
        add_action('wp_ajax_ispag_project_assoc_add',    [$this, 'ajax_add']);
        add_action('wp_ajax_ispag_project_assoc_remove', [$this, 'ajax_remove']);
        add_action('wp_ajax_ispag_project_assoc_primary', [$this, 'ajax_primary']);
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'achats_liste_commande';
    }

    private function guard() {
        if (!current_user_can('manage_order')) {
            wp_send_json_error(['message' => __('You are not allowed', 'creation-reservoir')], 403);
        }
        check_ajax_referer('ispag_crm_nonce', 'security');
    }

    private function project(int $deal_id) {
        global $wpdb;
        $row = $deal_id ? $wpdb->get_row($wpdb->prepare("SELECT id, AssociatedCompanyID, AssociatedContactIDs FROM {$this->table()} WHERE hubspot_deal_id = %d LIMIT 1", $deal_id)) : null;
        if (!$row) wp_send_json_error(['message' => 'Project not found.'], 404);
        return $row;
    }

    /** La colonne AssociatedCompanyID est-elle numérique (un seul identifiant) ? */
    private function company_is_single(): bool {
        global $wpdb;
        $type = strtolower((string) $wpdb->get_var("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . esc_sql($this->table()) . "' AND COLUMN_NAME = 'AssociatedCompanyID'"));
        return in_array($type, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true);
    }

    private static function id_list($raw): array {
        return array_values(array_unique(array_filter(array_map('absint', array_map('trim', explode(',', (string) $raw))))));
    }

    /** Recherche d'une entreprise ou d'un contact à associer. */
    public function ajax_search() {
        $this->guard();
        global $wpdb;
        $type    = sanitize_key($_POST['type'] ?? '');
        $term    = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
        $deal_id = absint($_POST['deal_id'] ?? 0);
        $project = $this->project($deal_id);
        $out     = [];

        if ($type === 'company') {
            $taken = self::id_list($project->AssociatedCompanyID);
            $table = $wpdb->prefix . 'ispag_companies';
            $where = $term !== '' ? $wpdb->prepare('WHERE company_name LIKE %s', '%' . $wpdb->esc_like($term) . '%') : '';
            foreach ((array) $wpdb->get_results("SELECT Id, company_name, city FROM $table $where ORDER BY company_name ASC LIMIT 30") as $c) {
                if (in_array((int) $c->Id, $taken, true)) continue;
                $out[] = ['id' => (int) $c->Id, 'name' => (string) $c->company_name, 'sub' => (string) $c->city];
            }
        } elseif ($type === 'contact') {
            $taken      = self::id_list($project->AssociatedContactIDs);
            $company_id = (int) (self::id_list($project->AssociatedCompanyID)[0] ?? 0);
            $only_co    = !empty($_POST['company_only']) && $company_id;
            $args = ['number' => 30, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => ['ID', 'display_name', 'user_email'],
                     'exclude' => $taken, 'search_columns' => ['user_login', 'user_email', 'display_name']];
            if ($term !== '') $args['search'] = '*' . $term . '*';
            if ($only_co) {
                $args['meta_query'] = [['key' => ISPAG_Crm_Company_Constants::META_COMPANY_ID, 'value' => '(^|,)\s*' . $company_id . '\s*(,|$)', 'compare' => 'REGEXP']];
            }
            foreach ((new WP_User_Query($args))->get_results() as $u) {
                $out[] = ['id' => (int) $u->ID, 'name' => (string) $u->display_name, 'sub' => (string) $u->user_email];
            }
        } else {
            wp_send_json_error(['message' => 'Invalid type.']);
        }
        wp_send_json_success(['results' => $out, 'has_company' => !empty(self::id_list($project->AssociatedCompanyID)), 'company_single' => $this->company_is_single()]);
    }

    public function ajax_add() {
        $this->guard();
        global $wpdb;
        $type    = sanitize_key($_POST['type'] ?? '');
        $id      = absint($_POST['id'] ?? 0);
        $deal_id = absint($_POST['deal_id'] ?? 0);
        $project = $this->project($deal_id);
        if (!$id) wp_send_json_error(['message' => 'Missing id.']);

        if ($type === 'company') {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT Id FROM {$wpdb->prefix}ispag_companies WHERE Id = %d", $id));
            if (!$exists) wp_send_json_error(['message' => 'Company not found.']);
            $value = $this->company_is_single() ? (string) $id : implode(',', array_unique(array_merge(self::id_list($project->AssociatedCompanyID), [$id])));
            $wpdb->update($this->table(), ['AssociatedCompanyID' => $value], ['id' => $project->id]);
        } elseif ($type === 'contact') {
            if (!get_userdata($id)) wp_send_json_error(['message' => 'Contact not found.']);
            $ids = array_unique(array_merge(self::id_list($project->AssociatedContactIDs), [$id]));
            $wpdb->update($this->table(), ['AssociatedContactIDs' => implode(',', $ids)], ['id' => $project->id]);
        } else {
            wp_send_json_error(['message' => 'Invalid type.']);
        }
        do_action('ispag_project_association_changed', $deal_id, $type, $id, 'add');
        wp_send_json_success();
    }

    public function ajax_remove() {
        $this->guard();
        global $wpdb;
        $type    = sanitize_key($_POST['type'] ?? '');
        $id      = absint($_POST['id'] ?? 0);
        $deal_id = absint($_POST['deal_id'] ?? 0);
        $project = $this->project($deal_id);

        if ($type === 'company') {
            $left  = array_values(array_diff(self::id_list($project->AssociatedCompanyID), [$id]));
            $value = $this->company_is_single() ? (string) ($left[0] ?? 0) : implode(',', $left);
            $wpdb->update($this->table(), ['AssociatedCompanyID' => $value], ['id' => $project->id]);
        } elseif ($type === 'contact') {
            $left = array_diff(self::id_list($project->AssociatedContactIDs), [$id]);
            $wpdb->update($this->table(), ['AssociatedContactIDs' => implode(',', $left)], ['id' => $project->id]);
        } else {
            wp_send_json_error(['message' => 'Invalid type.']);
        }
        do_action('ispag_project_association_changed', $deal_id, $type, $id, 'remove');
        wp_send_json_success();
    }

    /** Définit le contact principal : son identifiant passe en tête de la liste. */
    public function ajax_primary() {
        $this->guard();
        global $wpdb;
        $id      = absint($_POST['id'] ?? 0);
        $deal_id = absint($_POST['deal_id'] ?? 0);
        $project = $this->project($deal_id);
        $ids     = self::id_list($project->AssociatedContactIDs);
        if (!in_array($id, $ids, true)) wp_send_json_error(['message' => 'Contact not linked to this project.']);

        $ordered = array_merge([$id], array_values(array_diff($ids, [$id])));
        $wpdb->update($this->table(), ['AssociatedContactIDs' => implode(',', $ordered)], ['id' => $project->id]);
        do_action('ispag_project_association_changed', $deal_id, 'contact', $id, 'primary');
        wp_send_json_success();
    }
}
