<?php
/**
 * Class ISPAG_Baikal_Sync
 * Gère la synchronisation des contacts entre le CRM ISPAG et Baïkal (CardDAV).
 * Utilise ISPAG_Logger pour centraliser les logs dans wp-content/ispag_logs/ispag_baikal_sync.log.
 */
class ISPAG_Baikal_Sync_OLD
{
    private $baikal_ip = 'contacts.barthels.duckdns.org';
    private $addressbook_token = 'ispag';
    private $baikal_pass = 'IsPaG2026SecureSync';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    /**
     * Constructeur.
     */
    public function __construct()
    {
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // $this->logger->log_user_action('baikal_sync', 'class_initialized', [], $user_id);

        // Hooks pour la synchronisation sortante (CRM -> Baïkal)
        add_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);
        add_action('added_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);

        // Planification de la synchronisation entrante (Baïkal -> CRM)
        if (!wp_next_scheduled('ispag_sync_from_baikal_cron'))
        {
            wp_schedule_event(time(), 'hourly', 'ispag_sync_from_baikal_cron');
            $this->logger->log_user_action('baikal_sync', 'cron_scheduled', ['event' => 'ispag_sync_from_baikal_cron'], $user_id);
        }
        add_action('ispag_sync_from_baikal_cron', [$this, 'sync_all_from_baikal']);

        // Suppression des contacts lors de la suppression d'un utilisateur
        add_action('delete_user', function ($user_id)
        {
            $user_id_log = get_current_user_id();
            $this->logger->log_user_action('baikal_sync', 'user_deletion_triggered', ['deleted_user_id' => $user_id], $user_id_log);

            // On récupère le département avant que l'utilisateur ne soit supprimé
            global $user_department;
            $this->logger->log_db_change('baikal_sync', 'user_meta', 'GET_DEPARTMENT', ['user_id' => $user_id, 'department' => $user_department], $user_id_log);

            // Fallback : on supprime partout par sécurité
            foreach (['cyril', 'claudio'] as $baikal_user)
            {
                $this->delete_from_baikal($user_id, $baikal_user);
            }
        });
    }
 
    /**
     * Log un message dans le fichier de log.
     *
     * @param string $message Message à logger.
     */
    private function log($message)
    {
        $user_id = get_current_user_id();
        $this->logger->log('baikal_sync', $message, $user_id);
    }

    /**
     * Définition des cibles de synchronisation : Cyril et Claudio reçoivent tout Vaulruz.
     *
     * @param string $department_key Clé du département.
     * @return array
     */
    protected function get_sync_targets($department_key)
    {
        $user_id = get_current_user_id();
        $this->logger->log_db_change('baikal_sync', 'department', 'GET_TARGETS', ['department_key' => $department_key], $user_id);

        if ($department_key === 'vaulruz_ispag')
        {
            $this->logger->log_user_action('baikal_sync', 'sync_targets_resolved', ['targets' => ['cyril', 'claudio']], $user_id);
            return ['cyril', 'claudio'];
        }

        $this->logger->log_user_action('baikal_sync', 'no_sync_targets', ['department_key' => $department_key], $user_id);
        return [];
    }

    /**
     * Déclenche la synchronisation lors de la mise à jour d'une méta-donnée utilisateur.
     *
     * @param int $meta_id ID de la méta-donnée.
     * @param int $object_id ID de l'objet (utilisateur).
     * @param string $meta_key Clé de la méta-donnée.
     * @param mixed $_meta_value Valeur de la méta-donnée.
     */
    public function trigger_sync_on_meta_update($meta_id, $object_id, $meta_key, $_meta_value)
    {
        $user_id = get_current_user_id();
        $keys_to_watch = [
            ISPAG_Crm_Contact_Constants::META_OWNER,
            ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,
            ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION,
            ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
            ISPAG_Crm_Contact_Constants::META_USER_ROLE,
            ISPAG_Crm_Contact_Constants::PRIORITY_LEVEL,
            ISPAG_Crm_Contact_Constants::USER_AVATAR,
            'user_email', 'first_name', 'last_name'
        ];

        $this->logger->log_user_action('baikal_sync', 'meta_update_triggered', ['meta_key' => $meta_key, 'object_id' => $object_id], $user_id);

        if (in_array($meta_key, $keys_to_watch))
        {
            $this->log("Déclenchement synchro pour l'ID $object_id (clé: $meta_key)");
            $this->sync_contact_to_baikal($object_id);
        }
    }

    /**
     * Synchronise un contact vers Baïkal.
     *
     * @param int $contact_id ID du contact.
     */
    public function sync_contact_to_baikal($contact_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('baikal_sync', 'sync_contact_start', ['contact_id' => $contact_id], $user_id);

        $repo = new ISPAG_Crm_Contacts_Repository();
        $contact = $repo->get_contact_by_id($contact_id);

        if (!$contact)
        {
            $this->log("ERREUR : Contact $contact_id introuvable.");
            return;
        }

        $this->logger->log_db_change('baikal_sync', 'contacts', 'FETCH_CONTACT', ['contact_id' => $contact_id], $user_id);

        // On détermine le département
        global $user_department;
        

        $targets = $this->get_sync_targets($user_department);

        if (empty($targets))
        {
            $this->log("INFO : Pas de cible définie pour le contact $contact_id (Dept: $user_department)");
            return;
        }

        $this->logger->log_user_action('baikal_sync', 'targets_resolved', ['contact_id' => $contact_id, 'targets' => $targets], $user_id);

        $vcard = $this->generate_vcard($contact);
        $this->logger->log_user_action('baikal_sync', 'vcard_generated', ['contact_id' => $contact_id], $user_id);

        foreach ($targets as $baikal_user)
        {
            $this->push_to_baikal($contact_id, $baikal_user, $this->baikal_pass, $vcard);
        }
    }

    /**
     * Génère une vCard à partir des données d'un contact.
     *
     * @param object $c Objet contact.
     * @return string
     */
    private function generate_vcard($c)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('baikal_sync', 'vcard_generation_start', ['contact_id' => $c->ID], $user_id);

        $first_name = !empty($c->first_name) ? $c->first_name : get_user_meta($c->ID, 'first_name', true);
        $last_name = !empty($c->last_name) ? $c->last_name : get_user_meta($c->ID, 'last_name', true);

        if (empty($first_name) && empty($last_name) && !empty($c->display_name))
        {
            $parts = explode(' ', trim($c->display_name), 2);
            $first_name = $parts[0] ?? '';
            $last_name = $parts[1] ?? '';
            $this->logger->log_user_action('baikal_sync', 'name_parsed_from_display_name', ['first_name' => $first_name, 'last_name' => $last_name], $user_id);
        }

        $display_name = !empty($c->display_name) ? $c->display_name : trim($first_name . ' ' . $last_name);
        $email = $c->email ?? '';
        $phone = $c->phone ?? '';
        $company = $c->company_name ?? '';
        $job = $c->lead_function ?? '';

        $this->logger->log_user_action('baikal_sync', 'contact_data_extracted', ['display_name' => $display_name, 'email' => $email, 'phone' => $phone, 'company' => $company, 'job' => $job], $user_id);

        $v = "BEGIN:VCARD\r\n";
        $v .= "VERSION:3.0\r\n";
        $v .= "N;CHARSET=UTF-8:{$last_name};{$first_name};;;\r\n";
        $v .= "FN;CHARSET=UTF-8:{$display_name}\r\n";

        // Photo
        $avatar_id = get_user_meta($c->ID, ISPAG_Crm_Contact_Constants::USER_AVATAR, true);
        if ($avatar_id)
        {
            $path = get_attached_file($avatar_id);
            if ($path && file_exists($path))
            {
                $type = strtoupper(wp_check_filetype($path)['ext'] == 'jpg' ? 'JPEG' : wp_check_filetype($path)['ext']);
                $data = base64_encode(file_get_contents($path));
                $v .= "PHOTO;TYPE={$type};ENCODING=b:" . $data . "\r\n";
                $this->logger->log_user_action('baikal_sync', 'avatar_added_to_vcard', ['avatar_id' => $avatar_id, 'type' => $type], $user_id);
            }
        }

        if (!empty($company)) $v .= "ORG;CHARSET=UTF-8:{$company}\r\n";
        if (!empty($job)) $v .= "TITLE;CHARSET=UTF-8:{$job}\r\n";

        $v .= "EMAIL;TYPE=INTERNET,WORK:{$email}\r\n";
        if (!empty($phone)) $v .= "TEL;TYPE=CELL,VOICE:{$phone}\r\n";

        $v .= "REV:" . date('Ymd\THis\Z') . "\r\n";
        $v .= "END:VCARD";

        $this->logger->log_user_action('baikal_sync', 'vcard_generation_complete', [], $user_id);
        return $v;
    }

    /**
     * Envoie une vCard vers Baïkal.
     *
     * @param int $id ID du contact.
     * @param string $user Utilisateur Baïkal (cyril ou claudio).
     * @param string $pass Mot de passe Baïkal.
     * @param string $vcard Contenu de la vCard.
     */
    private function push_to_baikal($id, $user, $pass, $vcard)
    {
        $user_id = get_current_user_id();
        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/contact-{$id}.vcf";

        $this->logger->log_user_action('baikal_sync', 'push_to_baikal_start', ['contact_id' => $id, 'baikal_user' => $user, 'url' => $url], $user_id);

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:$pass"),
                'Content-Type' => 'text/vcard; charset=utf-8',
            ],
            'body' => $vcard,
            'timeout' => 30
        ]);

        if (is_wp_error($response))
        {
            $this->log("ERREUR PUSH [$user] : " . $response->get_error_message());
            $this->logger->log('baikal_sync', 'ERROR: PUSH_FAILED - ' . $response->get_error_message(), $user_id);
        }
        else
        {
            $code = wp_remote_retrieve_response_code($response);
            $this->log("PUSH SUCCESS [$user] : Contact $id -> Code $code");
            $this->logger->log_user_action('baikal_sync', 'push_success', ['contact_id' => $id, 'baikal_user' => $user, 'http_code' => $code], $user_id);
        }
    }

    /**
     * Synchronisation massive des contacts vers Baïkal.
     * Ne synchronise que les contacts assignés à Vaulruz dans la table owners.
     */
    public function sync_all_contacts()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('baikal_sync', 'sync_all_contacts_start', [], $user_id);

        // Empêcher l'arrêt du script par le serveur
        ignore_user_abort(true);
        set_time_limit(0);
        ini_set('memory_limit', '1G');

        // Désactiver la compression pour voir l'avancement en temps réel
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
        ini_set('zlib.output_compression', 0);
        ob_implicit_flush(1);
        while (ob_get_level()) ob_end_flush();

        echo "<style>
            body { font-family: sans-serif; background: #1d2327; color: #f0f0f1; padding: 20px; }
            .log-entry { font-family: monospace; font-size: 12px; margin-bottom: 4px; border-bottom: 1px solid #333; padding: 2px 0; }
            .success { color: #00ff00; }
            .info { color: #72aee6; }
            .header { position: sticky; top: 0; background: #1d2327; padding: 10px 0; border-bottom: 2px solid #333; margin-bottom: 20px; }
            #stats { font-size: 18px; font-weight: bold; color: #ffb900; }
        </style>";

        echo "<div class='header'>
                <h1>🚀 Synchro Baïkal : Cyril & Claudio</h1>
                <p id='stats'>Récupération des données CRM...</p>
            </div>
            <div id='log-container'>";

        global $wpdb;
        $table_owners = $wpdb->prefix . 'ispag_contacts_owners';
        

        $this->logger->log_db_change('baikal_sync', $table_owners, 'SELECT_CONTACTS', ['department_keys' => ['vaulruz_ispag', 'ispag'], 'status' => 'active'], $user_id);

        // On ne récupère que les contacts actifs de Vaulruz
        $contact_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT contact_id FROM $table_owners
            WHERE department_key IN (%s, %s)
            AND status = %s",
            'vaulruz_ispag',
            'ispag',
            'active'
        ));

        $total = count($contact_ids);
        $repo = new ISPAG_Crm_Contacts_Repository();

        $this->logger->log_user_action('baikal_sync', 'contacts_to_sync', ['total' => $total], $user_id);

        if (empty($contact_ids))
        {
            $this->logger->log_user_action('baikal_sync', 'no_contacts_found', [], $user_id);
            echo "<div class='log-entry' style='color:orange;'>⚠️ Aucun contact actif trouvé pour Vaulruz dans la table owners.</div>";
        }
        else
        {
            foreach ($contact_ids as $index => $contact_id)
            {
                $current_num = $index + 1;
                $contact = $repo->get_contact_by_id($contact_id);

                if ($contact)
                {
                    $this->logger->log_user_action('baikal_sync', 'syncing_contact', ['contact_id' => $contact_id, 'current' => $current_num, 'total' => $total], $user_id);
                    $this->sync_contact_to_baikal($contact_id);

                    $name = !empty($contact->display_name) ? $contact->display_name : "ID: $contact_id";
                    echo "<div class='log-entry success'>[{$current_num}/{$total}] ✅ Synchro OK : <b>{$name}</b></div>";
                }
                else
                {
                    $this->logger->log('baikal_sync', 'ERROR: Contact not found in repository - ID: ' . $contact_id, $user_id);
                    echo "<div class='log-entry' style='color:red;'>[{$current_num}/{$total}] ❌ Erreur : Contact $contact_id introuvable dans le Repository.</div>";
                }

                // Mise à jour du compteur en haut de page
                echo "<script>document.getElementById('stats').innerHTML = 'Progression : <b>{$current_num} / {$total} contacts</b>';</script>";

                // Forcer l'affichage dans le navigateur
                flush();
            }
        }

        $this->logger->log_user_action('baikal_sync', 'sync_all_contacts_complete', ['total_contacts' => $total], $user_id);
        echo "</div><h2 style='color:#00ff00; margin-top:30px;'>✅ Travail terminé ! Vos iPhones devraient être à jour d'ici quelques minutes.</h2>";
        echo "<a href='" . admin_url() . "' style='display:inline-block; background:#2271b1; color:white; padding:10px 20px; text-decoration:none; border-radius:3px;'>Retour au CRM</a>";
        exit;
    }

    /**
     * Synchronisation entrante (Baïkal -> CRM).
     */
    public function sync_all_from_baikal()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('baikal_sync', 'sync_all_from_baikal_start', [], $user_id);

        $this->log("--- DÉBUT SYNCHRO ENTRANTE (Baïkal -> CRM) ---");

        $targets = ['cyril', 'claudio'];

        foreach ($targets as $user)
        {
            $this->log("Traitement du carnet de : {$user}");
            $this->logger->log_user_action('baikal_sync', 'processing_baikal_user', ['user' => $user], $user_id);
            $this->sync_user_addressbook_from_baikal($user, $this->baikal_pass);
        }

        $this->log("--- FIN SYNCHRO ENTRANTE ---");
        $this->logger->log_user_action('baikal_sync', 'sync_all_from_baikal_complete', [], $user_id);
    }

    /**
     * Synchronise le carnet d'adresses d'un utilisateur Baïkal vers le CRM.
     *
     * @param string $user Utilisateur Baïkal.
     * @param string $pass Mot de passe Baïkal.
     */
    private function sync_user_addressbook_from_baikal($user, $pass)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('baikal_sync', 'sync_user_addressbook_start', ['baikal_user' => $user], $user_id);

        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/";
        $this->log("[{$user}] PROPFIND → {$url}");

        $this->logger->log_user_action('baikal_sync', 'propfind_request', ['user' => $user, 'url' => $url], $user_id);

        $response = wp_remote_request($url, [
            'method' => 'PROPFIND',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:$pass"),
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8'
            ],
            'body' => '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag /></d:prop></d:propfind>',
            'timeout' => 60,
        ]);

        if (is_wp_error($response))
        {
            $this->log("[{$user}] ERREUR wp_remote_request : " . $response->get_error_message());
            $this->logger->log('baikal_sync', 'ERROR: PROPFIND_FAILED - ' . $response->get_error_message(), $user_id);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->log("[{$user}] Code HTTP PROPFIND : {$code}");
        $this->logger->log_user_action('baikal_sync', 'propfind_response', ['user' => $user, 'http_code' => $code], $user_id);

        if ($code !== 207)
        {
            $body = wp_remote_retrieve_body($response);
            $this->log("[{$user}] Réponse inattendue (attendu 207) : " . substr($body, 0, 500));
            $this->logger->log('baikal_sync', 'ERROR: Unexpected HTTP code - ' . $code, $user_id);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $this->log("[{$user}] Taille réponse XML : " . strlen($body) . " octets");
        $this->logger->log_user_action('baikal_sync', 'xml_response_received', ['user' => $user, 'size' => strlen($body)], $user_id);

        $xml = simplexml_load_string($body);

        if ($xml === false)
        {
            $errors = libxml_get_errors();
            $err_msg = implode(' | ', array_map(fn($e) => $e->message, $errors));
            $this->log("[{$user}] ERREUR parse XML : {$err_msg}");
            $this->logger->log('baikal_sync', 'ERROR: XML_PARSE_FAILED - ' . $err_msg, $user_id);
            $this->log("[{$user}] Début du XML reçu : " . substr($body, 0, 500));
            return;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $responses = $xml->xpath('//d:response');
        $this->log("[{$user}] Nombre de d:response trouvés : " . count($responses));
        $this->logger->log_user_action('baikal_sync', 'xml_responses_parsed', ['user' => $user, 'count' => count($responses)], $user_id);

        $found_vcf = 0;
        $skipped_etag = 0;
        $updated = 0;

        foreach ($responses as $res)
        {
            $href_nodes = $res->xpath('d:href');
            if (empty($href_nodes))
            {
                $this->log("[{$user}] d:response sans d:href — ignoré");
                $this->logger->log_user_action('baikal_sync', 'response_without_href', ['user' => $user], $user_id);
                continue;
            }

            $href = (string)$href_nodes[0];

            if (!preg_match('/contact-(\d+)\.vcf$/', $href, $matches))
            {
                $this->log("[{$user}] href ignoré (pas un contact vcf) : {$href}");
                $this->logger->log_user_action('baikal_sync', 'non_vcf_href_ignored', ['user' => $user, 'href' => $href], $user_id);
                continue;
            }

            $found_vcf++;
            $contact_id = $matches[1];

            $etag_nodes = $res->xpath('.//d:getetag');
            $etag = !empty($etag_nodes) ? trim((string)$etag_nodes[0], '"') : '';
            $this->log("[{$user}] Contact {$contact_id} | etag distant : {$etag}");
            $this->logger->log_db_change('baikal_sync', 'contact_etag', 'REMOTE_ETAG', ['contact_id' => $contact_id, 'etag' => $etag], $user_id);

            $last_etag = get_user_meta($contact_id, '_baikal_last_etag', true);
            $this->log("[{$user}] Contact {$contact_id} | etag local   : {$last_etag}");
            $this->logger->log_db_change('baikal_sync', 'contact_etag', 'LOCAL_ETAG', ['contact_id' => $contact_id, 'etag' => $last_etag], $user_id);

            if ($etag === $last_etag)
            {
                $skipped_etag++;
                $this->log("[{$user}] Contact {$contact_id} | etag identique → ignoré");
                $this->logger->log_user_action('baikal_sync', 'etag_unchanged_skipped', ['contact_id' => $contact_id], $user_id);
                continue;
            }

            $this->log("[{$user}] Contact {$contact_id} | etag différent → mise à jour");
            $this->logger->log_user_action('baikal_sync', 'etag_changed_update', ['contact_id' => $contact_id], $user_id);
            $this->update_crm_contact_from_baikal($contact_id, $user, $pass, $href, $etag);
            $updated++;
        }

        $this->log("[{$user}] BILAN : {$found_vcf} vcf trouvés | {$skipped_etag} ignorés (etag identique) | {$updated} mis à jour");
        $this->logger->log_user_action('baikal_sync', 'sync_summary', ['user' => $user, 'found_vcf' => $found_vcf, 'skipped_etag' => $skipped_etag, 'updated' => $updated], $user_id);
    }

    /**
     * Met à jour un contact CRM depuis Baïkal.
     *
     * @param int $id ID du contact.
     * @param string $user Utilisateur Baïkal.
     * @param string $pass Mot de passe Baïkal.
     * @param string $href URL du contact dans Baïkal.
     * @param string $etag ETag du contact.
     */
    private function update_crm_contact_from_baikal($id, $user, $pass, $href, $etag)
    {
        $user_id = get_current_user_id();
        $url = "https://{$this->baikal_ip}{$href}";
        $this->log("[{$user}] GET vCard → {$url}");
        $this->logger->log_user_action('baikal_sync', 'get_vcard_start', ['contact_id' => $id, 'url' => $url], $user_id);

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("$user:$pass")],
            'timeout' => 30,
        ]);

        if (is_wp_error($response))
        {
            $this->log("[{$user}] ERREUR GET vCard contact {$id} : " . $response->get_error_message());
            $this->logger->log('baikal_sync', 'ERROR: GET_VCARD_FAILED - ' . $response->get_error_message(), $user_id);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->log("[{$user}] Code HTTP GET vCard contact {$id} : {$code}");
        $this->logger->log_user_action('baikal_sync', 'get_vcard_response', ['contact_id' => $id, 'http_code' => $code], $user_id);

        if ($code !== 200)
        {
            $this->log("[{$user}] Réponse inattendue pour contact {$id} : " . substr(wp_remote_retrieve_body($response), 0, 300));
            $this->logger->log('baikal_sync', 'ERROR: Unexpected HTTP code for vCard - ' . $code, $user_id);
            return;
        }

        $vcard_content = wp_remote_retrieve_body($response);
        $this->log("[{$user}] vCard contact {$id} reçue (" . strlen($vcard_content) . " octets)");
        $this->logger->log_user_action('baikal_sync', 'vcard_received', ['contact_id' => $id, 'size' => strlen($vcard_content)], $user_id);

        // Parsing
        $phone = '';
        $job = '';

        if (preg_match('/^TEL(?:;.*)?:(.*)$/m', $vcard_content, $m))
        {
            $phone = trim($m[1]);
            $this->log("[{$user}] Contact {$id} | TEL trouvé : {$phone}");
            $this->logger->log_db_change('baikal_sync', 'contact_phone', 'PARSED', ['contact_id' => $id, 'phone' => $phone], $user_id);
        }
        else
        {
            $this->log("[{$user}] Contact {$id} | TEL non trouvé dans la vCard");
            $this->logger->log_user_action('baikal_sync', 'phone_not_found_in_vcard', ['contact_id' => $id], $user_id);
        }

        if (preg_match('/^TITLE(?:;.*)?:(.*)$/m', $vcard_content, $m))
        {
            $job = trim($m[1]);
            $this->log("[{$user}] Contact {$id} | TITLE trouvé : {$job}");
            $this->logger->log_db_change('baikal_sync', 'contact_job', 'PARSED', ['contact_id' => $id, 'job' => $job], $user_id);
        }
        else
        {
            $this->log("[{$user}] Contact {$id} | TITLE non trouvé dans la vCard");
            $this->logger->log_user_action('baikal_sync', 'job_not_found_in_vcard', ['contact_id' => $id], $user_id);
        }

        // Mise à jour sans déclencher de boucle infinie
        remove_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update']);

        if (!empty($phone))
        {
            $result = update_user_meta($id, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, $phone);
            $this->log("[{$user}] Contact {$id} | update META_LEAD_PHONE : " . ($result ? 'OK' : 'INCHANGÉ ou ERREUR'));
            $this->logger->log_db_change('baikal_sync', 'user_meta', 'UPDATE_PHONE', ['contact_id' => $id, 'phone' => $phone, 'result' => $result], $user_id);
        }

        if (!empty($job))
        {
            $result = update_user_meta($id, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, $job);
            $this->log("[{$user}] Contact {$id} | update META_LEAD_FUNCTION : " . ($result ? 'OK' : 'INCHANGÉ ou ERREUR'));
            $this->logger->log_db_change('baikal_sync', 'user_meta', 'UPDATE_JOB', ['contact_id' => $id, 'job' => $job, 'result' => $result], $user_id);
        }

        update_user_meta($id, '_baikal_last_etag', $etag);
        $this->log("[{$user}] Contact {$id} | etag local mis à jour → {$etag}");
        $this->logger->log_db_change('baikal_sync', 'user_meta', 'UPDATE_ETAG', ['contact_id' => $id, 'etag' => $etag], $user_id);

        add_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);

        // Re-propagation vers les autres carnets Baïkal
        global $user_department;
        
        $this->logger->log_db_change('baikal_sync', 'user_meta', 'GET_DEPARTMENT', ['contact_id' => $id, 'department' => $user_department], $user_id);

        $targets = $this->get_sync_targets($user_department);
        $repo = new ISPAG_Crm_Contacts_Repository();
        $contact = $repo->get_contact_by_id($id);

        if ($contact)
        {
            $this->logger->log_user_action('baikal_sync', 'contact_fetched_for_repropagation', ['contact_id' => $id], $user_id);
            $vcard = $this->generate_vcard($contact);

            foreach ($targets as $target_user)
            {
                if ($target_user === $user) continue;
                $this->log("[{$user}] Re-propagation vers [{$target_user}] pour contact {$id}");
                $this->logger->log_user_action('baikal_sync', 'repropagate_to_baikal', ['from_user' => $user, 'to_user' => $target_user, 'contact_id' => $id], $user_id);
                $this->push_to_baikal($id, $target_user, $this->baikal_pass, $vcard);
            }
        }

        $this->log("[{$user}] IMPORT TERMINÉ : Contact {$id}");
        $this->logger->log_user_action('baikal_sync', 'contact_import_complete', ['contact_id' => $id, 'user' => $user], $user_id);
    }

    /**
     * Supprime un contact de Baïkal.
     *
     * @param int $id ID du contact.
     * @param string $user Utilisateur Baïkal.
     */
    public function delete_from_baikal($id, $user)
    {
        $user_id = get_current_user_id();
        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/contact-{$id}.vcf";

        $this->logger->log_user_action('baikal_sync', 'delete_from_baikal_start', ['contact_id' => $id, 'baikal_user' => $user, 'url' => $url], $user_id);

        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}"),
            ],
            'timeout' => 10
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $this->log("DELETE [{$user}] Contact {$id} : Code {$code}");
        $this->logger->log_user_action('baikal_sync', 'delete_from_baikal_complete', ['contact_id' => $id, 'baikal_user' => $user, 'http_code' => $code], $user_id);
    }

    /**
     * Purge un carnet d'adresses Baïkal.
     *
     * @param string $user Utilisateur Baïkal.
     */
    public function purge_baikal_addressbook($user)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('baikal_sync', 'purge_addressbook_start', ['baikal_user' => $user], $user_id);

        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/";

        $response = wp_remote_request($url, [
            'method' => 'PROPFIND',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}"),
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8'
            ]
        ]);

        if (is_wp_error($response))
        {
            $this->log("PURGE ERROR : " . $response->get_error_message());
            $this->logger->log('baikal_sync', 'ERROR: PURGE_PROPFIND_FAILED - ' . $response->get_error_message(), $user_id);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $xml = @simplexml_load_string($body);
        if (!$xml)
        {
            $this->logger->log('baikal_sync', 'ERROR: PURGE_XML_PARSE_FAILED', $user_id);
            return;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $nodes = $xml->xpath('//d:response');

        $this->logger->log_user_action('baikal_sync', 'purge_nodes_found', ['baikal_user' => $user, 'count' => count($nodes)], $user_id);

        foreach ($nodes as $res)
        {
            $href = (string)$res->xpath('d:href')[0];

            // On ne cible que les fichiers .vcf
            if (strpos($href, '.vcf') !== false)
            {
                $base_host = "https://{$this->baikal_ip}";
                $final_delete_url = (strpos($href, 'http') === 0) ? $href : $base_host . $href;

                $this->logger->log_user_action('baikal_sync', 'deleting_vcf_file', ['baikal_user' => $user, 'file' => $href], $user_id);

                $del_res = wp_remote_request($final_delete_url, [
                    'method' => 'DELETE',
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}")
                    ],
                    'timeout' => 10
                ]);

                $status = wp_remote_retrieve_response_code($del_res);
                $this->log("PURGE FILE : {$href} | Status: {$status}");
                $this->logger->log_user_action('baikal_sync', 'file_deleted', ['baikal_user' => $user, 'file' => $href, 'http_status' => $status], $user_id);
            }
        }

        $this->log("PURGE FINIE pour {$user}");
        $this->logger->log_user_action('baikal_sync', 'purge_addressbook_complete', ['baikal_user' => $user], $user_id);
    }
}