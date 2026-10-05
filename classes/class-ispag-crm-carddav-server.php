<?php
defined('ABSPATH') || exit;

/**
 * Carnet d'adresses CardDAV (lecture seule) servi directement par WordPress : plus besoin d'un serveur Baïkal.
 *
 * L'iPhone (Réglages → Contacts → Comptes → Autre → compte CardDAV) ou tout autre client se connecte à
 *     https://<votre-site>/carddav/            (serveur : le nom du site ; utilisateur : l'identifiant WordPress)
 * avec un MOT DE PASSE D'APPLICATION WordPress (Profil → Mots de passe d'application) : il se révoque à tout moment et
 * ne donne jamais accès au compte WordPress lui-même.
 * Contacts proposés (avec leur photo) : ceux des départements cochés (ISPAG Settings → Calendar sync), pour les utilisateurs ayant le droit view_contact.
 * Mise à jour : les modifications faites dans le CRM arrivent sur le téléphone ; l'inverse (modifier sur le téléphone) n'est pas pris en charge.
 * Photos : la photo du contact (version « moyenne » de la médiathèque) est ajoutée à la fiche au moment de l'envoi ; le carnet en cache ne contient
 * que son identifiant, jamais l'image (le cache reste léger).
 */
class ISPAG_Crm_Carddav_Server {

    const SLUG       = 'carddav';
    const BOOK       = 'ispag';
    const OPT_CACHE  = 'ispag_carddav_cache';
    const OPT_ON     = 'ispag_carddav_enabled';
    const CRON_HOOK  = 'ispag_carddav_rebuild';
    const TTL        = 1800;   // le carnet est reconstruit au plus toutes les 30 min (et par tâche planifiée)
    const NS_D = 'DAV:', NS_C = 'urn:ietf:params:xml:ns:carddav', NS_CS = 'http://calendarserver.org/ns/';

    public function __construct() {
        add_action('parse_request', [self::class, 'maybe_serve'], 1);
        add_action(self::CRON_HOOK, [self::class, 'rebuild']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }, 25);
        if (is_admin()) {
            add_action('admin_menu', [$this, 'menu'], 30);
            add_action('admin_post_ispag_carddav_save', [$this, 'handle_save']);
        }
    }

    public static function enabled(): bool { return (bool) get_option(self::OPT_ON, 1); }

    // ------------------------------------------------------------------ carnet (cartes + empreintes)

    /** Identifiants des contacts du périmètre (départements cochés). */
    private static function contact_ids(): array {
        global $wpdb;
        $depts = class_exists('ISPAG_Baikal_Settings') ? ISPAG_Baikal_Settings::selected_departments() : [];
        if (!$depts) return [];
        $table = $wpdb->prefix . 'ispag_contacts_owners';
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT contact_id FROM {$table} WHERE department_key IN (" . implode(',', array_fill(0, count($depts), '%s')) . ") AND status = 'active' ORDER BY contact_id",
            $depts
        )));
    }

    private static function esc($t): string {
        return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\;", "\\,", "\\n", "\\n", "\\n"], (string) $t);
    }

    /** vCard 3.0 d'un contact (sans photo, sans REV : le contenu doit rester identique tant que le contact ne change pas). */
    public static function vcard($c): string {
        $first = !empty($c->first_name) ? $c->first_name : get_user_meta($c->ID, 'first_name', true);
        $last  = !empty($c->last_name) ? $c->last_name : get_user_meta($c->ID, 'last_name', true);
        if (empty($first) && empty($last) && !empty($c->display_name)) {
            $parts = explode(' ', trim($c->display_name), 2);
            $first = $parts[0] ?? ''; $last = $parts[1] ?? '';
        }
        $name = !empty($c->display_name) ? $c->display_name : trim($first . ' ' . $last);
        $v  = "BEGIN:VCARD\r\nVERSION:3.0\r\n";
        $v .= 'UID:ispag-crm-contact-' . (int) $c->ID . "\r\n";
        $v .= 'N;CHARSET=UTF-8:' . self::esc($last) . ';' . self::esc($first) . ";;;\r\n";
        $v .= 'FN;CHARSET=UTF-8:' . self::esc($name) . "\r\n";
        if (!empty($c->company_name))  $v .= 'ORG;CHARSET=UTF-8:' . self::esc($c->company_name) . "\r\n";
        if (!empty($c->lead_function)) $v .= 'TITLE;CHARSET=UTF-8:' . self::esc($c->lead_function) . "\r\n";
        if (!empty($c->email))         $v .= 'EMAIL;TYPE=INTERNET,WORK:' . self::esc($c->email) . "\r\n";
        if (!empty($c->phone))         $v .= 'TEL;TYPE=CELL,VOICE:' . self::esc($c->phone) . "\r\n";
        return $v . "END:VCARD\r\n";
    }

    /** Fichier de la photo d'un contact (version moyenne si elle existe) : [chemin, type MIME, jeton de version] ou null. */
    private static function photo_file($contact_id): ?array {
        $att = (int) get_user_meta($contact_id, ISPAG_Crm_Contact_Constants::USER_AVATAR, true);
        if (!$att) return null;
        $path = '';
        $m = function_exists('image_get_intermediate_size') ? image_get_intermediate_size($att, 'medium') : false;
        if (is_array($m) && !empty($m['path'])) {
            $up = wp_get_upload_dir();
            $cand = trailingslashit($up['basedir']) . $m['path'];
            if (is_readable($cand)) $path = $cand;
        }
        if ($path === '') { $orig = get_attached_file($att); if ($orig && is_readable($orig)) $path = $orig; }
        if ($path === '') return null;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = ['jpg' => 'JPEG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF'][$ext] ?? '';
        if ($type === '') return null;
        return [$path, $type, $att . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path)];
    }

    /** Ligne PHOTO (base64 repliée à 75 caractères) ; '' si pas de photo. */
    private static function photo_line($contact_id): string {
        $f = self::photo_file($contact_id);
        if (!$f) return '';
        $data = @file_get_contents($f[0]);
        if ($data === false || $data === '') return '';
        $b64 = chunk_split(base64_encode($data), 74, "\r\n ");
        return 'PHOTO;ENCODING=b;TYPE=' . $f[1] . ':' . rtrim($b64) . "\r\n";
    }

    /** Fiche à servir : la vCard du cache + la photo (ajoutée avant END:VCARD). */
    private static function card_text(int $id, array $card): string {
        if (empty($card['photo'])) return $card['vcf'];
        $line = self::photo_line($id);
        return $line === '' ? $card['vcf'] : preg_replace('/END:VCARD\r\n$/', $line . "END:VCARD\r\n", $card['vcf']);
    }

    /** Reconstruit le carnet : [id => ['etag' => md5, 'vcf' => texte sans photo, 'photo' => 0|1]] + ctag global. */
    public static function rebuild(): array {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $repo  = new ISPAG_Crm_Contacts_Repository();
        $cards = [];
        foreach (self::contact_ids() as $id) {
            $c = $repo->get_contact_by_id($id);
            if (!$c) continue;
            $vcf   = self::vcard($c);
            $photo = self::photo_file($id);
            // l'empreinte porte sur le texte et sur la version de la photo (pas sur l'image elle-même)
            $cards[$id] = ['etag' => md5($vcf . '|' . ($photo ? $photo[2] : '')), 'vcf' => $vcf, 'photo' => $photo ? 1 : 0];
        }
        $cache = ['built' => time(), 'ctag' => md5(implode('|', array_map(function ($c) { return $c['etag']; }, $cards))), 'cards' => $cards];
        update_option(self::OPT_CACHE, $cache, false);
        return $cache;
    }

    private static function book(): array {
        $cache = get_option(self::OPT_CACHE, null);
        if (!is_array($cache) || empty($cache['built']) || time() - (int) $cache['built'] > self::TTL) $cache = self::rebuild();
        return $cache;
    }

    // ------------------------------------------------------------------ routage

    private static function base_path(): string {
        return rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    }

    private static function href(string $tail = ''): string {
        return self::base_path() . '/' . self::SLUG . '/' . ltrim($tail, '/');
    }

    public static function maybe_serve() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = self::base_path();
        $rel  = substr($path, strlen($base));
        if ($rel === '/.well-known/carddav' || $rel === '/.well-known/carddav/') {
            if (!self::enabled()) return;
            header('Location: ' . home_url('/' . self::SLUG . '/'), true, 301);
            exit;
        }
        if (strpos($rel . '/', '/' . self::SLUG . '/') !== 0) return;
        if (!self::enabled()) { status_header(404); exit; }
        self::handle(trim(substr($rel, strlen(self::SLUG) + 1), '/'));
    }

    private static function credentials(): array {
        $user = $_SERVER['PHP_AUTH_USER'] ?? ''; $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        if ($user === '') {
            $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (stripos($h, 'basic ') === 0) {
                $d = base64_decode(substr($h, 6)); if ($d !== false && strpos($d, ':') !== false) [$user, $pass] = explode(':', $d, 2);
            }
        }
        return [(string) $user, (string) $pass];
    }

    private static function deny($code = 401) {
        status_header($code);
        if ($code === 401) header('WWW-Authenticate: Basic realm="ISPAG contacts", charset="UTF-8"');
        header('Content-Type: text/plain; charset=utf-8');
        echo $code === 401 ? 'Authentication required' : 'Forbidden';
        exit;
    }

    private static function authenticate(): WP_User {
        [$login, $pass] = self::credentials();
        if ($login === '' || $pass === '') self::deny(401);
        $user = get_user_by('login', $login) ?: get_user_by('email', $login);
        if (!$user || !function_exists('wp_authenticate_application_password')) self::deny(401);
        // WordPress ne valide les mots de passe d'application que pour les requêtes « API » (REST / XML-RPC) : celle-ci en est une
        add_filter('application_password_is_api_request', '__return_true');
        $res = wp_authenticate_application_password(null, $user->user_login, $pass);
        remove_filter('application_password_is_api_request', '__return_true');
        if (!($res instanceof WP_User)) self::deny(401);
        if (!user_can($res, 'view_contact') && !user_can($res, 'manage_options')) self::deny(403);
        return $res;
    }

    private static function send_xml(int $code, string $xml) {
        status_header($code);
        nocache_headers();
        header('DAV: 1, 3, addressbook');
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n" . $xml;
        exit;
    }

    private static function handle(string $rel) {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        header('X-Robots-Tag: noindex, nofollow');
        if ($method === 'OPTIONS') {
            status_header(200);
            header('DAV: 1, 3, addressbook');
            header('Allow: OPTIONS, GET, HEAD, PROPFIND, REPORT');
            header('Content-Length: 0');
            exit;
        }
        $user = self::authenticate();
        $login = $user->user_login;
        $seg = $rel === '' ? [] : explode('/', $rel);
        $body = (string) file_get_contents('php://input');

        // Zone réservée à l'utilisateur connecté
        if (isset($seg[1]) && in_array($seg[0], ['principals', 'addressbooks'], true) && rawurldecode($seg[1]) !== $login) self::deny(403);

        if ($method === 'GET' || $method === 'HEAD') {
            if (($seg[0] ?? '') === 'addressbooks' && count($seg) === 4 && preg_match('/^contact-(\d+)\.vcf$/', $seg[3], $m) && ($seg[2] ?? '') === self::BOOK) {
                $book = self::book();
                $id = (int) $m[1];
                if (!isset($book['cards'][$id])) { status_header(404); exit; }
                $text = self::card_text($id, $book['cards'][$id]);
                status_header(200);
                header('Content-Type: text/vcard; charset=utf-8');
                header('ETag: "' . $book['cards'][$id]['etag'] . '"');
                header('Content-Length: ' . strlen($text));
                if ($method === 'GET') echo $text;
                exit;
            }
            status_header(200); header('Content-Type: text/plain; charset=utf-8');
            echo "ISPAG CardDAV: use a CardDAV client (iPhone: Settings > Contacts > Accounts > Other > Add CardDAV account).";
            exit;
        }
        if ($method === 'PROPFIND') {
            $depth = (isset($_SERVER['HTTP_DEPTH']) && $_SERVER['HTTP_DEPTH'] === '0') ? 0 : 1;
            self::send_xml(207, self::propfind($seg, $login, $depth, self::requested_props($body)));
        }
        if ($method === 'REPORT') {
            self::send_xml(207, self::report($seg, $login, $body));
        }
        // Lecture seule : PUT / DELETE / MKCOL / PROPPATCH… sont refusés
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Read-only address book';
        exit;
    }

    // ------------------------------------------------------------------ XML

    /** Noms (« ns|nom ») des propriétés demandées par un PROPFIND ; [] = toutes. */
    public static function requested_props(string $body): array {
        if (trim($body) === '') return [];
        $doc = new DOMDocument();
        if (!@$doc->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return [];
        if ($doc->getElementsByTagNameNS(self::NS_D, 'allprop')->length) return [];
        $props = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_D, 'prop') as $prop) {
            foreach ($prop->childNodes as $n) { if ($n instanceof DOMElement) $props[] = $n->namespaceURI . '|' . $n->localName; }
        }
        return $props;
    }

    private static function x($s): string { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    /** Une ressource : href + propriétés connues [ 'ns|nom' => fragment XML ]. */
    private static function response(string $href, array $known, array $wanted): string {
        $ok = $nf = '';
        $names = $wanted ?: array_keys($known);
        foreach ($names as $key) {
            [$ns, $name] = explode('|', $key, 2);
            if (isset($known[$key])) $ok .= $known[$key];
            else $nf .= '<x:' . self::x($name) . ' xmlns:x="' . self::x($ns) . '"/>';
        }
        $out = '<d:response><d:href>' . self::x($href) . '</d:href>';
        if ($ok !== '') $out .= '<d:propstat><d:prop>' . $ok . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';
        if ($nf !== '') $out .= '<d:propstat><d:prop>' . $nf . '</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>';
        return $out . '</d:response>';
    }

    private static function multistatus(string $inner): string {
        return '<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">' . $inner . '</d:multistatus>';
    }

    private static function privileges(): string {
        return '<d:current-user-privilege-set><d:privilege><d:read/></d:privilege></d:current-user-privilege-set>';
    }

    public static function propfind(array $seg, string $login, int $depth, array $wanted): string {
        $enc   = rawurlencode($login);
        $princ = self::href("principals/$enc/");
        $home  = self::href("addressbooks/$enc/");
        $bookH = self::href("addressbooks/$enc/" . self::BOOK . '/');
        $out   = '';
        $first = $seg[0] ?? '';

        $reportsetBook = '<d:supported-report-set>'
            . '<d:supported-report><d:report><card:addressbook-multiget/></d:report></d:supported-report>'
            . '<d:supported-report><d:report><card:addressbook-query/></d:report></d:supported-report></d:supported-report-set>';

        $principalProps = [
            'DAV:|resourcetype'                     => '<d:resourcetype><d:principal/></d:resourcetype>',
            'DAV:|displayname'                      => '<d:displayname>' . self::x($login) . '</d:displayname>',
            'DAV:|current-user-principal'           => '<d:current-user-principal><d:href>' . self::x($princ) . '</d:href></d:current-user-principal>',
            'DAV:|principal-URL'                    => '<d:principal-URL><d:href>' . self::x($princ) . '</d:href></d:principal-URL>',
            self::NS_C . '|addressbook-home-set'    => '<card:addressbook-home-set><d:href>' . self::x($home) . '</d:href></card:addressbook-home-set>',
        ];

        if ($first === '' || $first === 'principals') {
            // racine et principal : découverte (current-user-principal → addressbook-home-set)
            $out .= self::response($first === '' ? self::href() : $princ, $first === '' ? [
                'DAV:|resourcetype'           => '<d:resourcetype><d:collection/></d:resourcetype>',
                'DAV:|current-user-principal' => $principalProps['DAV:|current-user-principal'],
                'DAV:|principal-URL'          => $principalProps['DAV:|principal-URL'],
                'DAV:|displayname'            => '<d:displayname>ISPAG</d:displayname>',
                self::NS_C . '|addressbook-home-set' => $principalProps[self::NS_C . '|addressbook-home-set'],
            ] : $principalProps, $wanted);
            return self::multistatus($out);
        }

        $homeProps = [
            'DAV:|resourcetype'                  => '<d:resourcetype><d:collection/></d:resourcetype>',
            'DAV:|displayname'                   => '<d:displayname>' . self::x($login) . '</d:displayname>',
            'DAV:|current-user-principal'        => $principalProps['DAV:|current-user-principal'],
            'DAV:|current-user-privilege-set'    => self::privileges(),
            'DAV:|owner'                         => '<d:owner><d:href>' . self::x($princ) . '</d:href></d:owner>',
        ];
        $book = self::book();
        $bookProps = [
            'DAV:|resourcetype'                  => '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>',
            'DAV:|displayname'                   => '<d:displayname>ISPAG</d:displayname>',
            self::NS_CS . '|getctag'             => '<cs:getctag>' . self::x($book['ctag']) . '</cs:getctag>',
            'DAV:|getetag'                       => '<d:getetag>"' . self::x($book['ctag']) . '"</d:getetag>',
            'DAV:|supported-report-set'          => $reportsetBook,
            'DAV:|current-user-privilege-set'    => self::privileges(),
            'DAV:|current-user-principal'        => $principalProps['DAV:|current-user-principal'],
            'DAV:|owner'                         => '<d:owner><d:href>' . self::x($princ) . '</d:href></d:owner>',
            self::NS_C . '|supported-address-data' => '<card:supported-address-data><card:address-data-type content-type="text/vcard" version="3.0"/></card:supported-address-data>',
        ];

        $segCount = count($seg);
        if ($segCount <= 2) {   // addressbooks/<login>/
            $out .= self::response($home, $homeProps, $wanted);
            if ($depth > 0) $out .= self::response($bookH, $bookProps, $wanted);
        } elseif ($segCount === 3 && $seg[2] === self::BOOK) {   // carnet
            $out .= self::response($bookH, $bookProps, $wanted);
            if ($depth > 0) foreach ($book['cards'] as $id => $card) $out .= self::card_response($bookH, $id, $card, $wanted, false);
        } elseif ($segCount === 4 && preg_match('/^contact-(\d+)\.vcf$/', $seg[3], $m) && isset($book['cards'][(int) $m[1]])) {
            $out .= self::card_response($bookH, (int) $m[1], $book['cards'][(int) $m[1]], $wanted, false);
        } else {
            status_header(404);
            return '<d:error xmlns:d="DAV:"><d:resource-must-be-null/></d:error>';
        }
        return self::multistatus($out);
    }

    private static function card_response(string $bookHref, int $id, array $card, array $wanted, bool $withData): string {
        $known = [
            'DAV:|getetag'        => '<d:getetag>"' . self::x($card['etag']) . '"</d:getetag>',
            'DAV:|getcontenttype' => '<d:getcontenttype>text/vcard; charset=utf-8</d:getcontenttype>',
            'DAV:|resourcetype'   => '<d:resourcetype/>',
            'DAV:|current-user-privilege-set' => self::privileges(),
        ];
        if ($withData) $known[self::NS_C . '|address-data'] = '<card:address-data>' . self::x(self::card_text($id, $card)) . '</card:address-data>';
        return self::response($bookHref . 'contact-' . $id . '.vcf', $known, $wanted);
    }

    public static function report(array $seg, string $login, string $body): string {
        $book = self::book();
        $enc  = rawurlencode($login);
        $bookH = self::href("addressbooks/$enc/" . self::BOOK . '/');
        $doc = new DOMDocument();
        if (!@$doc->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) { status_header(400); return '<d:error xmlns:d="DAV:"/>'; }
        $root = $doc->documentElement;
        $wanted = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_D, 'prop') as $prop) {
            foreach ($prop->childNodes as $n) { if ($n instanceof DOMElement) $wanted[] = $n->namespaceURI . '|' . $n->localName; }
        }
        $out = '';
        if ($root && $root->localName === 'addressbook-multiget') {
            foreach ($doc->getElementsByTagNameNS(self::NS_D, 'href') as $h) {
                $p = rawurldecode((string) wp_parse_url(trim($h->textContent), PHP_URL_PATH));
                if (preg_match('#/contact-(\d+)\.vcf$#', $p, $m) && isset($book['cards'][(int) $m[1]])) {
                    $out .= self::card_response($bookH, (int) $m[1], $book['cards'][(int) $m[1]], $wanted, true);
                } else {
                    $out .= '<d:response><d:href>' . self::x($h->textContent) . '</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>';
                }
            }
        } else {   // addressbook-query : tout le carnet (le filtre éventuel est ignoré, il est petit)
            foreach ($book['cards'] as $id => $card) $out .= self::card_response($bookH, $id, $card, $wanted, true);
        }
        return self::multistatus($out);
    }

    // ------------------------------------------------------------------ administration

    public function menu() {
        $title = __('iPhone contacts (CardDAV)', 'ispag-crm');
        if (!empty($GLOBALS['admin_page_hooks']['ispag-settings'])) {
            add_submenu_page('ispag-settings', $title, $title, 'manage_options', 'ispag-carddav', [$this, 'page']);
        } else {
            add_options_page($title, $title, 'manage_options', 'ispag-carddav', [$this, 'page']);
        }
    }

    public function page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'ispag-crm'));
        $cache = get_option(self::OPT_CACHE, []);
        $count = is_array($cache) ? count($cache['cards'] ?? []) : 0;
        $host  = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $profile = admin_url('profile.php#application-passwords-section');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('iPhone contacts (CardDAV)', 'ispag-crm'); ?></h1>
            <p><?php esc_html_e('The CRM contacts of the selected departments are offered by this website as a CardDAV address book: no separate server is needed. It is read-only: changes made in the CRM arrive on the phone, not the other way round.', 'ispag-crm'); ?></p>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'ispag-crm'); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ispag_carddav_save'); ?>
                <input type="hidden" name="action" value="ispag_carddav_save">
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Address book', 'ispag-crm'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Enabled', 'ispag-crm'); ?></label>
                        <p class="description"><?php echo esc_html(sprintf(__('%d contacts are offered (departments checked in ISPAG Settings → Calendar sync).', 'ispag-crm'), $count)); ?></p></td></tr>
                </table>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Save', 'ispag-crm'); ?></button>
                   <button class="button" type="submit" name="rebuild" value="1"><?php esc_html_e('Rebuild the address book now', 'ispag-crm'); ?></button></p>
            </form>
            <h2><?php esc_html_e('Add the account on the iPhone', 'ispag-crm'); ?></h2>
            <ol>
                <li><?php printf(wp_kses(__('Each person creates an <a href="%s">application password</a> in his WordPress profile (name it « iPhone »). It is shown only once.', 'ispag-crm'), ['a' => ['href' => []]]), esc_url($profile)); ?></li>
                <li><?php esc_html_e('iPhone: Settings → Contacts → Accounts → Add account → Other → Add CardDAV account.', 'ispag-crm'); ?></li>
                <li><?php esc_html_e('Server:', 'ispag-crm'); ?> <code><?php echo esc_html($host); ?></code> &nbsp; <?php esc_html_e('User: your WordPress login. Password: the application password.', 'ispag-crm'); ?></li>
                <li><?php esc_html_e('If the account is refused, use the full address as server:', 'ispag-crm'); ?> <code><?php echo esc_html(home_url('/' . self::SLUG . '/')); ?></code></li>
            </ol>
            <p><?php esc_html_e('To stop a phone: delete its application password in the WordPress profile.', 'ispag-crm'); ?></p>
        </div>
        <?php
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'ispag-crm'));
        check_admin_referer('ispag_carddav_save');
        update_option(self::OPT_ON, empty($_POST['enabled']) ? 0 : 1);
        if (!empty($_POST['rebuild'])) self::rebuild();
        wp_safe_redirect(add_query_arg('saved', 1, wp_get_referer() ?: admin_url('admin.php?page=ispag-carddav')));
        exit;
    }
}
