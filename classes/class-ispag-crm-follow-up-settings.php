<?php
defined('ABSPATH') || exit;

/**
 * Délais de relance : au bout de combien de jours sans contact on alerte.
 * Utilisés par la tâche automatique « santé des contacts » ET par l'alerte « Aucun contact depuis plus de N jours » des fiches.
 * Réglages : Réglages → Relances CRM.
 */
class ISPAG_Crm_Follow_Up_Settings {

    const OPTION   = 'ispag_crm_follow_up_days';
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
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
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
        wp_safe_redirect(admin_url('options-general.php?page=ispag-crm-follow-up&saved=1'));
        exit;
    }
}
