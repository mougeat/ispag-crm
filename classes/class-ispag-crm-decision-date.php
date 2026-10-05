<?php
defined('ABSPATH') || exit;

/**
 * Date de décision attendue d'une offre (facultative).
 *
 * Dans la chaîne chiffreur → entreprise générale → installateur, la décision tombe longtemps après l'offre : cette date
 * sert de repère pour les relances. Elle n'est jamais obligatoire : sans elle, on prend la date de clôture du deal,
 * et à défaut la date de l'offre + 30 jours.
 */
class ISPAG_Crm_Decision_Date {

    const COLUMN  = 'expected_decision_date';
    const VERSION = 'ispag_crm_decision_col_v1';
    const ACTION  = 'ispag_crm_set_decision_date';
    const NONCE   = 'ispag_decision_date';

    public function __construct() {
        add_action('init', [self::class, 'ensure_column'], 30);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax_set']);
        add_action('wp_ajax_ispag_crm_save_deal_field', [self::class, 'ajax_save_field']);   // édition en ligne (✏️) de la fiche deal
    }

    /** Ajoute la colonne une seule fois (sans toucher aux données existantes). */
    public static function ensure_column() {
        if (get_option(self::VERSION)) return;
        global $wpdb;
        $table = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $has   = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", self::COLUMN));
        if (!$has) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `" . self::COLUMN . "` date DEFAULT NULL AFTER `closing_date`");
        }
        update_option(self::VERSION, 1, false);
    }

    /**
     * Date effective de décision d'un deal.
     * @return array{date:string,source:string} date Y-m-d ('' si rien) ; source = 'expected' | 'closing' | 'created' | ''
     */
    public static function effective($deal): array {
        $valid = function ($d) { return is_string($d) && $d !== '' && strpos($d, '0000') !== 0 && strtotime($d); };
        $expected = $deal->{self::COLUMN} ?? '';
        if ($valid($expected)) return ['date' => date('Y-m-d', strtotime($expected)), 'source' => 'expected'];
        $closing = $deal->closing_date ?? '';
        if ($valid($closing)) return ['date' => date('Y-m-d', strtotime($closing)), 'source' => 'closing'];
        $created = $deal->date_creation ?? '';
        if ($valid($created)) return ['date' => date('Y-m-d', strtotime($created . ' +30 days')), 'source' => 'created'];
        return ['date' => '', 'source' => ''];
    }

    /** Édition en ligne d'un champ de la fiche deal (même protocole que les champs contact / entreprise). Champ géré : expected_decision_date. */
    public static function ajax_save_field() {
        if (!current_user_can('manage_order')) wp_send_json_error(['message' => 'Droits insuffisants'], 403);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE)) wp_send_json_error(['message' => 'Invalid nonce — reload the page'], 403);
        if (sanitize_key($_POST['field_name'] ?? '') !== self::COLUMN) wp_send_json_error(['message' => 'Unsupported field'], 400);
        self::save(absint($_POST['deal_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['new_value'] ?? '')));
    }

    /** Appel direct (ancienne action) : nonce propre à la date de décision. */
    public static function ajax_set() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_order')) wp_send_json_error(['message' => 'Droits insuffisants'], 403);
        self::save(absint($_POST['deal_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['date'] ?? '')));
    }

    /** Enregistre la date ('' = effacer) et répond en JSON (valeur effective + affichage du champ éditable). */
    private static function save(int $deal_id, string $raw) {
        if (!$deal_id) wp_send_json_error(['message' => 'Missing deal'], 400);
        $date = null;
        if ($raw !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$dt || $dt->format('Y-m-d') !== $raw) wp_send_json_error(['message' => 'Invalid date'], 400);
            $date = $raw;
        }
        self::ensure_column();
        global $wpdb;
        $table = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $ok = $wpdb->update($table, [self::COLUMN => $date], ['id' => $deal_id], ['%s'], ['%d']);
        if ($ok === false) wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error], 500);
        $deal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $deal_id));
        $eff  = $deal ? self::effective($deal) : ['date' => '', 'source' => ''];
        $label = $eff['date'] ? date_i18n('d.m.Y', strtotime($eff['date'])) : '—';
        $hint  = ['expected' => '', 'closing' => __('closing date', 'ispag-crm'), 'created' => __('offer date + 30 days', 'ispag-crm')][$eff['source']] ?? '';
        wp_send_json_success([
            'date' => $eff['date'], 'source' => $eff['source'], 'label' => $label,
            'display_value' => esc_html($label) . ($hint ? ' <small style="color:#6b7280">(' . esc_html($hint) . ')</small>' : '') . ' <span class="edit-icon">✏️</span>',
        ]);
    }
}
