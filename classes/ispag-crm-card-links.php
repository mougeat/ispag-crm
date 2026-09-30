<?php
defined('ABSPATH') || exit;

/**
 * Cartes entreprise / contact d'un deal : pour un utilisateur sans manage_order, on garde l'affichage
 * mais on retire les liens qui mènent aux fiches détaillées (/company/…, /contact/…).
 */
if (!function_exists('ispag_crm_strip_detail_links')) {
    function ispag_crm_strip_detail_links($html) {
        if (current_user_can('manage_order') || $html === '') {
            return $html;
        }
        $is_detail_url = function ($url) {
            return (bool) preg_match('#/(company|contact|entreprise-detail|contact-detail)(/|\?|$)|[?&](company|contact)_id=#i', (string) $url);
        };
        // <a href="…/company/12/">texte</a> → texte
        $html = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($m) use ($is_detail_url) {
            if (preg_match('#href\s*=\s*(["\'])(.*?)\1#i', $m[1], $h) && $is_detail_url($h[2])) {
                return $m[2];
            }
            return $m[0];
        }, $html);
        // Éléments cliquables portant l'adresse d'une fiche (data-href, data-url, onclick)
        $html = preg_replace_callback('#\s(data-href|data-url|onclick)\s*=\s*(["\'])(.*?)\2#i', function ($m) use ($is_detail_url) {
            return $is_detail_url($m[3]) ? '' : $m[0];
        }, $html);
        return $html;
    }
}
