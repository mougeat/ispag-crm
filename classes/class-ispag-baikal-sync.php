<?php
defined('ABSPATH') || exit;
/**
 * Class ISPAG_Baikal_Sync
 * Synchronise les contacts entre le CRM ISPAG et Baïkal (CardDAV), dans les
 * deux sens, avec résolution de conflit "le plus récent gagne".
 *
 * L'appartenance au périmètre de synchro se détermine via la table
 * wor9711_ispag_contacts_owners (status = 'active', department_key = 'vaulruz_ispag').
 */
class ISPAG_Baikal_Sync
{
    private $log_file = 'ispag_baikal_sync';

    // Réglages (serveur, carnet, utilisateurs cibles, département, mot de passe) : ISPAG Settings → Calendar sync
    // (plugin Project Manager, classe ISPAG_Baikal_Settings). Le mot de passe n'est plus dans le code.
    private function cfg(): array {
        if (class_exists('ISPAG_Baikal_Settings')) {
            return ISPAG_Baikal_Settings::contacts();
        }
        return ['enabled' => 0, 'host' => '', 'addressbook' => 'ispag', 'users' => [], 'departments' => ['vaulruz_ispag'], 'interval' => 'hourly', 'password' => ''];
    }
    private function pass(): string { return (string) $this->cfg()['password']; }
    private function targets(): array { return (array) $this->cfg()['users']; }
    /** Départements synchronisés (clés). */
    private function depts(): array { return array_values((array) $this->cfg()['departments']); }
    private function is_enabled(): bool {
        $c = $this->cfg();
        return !empty($c['enabled']) && $c['host'] !== '' && $c['password'] !== '' && $c['users'] && $c['departments'];
    }
    private function ab_url(string $user, string $file = ''): string {
        $c = $this->cfg();
        return apply_filters('ispag_baikal_scheme', 'https') . '://' . $c['host'] . '/dav.php/addressbooks/' . rawurlencode($user) . '/' . rawurlencode($c['addressbook']) . '/' . $file;
    }

    /** Meta locale trackant le dernier changement "pertinent" du contact. */
    private const META_LOCAL_MODIFIED = '_ispag_baikal_local_modified';

    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $table_owners;

    /** @var ISPAG_Logger */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_owners = $wpdb->prefix . 'ispag_contacts_owners';
        $this->logger = ISPAG_Logger::get_instance();

        // Synchro sortante (CRM -> Baïkal)
        add_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);
        add_action('added_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);

        // Synchro entrante (Baïkal -> CRM), planifiée (activation et fréquence : ISPAG Settings → Calendar sync)
        add_action('ispag_sync_from_baikal_cron', [$this, 'sync_all_from_baikal']);
        add_action('init', [$this, 'ensure_scheduled'], 20);

        // Suppression : on nettoie les deux carnets sans condition de département
        add_action('delete_user', function ($user_id) {
            if (!$this->is_enabled()) return;
            foreach ($this->targets() as $baikal_user) {
                $this->delete_from_baikal($user_id, $baikal_user);
            }
        });

        // Point d'entrée AJAX pour le traitement par lots
        add_action('wp_ajax_ispag_sync_batch', [$this, 'ajax_sync_batch']);
    }

    /** Planifie / replanifie / supprime le cron entrant selon les réglages. */
    public function ensure_scheduled()
    {
        $hook    = 'ispag_sync_from_baikal_cron';
        $next    = wp_next_scheduled($hook);
        $current = $next ? wp_get_schedule($hook) : false;
        if (!$this->is_enabled()) {
            if ($next) wp_clear_scheduled_hook($hook);
            return;
        }
        $interval = (string) $this->cfg()['interval'];
        $interval = in_array($interval, ['hourly', 'twicedaily', 'daily'], true) ? $interval : 'hourly';
        if ($current !== $interval) {
            if ($next) wp_clear_scheduled_hook($hook);
            wp_schedule_event(time() + 120, $interval, $hook);
        }
    }

    /**
     * Méthode interne de log générique utilisant ISPAG_Logger.
     */
    private function log($message, array $context = [])
    {
        if (!empty($context)) {
            $this->logger->log_error($this->log_file, $message, $context, get_current_user_id());
        } else {
            $this->logger->log($this->log_file, $message, get_current_user_id());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // PÉRIMÈTRE : lecture directe de la table owners
    // ─────────────────────────────────────────────────────────────────

    private function get_contact_department($contact_id)
    {
        $dept = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT department_key FROM {$this->table_owners}
             WHERE contact_id = %d AND status = 'active'
             ORDER BY assigned_at DESC
             LIMIT 1",
            $contact_id
        ));

        $this->logger->log_db_change($this->log_file, $this->table_owners, 'GET_DEPARTMENT', [
            'contact_id' => $contact_id,
            'department' => $dept,
        ], get_current_user_id());

        return $dept ?: null;
    }

    private function is_contact_in_sync_scope($contact_id)
    {
        return in_array($this->get_contact_department($contact_id), $this->depts(), true);
    }

    // ─────────────────────────────────────────────────────────────────
    // HORODATAGE LOCAL (pour la résolution de conflit)
    // ─────────────────────────────────────────────────────────────────

    private function touch_local_modified($contact_id,$timestamp = null)
    {
        $timestamp =$timestamp ?? time();
        update_user_meta($contact_id, self::META_LOCAL_MODIFIED,$timestamp);
        return $timestamp;
    }

    private function get_local_modified($contact_id)
    {
        $ts = get_user_meta($contact_id, self::META_LOCAL_MODIFIED, true);
        return $ts ? (int) $ts : 0;
    }

    private function parse_vcard_rev($vcard_content)
    {
        if (preg_match('/^REV:(.+)$/m', $vcard_content,$m)) {
            $ts = strtotime(trim($m[1]));
            return $ts ?: 0;
        }
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────
    // SYNCHRO SORTANTE (CRM -> Baïkal)
    // ─────────────────────────────────────────────────────────────────

    public function trigger_sync_on_meta_update($meta_id,$object_id, $meta_key,$_meta_value)
    {
        $keys_to_watch = [
            ISPAG_Crm_Contact_Constants::META_OWNER,
            ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,
            ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION,
            ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
            ISPAG_Crm_Contact_Constants::META_USER_ROLE,
            ISPAG_Crm_Contact_Constants::PRIORITY_LEVEL,
            ISPAG_Crm_Contact_Constants::USER_AVATAR,
            'user_email', 'first_name', 'last_name',
        ];

        if ($meta_key === self::META_LOCAL_MODIFIED ||$meta_key === '_baikal_last_etag') {
            return;
        }

        if (!$this->is_enabled()) {
            return;
        }

        if (!in_array($meta_key,$keys_to_watch, true)) {
            return;
        }

        $this->logger->log_user_action($this->log_file, 'trigger_sync', [
            'contact_id' => $object_id,
            'meta_key' => $meta_key
        ], get_current_user_id());

        $this->touch_local_modified($object_id);
        $this->sync_contact_to_baikal($object_id);
    }

    public function sync_contact_to_baikal($contact_id)
    {
        if (!$this->is_enabled()) {
            return;
        }
        if (!$this->is_contact_in_sync_scope($contact_id)) {$this->logger->log($this->log_file, "INFO : Contact {$contact_id} hors périmètre (" . implode(', ', $this->depts()) . ") — synchro ignorée.", get_current_user_id());
            return;
        }

        $repo = new ISPAG_Crm_Contacts_Repository();$contact = $repo->get_contact_by_id($contact_id);

        if (!$contact) {$this->logger->log_error($this->log_file, "Contact {$contact_id} introuvable pour la synchronisation.", ['contact_id' => $contact_id], get_current_user_id());
            return;
        }

        $local_vcard =$this->generate_vcard($contact);$local_ts = $this->get_local_modified($contact_id);

        foreach ($this->targets() as $baikal_user) {$this->resolve_and_push($contact_id,$baikal_user, $local_vcard,$local_ts);
        }
    }

    private function resolve_and_push($contact_id,$baikal_user, $local_vcard,$local_ts)
    {
        $remote_vcard =$this->fetch_remote_vcard($contact_id,$baikal_user);

        if ($remote_vcard === null) {$this->push_to_baikal($contact_id,$baikal_user, $this->pass(),$local_vcard);
            return;
        }

        $remote_ts = $this->parse_vcard_rev($remote_vcard);

        if ($remote_ts >$local_ts) {
            $this->logger->log($this->log_file, "CONFLIT contact {$contact_id} [{$baikal_user}] : distant plus récent ({$remote_ts} > {$local_ts}) -> import au lieu d'écrasement.", get_current_user_id());
            
            // Log détaillé de la modification par conflit (provenance Baïkal)
            $this->logger->log_user_action($this->log_file, 'contact_modified_from_remote', [
                'contact_id' => $contact_id,
                'target_user' => $baikal_user,
                'remote_timestamp' => $remote_ts,
                'local_timestamp' => $local_ts
            ], get_current_user_id());

            $this->apply_remote_vcard_to_local($contact_id, $remote_vcard,$remote_ts);
            return;
        }

        $this->push_to_baikal($contact_id,$baikal_user, $this->pass(),$local_vcard);
    }

    private function fetch_remote_vcard($contact_id,$baikal_user)
    {
        $url = $this->ab_url($baikal_user, "contact-{$contact_id}.vcf");

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("{$baikal_user}:{$this->pass()}")],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->logger->log_error($this->log_file, "Error GET distant contact {$contact_id} pour [{$baikal_user}]", [
                'error' => $response->get_error_message()
            ], get_current_user_id());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    private function generate_vcard($c)
    {
        $first_name = !empty($c->first_name) ? $c->first_name : get_user_meta($c->ID, 'first_name', true);
        $last_name = !empty($c->last_name) ? $c->last_name : get_user_meta($c->ID, 'last_name', true);

        if (empty($first_name) && empty($last_name) && !empty($c->display_name)) {
            $parts = explode(' ', trim($c->display_name), 2);
            $first_name =$parts[0] ?? '';
            $last_name =$parts[1] ?? '';
        }

        $display_name = !empty($c->display_name) ?$c->display_name : trim($first_name . ' ' .$last_name);
        $email =$c->email ?? '';
        $phone =$c->phone ?? '';
        $company =$c->company_name ?? '';
        $job =$c->lead_function ?? '';

        $v = "BEGIN:VCARD\r\n";
        $v .= "VERSION:3.0\r\n";
        $v .= "N;CHARSET=UTF-8:{$last_name};{$first_name};;;\r\n";
        $v .= "FN;CHARSET=UTF-8:{$display_name}\r\n";

        $avatar_id = get_user_meta($c->ID, ISPAG_Crm_Contact_Constants::USER_AVATAR, true);
        if ($avatar_id) {
            $path = get_attached_file($avatar_id);
            if ($path && file_exists($path)) {
                $type = strtoupper(wp_check_filetype($path)['ext'] == 'jpg' ? 'JPEG' : wp_check_filetype($path)['ext']);$data = base64_encode(file_get_contents($path));$v .= "PHOTO;TYPE={$type};ENCODING=b:" . $data . "\r\n";
            }
        }

        if (!empty($company)) $v .= "ORG;CHARSET=UTF-8:{$company}\r\n";
        if (!empty($job)) $v .= "TITLE;CHARSET=UTF-8:{$job}\r\n";

        $v .= "EMAIL;TYPE=INTERNET,WORK:{$email}\r\n";
        if (!empty($phone)) $v .= "TEL;TYPE=CELL,VOICE:{$phone}\r\n";

        $v .= "REV:" . date('Ymd\THis\Z') . "\r\n";
        $v .= "END:VCARD";

        return $v;
    }

    private function push_to_baikal($id,$user, $pass,$vcard)
    {
        $url = $this->ab_url($user, "contact-{$id}.vcf");

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:$pass"),
                'Content-Type' => 'text/vcard; charset=utf-8',
            ],
            'body' => $vcard,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->logger->log_error($this->log_file, "Error PUSH [{$user}] pour le contact {$id}", [
                'error' => $response->get_error_message()
            ], get_current_user_id());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $etag = trim((string) wp_remote_retrieve_header($response, 'etag'), '"');
        
        $this->logger->log($this->log_file, "PUSH SUCCESS [{$user}] : Contact {$id} -> Code {$code}" . ($etag ? " (etag {$etag})" : ''), get_current_user_id());

        // Log d'une modification/création sortante validée vers le carnet distant
        $this->logger->log_user_action($this->log_file, 'contact_modified_outbound', [
            'contact_id' => $id,
            'target_user' => $user,
            'response_code' => $code,
            'etag' => $etag
        ], get_current_user_id());

        return $etag ?: true;
    }

    // ─────────────────────────────────────────────────────────────────
    // SYNCHRO MASSIVE (bouton manuel)
    // ─────────────────────────────────────────────────────────────────

    public function sync_all_contacts()
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $depts = $this->depts();
        $contact_ids = $depts ? $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT DISTINCT contact_id FROM {$this->table_owners}
             WHERE department_key IN (" . implode(',', array_fill(0, count($depts), '%s')) . ") AND status = 'active'",
            $depts
        )) : [];

        $total = count($contact_ids);
        $this->logger->log_user_action($this->log_file, 'manual_sync_all_start', ['total_contacts' =>$total], get_current_user_id());

        echo "<style>
            body { font-family: sans-serif; background: #1d2327; color: #f0f0f1; padding: 20px; }
            .log-entry { font-family: monospace; font-size: 12px; margin-bottom: 4px; border-bottom: 1px solid #333; padding: 4px 0; }
            .success { color: #00ff00; }
            .error { color: #ff5555; }
            .header { position: sticky; top: 0; background: #1d2327; padding: 10px 0; border-bottom: 2px solid #333; margin-bottom: 20px; z-index: 10; }
            #stats { font-size: 18px; font-weight: bold; color: #ffb900; }
            #progress-bar { width: 100%; background: #333; height: 10px; border-radius: 5px; margin-top: 10px; overflow: hidden; }
            #progress-fill { width: 0%; background: #2271b1; height: 100%; transition: width 0.3s ease; }
        </style>";
        
        echo "<div class='header'>
                <h1>🚀 Baïkal batch sync: Cyril & Claudio</h1>
                <p id='stats'>Preparing...</p>
                <div id='progress-bar'><div id='progress-fill'></div></div>
              </div>
              <div id='log-container'></div>";

        $json_ids = json_encode($contact_ids);$ajax_url = admin_url('admin-ajax.php');

        echo "<script>
        const contactIds = {$json_ids};
        const total = contactIds.length;
        const batchSize = 5;
        let currentIndex = 0;

        const statsEl = document.getElementById('stats');
        const fillEl = document.getElementById('progress-fill');
        const containerEl = document.getElementById('log-container');

        async function processBatch() {
            if (currentIndex >= total) {
                statsEl.innerHTML = 'Sync completed successfully! 🎉';
                fillEl.style.width = '100%';
                let finishDiv = document.createElement('div');
                finishDiv.style.marginTop = '30px';
                finishDiv.innerHTML = '<a href=\"" . admin_url() . "\" style=\"display:inline-block; background:#2271b1; color:white; padding:10px 20px; text-decoration:none; border-radius:3px;\">Back to CRM</a>';
                containerEl.appendChild(finishDiv);
                return;
            }

            let chunk = contactIds.slice(currentIndex, currentIndex + batchSize);
            statsEl.innerHTML = `Progress: \${currentIndex} / \${total} contacts processed...`;
            let percent = (currentIndex / total) * 100;
            fillEl.style.width = percent + '%';

            try {
                let formData = new FormData();
                formData.append('action', 'ispag_sync_batch');
                formData.append('ids', JSON.stringify(chunk));

                let response = await fetch('{$ajax_url}', {
                    method: 'POST',
                    body: formData
                });

                let result = await response.json();

                if (result.success && result.data.logs) {
                    result.data.logs.forEach(log => {
                        let div = document.createElement('div');
                        div.className = 'log-entry ' + log.type;
                        div.innerHTML = log.message;
                        containerEl.appendChild(div);
                    });
                } else {
                    let div = document.createElement('div');
                    div.className = 'log-entry error';
                    div.innerHTML = '❌ Server error : ' + (result.data?.message || 'Invalid response');
                    containerEl.appendChild(div);
                }
            } catch (e) {
                let div = document.createElement('div');
                div.className = 'log-entry error';
                div.innerHTML = '❌ Network error / JS : ' + e.message;
                containerEl.appendChild(div);
            }

            currentIndex += batchSize;
            window.scrollTo(0, document.body.scrollHeight);
            processBatch();
        }

        if (total === 0) {
            statsEl.innerHTML = '⚠️ No active contact found for " . esc_js(implode(', ', $this->depts())) . ".';
        } else {
            processBatch();
        }
        </script>";
        exit;
    }

    public function ajax_sync_batch()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $ids = isset($_POST['ids']) ? json_decode(stripslashes($_POST['ids']), true) : [];
        if (empty($ids) || !is_array($ids)) {
            wp_send_json_error(['message' => 'No ID received ou format invalide.']);
        }

        $logs = [];$repo = new ISPAG_Crm_Contacts_Repository();

        foreach ($ids as $contact_id) {$contact = $repo->get_contact_by_id($contact_id);

            if ($contact) {
                $this->sync_contact_to_baikal($contact_id);
                $name = !empty($contact->display_name) ? $contact->display_name : "ID: {$contact_id}";
                $logs[] = [
                    'type' => 'success',
                    'message' => "✅ Synchro OK : <b>" . esc_html($name) . "</b>"
                ];
            } else {
                $logs[] = [
                    'type' => 'error',
                    'message' => "❌ Contact {$contact_id} introuvable."
                ];
            }
        }

        wp_send_json_success(['logs' => $logs]);
    }

    // ─────────────────────────────────────────────────────────────────
    // SYNCHRO ENTRANTE (Baïkal -> CRM)
    // ─────────────────────────────────────────────────────────────────

    public function sync_all_from_baikal()
    {
        if (!$this->is_enabled()) {
            return;
        }
        $this->logger->log_user_action($this->log_file, 'cron_sync_from_baikal_start', [], get_current_user_id());

        $sum = ['time' => time(), 'found' => 0, 'unchanged' => 0, 'processed' => 0, 'errors' => 0];
        foreach ($this->targets() as $user) {
            $r = $this->pull_addressbook_from_baikal($user, $this->pass());
            if ($r === null) { $sum['errors']++; continue; }
            $sum['found'] += $r['found']; $sum['unchanged'] += $r['skipped']; $sum['processed'] += $r['updated'];
        }
        update_option('ispag_baikal_contacts_last_run', $sum, false); // affiché dans ISPAG Settings → Calendar sync

        $this->logger->log_user_action($this->log_file, 'cron_sync_from_baikal_end', [], get_current_user_id());
    }

    private function pull_addressbook_from_baikal($user,$pass)
    {
        $url = $this->ab_url($user);

        $response = wp_remote_request($url, [
            'method' => 'PROPFIND',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:$pass"),
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'body' => '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag /></d:prop></d:propfind>',
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            $this->logger->log_error($this->log_file, "Error PROPFIND pour l'utilisateur [{$user}]", [
                'error' => $response->get_error_message()
            ], get_current_user_id());
            return null;
        }

        if (wp_remote_retrieve_response_code($response) !== 207) {
            $this->logger->log_error($this->log_file, "Réponse PROPFIND inattendue pour [{$user}]", [
                'response_code' => wp_remote_retrieve_response_code($response)
            ], get_current_user_id());
            return null;
        }

        $xml = simplexml_load_string(wp_remote_retrieve_body($response));
        if ($xml === false) {
            $this->logger->log_error($this->log_file, "Error parsing XML PROPFIND pour [{$user}]", [], get_current_user_id());
            return null;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $responses =$xml->xpath('//d:response');

        $found = 0; $skipped = 0; $updated = 0;

        foreach ($responses as$res) {
            $href_nodes =$res->xpath('d:href');
            if (empty($href_nodes)) continue;

            $href = (string)$href_nodes[0];
            if (!preg_match('/contact-(\d+)\.vcf$/', $href,$matches)) continue;

            $found++;
            $contact_id = (int)$matches[1];

            $etag_nodes = $res->xpath('.//d:getetag');$etag = !empty($etag_nodes) ? trim((string) $etag_nodes[0], '"') : '';
            $last_etag = get_user_meta($contact_id, '_baikal_last_etag', true);

            if ($etag === $last_etag) {$skipped++;
                continue;
            }

            $this->handle_remote_change($contact_id,$user, $pass,$href, $etag);$updated++;
        }

        $this->logger->log($this->log_file, "[{$user}] BILAN PULL : {$found} vcf | {$skipped} inchangés | {$updated} traités", get_current_user_id());
        return ['found' => $found, 'skipped' => $skipped, 'updated' => $updated];
    }

    private function handle_remote_change($contact_id, $user,$pass, $href,$etag)
    {
        $url = apply_filters('ispag_baikal_scheme', 'https') . '://' . $this->cfg()['host'] . $href;

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("$user:$pass")],
            'timeout' => 30,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->logger->log_error($this->log_file, "Impossible de récupérer la vCard distante du contact {$contact_id} pour [{$user}]", [], get_current_user_id());
            return;
        }

        $vcard_content = wp_remote_retrieve_body($response);
        $remote_ts =$this->parse_vcard_rev($vcard_content);$local_ts = $this->get_local_modified($contact_id);

        if ($remote_ts >=$local_ts) {
            $this->apply_remote_vcard_to_local($contact_id, $vcard_content,$remote_ts);
            update_user_meta($contact_id, '_baikal_last_etag',$etag);
            
            $this->logger->log($this->log_file, "[{$user}] Contact {$contact_id} : distant plus récent -> importé dans le CRM.", get_current_user_id());

            // Log explicite de la modification entrante
            $this->logger->log_user_action($this->log_file, 'contact_modified_from_remote', [
                'contact_id' => $contact_id,
                'target_user' => $user,
                'remote_timestamp' => $remote_ts
            ], get_current_user_id());

            $this->repropagate_to_other_targets($contact_id,$user);
            return;
        }

        $this->logger->log($this->log_file, "[{$user}] Contact {$contact_id} : local plus récent -> réécriture de Baïkal (conflit résolu en faveur du CRM).", get_current_user_id());
        
        $repo = new ISPAG_Crm_Contacts_Repository();$contact = $repo->get_contact_by_id($contact_id);
        if ($contact) {$local_vcard = $this->generate_vcard($contact);
            $new_etag =$this->push_to_baikal($contact_id,$user, $pass,$local_vcard);
            if (is_string($new_etag)) {
                update_user_meta($contact_id, '_baikal_last_etag',$new_etag);
            }
        }
    }

    private function apply_remote_vcard_to_local($contact_id, $vcard_content,$remote_ts)
    {
        $phone = '';$job = '';

        if (preg_match('/^TEL(?:;.*)?:(.*)$/m', $vcard_content,$m)) {
            $phone = trim($m[1]);
        }
        if (preg_match('/^TITLE(?:;.*)?:(.*)$/m', $vcard_content,$m)) {
            $job = trim($m[1]);
        }

        remove_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update']);
        remove_action('added_user_meta', [$this, 'trigger_sync_on_meta_update']);

        $updated_data = [];
        if (!empty($phone)) {
            update_user_meta($contact_id, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,$phone);
            $updated_data['phone'] =$phone;
        }
        if (!empty($job)) {
            update_user_meta($contact_id, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION,$job);
            $updated_data['job'] =$job;
        }

        $this->touch_local_modified($contact_id,$remote_ts ?: time());

        add_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);
        add_action('added_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);

        $this->logger->log_db_change($this->log_file, 'usermeta', 'UPDATE_FROM_REMOTE', [
            'contact_id' => $contact_id,
            'fields' => $updated_data
        ], get_current_user_id());
    }

    private function repropagate_to_other_targets($contact_id,$from_user)
    {
        $repo = new ISPAG_Crm_Contacts_Repository();$contact = $repo->get_contact_by_id($contact_id);
        if (!$contact) return;

        $vcard = $this->generate_vcard($contact);

        foreach ($this->targets() as $target_user) {
            if ($target_user ===$from_user) continue;
            $this->resolve_and_push($contact_id, $target_user,$vcard, $this->get_local_modified($contact_id));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // SUPPRESSION
    // ─────────────────────────────────────────────────────────────────

    public function delete_from_baikal($id,$user)
    {
        $url = $this->ab_url($user, "contact-{$id}.vcf");

        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => ['Authorization' => 'Basic ' . base64_encode("$user:{$this->pass()}")],
            'timeout' => 10,
        ]);

        $code = wp_remote_retrieve_response_code($response);
        
        // Log détaillé et explicite de la suppression effective d'un contact
        $this->logger->log_user_action($this->log_file, 'contact_deleted_from_baikal', [
            'contact_id' => $id,
            'target_user' => $user,
            'response_code' => $code,
            'status' => ($code === 204 || $code === 200 ||$code === 404) ? 'success' : 'failed'
        ], get_current_user_id());
    }
}