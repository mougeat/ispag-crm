<?php
class ISPAG_Crm_WhatsApp_Sender {

    /**
     * Formate le numéro et nettoie le message pour WhatsApp
     */
    public static function get_link($phone, $message = '') {
        // 1. Détection de l'international
        $has_plus = (strpos(trim($phone), '+') === 0);
        
        // 2. Nettoyage du téléphone
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);

        // 3. Logique de l'indicatif (France/Suisse)
        if (!$has_plus) {
            if (strpos($clean_phone, '0') === 0) {
                if (strlen($clean_phone) === 10) {
                    $clean_phone = '33' . substr($clean_phone, 1);
                } else {
                    $clean_phone = '41' . substr($clean_phone, 1);
                }
            }
        }

        $url = "https://wa.me/" . $clean_phone;

        if (!empty($message)) {
            // --- GESTION DES SAUTS DE LIGNE ---
            // On remplace les <br>, <br /> et les fermetures de paragraphes </p> 
            // par un vrai saut de ligne PHP (\n)
            $message_with_newlines = str_replace(['</p>', '<br>', '<br />', '<br/>'], "\n", $message);

            // --- NETTOYAGE FINAL ---
            // On enlève le reste des balises HTML
            $clean_message = strip_tags($message_with_newlines);
            
            // On décode les entités (ex: &eacute; devient é)
            $clean_message = html_entity_decode($clean_message, ENT_QUOTES, 'UTF-8');
            
            // Nettoyage des espaces doubles créés par le remplacement des </p>
            $clean_message = trim($clean_message);

            $url .= "?text=" . rawurlencode($clean_message);
        }

        return $url;
    }
}