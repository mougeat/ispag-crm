<?php
defined('ABSPATH') || exit;

/**
 * Agenda de l'iPhone → plateforme, pour le point du lundi.
 *
 * Un Raccourci iPhone (automatisation quotidienne) envoie les événements des 14 prochains jours à :
 *   POST /wp-json/ispag/v1/agenda-sync        (en-tête X-ISPAG-Token)
 * Corps en texte brut, une ligne par événement :  début|fin|toute la journée|calendrier|lieu|titre
 * (dates au format ISO 8601, « titre » en dernier : il peut contenir des « | »).
 *
 * Chaque envoi REMPLACE la liste précédente. Seuls ces champs sont gardés : jamais de notes, de participants ni de pièces jointes.
 * Le jeton est généré par le site (jamais dans le code) ; page de réglage : Réglages → ISPAG Agenda iPhone.
 */
class ISPAG_Agenda_Sync {

    const OPT        = 'ispag_agenda_sync';
    const OPT_TOKEN  = 'ispag_agenda_sync_token';
    const MAX_EVENTS = 400;
    const WINDOW_DAYS = 21;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_ispag_agenda_token_reset', [$this, 'reset_token']);
    }

    // ── Jeton ────────────────────────────────────────────────────────────────

    public static function token() {
        $t = get_option(self::OPT_TOKEN);
        if (!is_string($t) || strlen($t) < 32) {
            $t = wp_generate_password(40, false, false);
            update_option(self::OPT_TOKEN, $t, false);
        }
        return $t;
    }

    public function reset_token() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('ispag_agenda_token_reset');
        delete_option(self::OPT_TOKEN);
        self::token();
        wp_safe_redirect(add_query_arg(['page' => 'ispag-agenda-sync', 'reset' => 1], admin_url('options-general.php')));
        exit;
    }

    // ── Route ────────────────────────────────────────────────────────────────

    public function register_routes() {
        register_rest_route('ispag/v1', '/agenda-sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_sync'],
            'permission_callback' => [$this, 'check_token'],
        ]);
    }

    public function check_token($req) {
        $given = (string) $req->get_header('x-ispag-token');
        if ($given === '' || !hash_equals(self::token(), $given)) {
            return new WP_Error('agenda_auth', 'Not authorized', ['status' => 401]);
        }
        return true;
    }

    public function rest_sync($req) {
        $events = self::parse((string) $req->get_body(), wp_date('Y-m-d'));
        update_option(self::OPT, ['synced_at' => wp_date('Y-m-d H:i'), 'events' => $events], false);
        return rest_ensure_response(['ok' => true, 'count' => count($events), 'synced_at' => wp_date('Y-m-d H:i')]);
    }

    /**
     * Texte du Raccourci → événements nettoyés (heure de Zurich), limités à la fenêtre utile.
     * @return array<int,array{start:string,end:string,all_day:bool,calendar:string,location:string,title:string}>
     */
    public static function parse($body, $today) {
        $tz   = wp_timezone();
        $from = new DateTimeImmutable($today . ' 00:00:00', $tz);
        $to   = $from->modify('+' . self::WINDOW_DAYS . ' days');
        $out  = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = explode('|', $line, 6);
            if (count($p) < 6) continue;
            try {
                $s = (new DateTimeImmutable(trim($p[0])))->setTimezone($tz);
                $e = (new DateTimeImmutable(trim($p[1])))->setTimezone($tz);
            } catch (Exception $ex) { continue; }
            if ($e < $from || $s > $to) continue;
            $all = in_array(strtolower(trim($p[2])), ['1', 'true', 'oui', 'yes', 'vrai'], true);
            $out[] = [
                'start'    => $s->format('Y-m-d H:i'),
                'end'      => $e->format('Y-m-d H:i'),
                'all_day'  => $all,
                'calendar' => mb_substr(sanitize_text_field($p[3]), 0, 60),
                'location' => mb_substr(sanitize_text_field($p[4]), 0, 200),
                'title'    => mb_substr(sanitize_text_field($p[5]), 0, 200),
            ];
            if (count($out) >= self::MAX_EVENTS) break;
        }
        usort($out, function ($a, $b) { return strcmp($a['start'], $b['start']); });
        return $out;
    }

    /** Événements qui touchent la période [$from, $to] (Y-m-d). */
    public static function events($from, $to) {
        $d = get_option(self::OPT, []);
        $res = [];
        foreach ((array) ($d['events'] ?? []) as $e) {
            // un événement qui finit à 00:00 (journée entière) ne déborde pas sur le jour suivant
            $end_day = date('Y-m-d', strtotime($e['end']) - ($e['end'] > $e['start'] ? 60 : 0));
            if ($end_day >= $from && substr($e['start'], 0, 10) <= $to) $res[] = $e;
        }
        return ['synced_at' => $d['synced_at'] ?? null, 'events' => $res];
    }

    // ── Page de réglage ──────────────────────────────────────────────────────

    public function menu() {
        add_options_page('ISPAG Agenda iPhone', 'ISPAG Agenda iPhone', 'manage_options', 'ispag-agenda-sync', [$this, 'render_page']);
    }

    public function render_page() {
        if (!current_user_can('manage_options')) return;
        $url = rest_url('ispag/v1/agenda-sync');
        $d   = get_option(self::OPT, []);
        ?>
        <div class="wrap">
            <h1>ISPAG Agenda iPhone</h1>
            <?php if (!empty($_GET['reset'])): ?><div class="notice notice-success"><p>Nouveau jeton généré : l'ancien Raccourci ne fonctionne plus, collez le nouveau jeton dans le Raccourci.</p></div><?php endif; ?>
            <p>Dernière synchronisation : <strong><?php echo !empty($d['synced_at']) ? esc_html($d['synced_at']) . ' (' . count((array) ($d['events'] ?? [])) . ' événements)' : 'jamais'; ?></strong></p>
            <table class="form-table" role="presentation">
                <tr><th>Adresse</th><td><input type="text" readonly class="large-text code" value="<?php echo esc_attr($url); ?>" onclick="this.select()"></td></tr>
                <tr><th>Jeton (en-tête <code>X-ISPAG-Token</code>)</th><td><input type="text" readonly class="large-text code" value="<?php echo esc_attr(self::token()); ?>" onclick="this.select()">
                    <p class="description">À garder confidentiel : il permet d'envoyer des événements au site. Il n'est lu par personne d'autre que le Raccourci.</p></td></tr>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ispag_agenda_token_reset">
                <?php wp_nonce_field('ispag_agenda_token_reset'); ?>
                <?php submit_button('Générer un nouveau jeton', 'secondary', 'submit', false, ['onclick' => "return confirm('L\'ancien jeton ne fonctionnera plus. Continuer ?');"]); ?>
            </form>
            <h2>Raccourci iPhone</h2>
            <ol>
                <li>Application <strong>Raccourcis</strong> → nouveau raccourci « Agenda vers ISPAG ».</li>
                <li><strong>Rechercher des événements du calendrier</strong> : « Date de début » est « dans les prochains » <strong>14 jours</strong> ; trier par date de début. Choisissez les calendriers à envoyer (Outlook, personnel…).</li>
                <li><strong>Répéter avec chaque</strong> événement : action <strong>Texte</strong> contenant, séparés par <code>|</code> : début (format ISO 8601) | fin (ISO 8601) | Toute la journée | Calendrier | Lieu | Titre. Puis <strong>Ajouter à la variable</strong> « Lignes ».</li>
                <li>Après la boucle : <strong>Combiner le texte</strong> « Lignes » avec un <em>retour à la ligne</em>.</li>
                <li><strong>Obtenir le contenu de l'URL</strong> : méthode <strong>POST</strong>, l'adresse ci-dessus, en-tête <code>X-ISPAG-Token</code> = le jeton, corps de la requête = <strong>Fichier</strong> (le texte combiné).</li>
                <li>Onglet <strong>Automatisation</strong> → Heure de la journée (par exemple chaque jour à 5 h) → exécuter ce raccourci → <strong>Exécuter immédiatement</strong>.</li>
            </ol>
            <p class="description">Seuls l'heure, le calendrier, le lieu et le titre sont envoyés : jamais les notes, les participants ni les pièces jointes.</p>
        </div>
        <?php
    }
}
