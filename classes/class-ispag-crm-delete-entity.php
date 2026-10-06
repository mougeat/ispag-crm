<?php
defined('ABSPATH') || exit;

/**
 * Suppression d'un contact ou d'une entreprise depuis sa fiche détail — réservée aux administrateurs, avec confirmation.
 *
 * Deux étapes : « preview » (liste ce qui est lié et dit si la suppression est possible) puis « delete ».
 * Un contact ou une entreprise encore rattaché à une offre, un projet ou une commande n'est PAS supprimé : il faut d'abord
 * les détacher, pour ne jamais laisser de références cassées. Les notes/tâches, propriétaires et remises sont supprimés avec la fiche.
 */
class ISPAG_Crm_Delete_Entity {

    const NONCE = 'ispag_crm_delete_entity';

    public function __construct() {
        add_action('wp_ajax_ispag_crm_delete_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_ispag_crm_delete_entity',  [$this, 'ajax_delete']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public static function allowed(): bool { return is_user_logged_in() && current_user_can('manage_options'); }

    public function enqueue() {
        if (!self::allowed() || is_admin()) return;
        $f = ISPAG_CRM_PLUGIN_DIR . 'assets/js/ispag-delete-entity.js';
        wp_enqueue_script('ispag-delete-entity', ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-delete-entity.js', ['jquery'], (int) @filemtime($f), true);
        wp_localize_script('ispag-delete-entity', 'ispagDeleteEntity', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE),
            'i18n'     => [
                'title_contact' => __('Delete this contact?', 'ispag-crm'),
                'title_company' => __('Delete this company?', 'ispag-crm'),
                'will_delete'   => __('The following will be deleted with it:', 'ispag-crm'),
                'irreversible'  => __('This cannot be undone.', 'ispag-crm'),
                'blocked'       => __('Deletion is not possible while it is still linked to:', 'ispag-crm'),
                'detach_first'  => __('Detach or reassign these first, then try again.', 'ispag-crm'),
                'confirm'       => __('Delete permanently', 'ispag-crm'),
                'cancel'        => __('Cancel', 'ispag-crm'),
                'close'         => __('Close', 'ispag-crm'),
                'loading'       => __('Loading…', 'ispag-crm'),
                'error'         => __('An error occurred.', 'ispag-crm'),
                'deleted'       => __('Deleted.', 'ispag-crm'),
            ],
        ]);
    }

    private function guard(): array {
        if (!self::allowed()) wp_send_json_error(['message' => __('Administrators only.', 'ispag-crm')], 403);
        if (!wp_verify_nonce((string) ($_POST['nonce'] ?? ''), self::NONCE)) wp_send_json_error(['message' => __('Session expired, reload the page.', 'ispag-crm')], 403);
        $type = (($_POST['entity'] ?? '') === 'company') ? 'company' : 'contact';
        $id   = absint($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid request.', 'ispag-crm')], 400);
        return [$type, $id];
    }

    private static function table_exists(string $t): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
    }

    /** @return array{name:string, blockers:string[], removes:string[]} */
    private function inspect(string $type, int $id): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $notes = $p . 'ispag_contact_notes';
        $blockers = []; $removes = []; $name = '';

        if ($type === 'contact') {
            $u = get_userdata($id);
            if (!$u) wp_send_json_error(['message' => __('Contact not found.', 'ispag-crm')], 404);
            $name = $u->display_name;
            if ($id === get_current_user_id())   $blockers[] = __('your own account', 'ispag-crm');
            if (user_can($u, 'manage_options'))  $blockers[] = __('an administrator account', 'ispag-crm');
            $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$deals} WHERE FIND_IN_SET(%d, REPLACE(associated_contact_ids, ' ', '')) > 0", $id));
            if ($n) $blockers[] = sprintf(_n('%d offer / deal', '%d offers / deals', $n, 'ispag-crm'), $n);
            $pt = $p . 'achats_liste_commande';
            if (self::table_exists($pt)) {
                $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pt} WHERE FIND_IN_SET(%d, REPLACE(AssociatedContactIDs, ' ', '')) > 0", $id));
                if ($n) $blockers[] = sprintf(_n('%d project', '%d projects', $n, 'ispag-crm'), $n);
            }
            $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$notes} WHERE FIND_IN_SET(%d, REPLACE(contact_id, ' ', '')) > 0", $id));
            if ($n) $removes[] = sprintf(_n('%d note / task', '%d notes / tasks', $n, 'ispag-crm'), $n);
        } else {
            $c = $wpdb->get_row($wpdb->prepare("SELECT company_name FROM {$p}ispag_companies WHERE Id = %d", $id));
            if (!$c) wp_send_json_error(['message' => __('Company not found.', 'ispag-crm')], 404);
            $name = (string) $c->company_name;
            $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", ISPAG_Crm_Contact_Constants::META_COMPANY_ID, (string) $id));
            if ($n) $blockers[] = sprintf(_n('%d contact', '%d contacts', $n, 'ispag-crm'), $n);
            $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$deals} WHERE associated_company_id = %d", $id));
            if ($n) $blockers[] = sprintf(_n('%d offer / deal', '%d offers / deals', $n, 'ispag-crm'), $n);
            $po = $p . 'achats_commande_liste_fournisseurs';
            if (self::table_exists($po)) {
                $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$po} WHERE IdFournisseur = %d", $id));
                if ($n) $blockers[] = sprintf(_n('%d purchase order', '%d purchase orders', $n, 'ispag-crm'), $n);
            }
            $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$notes} WHERE TRIM(company_id) = %s", (string) $id));
            if ($n) $removes[] = sprintf(_n('%d note / task', '%d notes / tasks', $n, 'ispag-crm'), $n);
        }
        return ['name' => $name, 'blockers' => $blockers, 'removes' => $removes];
    }

    public function ajax_preview() {
        [$type, $id] = $this->guard();
        $info = $this->inspect($type, $id);
        wp_send_json_success($info + ['type' => $type]);
    }

    public function ajax_delete() {
        global $wpdb;
        [$type, $id] = $this->guard();
        $info = $this->inspect($type, $id);
        if ($info['blockers']) wp_send_json_error(['message' => __('Still linked to other records.', 'ispag-crm')], 409);
        $p = $wpdb->prefix;
        $notes = $p . 'ispag_contact_notes';

        if ($type === 'contact') {
            $wpdb->query($wpdb->prepare("DELETE FROM {$notes} WHERE REPLACE(contact_id, ' ', '') = %s", (string) $id));
            $wpdb->delete($p . 'ispag_contacts_owners', ['contact_id' => $id]);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            if (!wp_delete_user($id)) wp_send_json_error(['message' => __('The contact could not be deleted.', 'ispag-crm')], 500);
            $redirect = home_url('/listes-des-contacts/');
        } else {
            $wpdb->query($wpdb->prepare("DELETE FROM {$notes} WHERE TRIM(company_id) = %s", (string) $id));
            foreach (['ispag_companies_meta', 'ispag_companies_owners', 'ispag_companies_discounts', 'ispag_companies_lifecycle'] as $t) {
                if (self::table_exists($p . $t)) $wpdb->delete($p . $t, ['company_id' => $id]);
            }
            if (!$wpdb->delete($p . 'ispag_companies', ['Id' => $id])) wp_send_json_error(['message' => __('The company could not be deleted.', 'ispag-crm')], 500);
            $redirect = home_url('/entreprises/');
        }
        wp_send_json_success(['redirect' => $redirect]);
    }
}
