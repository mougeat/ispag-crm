<?php

class ISPAG_Company_Registry_Sync {

    private $table_name;
    private $parent_slug = 'ispag_main_menu';
    private $log_file;

    private const SPARQL_ENDPOINT = 'https://lindas.admin.ch/query';
    private const CRON_HOOK       = 'ispag_uid_cron_tick';
    private const BATCH_SIZE      = 5;

    public function __construct() {
        global $wpdb;
        $this->table_name = "wor9711_ispag_companies";
        $this->log_file   = WP_CONTENT_DIR . '/ispag_uid_sync.log';

        add_action('admin_menu', [$this, 'add_registry_menu']);
        add_action('admin_init', [$this, 'handle_manual_scan']);
        add_action('wp_ajax_ispag_confirm_uid',    [$this, 'ajax_confirm_uid']);
        add_action('wp_ajax_ispag_cron_status',    [$this, 'ajax_cron_status']);
        add_action('wp_ajax_ispag_get_homonyms',   [$this, 'ajax_get_homonyms']);
        add_action(self::CRON_HOOK, [$this, 'cron_process_batch']);
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function log($message) {
        $timestamp = current_time('mysql');
        error_log("[{$timestamp}] {$message}\n", 3, $this->log_file);
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function add_registry_menu() {
        add_submenu_page(
            $this->parent_slug,
            'Registre Commerce',
            'Registre Commerce',
            'manage_options',
            'ispag-uid-validation',
            [$this, 'render_validation_page']
        );
    }

    // -------------------------------------------------------------------------
    // handle_manual_scan : démarrage/arrêt cron + diagnostic
    // -------------------------------------------------------------------------

    public function handle_manual_scan() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'ispag-uid-validation') return;

        if (isset($_GET['debug_network']) && isset($_GET['ispag_uid_scan'])) {
            $this->run_network_diagnostics();
            exit;
        }

        if (isset($_GET['ispag_start_cron']) && current_user_can('manage_options')) {
            $this->start_cron();
            wp_redirect(admin_url('admin.php?page=ispag-uid-validation&cron_started=1'));
            exit;
        }

        if (isset($_GET['ispag_stop_cron']) && current_user_can('manage_options')) {
            $this->stop_cron();
            wp_redirect(admin_url('admin.php?page=ispag-uid-validation&cron_stopped=1'));
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Gestion du cron
    // -------------------------------------------------------------------------

    private function start_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'every_minute', self::CRON_HOOK);
            $this->log("=== CRON DÉMARRÉ ===");
        }
    }

    private function stop_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        $this->log("=== CRON ARRÊTÉ ===");
    }

    public function add_cron_interval($schedules) {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => 'Toutes les minutes',
            ];
        }
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // Traitement batch par le cron
    // -------------------------------------------------------------------------

    public function cron_process_batch() {
        global $wpdb;

        $companies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT viag_id, company_name
                 FROM {$this->table_name}
                 WHERE is_active = 1
                   AND uid_number IS NULL
                   AND uid_validation_data IS NULL
                   AND (uid_status IS NULL OR uid_status NOT IN ('api_error', 'manual_review'))
                 LIMIT %d",
                self::BATCH_SIZE
            )
        );

        if (empty($companies)) {
            $this->log("=== CRON : aucune société à traiter, arrêt automatique ===");
            $this->stop_cron();
            return;
        }

        foreach ($companies as $company) {
            $this->log("--- CRON SCAN : {$company->company_name} ---");
            $this->process_single_company($company);
        }
    }

    // -------------------------------------------------------------------------
    // AJAX : statut du cron
    // -------------------------------------------------------------------------

    public function ajax_cron_status() {
        if (!current_user_can('manage_options')) wp_send_json_error();

        global $wpdb;

        $pending_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_active = 1 AND uid_number IS NULL AND uid_validation_data IS NULL AND (uid_status IS NULL OR uid_status NOT IN ('api_error','manual_review'))");
        $homonyms_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE uid_validation_data IS NOT NULL");
        $done_count     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE uid_number IS NOT NULL");
        $error_count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE uid_status = 'api_error'");
        $not_found      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE uid_status = 'not_found'");

        $next_cron    = wp_next_scheduled(self::CRON_HOOK);
        $cron_running = $next_cron !== false;

        wp_send_json_success([
            'cron_running' => $cron_running,
            'next_tick'    => $next_cron ? human_time_diff(time(), $next_cron) : null,
            'pending'      => $pending_count,
            'homonyms'     => $homonyms_count,
            'done'         => $done_count,
            'errors'       => $error_count,
            'not_found'    => $not_found,
        ]);
    }

    // -------------------------------------------------------------------------
    // AJAX : liste des homonymes (HTML)
    // -------------------------------------------------------------------------

    public function ajax_get_homonyms() {
        check_ajax_referer('ispag_get_homonyms', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        global $wpdb;
        $pending = $wpdb->get_results(
            "SELECT viag_id, company_name, uid_validation_data
             FROM {$this->table_name}
             WHERE uid_validation_data IS NOT NULL
             ORDER BY company_name"
        );

        ob_start();
        foreach ($pending as $co) {
            $choices = json_decode($co->uid_validation_data, true);
            if (!$choices) continue;
            echo $this->render_homonym_row($co, $choices);
        }
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => count($pending)]);
    }

    // -------------------------------------------------------------------------
    // Rendu d'une ligne homonyme (cartes cliquables)
    // -------------------------------------------------------------------------

    private function render_homonym_row($co, $choices) {
        $vid = esc_attr($co->viag_id);
        $out = "<tr data-id='{$vid}'>";

        // Colonne gauche : nom CRM + bouton ignorer
        $out .= "<td style='vertical-align:top;width:220px;padding-top:12px;'>";
        $out .= "<strong>" . esc_html($co->company_name) . "</strong>";
        $out .= "<br><button class='button do-skip' data-id='{$vid}' style='margin-top:10px;color:#b32d2e;border-color:#b32d2e;'>✖ Aucun résultat</button>";
        $out .= "</td>";

        // Colonne droite : cartes Zefix
        $out .= "<td style='padding:8px 0;'>";

        foreach ($choices as $c) {
            $badge     = $c['status'] === 'active' ? '🟢 Active' : '🔴 Radiée';
            $uid       = esc_html($c['uid']);
            $co_name   = esc_html($c['name']);
            $town      = esc_html($c['town']);
            $data_val  = esc_attr(json_encode($c));

            // Lien Zefix vers la fiche officielle par UID
            $uid_raw   = preg_replace('/[^0-9]/', '', $c['uid']); // extrait les chiffres
            $zefix_url = !empty($uid_raw)
                ? 'https://www.zefix.admin.ch/fr/search/entity/list/detail/' . $uid_raw
                : 'https://www.zefix.admin.ch/fr/search/entity/list?name=' . urlencode($c['name']);

            $out .= "<div class='ispag-choice-card' data-val='{$data_val}' style='
                border:1px solid #dcdcde;
                border-radius:4px;
                padding:10px 14px;
                margin-bottom:8px;
                cursor:pointer;
                display:flex;
                justify-content:space-between;
                align-items:center;
                background:#fff;
                transition:background .15s, border-color .15s;
            '>";

            // Infos société
            $out .= "<div style='min-width:0;'>";
            $out .= "<div style='font-weight:600;font-size:14px;'>{$co_name}</div>";
            $out .= "<div style='color:#666;font-size:12px;margin-top:3px;'>{$uid} &nbsp;·&nbsp; {$town} &nbsp;·&nbsp; {$badge}</div>";
            $out .= "</div>";

            // Actions
            $out .= "<div style='display:flex;gap:8px;align-items:center;flex-shrink:0;margin-left:16px;'>";
            $out .= "<a href='" . esc_url($zefix_url) . "' target='_blank' class='button' style='font-size:11px;' onclick='event.stopPropagation();'>🔗 Fiche Zefix</a>";
            $out .= "<button class='button button-primary do-validate-card' data-id='{$vid}' data-val='{$data_val}' style='font-size:11px;' onclick='event.stopPropagation();'>✔ Lier</button>";
            $out .= "</div>";

            $out .= "</div>"; // .ispag-choice-card
        }

        $out .= "</td>";
        $out .= "</tr>";

        return $out;
    }

    // -------------------------------------------------------------------------
    // Traitement d'une société
    // -------------------------------------------------------------------------

    private function process_single_company($company) {
        global $wpdb;

        $name = preg_replace('/^(Madame|Monsieur|M\.|Mme|Dr\.?|Me\.?)\s+/i', '', $company->company_name);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if (mb_strlen($name) > 80) {
            $this->log("  [SKIP] Nom trop long (" . mb_strlen($name) . " chars) — marqué manual_review");
            $wpdb->update($this->table_name, ['uid_status' => 'manual_review'], ['viag_id' => $company->viag_id]);
            return "⚠️ Nom trop long";
        }

        $this->log("Recherche SPARQL pour : $name");
        $results = $this->sparql_search($name);

        if ($results === null) {
            $wpdb->update($this->table_name, ['uid_status' => 'api_error'], ['viag_id' => $company->viag_id]);
            return "❌ Erreur SPARQL";
        }

        if (empty($results)) {
            $wpdb->update($this->table_name, ['uid_status' => 'not_found'], ['viag_id' => $company->viag_id]);
            return "❓ Non trouvé";
        }

        if (count($results) === 1) {
            $res = $results[0];
            $wpdb->update($this->table_name, [
                'uid_number'     => $res['uid'],
                'uid_status'     => $res['status'],
                'last_uid_check' => current_time('mysql'),
            ], ['viag_id' => $company->viag_id]);
            $this->log("UID lié : {$res['uid']}");
            return "✅ {$res['uid']} lié";
        }

        $choices = array_slice($results, 0, 8);
        $wpdb->update($this->table_name, [
            'uid_validation_data' => json_encode($choices),
        ], ['viag_id' => $company->viag_id]);
        return "⚠️ " . count($results) . " homonymes";
    }

    // -------------------------------------------------------------------------
    // Requête SPARQL — cURL direct, bypass WP_Http
    // -------------------------------------------------------------------------

    private function sparql_search($name) {
        $search_term = $this->normalize_for_search($name);
        $safe_term   = str_replace(['\\', '"'], ['\\\\', '\\"'], strtolower($search_term));

        $this->log("  [SPARQL] Nom brut      : " . $name);
        $this->log("  [SPARQL] Terme recherche: " . $safe_term);

        $sparql = 'PREFIX schema: <http://schema.org/>
PREFIX admin:  <https://schema.ld.admin.ch/>

SELECT DISTINCT ?legalName ?city ?status ?uidIri
FROM <https://lindas.admin.ch/foj/zefix>
WHERE {
  ?company a admin:ZefixOrganisation ;
           schema:legalName ?legalName .

  OPTIONAL { ?company schema:address/schema:addressLocality ?city . }

  OPTIONAL {
    ?company schema:identifier ?uidIri .
    FILTER(CONTAINS(STR(?uidIri), "/UID/"))
  }

  OPTIONAL { ?company schema:dissolutionDate ?dissDate . }

  BIND(IF(BOUND(?dissDate), "inactive", "active") AS ?status)

  FILTER(CONTAINS(LCASE(STR(?legalName)), "' . $safe_term . '"))
}
ORDER BY ?legalName
LIMIT 10';

        $this->log("  [SPARQL] Filtre : CONTAINS(LCASE, \"" . $safe_term . "\")");
        $this->log("  [SPARQL] Envoi cURL direct vers : " . self::SPARQL_ENDPOINT);

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::SPARQL_ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'query'  => $sparql,
                'format' => 'application/sparql-results+json',
            ]),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/sparql-results+json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ISPAG-WP-Plugin/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $elapsed = round((microtime(true) - $time_start) * 1000) . 'ms';

        if ($errno) {
            $this->log("  [SPARQL] ❌ Erreur cURL ({$elapsed}) : [{$errno}] {$error}");
            return null;
        }

        $this->log("  [SPARQL] HTTP {$code} — durée : {$elapsed} — réponse : " . strlen($body) . " octets");

        if ($code !== 200) {
            $this->log("  [SPARQL] ❌ Body erreur : " . substr($body, 0, 1000));
            return null;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("  [SPARQL] ❌ JSON invalide : " . json_last_error_msg());
            $this->log("  [SPARQL] Body brut : " . substr($body, 0, 500));
            return null;
        }

        $bindings = $data['results']['bindings'] ?? [];
        $this->log("  [SPARQL] ✅ " . count($bindings) . " résultat(s) brut(s)");

        if (empty($bindings)) {
            return [];
        }

        $results = [];
        foreach ($bindings as $i => $row) {
            $uid_iri = $row['uidIri']['value'] ?? '';
            $uid     = 'N/A';

            if (preg_match('/\/UID\/(CHE\d{9})$/', $uid_iri, $m)) {
                $digits = substr($m[1], 3);
                $uid    = 'CHE-' . substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3);
            } else {
                $this->log("  [SPARQL] ⚠️  Résultat #{$i} — uidIri non parsé : '{$uid_iri}'");
            }

            $city   = $row['city']['value']      ?? 'N/A';
            $rname  = $row['legalName']['value'] ?? 'N/A';
            $status = $row['status']['value']    ?? 'active';

            $this->log("  [SPARQL]   #{$i} → uid={$uid} | name={$rname} | city={$city} | status={$status}");

            if (!isset($results[$uid])) {
                $results[$uid] = [
                    'uid'    => $uid,
                    'name'   => $rname,
                    'town'   => $city,
                    'status' => $status,
                ];
            }
        }

        $this->log("  [SPARQL] " . count($results) . " résultat(s) après dédoublonnage");

        return array_values($results);
    }

    // -------------------------------------------------------------------------
    // Normalisation ASCII
    // -------------------------------------------------------------------------

    private function normalize_for_search($name) {
        $from = [
            'à','á','â','ã','ä','å','æ','ç','è','é','ê','ë',
            'ì','í','î','ï','ð','ñ','ò','ó','ô','õ','ö','ø',
            'ù','ú','û','ü','ý','þ','ÿ','ß','œ','š','ž',
            'À','Á','Â','Ã','Ä','Å','Æ','Ç','È','É','Ê','Ë',
            'Ì','Í','Î','Ï','Ð','Ñ','Ò','Ó','Ô','Õ','Ö','Ø',
            'Ù','Ú','Û','Ü','Ý','Þ','Œ','Š','Ž',
        ];
        $to = [
            'a','a','a','a','a','a','ae','c','e','e','e','e',
            'i','i','i','i','d','n','o','o','o','o','o','o',
            'u','u','u','u','y','th','y','ss','oe','s','z',
            'A','A','A','A','A','A','AE','C','E','E','E','E',
            'I','I','I','I','D','N','O','O','O','O','O','O',
            'U','U','U','U','Y','TH','OE','S','Z',
        ];
        return str_replace($from, $to, $name);
    }

    // -------------------------------------------------------------------------
    // Page d'administration
    // -------------------------------------------------------------------------

    public function render_validation_page() {
        global $wpdb;

        $pending = $wpdb->get_results(
            "SELECT viag_id, company_name, uid_validation_data
             FROM {$this->table_name}
             WHERE uid_validation_data IS NOT NULL
             ORDER BY company_name"
        );

        $manual_review_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE uid_status = 'manual_review'"
        );

        $cron_running = wp_next_scheduled(self::CRON_HOOK) !== false;
        $start_url    = admin_url('admin.php?page=ispag-uid-validation&ispag_start_cron=1');
        $stop_url     = admin_url('admin.php?page=ispag-uid-validation&ispag_stop_cron=1');
        $debug_url    = admin_url('admin.php?page=ispag-uid-validation&ispag_uid_scan=1&debug_network=1');

        echo '<div class="wrap"><h1>Maintenance Registre du Commerce</h1>';
        echo '<p style="color:#666;margin-top:0;">Source : <a href="https://lindas.admin.ch" target="_blank">LINDAS / Zefix Linked Data</a> — sans authentification, mis à jour quotidiennement.</p>';

        if (isset($_GET['cron_started'])) echo '<div class="notice notice-success"><p>✅ Scan démarré — traitement en arrière-plan toutes les minutes.</p></div>';
        if (isset($_GET['cron_stopped'])) echo '<div class="notice notice-warning"><p>⏹ Scan arrêté.</p></div>';

        // Boutons
        echo '<div style="display:flex;gap:10px;align-items:center;margin-bottom:20px;">';
        if (!$cron_running) {
            echo '<a href="' . esc_url($start_url) . '" class="button button-primary">▶ Démarrer le scan</a>';
        } else {
            echo '<a href="' . esc_url($stop_url) . '" class="button button-secondary">⏹ Arrêter le scan</a>';
        }
        echo '<a href="' . esc_url($debug_url) . '" class="button">🔍 Diagnostic réseau</a>';
        echo '</div>';

        // Dashboard live
        echo '<div id="ispag-dashboard" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px;margin-bottom:25px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">';
        echo '<strong>📊 Statut du scan</strong>';
        echo '<span id="ispag-cron-badge"></span>';
        echo '</div>';
        echo '<div style="display:flex;gap:24px;font-size:14px;flex-wrap:wrap;">';
        echo '<span>🟢 Liés : <strong id="ispag-done">…</strong></span>';
        echo '<span>⏳ En attente : <strong id="ispag-pending">…</strong></span>';
        echo '<span>⚠️ Homonymes : <strong id="ispag-homonyms">…</strong></span>';
        echo '<span>❓ Non trouvés : <strong id="ispag-not-found">…</strong></span>';
        echo '<span>❌ Erreurs : <strong id="ispag-errors">…</strong></span>';
        echo '</div>';
        echo '<small id="ispag-next-tick" style="color:#888;margin-top:8px;display:block;"></small>';
        echo '</div>';

        if ($manual_review_count > 0) {
            echo '<div class="notice notice-warning"><p>';
            echo '⚠️ <strong>' . $manual_review_count . ' société(s)</strong> marquées <code>manual_review</code> (noms corrompus ou trop longs).';
            echo ' Corrigez-les en BDD : <code>WHERE uid_status = \'manual_review\'</code>';
            echo '</p></div>';
        }

        // Tableau des homonymes
        echo '<h2 id="ispag-homonyms-title" style="margin-top:10px;">';
        echo 'Homonymes à valider' . ($pending ? ' (' . count($pending) . ')' : '');
        echo '</h2>';

        if ($pending) {
            echo '<table class="widefat" style="margin-top:8px;border-collapse:collapse;">';
            echo '<thead><tr>';
            echo '<th style="width:220px;">Société CRM</th>';
            echo '<th>Correspondances Zefix</th>';
            echo '</tr></thead>';
            echo '<tbody id="ispag-homonyms-table">';
            foreach ($pending as $co) {
                $choices = json_decode($co->uid_validation_data, true);
                if (!$choices) continue;
                echo $this->render_homonym_row($co, $choices);
            }
            echo '</tbody></table>';
        } else {
            echo '<p id="ispag-no-homonyms" style="color:#666;">Aucune validation manuelle en attente.</p>';
        }

        echo '</div>'; // .wrap
        ?>
        <style>
        .ispag-choice-card:hover {
            background: #f0f6fc !important;
            border-color: #2271b1 !important;
        }
        #ispag-homonyms-table tr {
            border-bottom: 1px solid #f0f0f0;
        }
        #ispag-homonyms-table td {
            padding: 12px 10px;
            vertical-align: top;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {

            var nonce_status   = '<?php echo wp_create_nonce('ispag_cron_status'); ?>';
            var nonce_homonyms = '<?php echo wp_create_nonce('ispag_get_homonyms'); ?>';
            var nonce_confirm  = '<?php echo wp_create_nonce('ispag_confirm_uid'); ?>';
            var prevHomonyms   = <?php echo count($pending); ?>;

            // -----------------------------------------------------------------
            // Polling statut
            // -----------------------------------------------------------------
            function refreshStatus() {
                $.post(ajaxurl, { action: 'ispag_cron_status', nonce: nonce_status }, function(res) {
                    if (!res.success) return;
                    var d = res.data;

                    $('#ispag-done').text(d.done);
                    $('#ispag-pending').text(d.pending);
                    $('#ispag-not-found').text(d.not_found);
                    $('#ispag-errors').text(d.errors);

                    $('#ispag-cron-badge').html(d.cron_running
                        ? '<span style="color:#00a32a;font-weight:bold;font-size:12px;">● EN COURS</span>'
                        : '<span style="color:#999;font-size:12px;">● Arrêté</span>'
                    );
                    $('#ispag-next-tick').text(
                        d.cron_running && d.next_tick ? 'Prochain batch dans : ' + d.next_tick : ''
                    );

                    // Nouveaux homonymes → rafraîchir le tableau
                    if (d.homonyms !== prevHomonyms) {
                        prevHomonyms = d.homonyms;
                        $('#ispag-homonyms').text(d.homonyms);
                        $('#ispag-homonyms-title').text(
                            d.homonyms > 0 ? 'Homonymes à valider (' + d.homonyms + ')' : 'Homonymes à valider'
                        );
                        refreshHomonyms();
                    } else {
                        $('#ispag-homonyms').text(d.homonyms);
                    }

                    // Scan terminé
                    if (!d.cron_running && d.pending === 0) {
                        $('#ispag-next-tick').text('');
                    }
                });
            }

            // -----------------------------------------------------------------
            // Rafraîchissement du tableau des homonymes
            // -----------------------------------------------------------------
            function refreshHomonyms() {
                $.post(ajaxurl, { action: 'ispag_get_homonyms', nonce: nonce_homonyms }, function(res) {
                    if (!res.success) return;
                    if (res.data.html) {
                        $('#ispag-homonyms-table').html(res.data.html);
                        $('#ispag-no-homonyms').hide();
                    } else {
                        $('#ispag-homonyms-table').html('');
                        $('#ispag-no-homonyms').show().text('Aucune validation manuelle en attente.');
                    }
                });
            }

            // -----------------------------------------------------------------
            // Survol des cartes
            // -----------------------------------------------------------------
            $(document).on('mouseenter', '.ispag-choice-card', function() {
                $(this).css({ background: '#f0f6fc', borderColor: '#2271b1' });
            }).on('mouseleave', '.ispag-choice-card', function() {
                $(this).css({ background: '#fff', borderColor: '#dcdcde' });
            });

            // -----------------------------------------------------------------
            // Clic sur la carte entière → lier
            // -----------------------------------------------------------------
            $(document).on('click', '.ispag-choice-card', function() {
                var val = $(this).data('val');
                var id  = $(this).closest('tr').data('id');
                lierUID(id, val, $(this).closest('tr'));
            });

            // -----------------------------------------------------------------
            // Bouton "Lier" dans la carte
            // -----------------------------------------------------------------
            $(document).on('click', '.do-validate-card', function(e) {
                e.stopPropagation();
                var id  = $(this).data('id');
                var val = $(this).data('val');
                lierUID(id, val, $(this).closest('tr'));
            });

            // -----------------------------------------------------------------
            // Bouton "Aucun résultat"
            // -----------------------------------------------------------------
            $(document).on('click', '.do-skip', function() {
                var btn = $(this);
                var id  = btn.data('id');
                btn.prop('disabled', true).text('...');
                $.post(ajaxurl, {
                    action:  'ispag_confirm_uid',
                    viag_id: id,
                    choice:  null,
                    nonce:   nonce_confirm
                }, function(res) {
                    if (res.success) {
                        btn.closest('tr').fadeOut(400, function() {
                            $(this).remove();
                            updateHomonymCount(-1);
                        });
                    } else {
                        btn.prop('disabled', false).text('✖ Aucun résultat');
                    }
                });
            });

            // -----------------------------------------------------------------
            // Lier un UID
            // -----------------------------------------------------------------
            function lierUID(id, val, $row) {
                $row.css('opacity', '0.5');
                var choiceJson = typeof val === 'object' ? JSON.stringify(val) : val;
                $.post(ajaxurl, {
                    action:  'ispag_confirm_uid',
                    viag_id: id,
                    choice:  choiceJson,
                    nonce:   nonce_confirm
                }, function(res) {
                    if (res.success) {
                        $row.fadeOut(400, function() {
                            $(this).remove();
                            updateHomonymCount(-1);
                        });
                    } else {
                        $row.css('opacity', '1');
                    }
                });
            }

            // -----------------------------------------------------------------
            // Met à jour le compteur d'homonymes après action
            // -----------------------------------------------------------------
            function updateHomonymCount(delta) {
                prevHomonyms = Math.max(0, prevHomonyms + delta);
                $('#ispag-homonyms').text(prevHomonyms);
                $('#ispag-homonyms-title').text(
                    prevHomonyms > 0
                        ? 'Homonymes à valider (' + prevHomonyms + ')'
                        : 'Homonymes à valider'
                );
                if (prevHomonyms === 0) {
                    $('#ispag-no-homonyms').show().text('Aucune validation manuelle en attente.');
                }
            }

            // -----------------------------------------------------------------
            // Init
            // -----------------------------------------------------------------
            refreshStatus();
            setInterval(refreshStatus, 10000);
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Diagnostic réseau
    // -------------------------------------------------------------------------

    private function run_network_diagnostics() {
        echo "<div style='background:#1d2327;color:#f0f0f1;padding:30px;font-family:monospace;font-size:13px;'>";
        echo "<h2 style='color:#72aee6;'>🔍 Diagnostic réseau SPARQL / LINDAS</h2>";

        $tests = [
            'Test google.com'      => 'https://www.google.com',
            'Test admin.ch racine' => 'https://www.admin.ch',
            'DNS lindas.admin.ch'  => 'https://lindas.admin.ch',
            'SPARQL GET ASK'       => 'https://lindas.admin.ch/query?query=ASK+%7B%7D&format=application%2Fsparql-results%2Bjson',
        ];

        foreach ($tests as $label => $url) {
            echo "<br>─────────────────────────────────<br>";
            echo "🧪 <b>{$label}</b><br>   URL : {$url}<br>";
            $start = microtime(true);
            $r     = wp_remote_get($url, ['timeout' => 10]);
            $ms    = round((microtime(true) - $start) * 1000);
            if (is_wp_error($r)) {
                echo "   ❌ ({$ms}ms) : " . esc_html($r->get_error_message()) . "<br>";
            } else {
                $code = wp_remote_retrieve_response_code($r);
                echo "   " . ($code === 200 ? '✅' : '⚠️') . " HTTP {$code} — {$ms}ms — " . strlen(wp_remote_retrieve_body($r)) . " octets<br>";
            }
        }

        echo "<br>─────────────────────────────────<br>";
        echo "🧪 <b>SPARQL POST ASK {}</b><br>";
        $start = microtime(true);
        $r = wp_remote_post(self::SPARQL_ENDPOINT, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/sparql-results+json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => ['query' => 'ASK {}'],
        ]);
        $ms = round((microtime(true) - $start) * 1000);
        if (is_wp_error($r)) {
            echo "   ❌ ({$ms}ms) : " . esc_html($r->get_error_message()) . "<br>";
        } else {
            echo "   ✅ HTTP " . wp_remote_retrieve_response_code($r) . " — {$ms}ms — " . esc_html(substr(wp_remote_retrieve_body($r), 0, 100)) . "<br>";
        }

        echo "<br>─────────────────────────────────<br>";
        echo "🧪 <b>cURL direct — CONTAINS search (\"progin\")</b><br>";
        $sparql = 'PREFIX schema: <http://schema.org/>
PREFIX admin: <https://schema.ld.admin.ch/>
SELECT DISTINCT ?legalName FROM <https://lindas.admin.ch/foj/zefix>
WHERE {
  ?c a admin:ZefixOrganisation ; schema:legalName ?legalName .
  FILTER(CONTAINS(LCASE(STR(?legalName)), "progin"))
} LIMIT 3';
        $start = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::SPARQL_ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['query' => $sparql, 'format' => 'application/sparql-results+json']),
            CURLOPT_HTTPHEADER     => ['Accept: application/sparql-results+json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        $ms = round((microtime(true) - $start) * 1000);
        if ($errno) {
            echo "   ❌ ({$ms}ms) cURL [{$errno}] : " . esc_html($error) . "<br>";
        } else {
            echo "   " . ($code === 200 ? '✅' : '⚠️') . " HTTP {$code} — {$ms}ms<br>";
            echo "   Body : " . esc_html(substr($body, 0, 400)) . "<br>";
        }

        echo "<br>─────────────────────────────────<br>";
        echo "ℹ️ <b>Infos serveur</b><br>";
        echo "   PHP : " . PHP_VERSION . "<br>";
        echo "   IP  : " . esc_html($_SERVER['SERVER_ADDR'] ?? 'N/A') . "<br>";
        echo "   DNS : " . esc_html(gethostbyname('lindas.admin.ch')) . "<br>";
        echo "   allow_url_fopen : " . (ini_get('allow_url_fopen') ? '✅' : '❌') . "<br>";

        echo "<br><a href='" . esc_url(admin_url('admin.php?page=ispag-uid-validation')) . "' style='color:#72aee6;'>← Retour</a>";
        echo "</div>";
    }

    // -------------------------------------------------------------------------
    // AJAX — confirmation manuelle d'un UID
    // -------------------------------------------------------------------------

    public function ajax_confirm_uid() {
        check_ajax_referer('ispag_confirm_uid', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Accès refusé'], 403);
        }

        global $wpdb;
        $viag_id = intval($_POST['viag_id']);

        if (empty($_POST['choice']) || $_POST['choice'] === 'null') {
            $wpdb->update($this->table_name, [
                'uid_validation_data' => null,
                'uid_status'          => 'not_found',
                'last_uid_check'      => current_time('mysql'),
            ], ['viag_id' => $viag_id]);
            wp_send_json_success();
            return;
        }

        $raw    = stripslashes($_POST['choice']);
        $choice = json_decode($raw, true);

        // Double-décodage si le JS a envoyé un JSON stringifié deux fois
        if (!is_array($choice)) {
            $choice = json_decode($choice, true);
        }

        if (!$choice || empty($choice['uid'])) {
            wp_send_json_error(['message' => 'Données invalides — reçu : ' . esc_html(substr($raw, 0, 200))], 400);
            return;
        }

        $wpdb->update($this->table_name, [
            'uid_number'          => sanitize_text_field($choice['uid']),
            'uid_status'          => sanitize_text_field($choice['status']),
            'uid_validation_data' => null,
            'last_uid_check'      => current_time('mysql'),
        ], ['viag_id' => $viag_id]);

        wp_send_json_success();
    }
}

// Intervalle cron enregistré globalement (nécessaire avant instanciation)
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => 'Toutes les minutes',
        ];
    }
    return $schedules;
});

new ISPAG_Company_Registry_Sync();