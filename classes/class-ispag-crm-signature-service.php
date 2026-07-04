<?php
/**
 * Service de gestion des signatures d'email ISPAG
 */
class ISPAG_Crm_Signature_Service {

    /**
     * Génère le HTML de la signature en incluant les infos du contact cible
     */
    public static function get_html_signature($contact_id = null) {
        // 1. Récupération des infos du contact si l'ID est fourni
        $contact_info = [
            'name'  => 'Cyril Barthel',
            'phone' => '+41 79 194 54 69',
            'email' => 'c.barthel@ispag-asp.ch'
        ];
        if ($contact_id) {
            $contact_repo = new ISPAG_Crm_Contacts_Repository();
            $contact = $contact_repo->get_contact_by_id($contact_id);
            
            if ($contact) {
                $contact_info = [
                    'name'  => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
                    'phone' => $contact->phone ?? $contact->mobile ?? '',
                    'email' => $contact->email ?? ''
                ];
            }
        }

        $logo_url = "https://app.ispag-asp.ch/wp-content/uploads/2024/06/Logo_ISPAG_CMYK_F_web.png";
        
        // 2. Construction du HTML
        $html = '<br><br>';
        $html .= '<div style="font-family: Arial, sans-serif; color: #333; line-height: 1.5; border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px;">';
        
        // Infos de l'expéditeur (Toi)
        $html .= '<p style="margin: 0;"><strong>' . $contact_info['name'] . '</strong></p>';
        $html .= '<p style="margin: 0;">' . $contact_info['phone'] . ' | ' . $contact_info['email'] . '</p>';
        
        $html .= '<div style="margin-top: 10px;">';
        $html .= '    <a href="https://www.ispag.ch" target="_blank">';
        $html .= '        <img src="' . esc_url($logo_url) . '" alt="ISPAG" style="width: 150px; height: auto; border: 0;">';
        $html .= '    </a>';
        $html .= '    <p style="margin: 10px 0 0 0; font-size: 13px; color: #666;">';
        $html .= '        ISPAG, Succursale de Issa S.A. | Champ-Paccot 19 | CH-1627 Vaulruz | Centrale: +41 26 912 56 72 <br>';
        $html .= '        <a href="https://www.ispag-asp.ch" style="color: #0073aa; text-decoration: none;">www.ispag-asp.ch</a>';
        $html .= '    </p>';
        $html .= '</div>';


        $html .= '</div>';
        
        return $html;
    }

    /**
     * Enveloppe le contenu avec le style et la signature
     */
    public static function wrap_message($content, $contact_id = null) {
        return '
        <html>
            <head><meta charset="UTF-8"></head>
            <body style="margin:0; padding:20px; font-family: Arial, sans-serif; font-size: 14px; color: #333;">
                <div class="email-content">' . $content . '</div>
                ' . self::get_html_signature($contact_id) . '
            </body>
        </html>';
    }
}