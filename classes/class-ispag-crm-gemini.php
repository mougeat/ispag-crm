<?php

class ISPAG_Crm_Gemini {

    private static $api_key;
    private static $api_url;
    private static $log_file = WP_CONTENT_DIR . '/ispag_gemini.log';

    public static function init(){
        self::$api_key = getenv('CRM_GEMINI_API_KEY');
        // Correction du modèle : gemini-2.0-flash (ou 1.5-flash)
        // self::$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . self::$api_key;

        self::$api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . self::$api_key;
        
        add_filter('ispag_send_to_crm_gemini', [self::class, 'send_to_gemini'], 10, 5);
    }

    /**
     * Le wrapper pour le filtre WordPress 
     * Arguments envoyés par le filtre : $name, $function, $prepared_data, $type
     */
    public static function send_to_gemini($return, $name, $function, $prepared_data, $type) {
        // error_log("[GEMINI send_to_gemini] Received datas : {$name}, {$function}, {$type}. \n", 3, self::$log_file);
        // error_log("[GEMINI send_to_gemini] " . print_r($prepared_data,true)." \n", 3, self::$log_file);
        return self::get_gemini_infos($name, $function, $prepared_data, $type);
    }

    public static function get_gemini_infos($name, $contact_function, $prepared_data, $type = 'contact'){
        
        if (empty(self::$api_key)) {
            // error_log("[GEMINI get_gemini_infos] Erreur : Clé API manquante.", 3, self::$log_file);
            return ['summary' => 'Erreur de configuration API', 'actions' => ''];
        }

        // error_log("[GEMINI get_gemini_infos] STARTING get_gemini_infos \n", 3, self::$log_file);
        // error_log("[GEMINI get_gemini_infos] Received datas {$name}, {$contact_function}, {$type}\n", 3, self::$log_file);
        // error_log("[GEMINI get_gemini_infos] " . print_r($prepared_data,true)." \n", 3, self::$log_file);

        $user_locale = get_user_locale();

        $prompt_instruction = "
        RÔLE :
        Tu es l'Expert Business Analyst Senior pour ISPAG (Suisse/CVC). Tu analyses les données CRM pour préparer le Directeur avant son interaction client.

        RÉSUMÉ NARRATIF (Style HubSpot) :
        Rédige un paragraphe fluide de 2-3 phrases maximum. Fusionne chronologiquement les dernières interactions (notes, mails, cadeaux). 
        Exemple attendu : \"Le contact a reçu un tire-bouchon ISPAG le [Date]. Benjamin Lucas l'a ensuite contacté concernant le projet Leguriveira pour discuter des spécificités techniques.\"

        INSTRUCTION DE FORMATAGE :
        Tu dois impérativement répondre au format JSON pur, sans texte avant ou après.
        L'objet JSON doit avoir exactement cette structure :
        {
        \"summary_html\": \"Le contenu HTML du résumé narratif ici\",
        \"actions\": [
            \"Action concrète 1\",
            \"Action concrète 2\"
        ],
        \"client_dna\": {
            \"language\": \"Technique/Économique/Urgent\",
            \"preference\": \"Inox/Acier Noir\",
            \"health_score\": 1-5
        }
        }

        RÉSUMÉ NARRATIF (summary_html) :
        Rédige un paragraphe fluide de 2-3 phrases (Style HubSpot). Fusionne chronologiquement les dernières interactions. Inclus les dates et noms cités.

        ACTIONS (actions) :
        2-3 actions concrètes basées sur l'analyse.

        LANGUE : {$user_locale}
        ";

        // // Sélection du prompt selon le type
        // if($type === 'company'){
        //     $prompt_instruction = "Tu es un Business Analyst CVC en Suisse. Analyse les données de l'entreprise {$name}. Fais un résumé HTML de moins de 150 mots incluant les projets en cours (Devis/Projet) et les alertes d'échéances. Termine par <hr>.
        //     ACTIONS À ENTREPRENDRE : 2-3 actions.
        //     Sépare impérativement le résumé et les actions par [ACTIONS_SEPARATOR].
        //     Langue : {$user_locale}";
        // }
        // elseif($type === 'contact'){
        //     $prompt_instruction = "Tu es un Business Analyst CVC en Suisse. Synthétise les données de {$name} (Fonction: {$contact_function}). 
        //     Brief HTML < 100 mots : Projets en cours, interactions récentes, alertes close_date < 30j, et influence (décideur/technique). 
        //     Format : HTML sans <html>/<body>. Termine le brief par <hr>.
        //     ACTIONS À ENTREPRENDRE : 2-3 actions concrètes.
        //     Sépare impérativement la synthèse des actions par [ACTIONS_SEPARATOR].
        //     Langue : {$user_locale}";
        // } 
        // elseif($type === 'follow_up_email'){
        //     $prompt_instruction = "Tu es un commercial expert chez ISPAG (Suisse). 
        //     Analyse les dernières notes et emails pour identifier le TON (formel, amical, direct).
        //     Rédige un email de relance pour l'offre mentionnée dans les données.
        //     L'offre arrive à échéance. Sois convaincant mais professionnel.
        //     RECOPIE LE TON des échanges précédents.
        //     {$prepared_data}
        //     Format : Commence directement par l'objet du mail, puis le corps.
        //     Sépare l'objet du corps par [SUBJECT_SEPARATOR].
        //     Langue : {$user_locale}";
        // }
        // else {
            //  $prompt_instruction = "Génère un titre de 5 mots max pour cette tâche : {$prepared_data}. Sépare par [ACTIONS_SEPARATOR].";
        // }

        $full_prompt = $prompt_instruction . "\n\nDONNÉES À ANALYSER :\n" . $prepared_data;

        // error_log("[GEMINI get_gemini_infos] Prompt : {$full_prompt}", 3, self::$log_file);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $full_prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json', // Force le format JSON pur
                'temperature'      => 0.2,               // Plus précis pour l'analyse technique
                'maxOutputTokens'  => 1000,              // Augmenté légèrement pour ne pas couper le JSON
            ]
        ];

        $response = wp_remote_post(self::$api_url . '?key=' . self::$api_key, [
            'method'    => 'POST',
            'timeout'   => 30,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            // error_log("[GEMINI ERROR] WP_Error: " . $response->get_error_message(), 3, self::$log_file);
            return ['summary' => 'Erreur de connexion API.', 'actions' => ''];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);

        // LOG DU RETOUR BRUT (Utile pour voir les erreurs d'API Key ou de sécurité)
        // error_log("[GEMINI RESPONSE] Code: $response_code", 3, self::$log_file);
        // error_log("[GEMINI RESPONSE] Body: " . $body_raw, 3, self::$log_file);

        $body = json_decode($body_raw, true);

        // 1. Vérification Safety Filters
        if (isset($body['promptFeedback']['blockReason'])) {
            // error_log("[GEMINI ERROR] Bloqué par Google: " . $body['promptFeedback']['blockReason'], 3, self::$log_file);
        }

        // 2. Extraction du texte brut (qui contient ton JSON)
        $raw_ai_text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($raw_ai_text)) {
            // error_log("[GEMINI ERROR] Texte vide reçu de Gemini.", 3, self::$log_file);
            return ['summary' => 'Aucune donnée générée.', 'actions' => ''];
        }

        // 3. Nettoyage du texte (Gemini entoure souvent le JSON de ```json ... ```)
        $clean_json = preg_replace('/^```json\s*|```\s*$/', '', trim($raw_ai_text));

        // 4. Décodage du JSON généré par l'IA
        $ai_data = json_decode($clean_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // error_log("[GEMINI ERROR] JSON mal formé : " . $clean_json, 3, self::$log_file);
            // Fallback : si le JSON échoue, on tente de sauver les meubles avec ton ancien explode
            return [
                'summary' => $raw_ai_text, 
                'actions' => 'Erreur de formatage JSON.'
            ];
        }

        // 5. Retour des données structurées
        // On transforme le tableau d'actions en HTML (liste à puces) pour ton interface
        $actions_html = '';
        if (!empty($ai_data['actions'])) {
            $actions_html = '<ul class="ispag-actions-list">';
            foreach ($ai_data['actions'] as $action) {
                $actions_html .= '<li>' . esc_html($action) . '</li>';
            }
            $actions_html .= '</ul>';
        }

        return [
            'summary' => $ai_data['summary_html'] ?? 'Résumé indisponible.',
            'actions' => $actions_html,
            'dna'     => $ai_data['client_dna'] ?? null // Optionnel : pour usage futur
        ];
    }
}