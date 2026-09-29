<?php
defined('ABSPATH') || exit;

if (!class_exists('ISPAG_Page_Installer', false)) {

/**
 * Création des pages WordPress dont ISPAG a besoin (shortcodes et modèles de page du thème).
 *
 * Chaque plugin/thème déclare ses pages avec register() ; cette classe est partagée (une copie par paquet,
 * la première chargée est utilisée).
 *
 * Règles de sécurité pour un site existant :
 *  - une page n'est créée que si son adresse (slug) n'existe pas, et si aucune page ne porte déjà sa clé ISPAG ;
 *    les pages existantes ne sont JAMAIS modifiées ;
 *  - la création n'a lieu qu'à l'activation du plugin / du thème, ou sur clic dans Outils → Pages ISPAG.
 *    Jamais automatiquement au chargement (donc pas lors d'une mise à jour automatique) ;
 *  - les pages d'une autre langue (Polylang) ne sont créées que si Polylang et cette langue existent.
 *
 * Format d'une page : ['key','slug','title', 'content'?, 'template'?, 'lang'?, 'group'?]
 *   lang  : 'fr' (défaut) ou 'de' ; group : identifiant commun aux traductions d'une même page.
 */
class ISPAG_Page_Installer {

    const DEFAULT_LANG = 'fr';
    const KEY_META     = '_ispag_page_key';
    const FLUSH_OPTION = 'ispag_flush_rewrite_rules';

    /** @var array<string, array> paquet => pages */
    private static $registry = [];
    private static $hooked   = false;

    public static function register($package, array $pages) {
        self::$registry[$package] = $pages;
        if (!self::$hooked) {
            self::$hooked = true;
            add_action('admin_menu', [self::class, 'admin_menu']);
        }
    }

    /** À appeler à l'activation : crée les pages manquantes du paquet et programme le rafraîchissement des adresses. */
    public static function on_activation($package) {
        self::create_missing($package);
        self::schedule_flush();
    }

    // ------------------------------------------------------------------ Polylang

    private static function languages() {
        return function_exists('pll_languages_list') ? (array) pll_languages_list(['fields' => 'slug']) : [];
    }

    private static function lang_of($page) {
        return $page['lang'] ?? self::DEFAULT_LANG;
    }

    /** null si la page doit être créée, sinon la raison de l'ignorer. */
    private static function skip_reason($page) {
        $lang = self::lang_of($page);
        if ($lang !== self::DEFAULT_LANG && !in_array($lang, self::languages(), true)) {
            return 'langue « ' . $lang . ' » absente (Polylang)';
        }
        return null;
    }

    // ------------------------------------------------------------------ recherche / création

    /** ID de la page existante (par clé ISPAG, sinon par adresse), ou 0. */
    private static function find($page) {
        $ids = get_posts([
            'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
            'meta_key' => self::KEY_META, 'meta_value' => $page['key'],
        ]);
        if (!empty($ids)) return (int) $ids[0];
        $existing = get_page_by_path($page['slug'], OBJECT, 'page');
        return $existing ? (int) $existing->ID : 0;
    }

    /**
     * @param string|null $package limiter à un paquet (null = tous)
     * @return array ['created'=>[slug=>id], 'existing'=>[slug=>id], 'skipped'=>[slug=>raison], 'errors'=>[slug=>msg]]
     */
    public static function create_missing($package = null) {
        $out = ['created' => [], 'existing' => [], 'skipped' => [], 'errors' => []];
        $created_by_group = []; // group => [lang => id] (uniquement les pages créées ici)

        foreach (self::$registry as $name => $pages) {
            if ($package !== null && $name !== $package) continue;
            foreach ($pages as $page) {
                $slug = $page['slug'];
                if ($reason = self::skip_reason($page)) { $out['skipped'][$slug] = $reason; continue; }
                if ($id = self::find($page)) { $out['existing'][$slug] = $id; continue; }

                $id = wp_insert_post([
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_name'    => $slug,
                    'post_title'   => $page['title'],
                    'post_content' => $page['content'] ?? '',
                ], true);
                if (is_wp_error($id) || !$id) {
                    $out['errors'][$slug] = is_wp_error($id) ? $id->get_error_message() : 'wp_insert_post failed';
                    continue;
                }
                update_post_meta($id, self::KEY_META, $page['key']);
                // Meta posée directement : wp_insert_post refuse un modèle tant que le thème concerné n'est pas actif.
                if (!empty($page['template'])) update_post_meta($id, '_wp_page_template', $page['template']);

                $out['created'][$slug] = $id;
                if (!empty($page['group'])) $created_by_group[$page['group']][self::lang_of($page)] = $id;
            }
        }

        self::apply_languages($created_by_group);
        return $out;
    }

    /** Langue + liens de traduction, UNIQUEMENT pour les pages qu'on vient de créer. */
    private static function apply_languages(array $created_by_group) {
        if (!function_exists('pll_set_post_language')) return;
        $langs = self::languages();
        foreach ($created_by_group as $translations) {
            foreach ($translations as $lang => $id) {
                if (in_array($lang, $langs, true)) pll_set_post_language($id, $lang);
            }
            if (count($translations) > 1 && function_exists('pll_save_post_translations')) {
                pll_save_post_translations($translations);
            }
        }
    }

    // ------------------------------------------------------------------ adresses (permaliens)

    public static function schedule_flush() {
        update_option(self::FLUSH_OPTION, 1, false);
    }

    /**
     * Les règles /purchase/123, /deal/…, /contact/… ne sont enregistrées qu'à l'init : sans rafraîchissement
     * elles donnent une 404. Un site neuf est aussi en permaliens « simples », où elles ne peuvent pas fonctionner.
     */
    public static function maybe_flush() {
        if (!get_option(self::FLUSH_OPTION)) return;
        delete_option(self::FLUSH_OPTION);
        global $wp_rewrite;
        if (!get_option('permalink_structure') && $wp_rewrite) {
            $wp_rewrite->set_permalink_structure('/%postname%/');
        }
        flush_rewrite_rules();
    }

    // ------------------------------------------------------------------ écran d'administration

    public static function admin_menu() {
        add_management_page('Pages ISPAG', 'Pages ISPAG', 'manage_options', 'ispag-pages', [self::class, 'render_admin']);
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        $notice = '';
        if (!empty($_POST['ispag_create_pages']) && check_admin_referer('ispag_create_pages')) {
            $r = self::create_missing();
            self::schedule_flush();
            $notice = sprintf('<div class="notice notice-success"><p>%d page(s) created.%s</p></div>', count($r['created']),
                $r['errors'] ? ' Erreurs : ' . esc_html(implode(' ; ', array_map(function ($s, $m) { return "$s ($m)"; }, array_keys($r['errors']), $r['errors']))) : '');
        }
        echo '<div class="wrap"><h1>Pages ISPAG</h1>' . $notice;
        echo '<p>Pages required by the ISPAG plugins and theme. The button only creates missing pages; existing pages are never modified.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Source</th><th>Address</th><th>Title</th><th>Status</th></tr></thead><tbody>';
        foreach (self::$registry as $name => $pages) {
            foreach ($pages as $page) {
                if ($reason = self::skip_reason($page)) { $state = 'Skipped — ' . $reason; }
                elseif ($id = self::find($page)) { $state = 'Existe (#' . $id . ')'; }
                else { $state = '<strong>Manquante</strong>'; }
                printf('<tr><td>%s</td><td>/%s/</td><td>%s</td><td>%s</td></tr>', esc_html($name), esc_html($page['slug']), esc_html($page['title']), $state);
            }
        }
        echo '</tbody></table><form method="post" style="margin-top:16px">';
        wp_nonce_field('ispag_create_pages');
        echo '<input type="submit" name="ispag_create_pages" class="button button-primary" value="Create missing pages"></form></div>';
    }
}

// Enregistré au chargement de la classe (et non dans register()) : le CRM programme un rafraîchissement
// des adresses sans déclarer de pages.
add_action('admin_init', ['ISPAG_Page_Installer', 'maybe_flush'], 99);

}
