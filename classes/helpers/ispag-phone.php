<?php
defined('ABSPATH') || exit;

if (!function_exists('ispag_format_phone')) {
    /**
     * Numéro de téléphone lisible, pour l'affichage : +41 79 456 94 77.
     * Reconnaît les numéros suisses (+41 / 0041 / 0xx…), français (+33) et formate les autres pays en groupes ; si le numéro
     * n'est pas reconnaissable, il est renvoyé tel quel (jamais de perte d'information). La valeur enregistrée n'est pas modifiée.
     */
    function ispag_format_phone($raw): string {
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return $raw;
        $plus = strpos($raw, '+') === 0;

        if ($plus)                              $intl = $digits;
        elseif (strpos($digits, '00') === 0)    $intl = substr($digits, 2);
        elseif (preg_match('/^0\d{9}$/', $digits)) $intl = '41' . substr($digits, 1);   // national suisse (0xx xxx xx xx)
        else return $raw;

        if (strpos($intl, '41') === 0 && strlen($intl) === 11) {          // +41 xx xxx xx xx
            $n = substr($intl, 2);
            return '+41 ' . substr($n, 0, 2) . ' ' . substr($n, 2, 3) . ' ' . substr($n, 5, 2) . ' ' . substr($n, 7, 2);
        }
        if (strpos($intl, '33') === 0 && strlen($intl) === 11) {          // +33 x xx xx xx xx
            $n = substr($intl, 2);
            return '+33 ' . substr($n, 0, 1) . ' ' . implode(' ', str_split(substr($n, 1), 2));
        }
        if (strlen($intl) < 8 || strlen($intl) > 15) return $raw;
        // autres pays : indicatif sur 2 chiffres (49, 39, 44…), puis groupes de 3
        return '+' . substr($intl, 0, 2) . ' ' . implode(' ', str_split(substr($intl, 2), 3));
    }

    /** Lien tel: propre (chiffres et « + » seulement). */
    function ispag_phone_href($raw): string {
        $f = ispag_format_phone($raw);
        return 'tel:' . preg_replace('/[^+\d]/', '', $f !== '' && $f[0] === '+' ? $f : (string) $raw);
    }
}
