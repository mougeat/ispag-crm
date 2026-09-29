<?php
/**
 * Class ISPAG_Crm_Mistral
 * Gère les interactions avec l'API Mistral pour le CRM ISPAG.
 * Utilise ISPAG_Logger pour centraliser les logs dans wp-content/ispag_logs/ispag_crm_mistral.log.
 */
class ISPAG_Crm_Mistral
{
    private static $api_key;
    private static $api_url = 'https://api.mistral.ai/v1/agents/completions';

    /** @var ISPAG_Logger Instance du logger. */
    private static $logger;

    /**
     * Initialise la classe et le logger.
     */
    public static function init()
    {
        self::$api_key = getenv('CRM_MISTRAL_API_KEY');
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('crm_mistral', 'class_initialized', [], $user_id);

        add_filter('ispag_send_to_crm_mistral', [self::class, 'send_to_mistral'], 10, 5);
    }

    /**
     * Log un message dans le fichier de log.
     *
     * @param string $message Message à logger.
     * @param mixed|null $data Données supplémentaires à logger.
     */
    private static function log($message, $data = null)
    {
        $user_id = get_current_user_id();
        $context = [];
        if ($data !== null)
        {
            $context['data'] = is_string($data) ? $data : print_r($data, true);
        }
        self::$logger->log('crm_mistral', $message, $user_id, $context);
    }

    /**
     * Envoie une requête à Mistral et retourne les informations.
     *
     * @param mixed $return Valeur de retour par défaut.
     * @param string $name Nom du contact ou de l'entité.
     * @param string $contact_function Fonction du contact.
     * @param string $prepared_data Données préparées pour Mistral.
     * @param string $type Type de requête (ex: 'contact', 'meeting').
     * @return array
     */
    public static function send_to_mistral($return, $name, $contact_function, $prepared_data, $type)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('crm_mistral', 'send_to_mistral_start', ['name' => $name, 'type' => $type], $user_id);
        return self::get_mistral_infos($name, $contact_function, $prepared_data, $type);
    }

    /**
     * Récupère les informations depuis Mistral.
     *
     * @param string $name Nom du contact ou de l'entité.
     * @param string $contact_function Fonction du contact.
     * @param string $prepared_data Données préparées.
     * @param string $type Type de requête.
     * @return array
     */
    public static function get_mistral_infos($name, $contact_function, $prepared_data, $type = 'contact')
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('crm_mistral', 'get_mistral_infos_start', ['name' => $name, 'type' => $type], $user_id);

        self::log("--- DÉBUT REQUÊTE MISTRAL CRM ($type) ---");

        if (empty(self::$api_key))
        {
            self::log("ERREUR: Clé API vide.");
            self::$logger->log('crm_mistral', 'ERROR: API key is empty', $user_id);
            return ['summary' => 'Erreur configuration API', 'actions' => ''];
        }

        $user_locale = get_user_locale();
        self::$logger->log_user_action('crm_mistral', 'user_locale_detected', ['locale' => $user_locale], $user_id);

        $prompt_data = "IDENTITÉ : {$name}\n";
        $prompt_data .= "FONCTION : {$contact_function}\n";
        $prompt_data .= "DONNÉES CRM : {$prepared_data}\n";
        $prompt_data .= "LANGUE DE RÉPONSE OBLIGATOIRE : {$user_locale}";

        self::$logger->log_user_action('crm_mistral', 'prompt_prepared', ['prompt_length' => strlen($prompt_data)], $user_id);

        // Logique de choix de l'agent
        if ($type === 'meeting')
        {
            $agent_id = "ag_019de42d622573bc81ca31fa260d2bbe";
            self::$logger->log_user_action('crm_mistral', 'agent_selected', ['agent_id' => $agent_id, 'type' => 'meeting'], $user_id);
        }
        else
        {
            $agent_id = "ag_019c27412e2c701aa225a5f81d8433a0";
            self::$logger->log_user_action('crm_mistral', 'agent_selected', ['agent_id' => $agent_id, 'type' => 'contact'], $user_id);
        }

        $payload = [
            'agent_id' => $agent_id,
            'messages' => [['role' => 'user', 'content' => $prompt_data]]
        ];

        self::$logger->log_user_action('crm_mistral', 'request_payload_prepared', ['agent_id' => $agent_id], $user_id);

        $response = wp_remote_post(self::$api_url, [
            'method' => 'POST',
            'timeout' => 45,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . self::$api_key
            ],
            'body' => json_encode($payload),
        ]);

        if (is_wp_error($response))
        {
            $error_message = $response->get_error_message();
            self::log("ERREUR WP_REMOTE: " . $error_message);
            self::$logger->log('crm_mistral', 'ERROR: WP_REMOTE_REQUEST_FAILED - ' . $error_message, $user_id);
            return ['summary' => 'Network error.', 'actions' => ''];
        }

        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        self::$logger->log_user_action('crm_mistral', 'response_received', ['body_size' => strlen($body_raw)], $user_id);

        $content = $body['choices'][0]['message']['content'] ?? '';
        $raw_ai_text = '';

        if (is_array($content))
        {
            foreach ($content as $part)
            {
                if (isset($part['type']) && $part['type'] === 'text')
                {
                    $raw_ai_text = $part['text'];
                    break;
                }
            }
        }
        else
        {
            $raw_ai_text = $content;
        }

        self::log("SUCCÈS: Réponse brute reçue de l'agent [$agent_id]. Aperçu: " . strip_tags($raw_ai_text));
        self::$logger->log_user_action('crm_mistral', 'raw_response_received', ['agent_id' => $agent_id, 'preview' => substr(strip_tags($raw_ai_text), 0, 100)], $user_id);

        if (empty($raw_ai_text))
        {
            self::log("ERREUR: Contenu vide reçu de l'IA.");
            self::$logger->log('crm_mistral', 'ERROR: Empty AI response', $user_id);
            return ['summary' => 'The AI returned no data.', 'actions' => ''];
        }

        // Nettoyage agressif du JSON
        $cleaned = preg_replace('/^```json\s+/i', '', $raw_ai_text);
        $cleaned = preg_replace('/\s+```$/', '', $cleaned);

        $first_bracket = strpos($cleaned, '{');
        $last_bracket = strrpos($cleaned, '}');

        if ($first_bracket !== false && $last_bracket !== false)
        {
            $cleaned = substr($cleaned, $first_bracket, ($last_bracket - $first_bracket) + 1);
        }

        $cleaned = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s', function($matches)
        {
            return str_replace(["\r", "\n"], ['\r', '\n'], $matches[0]);
        }, $cleaned);

        $cleaned = preg_replace('!/\*.*?\*/!s', '', $cleaned);
        $cleaned = preg_replace('/(?<!:)\/\/.*/', '', $cleaned);
        $cleaned = trim($cleaned);

        self::$logger->log_user_action('crm_mistral', 'json_cleaned', ['cleaned_length' => strlen($cleaned)], $user_id);

        $ai_data = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE)
        {
            $error_msg = json_last_error_msg();
            self::log("ERREUR JSON: " . $error_msg, "Texte tenté: " . $cleaned);
            self::$logger->log('crm_mistral', 'ERROR: JSON_DECODE_FAILED - ' . $error_msg, $user_id, ['cleaned_text' => substr($cleaned, 0, 200)]);
            return ['summary' => 'AI data formatting error.', 'actions' => ''];
        }

        self::$logger->log_user_action('crm_mistral', 'json_decoded_successfully', [], $user_id);

        // Log de succès
        $log_summary = !empty($ai_data['summary_html']) ? $ai_data['summary_html'] : 'Pas de résumé';
        self::log("SUCCÈS: Réponse reçue de l'agent [$agent_id]. Aperçu: " . strip_tags($log_summary));
        self::$logger->log_user_action('crm_mistral', 'successful_response', ['agent_id' => $agent_id, 'summary_preview' => substr(strip_tags($log_summary), 0, 100)], $user_id);

        // Formatage pour l'affichage
        $actions_html = '';
        if (!empty($ai_data['actions']) && is_array($ai_data['actions']))
        {
            $actions_html = '<ul class="ispag-actions-list">';
            foreach ($ai_data['actions'] as $action)
            {
                $actions_html .= '<li>' . esc_html($action) . '</li>';
            }
            $actions_html .= '</ul>';
            self::$logger->log_user_action('crm_mistral', 'actions_formatted', ['count' => count($ai_data['actions'])], $user_id);
        }

        $summary = $ai_data['summary_html'] ?? 'Summary unavailable';
        if (!empty($ai_data['alert']))
        {
            $summary = '<div class="ispag-ai-alert">⚠️ ' . esc_html($ai_data['alert']) . '</div>' . $summary;
            self::$logger->log_user_action('crm_mistral', 'alert_detected', ['alert' => $ai_data['alert']], $user_id);
        }

        self::$logger->log_user_action('crm_mistral', 'response_formatted', [], $user_id);

        return [
            'summary' => $summary,
            'profil' => $ai_data['profile_html'] ?? 'Profil indisponible',
            'actions' => $actions_html,
            'objectives' => $ai_data['objectives'] ?? '',
            'questions' => $ai_data['questions'] ?? '',
            'attention' => $ai_data['attention'] ?? '',
            'agenda' => $ai_data['agenda'] ?? '',
            'hook' => $ai_data['hook'] ?? '',
            'dna' => [
                'language' => $ai_data['client_dna']['language'] ?? '-',
                'preference' => $ai_data['client_dna']['preference'] ?? '-',
                'health_score' => $ai_data['client_dna']['health_score'] ?? '-',
                'explication_health_score' => $ai_data['client_dna']['explication_health_score'] ?? ''
            ]
        ];
    }
}