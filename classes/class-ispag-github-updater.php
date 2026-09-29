<?php
defined('ABSPATH') || exit;

if (!class_exists('ISPAG_GitHub_Updater', false)) {

/**
 * Mise à jour automatique d'un plugin ou d'un thème ISPAG depuis une branche GitHub.
 *
 * DÉSACTIVÉ PAR DÉFAUT. Il ne s'active que si wp-config.php définit :
 *
 *     define('ISPAG_GITHUB_TOKEN', 'github_pat_xxx');            // jeton en lecture seule (Contents: Read) sur les dépôts
 *     define('ISPAG_UPDATE_BRANCH', 'claude/eager-galileo-tcm8l8'); // branche suivie
 *     define('ISPAG_GITHUB_AUTO_UPDATE', false);                  // (optionnel) true par défaut : installe sans confirmation
 *
 * Sans ces constantes (site de production), ce fichier ne fait strictement rien.
 *
 * Fonctionnement :
 *  - une mise à jour est proposée quand le SHA du dernier commit de la branche diffère du SHA installé
 *    (mémorisé en option après chaque installation) ;
 *  - le ZIP est téléchargé via l'API GitHub, épinglé sur ce SHA, avec le jeton ;
 *  - le dossier du ZIP (« mougeat-repo-<sha> ») est renommé au bon nom de plugin/thème ;
 *  - le fichier .env (absent de Git) est sauvegardé puis restauré, car WordPress vide le dossier lors d'une mise à jour ;
 *  - un cron toutes les 15 minutes détecte les nouveaux commits et, si l'auto-update est actif, les installe.
 */
class ISPAG_GitHub_Updater {

    const CACHE_TTL      = 300;   // secondes : durée de mémorisation du SHA distant
    const POLL_INTERVAL  = 900;   // secondes : fréquence du cron de détection
    const CRON_HOOK      = 'ispag_github_updater_poll';
    const PRESERVE       = ['.env'];

    /** @var self[] toutes les instances (un plugin/thème chacune), pour le cron partagé */
    private static $instances = [];
    private static $cron_ready = false;

    private $type;      // 'plugin' | 'theme'
    private $slug;      // nom du dossier
    private $basename;  // plugin : 'dossier/fichier.php' — thème : 'dossier'
    private $repo;      // 'mougeat/ispag-achats'
    private $pending_sha = ''; // SHA du ZIP en cours de téléchargement

    public static function is_configured() {
        return defined('ISPAG_GITHUB_TOKEN') && ISPAG_GITHUB_TOKEN !== ''
            && defined('ISPAG_UPDATE_BRANCH') && ISPAG_UPDATE_BRANCH !== '';
    }

    /** @param string $main_file __FILE__ du fichier principal du plugin */
    public static function plugin($main_file, $repo) {
        if (!self::is_configured()) return null;
        $basename = plugin_basename($main_file);
        return new self('plugin', dirname($basename), $basename, $repo);
    }

    /** @param string $stylesheet nom du dossier du thème (get_stylesheet()) */
    public static function theme($stylesheet, $repo) {
        if (!self::is_configured()) return null;
        return new self('theme', $stylesheet, $stylesheet, $repo);
    }

    private function __construct($type, $slug, $basename, $repo) {
        $this->type = $type; $this->slug = $slug; $this->basename = $basename; $this->repo = $repo;
        self::$instances[] = $this;

        if ($type === 'plugin') {
            add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
            add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
            add_filter('auto_update_plugin', [$this, 'maybe_auto_update'], 10, 2);
        } else {
            add_filter('pre_set_site_transient_update_themes', [$this, 'inject_update']);
            add_filter('auto_update_theme', [$this, 'maybe_auto_update'], 10, 2);
        }
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_filter('upgrader_pre_install', [$this, 'backup_preserved'], 10, 2);
        add_filter('upgrader_post_install', [$this, 'restore_preserved'], 10, 3);
        add_filter('http_request_args', [$this, 'authorize_download'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'note_package'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'remember_installed'], 10, 2);

        if (!self::$cron_ready) {
            self::$cron_ready = true;
            add_filter('cron_schedules', function ($s) {
                $s['ispag_15min'] = ['interval' => self::POLL_INTERVAL, 'display' => 'Toutes les 15 minutes (ISPAG)'];
                return $s;
            });
            add_action(self::CRON_HOOK, [self::class, 'poll']);
            add_action('init', function () {
                if (!wp_next_scheduled(self::CRON_HOOK)) {
                    wp_schedule_event(time() + 60, 'ispag_15min', self::CRON_HOOK);
                }
            });
        }
    }

    // ------------------------------------------------------------------ détection

    private function branch() { return (string) ISPAG_UPDATE_BRANCH; }
    private function sha_option() { return 'ispag_gh_sha_' . $this->slug; }
    private function installed_sha() { return (string) get_option($this->sha_option(), ''); }
    private function cache_key() { return 'ispag_gh_head_' . md5($this->repo . '|' . $this->branch()); }

    private function api_headers($accept = 'application/vnd.github+json') {
        return [
            'Authorization'        => 'Bearer ' . ISPAG_GITHUB_TOKEN,
            'Accept'               => $accept,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'ISPAG-Updater',
        ];
    }

    /** SHA du dernier commit de la branche (mémorisé 5 min ; 'force-check' de WP contourne le cache). */
    private function remote_sha($force = false) {
        if (!$force && !empty($_GET['force-check'])) $force = true;
        if (!$force) {
            $cached = get_transient($this->cache_key());
            if ($cached !== false) return $cached === 'ERR' ? '' : $cached;
        }
        $path = '/repos/' . $this->repo . '/commits/' . implode('/', array_map('rawurlencode', explode('/', $this->branch())));
        $res  = wp_remote_get('https://api.github.com' . $path, ['timeout' => 10, 'headers' => $this->api_headers('application/vnd.github.sha')]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $body = is_wp_error($res) ? '' : trim(wp_remote_retrieve_body($res));
        if ($code === 200 && preg_match('/^[0-9a-f]{40}$/', $body)) {
            set_transient($this->cache_key(), $body, self::CACHE_TTL);
            return $body;
        }
        error_log(sprintf('[ISPAG Updater] %s@%s : réponse GitHub inattendue (HTTP %s) %s', $this->repo, $this->branch(), $code,
            is_wp_error($res) ? $res->get_error_message() : ''));
        set_transient($this->cache_key(), 'ERR', self::CACHE_TTL); // évite de marteler l'API en cas d'erreur
        return '';
    }

    private function current_version() {
        if ($this->type === 'plugin') {
            if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $d = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->basename, false, false);
            return $d['Version'] ?: '0';
        }
        return wp_get_theme($this->slug)->get('Version') ?: '0';
    }

    public function has_update($force = false) {
        $sha = $this->remote_sha($force);
        return $sha !== '' && $sha !== $this->installed_sha();
    }

    public function inject_update($transient) {
        if (!is_object($transient)) return $transient;
        $sha = $this->remote_sha();
        if ($sha === '') return $transient; // GitHub injoignable : on ne touche à rien

        $version = $this->current_version();
        $item = [
            'slug'        => $this->slug,
            'new_version' => $version . '+' . substr($sha, 0, 7),
            'url'         => 'https://github.com/' . $this->repo . '/tree/' . $this->branch(),
            'package'     => 'https://api.github.com/repos/' . $this->repo . '/zipball/' . $sha,
        ];
        if ($this->type === 'plugin') {
            $item['plugin'] = $this->basename;
            $item = (object) $item;
        } else {
            $item['theme'] = $this->basename;
        }

        if ($sha !== $this->installed_sha()) {
            $transient->response[$this->basename] = $item;
            if (isset($transient->no_update[$this->basename])) unset($transient->no_update[$this->basename]);
        } else {
            if (isset($transient->response[$this->basename])) unset($transient->response[$this->basename]);
            $transient->no_update[$this->basename] = $item;
        }
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) return $result;
        $sha = $this->remote_sha();
        return (object) [
            'name'          => $this->slug,
            'slug'          => $this->slug,
            'version'       => $this->current_version() . '+' . substr($sha, 0, 7),
            'homepage'      => 'https://github.com/' . $this->repo,
            'download_link' => $sha ? 'https://api.github.com/repos/' . $this->repo . '/zipball/' . $sha : '',
            'sections'      => ['description' => 'Branche suivie : ' . esc_html($this->branch()) . ' (' . esc_html($this->repo) . ').'],
        ];
    }

    public function maybe_auto_update($update, $item) {
        $id = ($this->type === 'plugin') ? ($item->plugin ?? '') : ($item->theme ?? '');
        if ($id !== $this->basename) return $update;
        if (defined('ISPAG_GITHUB_AUTO_UPDATE') && !ISPAG_GITHUB_AUTO_UPDATE) return $update;
        return true;
    }

    // ------------------------------------------------------------------ cron

    /** Détecte un nouveau commit et déclenche le circuit normal de WordPress (qui installe si l'auto-update est actif). */
    public static function poll() {
        $needs = false;
        foreach (self::$instances as $i) {
            if ($i->has_update(true)) { $needs = true; break; }
        }
        if (!$needs) return;
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        if (function_exists('wp_update_plugins')) wp_update_plugins();
        if (function_exists('wp_update_themes'))  wp_update_themes();
        if (function_exists('wp_maybe_auto_update')) wp_maybe_auto_update();
    }

    // ------------------------------------------------------------------ installation

    /** Le ZIP GitHub contient un dossier « mougeat-repo-<sha> » : on le renomme au nom attendu. */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
        $target = ($this->type === 'plugin') ? ($hook_extra['plugin'] ?? null) : ($hook_extra['theme'] ?? null);
        if ($target !== $this->basename) return $source;

        global $wp_filesystem;
        $from = untrailingslashit($source);
        $to   = untrailingslashit($remote_source) . '/' . $this->slug;
        if ($from === $to) return $source;
        if (!$wp_filesystem || !$wp_filesystem->move($from, $to, true)) {
            return new WP_Error('ispag_gh_rename', 'ISPAG Updater : impossible de renommer le dossier téléchargé en ' . $this->slug);
        }
        return trailingslashit($to);
    }

    private function install_dir() {
        return ($this->type === 'plugin') ? WP_PLUGIN_DIR . '/' . $this->slug : get_theme_root($this->slug) . '/' . $this->slug;
    }
    private function backup_dir() {
        $u = wp_upload_dir();
        return untrailingslashit($u['basedir']) . '/ispag-updater-backup/' . $this->slug;
    }
    private function is_ours($hook_extra) {
        $t = ($this->type === 'plugin') ? ($hook_extra['plugin'] ?? null) : ($hook_extra['theme'] ?? null);
        return $t === $this->basename;
    }

    /** WordPress supprime le dossier avant d'installer : on met .env à l'abri. */
    public function backup_preserved($response, $hook_extra) {
        if (!$this->is_ours((array) $hook_extra)) return $response;
        $dst = $this->backup_dir();
        foreach (self::PRESERVE as $f) {
            $src = $this->install_dir() . '/' . $f;
            if (is_readable($src)) {
                if (!is_dir($dst)) wp_mkdir_p($dst);
                copy($src, $dst . '/' . $f);
            }
        }
        return $response;
    }

    public function restore_preserved($response, $hook_extra, $result) {
        if (!$this->is_ours((array) $hook_extra) || !is_array($result) || empty($result['destination'])) return $response;
        $src = $this->backup_dir();
        foreach (self::PRESERVE as $f) {
            if (is_readable($src . '/' . $f)) {
                copy($src . '/' . $f, untrailingslashit($result['destination']) . '/' . $f);
                @unlink($src . '/' . $f);
            }
        }
        return $response;
    }

    /** Ajoute le jeton uniquement pour le téléchargement du ZIP de CE dépôt. */
    public function authorize_download($args, $url) {
        if (strpos($url, 'https://api.github.com/repos/' . $this->repo . '/zipball/') === 0) {
            $args['headers'] = array_merge($args['headers'] ?? [], $this->api_headers());
        }
        return $args;
    }

    /** Relève le SHA épinglé dans l'URL du ZIP : c'est lui qui sera installé, même si la branche avance entre-temps. */
    public function note_package($reply, $package, $upgrader = null, $hook_extra = []) {
        $prefix = 'https://api.github.com/repos/' . $this->repo . '/zipball/';
        if (is_string($package) && strpos($package, $prefix) === 0) {
            $sha = substr($package, strlen($prefix));
            if (preg_match('/^[0-9a-f]{40}$/', $sha)) $this->pending_sha = $sha;
        }
        return $reply;
    }

    /** Mémorise le SHA réellement installé (celui du ZIP, épinglé dans l'URL du package). */
    public function remember_installed($upgrader, $options) {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== $this->type) return;
        $list = ($this->type === 'plugin') ? (array) ($options['plugins'] ?? []) : (array) ($options['themes'] ?? []);
        if (!in_array($this->basename, $list, true)) return;

        if ($this->pending_sha !== '') {
            update_option($this->sha_option(), $this->pending_sha, false);
            $this->pending_sha = '';
        }
        delete_transient($this->cache_key());
    }
}

}
