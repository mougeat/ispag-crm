<?php
defined('ABSPATH') || exit;

/**
 * API en lecture seule pour le point du lundi (routine hebdomadaire) : chiffres de la semaine et offres à relancer.
 *
 *   GET /wp-json/ispag/v1/management-briefing[?ref=YYYY-MM-DD]
 *
 * Droit : export_management_briefing, à donner à un compte API dédié (fiche utilisateur), jamais à un rôle.
 * Contient des montants HT et les noms des offres à relancer ; aucune marge, aucun coefficient, aucun contact.
 */
class ISPAG_Management_Briefing {

    const CAP = 'export_management_briefing';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('ispag/v1', '/management-briefing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_briefing'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => ['ref' => ['required' => false, 'validate_callback' => function ($v) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v); }]],
        ]);
    }

    public function can_read() {
        return is_user_logged_in() && current_user_can(self::CAP);
    }

    public function rest_briefing($req) {
        $ref = (string) ($req->get_param('ref') ?: wp_date('Y-m-d'));
        $data = ISPAG_Deal_Kpis::weekly_summary($ref);
        // Agenda de l'iPhone (synchronisé par un Raccourci) : 7 jours à partir de la date de référence
        $to = date('Y-m-d', strtotime($ref . ' +6 days'));
        $ag = ISPAG_Agenda_Sync::events($ref, $to);
        $age_h = !empty($ag['synced_at']) ? (time() - strtotime($ag['synced_at'])) / 3600 : null;
        $data['agenda'] = [
            'from'      => $ref, 'to' => $to,
            'synced_at' => $ag['synced_at'],
            'stale'     => $age_h === null || $age_h > 48,
            'events'    => $ag['events'],
        ];
        return rest_ensure_response($data);
    }
}
