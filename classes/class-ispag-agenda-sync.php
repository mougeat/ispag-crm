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
 * Le jeton est généré par le site (jamais dans le code) ; page de réglage : ISPAG Settings → ISPAG Agenda iPhone (à défaut : Réglages).
 */
class ISPAG_Agenda_Sync {

    const OPT        = 'ispag_agenda_sync';
    const OPT_TOKEN  = 'ispag_agenda_sync_token';
    const MAX_EVENTS = 400;
    const WINDOW_DAYS = 21;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'menu'], 30);   // après le menu « ISPAG Settings » du plugin Project Manager
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
        wp_safe_redirect(add_query_arg('reset', 1, self::page_url()));
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
        $body = (string) $req->get_body();
        update_option(self::OPT, [
            'synced_at' => wp_date('Y-m-d H:i'), 'events' => $events,
            'received'  => ['bytes' => strlen($body), 'first_line' => mb_substr(sanitize_text_field(strtok($body, "\r\n") ?: ''), 0, 200)],
        ], false);
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
        // Un lieu ou un titre peut contenir des retours à la ligne : toute ligne qui ne commence pas par une date (AAAA-MM-JJ) prolonge la précédente.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) || !$lines) $lines[] = $raw;
            else $lines[count($lines) - 1] .= ' ' . $raw;
        }
        foreach ($lines as $line) {
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
        if (!empty($GLOBALS['admin_page_hooks']['ispag-settings'])) {
            add_submenu_page('ispag-settings', 'ISPAG Agenda iPhone', 'ISPAG Agenda iPhone', 'manage_options', 'ispag-agenda-sync', [$this, 'render_page']);
        } else {
            add_options_page('ISPAG Agenda iPhone', 'ISPAG Agenda iPhone', 'manage_options', 'ispag-agenda-sync', [$this, 'render_page']);
        }
    }

    /** Adresse de la page, selon l'endroit où le menu a été rangé. */
    public static function page_url() {
        return class_exists('ISPAG_Settings') ? admin_url('admin.php?page=ispag-agenda-sync') : admin_url('options-general.php?page=ispag-agenda-sync');
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
            <?php $rc = $d['received'] ?? null; $ev = array_slice((array) ($d['events'] ?? []), 0, 8); ?>
            <?php if ($rc): ?>
                <h2>Ce que le site a compris au dernier envoi</h2>
                <p>Reçu : <?php echo (int) $rc['bytes']; ?> caractères — <?php echo count((array) ($d['events'] ?? [])); ?> événement(s) reconnu(s) sur les 21 prochains jours.
                <?php if (!$ev): ?><br><strong>Aucun événement reconnu.</strong> Première ligne reçue : <code><?php echo esc_html($rc['first_line'] ?: '(vide)'); ?></code><br>Elle doit ressembler à : <code>2026-10-13T09:00:00+02:00|2026-10-13T10:00:00+02:00|Non|Travail|Bulle|Rendez-vous client</code><?php endif; ?></p>
                <?php if ($ev): ?><ul style="list-style:disc;margin-left:20px"><?php foreach ($ev as $e): ?><li><?php echo esc_html($e['start'] . ' → ' . substr($e['end'], 11) . ' · ' . $e['calendar'] . ' · ' . $e['title'] . ($e['location'] ? ' (' . $e['location'] . ')' : '')); ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php endif; ?>

            <h2>Créer le Raccourci sur l'iPhone, pas à pas</h2>
            <p>Le principe : le Raccourci lit votre agenda, fabrique <strong>une ligne de texte par rendez-vous</strong> et envoie le tout au site. Chaque ligne a toujours la même forme, avec six morceaux séparés par une barre verticale <code>|</code> :</p>
            <p><code>début | fin | toute la journée | calendrier | lieu | titre</code></p>
            <p>Exemple : <code>2026-10-13T09:00:00+02:00|2026-10-13T10:00:00+02:00|Non|Travail|Bulle|Rendez-vous client</code></p>
            <p>Vous avez déjà l'action <strong>Rechercher des événements du calendrier</strong>. Voici la suite, action par action (ajoutez-les avec le bouton « + » en bas ; cherchez le nom dans la barre de recherche) :</p>
            <ol>
                <li><strong>Répéter avec chacun</strong> — choisissez comme entrée « Événements du calendrier » (le résultat de l'action précédente). Tout ce qui suit, jusqu'à « Fin de la répétition », se place <em>à l'intérieur</em> de cette boucle (en retrait).</li>
                <li>Dans la boucle : <strong>Formater la date</strong> → touchez « Date » et choisissez <em>Élément du répéteur</em> puis la propriété <strong>Date de début</strong> ; format de la date : <strong>ISO 8601</strong>.</li>
                <li>Juste après : <strong>Définir la variable</strong> → nom <code>Debut</code> (valeur : « Date formatée »).</li>
                <li>Encore une fois <strong>Formater la date</strong> avec la <strong>Date de fin</strong> (ISO 8601), puis <strong>Définir la variable</strong> nommée <code>Fin</code>.</li>
                <li>Ensuite l'action <strong>Texte</strong> (la zone blanche à remplir). Composez-y la ligne avec les variables, <strong>dans cet ordre, séparées par la barre <code>|</code></strong> (touche « | » du clavier : maintenez « / » ou passez par 123 puis #+=) :
                    <br>① variable <code>Debut</code> &nbsp;|&nbsp; ② variable <code>Fin</code> &nbsp;|&nbsp; ③ <em>Élément du répéteur</em> → propriété <strong>Toute la journée</strong> &nbsp;|&nbsp; ④ <em>Élément du répéteur</em> → <strong>Calendrier</strong> &nbsp;|&nbsp; ⑤ <em>Élément du répéteur</em> → <strong>Lieu</strong> &nbsp;|&nbsp; ⑥ <em>Élément du répéteur</em> → <strong>Titre</strong>.
                    <br><em>Pour insérer une variable</em> : touchez dans la zone Texte, une barre de variables apparaît au-dessus du clavier ; touchez « Élément du répéteur » (ou « Debut » / « Fin » via « Variables »), puis, pour une propriété, touchez à nouveau la pastille bleue dans le texte et choisissez la propriété dans la liste.</li>
                <li>Après la boucle (ligne « Fin de la répétition »), ajoutez <strong>Combiner le texte</strong> : entrée = <em>Résultats de la répétition</em>, séparateur = <strong>Nouvelle ligne</strong>.</li>
                <li>Ajoutez <strong>Obtenir le contenu de l'URL</strong> : <ul style="list-style:disc;margin-left:20px">
                    <li>URL : l'adresse ci-dessus ;</li>
                    <li>touchez « Afficher plus » → Méthode : <strong>POST</strong> ;</li>
                    <li>En-têtes → « Ajouter un nouvel en-tête » : clé <code>X-ISPAG-Token</code>, valeur = le jeton ci-dessus (touchez le champ jeton, tout sélectionner, copier, coller) ;</li>
                    <li>Corps de la requête : <strong>Fichier</strong>, puis choisissez <em>Texte combiné</em>.</li></ul></li>
                <li>Appuyez sur ▶ pour tester : cette page doit alors afficher « Ce que le site a compris au dernier envoi » avec vos rendez-vous. Si elle affiche « Aucun événement reconnu », la première ligne reçue vous montre ce qui cloche.</li>
                <li>Pour l'envoi automatique chaque jour : onglet <strong>Automatisation</strong> → « Heure de la journée » (par exemple 5 h, tous les jours) → « Exécuter le raccourci » → ce raccourci → <strong>Exécuter immédiatement</strong>.</li>
            </ol>
            <p class="description">Seuls l'heure, le calendrier, le lieu et le titre sont envoyés : jamais les notes, les participants ni les pièces jointes. Un lieu ou un titre sur plusieurs lignes est accepté.</p>
        </div>
        <?php
    }
}
