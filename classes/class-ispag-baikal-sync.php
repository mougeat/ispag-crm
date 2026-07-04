<?php

class ISPAG_Baikal_Sync {

    private $baikal_ip = 'contacts.barthels.duckdns.org';
    private $addressbook_token = 'ispag';
    private $baikal_pass = 'IsPaG2026SecureSync';
    private static $log_file = WP_CONTENT_DIR . '/ispag_baikal_contact_sync.log';

    public function __construct() {
        // Hooks pour la synchronisation sortante (CRM -> Baïkal)
        add_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);
        add_action('added_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);

        // Planification de la synchro entrante (Baïkal -> CRM)
        if (!wp_next_scheduled('ispag_sync_from_baikal_cron')) {
            wp_schedule_event(time(), 'hourly', 'ispag_sync_from_baikal_cron');
        }
        add_action('ispag_sync_from_baikal_cron', [$this, 'sync_all_from_baikal']);

        // add_action('delete_user', function($user_id) {
        //     $sync = new ISPAG_Baikal_Sync();
        //     $sync->delete_from_baikal($user_id, 'cyril');
        //     $sync->delete_from_baikal($user_id, 'claudio');
        // });
        add_action('delete_user', function($user_id) {
            $sync = new ISPAG_Baikal_Sync();

            // On récupère le département avant que l'user soit supprimé
            $dept    = get_user_meta($user_id, 'department_key', true) ?: 'vaulruz_ispag';
            $targets = $sync->get_sync_targets($dept); // ← mais get_sync_targets est private...
            
            // Fallback : on supprime partout par sécurité
            foreach (['cyril', 'claudio'] as $baikal_user) {
                $sync->delete_from_baikal($user_id, $baikal_user);
            }
        });
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";
        file_put_contents(self::$log_file, $log_message, FILE_APPEND);
    }

    /**
     * DÉFINITION DES CIBLES : Cyril et Claudio reçoivent tout Vaulruz
     */
    protected function get_sync_targets($department_key) {
        if ($department_key === 'vaulruz_ispag') {
            return ['cyril', 'claudio']; 
        }
        return [];
    }

    public function trigger_sync_on_meta_update($meta_id, $object_id, $meta_key, $_meta_value) {
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

        if (in_array($meta_key, $keys_to_watch)) {
            $this->log("Déclenchement synchro pour l'ID {$object_id} (clé: {$meta_key})");
            $this->sync_contact_to_baikal($object_id);
        }
    }

    public function sync_contact_to_baikal($contact_id) {
        $repo = new ISPAG_Crm_Contacts_Repository();
        $contact = $repo->get_contact_by_id($contact_id);

        if (!$contact) {
            $this->log("ERREUR : Contact {$contact_id} introuvable.");
            return;
        }

        // On détermine le département (depuis la table owners ou meta)
        $dept = $contact->department_key ?? get_user_meta($contact_id, 'department_key', true);
        if (empty($dept)) $dept = 'vaulruz_ispag'; // Fallback par défaut pour votre équipe

        $targets = $this->get_sync_targets($dept);

        if (empty($targets)) {
            $this->log("INFO : Pas de cible définie pour le contact {$contact_id} (Dept: {$dept})");
            return;
        }

        $vcard = $this->generate_vcard($contact);

        foreach ($targets as $baikal_user) {
            $this->push_to_baikal($contact_id, $baikal_user, $this->baikal_pass, $vcard);
        }
    }

    private function generate_vcard($c) {
        $first_name = !empty($c->first_name) ? $c->first_name : get_user_meta($c->ID, 'first_name', true);
        $last_name  = !empty($c->last_name) ? $c->last_name : get_user_meta($c->ID, 'last_name', true);
        
        if (empty($first_name) && empty($last_name) && !empty($c->display_name)) {
            $parts = explode(' ', trim($c->display_name), 2);
            $first_name = $parts[0] ?? '';
            $last_name = $parts[1] ?? '';
        }

        $display_name = !empty($c->display_name) ? $c->display_name : trim($first_name . ' ' . $last_name);
        $email   = $c->email ?? '';
        $phone   = $c->phone ?? '';
        $company = $c->company_name ?? '';
        $job     = $c->lead_function ?? '';

        $v = "BEGIN:VCARD\r\n";
        $v .= "VERSION:3.0\r\n";
        $v .= "N;CHARSET=UTF-8:{$last_name};{$first_name};;;\r\n";
        $v .= "FN;CHARSET=UTF-8:{$display_name}\r\n";
        
        // --- PHOTO DÉSACTIVÉE POUR LE TRANSFERT EN MASSE ---
        $avatar_id = get_user_meta($c->ID, ISPAG_Crm_Contact_Constants::USER_AVATAR, true);
        if ($avatar_id) {
            $path = get_attached_file($avatar_id);
            if ($path && file_exists($path)) {
                $type = strtoupper(wp_check_filetype($path)['ext'] == 'jpg' ? 'JPEG' : wp_check_filetype($path)['ext']);
                $data = base64_encode(file_get_contents($path));
                $v .= "PHOTO;TYPE={$type};ENCODING=b:" . $data . "\r\n";
            }
        }
        //---------------------------------------------------- /

        if (!empty($company)) $v .= "ORG;CHARSET=UTF-8:{$company}\r\n";
        if (!empty($job))     $v .= "TITLE;CHARSET=UTF-8:{$job}\r\n";
        
        $v .= "EMAIL;TYPE=INTERNET,WORK:{$email}\r\n";
        if (!empty($phone))   $v .= "TEL;TYPE=CELL,VOICE:{$phone}\r\n";

        $v .= "REV:" . date('Ymd\THis\Z') . "\r\n";
        $v .= "END:VCARD";
        
        return $v;
    }

    private function push_to_baikal($id, $user, $pass, $vcard) {
        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/contact-{$id}.vcf";

        $response = wp_remote_request($url, [
            'method'    => 'PUT',
            'headers'   => [
                'Authorization' => 'Basic ' . base64_encode("$user:$pass"),
                'Content-Type'  => 'text/vcard; charset=utf-8',
            ],
            'body'      => $vcard,
            'timeout'   => 30
        ]);

        if (is_wp_error($response)) {
            $this->log("ERREUR PUSH [{$user}] : " . $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $this->log("PUSH SUCCESS [{$user}] : Contact {$id} -> Code {$code}");
        }
    }

    /**
     * SYNCHRO MASSIVE (Version Visuelle Optimisée)
     * Ne synchronise que les contacts assignés à Vaulruz dans la table owners.
     */
    public function sync_all_contacts() {
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

        // 1. On ne récupère que les contacts actifs de Vaulruz
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

        if (empty($contact_ids)) {
            echo "<div class='log-entry' style='color:orange;'>⚠️ Aucun contact actif trouvé pour Vaulruz dans la table owners.</div>";
        } else {
            foreach ($contact_ids as $index => $contact_id) {
                $current_num = $index + 1;
                $contact = $repo->get_contact_by_id($contact_id);

                if ($contact) {
                    // On déclenche la synchro vers Cyril et Claudio
                    $this->sync_contact_to_baikal($contact_id);
                    
                    $name = !empty($contact->display_name) ? $contact->display_name : "ID: $contact_id";
                    echo "<div class='log-entry success'>[{$current_num}/{$total}] ✅ Synchro OK : <b>{$name}</b></div>";
                } else {
                    echo "<div class='log-entry' style='color:red;'>[{$current_num}/{$total}] ❌ Erreur : Contact {$contact_id} introuvable dans le Repository.</div>";
                }

                // Mise à jour du compteur en haut de page
                echo "<script>document.getElementById('stats').innerHTML = 'Progression : <b>{$current_num} / {$total} contacts</b>';</script>";
                
                // Forcer l'affichage dans le navigateur
                flush();
            }
        }

        echo "</div><h2 style='color:#00ff00; margin-top:30px;'>✅ Travail terminé ! Vos iPhones devraient être à jour d'ici quelques minutes.</h2>";
        echo "<a href='".admin_url()."' style='display:inline-block; background:#2271b1; color:white; padding:10px 20px; text-decoration:none; border-radius:3px;'>Retour au CRM</a>";
        exit;
    }

    /**
     * SYNCHRO ENTRANTE (Baïkal -> CRM)
     */
    public function sync_all_from_baikal() {
        $this->log("--- DÉBUT SYNCHRO ENTRANTE (Baïkal -> CRM) ---");
        
        $targets = ['cyril', 'claudio'];

        foreach ($targets as $user) {
            $this->log("Traitement du carnet de : {$user}");
            $this->sync_user_addressbook_from_baikal($user, $this->baikal_pass);
        }
        
        $this->log("--- FIN SYNCHRO ENTRANTE ---");
    }

    private function sync_user_addressbook_from_baikal($user, $pass) {
        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/";
        $this->log("[{$user}] PROPFIND → {$url}");

        $response = wp_remote_request($url, [
            'method'  => 'PROPFIND',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:$pass"),
                'Depth'         => '1',
                'Content-Type'  => 'application/xml; charset=utf-8'
            ],
            'body'    => '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag /></d:prop></d:propfind>',
            'timeout' => 60, // ← augmenté à 60s (était 5s par défaut)
        ]);

        if (is_wp_error($response)) {
            $this->log("[{$user}] ERREUR wp_remote_request : " . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->log("[{$user}] Code HTTP PROPFIND : {$code}");

        if ($code !== 207) {
            $body = wp_remote_retrieve_body($response);
            $this->log("[{$user}] Réponse inattendue (attendu 207) : " . substr($body, 0, 500));
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $this->log("[{$user}] Taille réponse XML : " . strlen($body) . " octets");

        $xml = simplexml_load_string($body);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $err_msg = implode(' | ', array_map(fn($e) => $e->message, $errors));
            $this->log("[{$user}] ERREUR parse XML : {$err_msg}");
            $this->log("[{$user}] Début du XML reçu : " . substr($body, 0, 500));
            return;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $responses = $xml->xpath('//d:response');
        $this->log("[{$user}] Nombre de d:response trouvés : " . count($responses));

        $found_vcf   = 0;
        $skipped_etag = 0;
        $updated     = 0;

        foreach ($responses as $res) {
            $href_nodes = $res->xpath('d:href');
            if (empty($href_nodes)) {
                $this->log("[{$user}] d:response sans d:href — ignoré");
                continue;
            }

            $href = (string)$href_nodes[0];

            if (!preg_match('/contact-(\d+)\.vcf$/', $href, $matches)) {
                $this->log("[{$user}] href ignoré (pas un contact vcf) : {$href}");
                continue;
            }

            $found_vcf++;
            $contact_id = $matches[1];

            $etag_nodes = $res->xpath('.//d:getetag');
            $etag = !empty($etag_nodes) ? trim((string)$etag_nodes[0], '"') : '';
            $this->log("[{$user}] Contact {$contact_id} | etag distant : {$etag}");

            $last_etag = get_user_meta($contact_id, '_baikal_last_etag', true);
            $this->log("[{$user}] Contact {$contact_id} | etag local   : {$last_etag}");

            if ($etag === $last_etag) {
                $skipped_etag++;
                $this->log("[{$user}] Contact {$contact_id} | etag identique → ignoré");
                continue;
            }

            $this->log("[{$user}] Contact {$contact_id} | etag différent → mise à jour");
            $this->update_crm_contact_from_baikal($contact_id, $user, $pass, $href, $etag);
            $updated++;
        }

        $this->log("[{$user}] BILAN : {$found_vcf} vcf trouvés | {$skipped_etag} ignorés (etag identique) | {$updated} mis à jour");
    }

    private function update_crm_contact_from_baikal($id, $user, $pass, $href, $etag) {
        $url = "https://{$this->baikal_ip}{$href}";
        $this->log("[{$user}] GET vCard → {$url}");

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("$user:$pass")],
            'timeout' => 30, // ← augmenté
        ]);

        if (is_wp_error($response)) {
            $this->log("[{$user}] ERREUR GET vCard contact {$id} : " . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->log("[{$user}] Code HTTP GET vCard contact {$id} : {$code}");

        if ($code !== 200) {
            $this->log("[{$user}] Réponse inattendue pour contact {$id} : " . substr(wp_remote_retrieve_body($response), 0, 300));
            return;
        }

        $vcard_content = wp_remote_retrieve_body($response);
        $this->log("[{$user}] vCard contact {$id} reçue (" . strlen($vcard_content) . " octets)");
        // $this->log("[{$user}] Contenu vCard contact {$id} :\n" . $vcard_content);

        // Parsing
        $phone = '';
        $job   = '';

        if (preg_match('/^TEL(?:;.*)?:(.*)$/m', $vcard_content, $m)) {
            $phone = trim($m[1]);
            $this->log("[{$user}] Contact {$id} | TEL trouvé : {$phone}");
        } else {
            $this->log("[{$user}] Contact {$id} | TEL non trouvé dans la vCard");
        }

        if (preg_match('/^TITLE(?:;.*)?:(.*)$/m', $vcard_content, $m)) {
            $job = trim($m[1]);
            $this->log("[{$user}] Contact {$id} | TITLE trouvé : {$job}");
        } else {
            $this->log("[{$user}] Contact {$id} | TITLE non trouvé dans la vCard");
        }

        // Mise à jour sans déclencher de boucle infinie
        remove_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update']);

        if (!empty($phone)) {
            $result = update_user_meta($id, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, $phone);
            $this->log("[{$user}] Contact {$id} | update META_LEAD_PHONE : " . ($result ? 'OK' : 'INCHANGÉ ou ERREUR'));
        }

        if (!empty($job)) {
            $result = update_user_meta($id, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, $job);
            $this->log("[{$user}] Contact {$id} | update META_LEAD_FUNCTION : " . ($result ? 'OK' : 'INCHANGÉ ou ERREUR'));
        }

        update_user_meta($id, '_baikal_last_etag', $etag);
        $this->log("[{$user}] Contact {$id} | etag local mis à jour → {$etag}");

        add_action('updated_user_meta', [$this, 'trigger_sync_on_meta_update'], 10, 4);

         // ── Re-propagation vers les autres carnets Baïkal ────────────────────
        // On re-pousse vers tous les targets SAUF celui qui vient d'envoyer
        // pour éviter une boucle infinie cyril → CRM → claudio → CRM → cyril...
        $dept    = get_user_meta($id, 'department_key', true) ?: 'vaulruz_ispag';
        $targets = $this->get_sync_targets($dept);

        $repo    = new ISPAG_Crm_Contacts_Repository();
        $contact = $repo->get_contact_by_id($id);

        if ($contact) {
            $vcard = $this->generate_vcard($contact);
            foreach ($targets as $target_user) {
                if ($target_user === $user) continue; // ← on saute l'expéditeur
                $this->log("[{$user}] Re-propagation vers [{$target_user}] pour contact {$id}");
                $this->push_to_baikal($id, $target_user, $this->baikal_pass, $vcard);
            }
        }


        $this->log("[{$user}] IMPORT TERMINÉ : Contact {$id}");
    }

    public function delete_from_baikal($id, $user) {
        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/contact-{$id}.vcf";

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}"),
            ],
            'timeout' => 10
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $this->log("DELETE [{$user}] Contact {$id} : Code {$code}");
    }

    public function purge_baikal_addressbook($user) {
        $url = "https://{$this->baikal_ip}/dav.php/addressbooks/{$user}/{$this->addressbook_token}/";

        $response = wp_remote_request($url, [
            'method'  => 'PROPFIND',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}"),
                'Depth'         => '1',
                'Content-Type'  => 'application/xml; charset=utf-8'
            ]
        ]);

        if (is_wp_error($response)) {
            $this->log("PURGE ERROR : " . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $xml = @simplexml_load_string($body);
        if (!$xml) return;

        $xml->registerXPathNamespace('d', 'DAV:');
        $nodes = $xml->xpath('//d:response');

        foreach ($nodes as $res) {
            $href = (string)$res->xpath('d:href')[0];

            // On ne cible que les fichiers .vcf
            if (strpos($href, '.vcf') !== false) {
                
                // SÉCURITÉ URL : Si le href commence déjà par /dav.php ou / , 
                // on ne garde que le domaine pour construire l'URL finale.
                $base_host = "https://{$this->baikal_ip}";
                
                // Si le href est un chemin relatif (ne commence pas par http), on le nettoie
                $final_delete_url = (strpos($href, 'http') === 0) ? $href : $base_host . $href;

                $del_res = wp_remote_request($final_delete_url, [
                    'method'  => 'DELETE',
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}")
                    ],
                    'timeout' => 10
                ]);

                $status = wp_remote_retrieve_response_code($del_res);
                $this->log("PURGE FILE : {$href} | Status: {$status}");
            }
        }
        $this->log("PURGE FINIE pour {$user}");
    }
}