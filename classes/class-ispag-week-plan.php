<?php
defined('ABSPATH') || exit;

/**
 * « Ma semaine » : cantons visités le mardi et le jeudi, et personne dont les tâches du CRM alimentent le point du lundi.
 * Valeurs par défaut récurrentes + remplacement possible pour une semaine précise. Page : ISPAG Settings → ISPAG Ma semaine (à défaut : Réglages).
 */
class ISPAG_Week_Plan {

    const OPT      = 'ispag_week_plan';
    const DAYS     = ['2' => 'Mardi', '4' => 'Jeudi'];   // numéro ISO du jour => libellé
    const SLUG     = 'ispag-week-plan';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 30);   // après le menu « ISPAG Settings » du plugin Project Manager
        add_action('admin_post_ispag_week_plan_save', [$this, 'handle_save']);
    }

    public static function get() {
        $o = get_option(self::OPT, []);
        return is_array($o) ? $o + ['defaults' => [], 'weeks' => [], 'todo_user' => 0] : ['defaults' => [], 'weeks' => [], 'todo_user' => 0];
    }

    private static function clean_cantons($v) {
        $ok = array_keys(ISPAG_Swiss_Geo::cantons());
        return array_values(array_intersect($ok, array_map('strtoupper', array_map('sanitize_text_field', (array) $v))));
    }

    /** Cantons du mardi et du jeudi de la semaine qui commence ce lundi (Y-m-d). */
    public static function resolve($monday) {
        $o = self::get();
        $w = $o['weeks'][$monday] ?? [];
        $res = [];
        foreach (self::DAYS as $iso => $label) {
            $cantons = !empty($w[$iso]) ? $w[$iso] : ($o['defaults'][$iso] ?? []);
            $res[$iso] = ['label' => $label, 'date' => date('Y-m-d', strtotime($monday . ' +' . ((int) $iso - 1) . ' days')), 'cantons' => array_values((array) $cantons), 'source' => !empty($w[$iso]) ? 'week' : 'default'];
        }
        return $res;
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('ispag_week_plan_save');
        $o = self::get();
        $in = isset($_POST['plan']) && is_array($_POST['plan']) ? wp_unslash($_POST['plan']) : [];
        foreach (self::DAYS as $iso => $l) $o['defaults'][$iso] = self::clean_cantons($in['defaults'][$iso] ?? []);
        foreach ((array) ($in['weeks'] ?? []) as $monday => $days) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $monday)) continue;
            foreach (self::DAYS as $iso => $l) $o['weeks'][$monday][$iso] = self::clean_cantons($days[$iso] ?? []);
            if (!array_filter($o['weeks'][$monday])) unset($o['weeks'][$monday]);
        }
        // on ne garde que les semaines récentes ou à venir
        $limit = date('Y-m-d', strtotime('-3 weeks'));
        foreach (array_keys($o['weeks']) as $m) if ($m < $limit) unset($o['weeks'][$m]);
        $o['todo_user'] = (int) ($_POST['todo_user'] ?? 0);
        update_option(self::OPT, $o, false);
        wp_safe_redirect(add_query_arg('saved', 1, self::page_url()));
        exit;
    }

    public function menu() {
        if (!empty($GLOBALS['admin_page_hooks']['ispag-settings'])) {
            add_submenu_page('ispag-settings', 'ISPAG Ma semaine', 'ISPAG Ma semaine', 'manage_options', self::SLUG, [$this, 'render_page']);
        } else {
            add_options_page('ISPAG Ma semaine', 'ISPAG Ma semaine', 'manage_options', self::SLUG, [$this, 'render_page']);
        }
    }

    /** Adresse de la page, selon l'endroit où le menu a été rangé. */
    public static function page_url() {
        return class_exists('ISPAG_Settings') ? admin_url('admin.php?page=' . self::SLUG) : admin_url('options-general.php?page=' . self::SLUG);
    }

    private function chips($name, $selected) {
        $html = '<div class="isw-chips">';
        foreach (ISPAG_Swiss_Geo::cantons() as $code => $label) {
            $html .= '<label title="' . esc_attr($label) . '"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($code) . '"' . checked(in_array($code, (array) $selected, true), true, false) . '><span>' . esc_html($code) . '</span></label>';
        }
        return $html . '</div>';
    }

    public function render_page() {
        if (!current_user_can('manage_options')) return;
        $o = self::get();
        $mon_this = date('Y-m-d', strtotime('monday this week'));
        $mon_next = date('Y-m-d', strtotime('monday next week'));
        ?>
        <style>.isw-chips{display:flex;flex-wrap:wrap;gap:6px;max-width:760px}.isw-chips label{cursor:pointer}.isw-chips input{position:absolute;opacity:0}.isw-chips span{display:inline-block;min-width:34px;text-align:center;padding:6px 8px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;font-weight:600}.isw-chips input:checked+span{background:#2e7d32;border-color:#2e7d32;color:#fff}.isw-chips input:focus-visible+span{outline:2px solid #2b4aa0}</style>
        <div class="wrap">
            <h1>ISPAG Ma semaine</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>Enregistré.</p></div><?php endif; ?>
            <p>Indiquez les cantons où vous êtes le mardi et le jeudi. Le point du lundi (5 h 50) en tient compte pour proposer des visites. Un jour sans canton n'a pas de proposition.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ispag_week_plan_save">
                <?php wp_nonce_field('ispag_week_plan_save'); ?>

                <h2>Par défaut (chaque semaine)</h2>
                <?php foreach (self::DAYS as $iso => $label): ?>
                    <p><strong><?php echo esc_html($label); ?></strong></p>
                    <?php echo $this->chips("plan[defaults][$iso]", $o['defaults'][$iso] ?? []); ?>
                <?php endforeach; ?>

                <?php foreach (['Cette semaine' => $mon_this, 'Semaine prochaine' => $mon_next] as $title => $monday): ?>
                    <h2><?php echo esc_html($title); ?> <small>(du <?php echo esc_html(date_i18n('d.m.Y', strtotime($monday))); ?>)</small></h2>
                    <p class="description">Laissez vide pour garder les valeurs par défaut.</p>
                    <?php foreach (self::DAYS as $iso => $label): ?>
                        <p><strong><?php echo esc_html($label); ?></strong></p>
                        <?php echo $this->chips("plan[weeks][$monday][$iso]", $o['weeks'][$monday][$iso] ?? []); ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <h2>Ma to-do list</h2>
                <p>Les tâches ouvertes du CRM de cette personne apparaissent dans le point du lundi.</p>
                <?php wp_dropdown_users(['name' => 'todo_user', 'selected' => (int) ($o['todo_user'] ?: get_current_user_id()), 'show_option_none' => '— aucune —', 'option_none_value' => 0]); ?>
                <?php submit_button('Enregistrer'); ?>
            </form>
        </div>
        <?php
    }
}
