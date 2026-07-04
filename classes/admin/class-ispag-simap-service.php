<?php

if ( ! class_exists( 'ISPAG_Simap_Service' ) ) {

    class ISPAG_Simap_Service {

        private $api_url = 'https://www.simap.ch/api/v2/public/publications/search'; 
        private static $log_file = WP_CONTENT_DIR . '/ispag_simap_service.log';

        public function __construct() {
            // Initialisation de la base de données
            $this->maybe_create_table();

            // L'action admin_menu doit être ajoutée ici
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }
        /**
         * SYSTÈME DE LOGS
         */
        public function log($message, $level = 'INFO') {
            $timestamp = current_time('mysql');
            $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
            file_put_contents(self::$log_file, $log_entry, FILE_APPEND);
        }

        /**
         * GESTION DE LA BASE DE DONNÉES
         */
        private function maybe_create_table() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'ispag_simap_notices';
            $charset_collate = $wpdb->get_charset_collate();

            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $sql = "CREATE TABLE $table_name (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    simap_id varchar(50) NOT NULL,
                    title varchar(255) NOT NULL,
                    type varchar(50) DEFAULT 'award',
                    publication_date datetime DEFAULT NULL,
                    matched_deal_id bigint(20) DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    UNIQUE KEY simap_id (simap_id)
                ) $charset_collate;";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);
            }
        }

        /**
         * ADMINISTRATION : AJOUT DU SOUS-MENU
         */
        public function add_admin_menu() {
            // On vérifie que le menu parent 'ispag-crm' existe bien
            add_submenu_page(
                'ispag_main_menu',           // Slug du menu parent (Ton menu principal CRM)
                'SIMAP Sync',          
                'SIMAP Sync',          
                'manage_options',      
                'ispag-simap-sync',    
                [$this, 'render_admin_page']
            );
        }

        public function render_admin_page() {
            // Gestion des actions
            if (isset($_POST['launch_simap_sync'])) {
                check_admin_referer('ispag_simap_action', 'simap_nonce');
                $this->sync_adjudications();
                echo '<div class="updated"><p>Synchronisation terminée avec succès.</p></div>';
            }

            if (isset($_POST['clear_simap_logs'])) {
                file_put_contents(self::$log_file, '');
                echo '<div class="updated"><p>Logs effacés.</p></div>';
            }

            ?>
            <div class="wrap">
                <h1><span class="dashicons dashicons-rest-api"></span> Synchronisation SIMAP</h1>
                <p>Comparez les adjudications SIMAP avec vos offres en cours.</p>

                <div class="card" style="max-width: 100%; margin-top: 20px;">
                    <form method="post">
                        <?php wp_nonce_field('ispag_simap_action', 'simap_nonce'); ?>
                        <button type="submit" name="launch_simap_sync" class="button button-primary button-large">
                            Lancer la synchronisation
                        </button>
                        <button type="submit" name="clear_simap_logs" class="button button-secondary">
                            Vider les logs
                        </button>
                    </form>
                </div>

                <h2 style="margin-top:30px;">Console de suivi (ispag_simap_service.log)</h2>
                <div style="background: #1c1c1c; color: #00ff00; padding: 15px; height: 500px; overflow-y: auto; font-family: 'Courier New', Courier, monospace; border-radius: 4px; line-height: 1.5;">
                    <?php 
                    if (file_exists(self::$log_file)) {
                        $content = file_get_contents(self::$log_file);
                        echo !empty($content) ? nl2br(esc_html($content)) : 'La console est vide. Lancez une synchro.';
                    } else {
                        echo 'Fichier de log non généré.';
                    }
                    ?>
                </div>
            </div>
            <script>
                // Auto-scroll en bas de la console
                var consoleDiv = document.querySelector('div[style*="overflow-y: auto"]');
                if(consoleDiv) consoleDiv.scrollTop = consoleDiv.scrollHeight;
            </script>
            <?php
        }

        /**
         * LOGIQUE DE SYNCHRONISATION
         */
        public function sync_adjudications() {
            $this->log("--- DÉBUT DE LA SYNCHRONISATION ---");
            
            $keywords = ['chauffage', 'accumulateur', 'chauffe-eau'];
            
            foreach ($keywords as $kw) {
                $this->log("Recherche SIMAP pour : '$kw'");
                $results = $this->fetch_from_api($kw);

                if (empty($results)) {
                    $this->log("Aucune nouvelle donnée pour '$kw'");
                    continue;
                }

                foreach ($results as $notice) {
                    $this->process_notice($notice);
                }
            }
            $this->log("--- SYNCHRONISATION TERMINÉE ---");
        }

        private function fetch_from_api($keyword) {
            $date_from = date('Y-m-d', strtotime('-90 days'));
            
            // 1. Log de la tentative
            $this->log("--- Tentative de connexion API SIMAP ---");
            $this->log("Mot-clé : '$keyword' | Depuis le : $date_from");

            $params = [
                'search_parameters' => [
                    'keywords' => [$keyword],
                    'document_types' => ['award_notice'],
                    'publication_date_from' => $date_from,
                    'language' => 'FR'
                ]
            ];

            // 2. Log du payload envoyé (pour vérifier la structure)
            $this->log("Payload JSON envoyé : " . json_encode($params));

            $response = wp_remote_post($this->api_url . 'notices/search', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ],
                'body'    => json_encode($params),
                'timeout' => 30
            ]);

            // 3. Log des erreurs de transport (DNS, Timeout...)
            if (is_wp_error($response)) {
                $this->log("ERREUR CRITIQUE (Transport) : " . $response->get_error_message(), 'ERROR');
                return [];
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            // 4. Log du statut et du contenu brut reçu
            $this->log("Réponse API reçue (Code: $code)");
            
            if ($code !== 200) {
                $this->log("Contenu de l'erreur : " . substr($body, 0, 500), 'ERROR');
                return [];
            }

            $data = json_decode($body);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Erreur de décodage JSON : " . json_last_error_msg(), 'ERROR');
                return [];
            }

            $count = isset($data->results) ? count($data->results) : 0;
            $this->log("API a retourné $count résultats pour '$keyword'");

            return $data->results ?? [];
        }

        private function process_notice($notice) {
            global $wpdb;
            
            $simap_id = !empty($notice->noticeId) ? $notice->noticeId : ($notice->id ?? 'ID_INCONNU');
            $title = !empty($notice->title) ? $notice->title : 'Sans titre';

            $this->log("Analyse du résultat : [$simap_id] $title");

            // Vérification doublon
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ispag_simap_notices WHERE simap_id = %s",
                $simap_id
            ));

            if ($exists) {
                $this->log("-> Ignoré : Déjà présent en base (ID local: $exists)");
                return;
            }

            // Matching avec la table Deals
            $this->log("-> Recherche de correspondance dans les Deals...");
            
            $deal = $wpdb->get_row($wpdb->prepare(
                "SELECT id, owner_id, deal_name FROM {$wpdb->prefix}ispag_deals 
                WHERE %s LIKE CONCAT('%', deal_name, '%') 
                OR deal_name LIKE %s",
                $title, '%' . $wpdb->esc_like($title) . '%'
            ));

            $wpdb->insert($wpdb->prefix . 'ispag_simap_notices', [
                'simap_id' => $simap_id,
                'title' => $title,
                'type' => 'award',
                'publication_date' => current_time('mysql'),
                'matched_deal_id' => $deal ? $deal->id : null
            ]);

            if ($deal) {
                $this->log("-> SUCCESS : Match avec le Deal '{$deal->deal_name}' (ID: {$deal->id})", 'SUCCESS');
                $this->notify_owner($deal, $title);
            } else {
                $this->log("-> Enregistré en base, mais aucun Deal correspondant trouvé.");
            }
        }

        private function notify_owner($deal, $simap_title) {
            $owner = get_userdata($deal->owner_id);
            if (!$owner) return;

            $subject = "SIMAP : Adjudication pour " . $deal->deal_name;
            $body = "Une adjudication sur SIMAP semble correspondre à votre offre.\n\nTitre SIMAP : $simap_title\nDeal : {$deal->deal_name}";

            wp_mail($owner->user_email, $subject, $body);
            $this->log("Email envoyé à {$owner->user_email}");
        }
    }
}

