<?php

class ISPAG_Crm_Mistral {

    private static $api_key;
    private static $api_url = 'https://api.mistral.ai/v1/agents/completions';
    private static $log_file;

    public static function init(){
        self::$api_key  = getenv('CRM_MISTRAL_API_KEY');
        self::$log_file = WP_CONTENT_DIR . '/ispag_mistral.log';
        add_filter('ispag_send_to_crm_mistral', [self::class, 'send_to_mistral'], 10, 5);
    }

    private static function log($message, $data = null) {
        $output = "[" . date('Y-m-d H:i:s') . "] " . $message;
        if ($data) { $output .= " | Data: " . (is_string($data) ? $data : print_r($data, true)); }
        error_log($output . PHP_EOL, 3, self::$log_file);
    }

    public static function send_to_mistral($return, $name, $function, $prepared_data, $type) {
        return self::get_mistral_infos($name, $function, $prepared_data, $type);
    }

    public static function get_mistral_infos($name, $contact_function, $prepared_data, $type = 'contact'){
    
        self::log("--- DEBUT REQUETE MISTRAL CRM ($type) ---");
        
        if (empty(self::$api_key)) {
            self::log("ERREUR: Clé API vide.");
            return ['summary' => 'Erreur configuration API', 'actions' => ''];
        }

        $user_locale = get_user_locale();
        
        $prompt_data = "IDENTITÉ : {$name}\n";
        $prompt_data .= "FONCTION : {$contact_function}\n";
        $prompt_data .= "DONNÉES CRM : {$prepared_data}\n";
        $prompt_data .= "LANGUE DE RÉPONSE OBLIGATOIRE : {$user_locale}";

        // Logique de choix de l'agent
        if ( $type === 'meeting' ) {
            $agent_id = "ag_019de42d622573bc81ca31fa260d2bbe";
        } else {
            $agent_id = "ag_019c27412e2c701aa225a5f81d8433a0";
        }

        $payload = [
            'agent_id' => $agent_id,
            'messages' => [['role' => 'user', 'content' => $prompt_data]]
        ];

        $response = wp_remote_post(self::$api_url, [
            'method'  => 'POST',
            'timeout' => 45,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . self::$api_key
            ],
            'body'    => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            self::log("ERREUR WP_REMOTE: " . $response->get_error_message());
            return ['summary' => 'Erreur réseau.', 'actions' => ''];
        }

        $body_raw = wp_remote_retrieve_body($response);
        $body     = json_decode($body_raw, true);

        $content = $body['choices'][0]['message']['content'] ?? '';
        $raw_ai_text = '';

        if (is_array($content)) {
            foreach ($content as $part) {
                if (isset($part['type']) && $part['type'] === 'text') {
                    $raw_ai_text = $part['text'];
                    break;
                }
            }
        } else {
            $raw_ai_text = $content;
        }

        self::log("SUCCÈS: Réponse brute reçue de l'agent [$agent_id]. Aperçu: " . strip_tags($raw_ai_text));

        if (empty($raw_ai_text)) {
            self::log("ERREUR: Contenu vide reçu de l'IA.");
            return ['summary' => 'L\'IA n\'a renvoyé aucune donnée.', 'actions' => ''];
        }

        // --- NETTOYAGE AGRESSIF DU JSON ---
        $cleaned = preg_replace('/^```json\s+/i', '', $raw_ai_text);
        $cleaned = preg_replace('/\s+```$/', '', $cleaned);
        
        $first_bracket = strpos($cleaned, '{');
        $last_bracket  = strrpos($cleaned, '}');
        
        if ($first_bracket !== false && $last_bracket !== false) {
            $cleaned = substr($cleaned, $first_bracket, ($last_bracket - $first_bracket) + 1);
        }

        $cleaned = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s', function($matches) {
            return str_replace(["\r", "\n"], ['\r', '\n'], $matches[0]);
        }, $cleaned);
        $cleaned = preg_replace('!/\*.*?\*/!s', '', $cleaned);
        $cleaned = preg_replace('/(?<!:)\/\/.*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $ai_data = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log("ERREUR JSON: " . json_last_error_msg(), "Texte tenté: " . $cleaned);
            return ['summary' => 'Erreur de formatage des données IA.', 'actions' => ''];
        }

        // --- LOG DE SUCCÈS ---
        $log_summary = !empty($ai_data['summary_html']) ? $ai_data['summary_html'] : 'Pas de résumé';
        self::log("SUCCÈS: Réponse reçue de l'agent [$agent_id]. Aperçu: " . strip_tags($log_summary));

        // --- FORMATAGE POUR L'AFFICHAGE ---
        $actions_html = '';
        if (!empty($ai_data['actions']) && is_array($ai_data['actions'])) {
            $actions_html = '<ul class="ispag-actions-list">';
            foreach ($ai_data['actions'] as $action) {
                $actions_html .= '<li>' . esc_html($action) . '</li>';
            }
            $actions_html .= '</ul>';
        }

        $summary = $ai_data['summary_html'] ?? 'Résumé indisponible';
        if (!empty($ai_data['alert'])) {
            $summary = '<div class="ispag-ai-alert">⚠️ ' . esc_html($ai_data['alert']) . '</div>' . $summary;
        }

        return [
            'summary' => $summary,
            'profil'  => $ai_data['profile_html'] ?? 'Profil indisponible',
            'actions' => $actions_html,
            'objectives' => $ai_data['objectives'] ?? '',
            'questions'  => $ai_data['questions']  ?? '',
            'attention'  => $ai_data['attention']  ?? '',
            'agenda'     => $ai_data['agenda']     ?? '',
            'hook'       => $ai_data['hook']       ?? '',
            'dna'     => [
                'language' => $ai_data['client_dna']['language'] ?? '-',
                'preference' => $ai_data['client_dna']['preference'] ?? '-',
                'health_score' => $ai_data['client_dna']['health_score'] ?? '-',
                'explication_health_score' => $ai_data['client_dna']['explication_health_score'] ?? ''
            ]
        ];
    }
}