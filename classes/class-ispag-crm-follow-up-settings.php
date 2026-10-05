<?php
defined('ABSPATH') || exit;

/**
 * Délais de relance : au bout de combien de jours sans contact on alerte.
 * Utilisés par la tâche automatique « santé des contacts » ET par l'alerte « Aucun contact depuis plus de N jours » des fiches.
 * Réglages : Réglages → Relances CRM.
 */
class ISPAG_Crm_Follow_Up_Settings {

    const OPTION       = 'ispag_crm_follow_up_days';
    const OPT_ROLES    = 'ispag_crm_follow_up_roles';          // [rôle WordPress => jours | 0 = aucune relance]
    const OPT_COMPANY  = 'ispag_crm_follow_up_company_types';  // [type d'entreprise => jours | 0 = aucune relance]
    const DEFAULTS = ['A' => 90, 'B' => 180, 'C' => 240, 'none' => 180, 'entity' => 90];

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_ispag_crm_follow_up_save', [$this, 'save']);
    }

    /** @return array<string,int> */
    public static function all(): array {
        $saved = (array) get_option(self::OPTION, []);
        $out   = [];
        foreach (self::DEFAULTS as $k => $def) {
            $out[$k] = isset($saved[$k]) && (int) $saved[$k] > 0 ? (int) $saved[$k] : $def;
        }
        return $out;
    }

    /** Délai (jours) d'un contact selon sa priorité (A/B/C ; autre = sans priorité). */
    public static function days_for_priority($priority): int {
        $p = strtoupper(trim((string) $priority));
        $all = self::all();
        return $all[$p] ?? $all['none'];
    }

    /** @return array<string,int> surcharges enregistrées (clé absente = pas de surcharge ; 0 = aucune relance). */
    private static function overrides(string $option): array {
        $out = [];
        foreach ((array) get_option($option, []) as $k => $v) {
            if ($v !== '' && (int) $v >= 0) $out[(string) $k] = (int) $v;
        }
        return $out;
    }

    /** Rôle WordPress (clé) d'un contact. */
    public static function contact_role(int $user_id): string {
        $u = $user_id ? get_userdata($user_id) : null;
        return ($u && !empty($u->roles)) ? (string) reset($u->roles) : '';
    }

    /** Type d'entreprise (prospect, customer, engineer…) d'une entreprise. */
    public static function company_type(int $company_id): string {
        if (!$company_id || !class_exists('ISPAG_Crm_Company_Constants')) return '';
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM ' . ISPAG_Crm_Company_Constants::TABLE_COMPANY_META . ' WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
            $company_id, ISPAG_Crm_Company_Constants::COMPANY_TYPE
        ));
        return is_string($v) ? strtolower(trim($v)) : '';
    }

    /**
     * Délai de relance d'un contact, du plus précis au plus général :
     * rôle du contact → type de son entreprise → priorité. 0 = aucune relance (ex. ingénieurs : ils ne commandent pas).
     */
    public static function days_for_contact($priority, string $role = '', string $company_type = ''): int {
        $roles = self::overrides(self::OPT_ROLES);
        if ($role !== '' && isset($roles[$role])) return $roles[$role];
        $types = self::overrides(self::OPT_COMPANY);
        if ($company_type !== '' && isset($types[$company_type])) return $types[$company_type];
        return self::days_for_priority($priority);
    }

    /** Délai (jours) pour une entreprise ou un deal. */
    public static function days_for_entity(): int {
        return self::all()['entity'];
    }

    public function menu() {
        add_options_page(__('CRM follow-up', 'ispag-crm'), __('CRM follow-up', 'ispag-crm'), 'manage_options', 'ispag-crm-follow-up', [$this, 'page']);
    }

    public function page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'ispag-crm'));
        $v = self::all();
        $rows = [
            'A'      => __('Contacts with priority A (high)', 'ispag-crm'),
            'B'      => __('Contacts with priority B (medium)', 'ispag-crm'),
            'C'      => __('Contacts with priority C (low)', 'ispag-crm'),
            'none'   => __('Contacts without priority', 'ispag-crm'),
            'entity' => __('Companies and deals', 'ispag-crm'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CRM follow-up', 'ispag-crm'); ?></h1>
            <p><?php esc_html_e('Number of days without contact before the alert "No contact for over N days" is shown on the pages, and before the automatic follow-up task is created. The alert turns orange after two thirds of the delay and red once it is exceeded.', 'ispag-crm'); ?></p>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'ispag-crm'); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ispag_crm_follow_up_save'); ?>
                <input type="hidden" name="action" value="ispag_crm_follow_up_save">
                <table class="form-table">
                <?php foreach ($rows as $k => $label): ?>
                    <tr><th scope="row"><label for="fu_<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></label></th>
                        <td><input type="number" min="1" max="1000" id="fu_<?php echo esc_attr($k); ?>" name="days[<?php echo esc_attr($k); ?>]" value="<?php echo (int) $v[$k]; ?>" style="width:90px"> <?php esc_html_e('days', 'ispag-crm'); ?></td></tr>
                <?php endforeach; ?>
                </table>
                <?php $fu = ISPAG_Crm_Deal_Follow_Up::settings(); ?>
                <h2><?php esc_html_e('Automatic follow-up of open offers', 'ispag-crm'); ?></h2>
                <p><?php esc_html_e('Every day, each open offer gets a follow-up task for its owner, based on the expected decision date (or the closing date when none is set). The next task is created once the previous one is completed. Contacts set to "No follow-up" below are ignored.', 'ispag-crm'); ?></p>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Automatic follow-up', 'ispag-crm'); ?></th>
                        <td><label><input type="checkbox" name="fu_enabled" value="1" <?php checked($fu['enabled']); ?>> <?php esc_html_e('Enabled', 'ispag-crm'); ?></label></td></tr>
                    <tr><th scope="row"><label for="fu_lead"><?php esc_html_e('Follow up before the decision', 'ispag-crm'); ?></label></th>
                        <td><input type="number" min="0" max="365" id="fu_lead" name="fu_lead" value="<?php echo (int) $fu['lead']; ?>" style="width:90px"> <?php esc_html_e('days before the expected decision', 'ispag-crm'); ?></td></tr>
                    <tr><th scope="row"><label for="fu_first"><?php esc_html_e('Not before', 'ispag-crm'); ?></label></th>
                        <td><input type="number" min="0" max="365" id="fu_first" name="fu_first" value="<?php echo (int) $fu['first']; ?>" style="width:90px"> <?php esc_html_e('days after the offer', 'ispag-crm'); ?></td></tr>
                    <tr><th scope="row"><label for="fu_repeat"><?php esc_html_e('Then repeat every', 'ispag-crm'); ?></label></th>
                        <td><input type="number" min="1" max="365" id="fu_repeat" name="fu_repeat" value="<?php echo (int) $fu['repeat']; ?>" style="width:90px"> <?php esc_html_e('days (also used once the decision date is past)', 'ispag-crm'); ?></td></tr>
                </table>

                <h2><?php esc_html_e('By contact role', 'ispag-crm'); ?></h2>
                <p><?php esc_html_e('Overrides the delay above for all contacts with this role. Leave empty to use the priority delay; tick "No follow-up" for roles that do not order (the contact is never flagged).', 'ispag-crm'); ?></p>
                <?php $this->override_table('roles', $this->role_options(), self::overrides(self::OPT_ROLES)); ?>
                <h2><?php esc_html_e('By company type', 'ispag-crm'); ?></h2>
                <p><?php esc_html_e('Used when the role has no delay of its own.', 'ispag-crm'); ?></p>
                <?php $this->override_table('company', $this->company_type_options(), self::overrides(self::OPT_COMPANY)); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function role_options(): array {
        global $wp_roles;
        if (!isset($wp_roles)) $wp_roles = new WP_Roles();
        $skip = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'shop_manager', 'customer'];
        $out = [];
        foreach ($wp_roles->get_names() as $key => $name) {
            if (!in_array($key, $skip, true)) $out[$key] = translate_user_role($name);
        }
        return $out;
    }

    private function company_type_options(): array {
        $out = [];
        if (class_exists('ISPAG_Company_Detail_Shortcode') && method_exists('ISPAG_Company_Detail_Shortcode', 'get_company_type_options')) {
            $out = (array) ISPAG_Company_Detail_Shortcode::get_company_type_options();
        }
        return $out ?: ['prospect' => 'Prospect', 'customer' => 'Customer', 'reseller' => 'Reseller', 'engineer' => 'Engineer', 'vendor' => 'Vendor'];
    }

    private function override_table(string $group, array $options, array $current) {
        echo '<table class="widefat striped" style="max-width:640px"><tbody>';
        foreach ($options as $key => $label) {
            $has   = array_key_exists($key, $current);
            $never = $has && $current[$key] === 0;
            echo '<tr><td style="width:240px"><strong>' . esc_html($label) . '</strong> <code>' . esc_html($key) . '</code></td><td>';
            printf('<input type="number" min="1" max="1000" name="%1$s[days][%2$s]" value="%3$s" style="width:90px" placeholder="—"> %4$s &nbsp; ', esc_attr($group), esc_attr($key), $has && !$never ? (int) $current[$key] : '', esc_html__('days', 'ispag-crm'));
            printf('<label><input type="checkbox" name="%1$s[never][%2$s]" value="1" %3$s> %4$s</label>', esc_attr($group), esc_attr($key), checked($never, true, false), esc_html__('No follow-up', 'ispag-crm'));
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function collect_overrides(string $group, array $options): array {
        $in = (array) ($_POST[$group] ?? []);
        $out = [];
        foreach ($options as $key => $_) {
            if (!empty($in['never'][$key])) { $out[$key] = 0; continue; }
            $d = (int) ($in['days'][$key] ?? 0);
            if ($d > 0) $out[$key] = min(1000, $d);
        }
        return $out;
    }

    public function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'ispag-crm'));
        check_admin_referer('ispag_crm_follow_up_save');
        $in  = (array) ($_POST['days'] ?? []);
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $out[$k] = max(1, min(1000, (int) ($in[$k] ?? $def)));
        }
        update_option(self::OPTION, $out, false);
        update_option(ISPAG_Crm_Deal_Follow_Up::OPTION, [
            'enabled' => empty($_POST['fu_enabled']) ? 0 : 1,
            'lead'    => max(0, min(365, (int) ($_POST['fu_lead'] ?? 7))),
            'first'   => max(0, min(365, (int) ($_POST['fu_first'] ?? 10))),
            'repeat'  => max(1, min(365, (int) ($_POST['fu_repeat'] ?? 14))),
        ], false);
        update_option(self::OPT_ROLES, $this->collect_overrides('roles', $this->role_options()), false);
        update_option(self::OPT_COMPANY, $this->collect_overrides('company', $this->company_type_options()), false);
        wp_safe_redirect(admin_url('options-general.php?page=ispag-crm-follow-up&saved=1'));
        exit;
    }
}
