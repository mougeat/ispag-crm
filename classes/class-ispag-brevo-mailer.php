<?php
/**
 * Class ISPAG_Brevo_Mailer
 * Gère l'envoi d'e-mails via l'API Brevo.
 * Utilise ISPAG_Logger pour centraliser les logs dans wp-content/ispag_logs/ispag_brevo_mailer.log.
 */
if (!class_exists('ISPAG_Brevo_Mailer'))
{
    class ISPAG_Brevo_Mailer
    {
        private $api_key;
        private $api_url = 'https://api.brevo.com/v3/smtp/email';

        /** @var ISPAG_Logger Instance du logger. */
        private $logger;

        /**
         * Constructeur.
         */
        public function __construct()
        {
            $this->api_key = getenv('BREVO_API_KEY');
            $this->logger = ISPAG_Logger::get_instance();
            // $user_id = get_current_user_id();
            // $this->logger->log_user_action('brevo_mailer', 'class_initialized', [
            //     'api_key_defined' => !empty($this->api_key)
            // ], $user_id);
        }

        /**
         * Récupère l'ID du template Brevo lié à un slug de phase
         */
        public static function getBrevoTemplateId(?string $slug = null) {
            $logger = ISPAG_Logger::get_instance();
            $user_id = get_current_user_id();

            if (empty($slug)) {
                $logger->log_user_action('brevo_mailer', 'getBrevoTemplateId_called_with_empty_slug', [], $user_id);
                return null;
            }

            $logger->log_user_action('brevo_mailer', 'getBrevoTemplateId_start', [
                'slug' => $slug
            ], $user_id);

            global $wpdb;
            $table = $wpdb->prefix . 'achats_slug_phase';

            $template_id = $wpdb->get_var($wpdb->prepare(
                "SELECT Brevo_id FROM $table WHERE SlugPhase = %s",
                $slug
            ));

            if (!$template_id) {
                $logger->log_error('brevo_mailer', "Aucun Brevo_id trouvé en base pour le slug '$slug'", [
                    'table' => $table,
                    'slug' => $slug
                ], $user_id);
                return null;
            }

            $logger->log_user_action('brevo_mailer', 'getBrevoTemplateId_success', [
                'slug' => $slug,
                'template_id' => $template_id
            ], $user_id);

            return $template_id;
        }

        /**
         * Récupère le délai (en jours) configuré pour un slug
         */
        public static function getBrevoDelayDays(?string $slug = null) {
            $logger = ISPAG_Logger::get_instance();
            $user_id = get_current_user_id();

            if (empty($slug)) {
                $logger->log_user_action('brevo_mailer', 'getBrevoDelayDays_called_with_empty_slug', [], $user_id);
                return 0;
            }

            $logger->log_user_action('brevo_mailer', 'getBrevoDelayDays_start', [
                'slug' => $slug
            ], $user_id);

            global $wpdb;
            $table = $wpdb->prefix . 'achats_slug_phase';

            $delay = $wpdb->get_var($wpdb->prepare(
                "SELECT Brevo_delay_days FROM $table WHERE SlugPhase = %s",
                $slug
            ));

            $result = $delay ? (int)$delay : 0;
            $logger->log_user_action('brevo_mailer', 'getBrevoDelayDays_success', [
                'slug' => $slug,
                'delay_days' => $result
            ], $user_id);

            return $result;
        }

        public static function encode_url_path($url) {
            $parts = parse_url($url);
            if (!isset($parts['path'])) return $url;
            $path = $parts['path'];
            $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
            $new_url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $encoded_path;
            if (isset($parts['query'])) $new_url .= '?' . $parts['query'];
            return $new_url;
        }

        public static function clean_filename($filename) {
            $filename = str_replace(' ', '_', $filename);
            $filename = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', htmlentities($filename, ENT_QUOTES, 'UTF-8'));
            $filename = preg_replace('/[^A-Za-z0-9_\-]/', '', $filename);
            return $filename;
        }

        /**
         * Envoie un e-mail via un template Brevo.
         *
         * @param string $to_email Email du destinataire.
         * @param int $template_id ID du template dans Brevo.
         * @param array $params Variables personnalisées {{ params.NOM_VARIABLE }}.
         * @param array $additional_cc_emails Destinataires supplémentaires en CC (format: [['email' => '...', 'name' => '...']]).
         * @return bool
         */
        public function send_template($to_email, $template_id, $params = array(), $additional_cc_emails = array()) {
            $user_id = get_current_user_id();
            $current_user = get_userdata($user_id);

            $this->logger->log_user_action('brevo_mailer', 'send_template_start', [
                'to_email' => $to_email,
                'template_id' => $template_id,
                'params_count' => count($params),
                'additional_cc_count' => count($additional_cc_emails)
            ], $user_id);

            if (empty($this->api_key)) {
                $this->logger->log_error('brevo_mailer', 'BREVO_API_KEY is empty or not set', [], $user_id);
                return false;
            }

            // Nettoyage des params pour éviter les valeurs NULL qui font planter le JSON
            foreach ($params as $key => $value) {
                if (is_null($value) || $value === false) {
                    $params[$key] = '';
                    $this->logger->log_user_action('brevo_mailer', 'null_param_replaced', [
                        'key' => $key,
                        'original_value' => $value
                    ], $user_id);
                }
            }

            $to_email = trim($to_email);
            if (!is_email($to_email)) {
                $this->logger->log_error('brevo_mailer', 'Invalid email address', [
                    'email' => $to_email
                ], $user_id);
                return false;
            }

            // 1. On récupère l'email de l'utilisateur ou la valeur par défaut
            $original_mail = !empty($current_user->user_email) ? $current_user->user_email : 'c.barthel@ispag-asp.com';
            $this->logger->log_user_action('brevo_mailer', 'original_mail_determined', [
                'original_mail' => $original_mail
            ], $user_id);

            // 2. On définit les correspondances (Mapping)
            $mail_mapping = [
                'vente@ispag-asp.com'     => 'c.tonelli@ispag-asp.ch',
                'c.barthel@ispag-asp.com' => 'c.barthel@ispag-asp.ch'
            ];

            // 3. On remplace si l'email est dans la liste, sinon on garde l'original
            $sender_mail = isset($mail_mapping[$original_mail]) ? $mail_mapping[$original_mail] : $original_mail;
            $sender_name = !empty($current_user->display_name) ? $current_user->display_name : 'ISPAG';

            // CC par défaut
            $cc_emails = [
                ['email' => 'c.barthel@ispag-asp.ch', 'name' => 'Cyril Barthel'],
                ['email' => 'log@mg.ispag-asp.com', 'name' => 'log CRM'],
                ['email' => $sender_mail, 'name' => $sender_name]
            ];

            // Ajouter les CC supplémentaires
            if (!empty($additional_cc_emails) && is_array($additional_cc_emails)) {
                foreach ($additional_cc_emails as $cc) {
                    if (isset($cc['email']) && is_email($cc['email'])) {
                        $cc_emails[] = [
                            'email' => $cc['email'],
                            'name' => $cc['name'] ?? ''
                        ];
                    }
                }
            }

            // Supprimer les doublons (basé sur l'email)
            $unique_cc = [];
            $seen_emails = [];
            foreach ($cc_emails as $cc) {
                if (!isset($seen_emails[$cc['email']])) {
                    $seen_emails[$cc['email']] = true;
                    $unique_cc[] = $cc;
                }
            }

            $data = [
                'sender' => ['name' => 'Cyril Barthel - ISPAG', 'email' => 'c.barthel@ispag-asp.com'],
                'to' => [['email' => $to_email]],
                'templateId' => (int)$template_id,
                'params' => $params
            ];

            // Ajouter les CC uniques
            if (!empty($unique_cc)) {
                $data['cc'] = array_values($unique_cc);
            }

            $this->logger->log_user_action('brevo_mailer', 'request_data_prepared', [
                'data' => $data
            ], $user_id);

            $response = wp_remote_post($this->api_url, [
                'body' => json_encode($data),
                'headers' => [
                    'api-key' => $this->api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->log_error('brevo_mailer', 'WP_REMOTE_POST_FAILED', [
                    'error' => $error_message,
                    'api_url' => $this->api_url
                ], $user_id);
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            $this->logger->log_user_action('brevo_mailer', 'api_response_received', [
                'http_code' => $code,
                'body_length' => strlen($body)
            ], $user_id);

            if ($code >= 400) {
                $this->logger->log_error('brevo_mailer', 'API Error', [
                    'http_code' => $code,
                    'response_body' => substr($body, 0, 500)
                ], $user_id);
                return false;
            }

            $this->logger->log_user_action('brevo_mailer', 'email_sent_successfully', [
                'to_email' => $to_email,
                'template_id' => $template_id,
                'http_code' => $code,
                'cc_count' => count($unique_cc)
            ], $user_id);

            return true;
        }
    }
}