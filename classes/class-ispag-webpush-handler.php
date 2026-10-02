<?php
defined('ABSPATH') || exit;

/**
 * Notifications push du navigateur (Web Push), sans service externe.
 *
 * Remplace OneSignal : le navigateur s'abonne auprès de son propre service de push (FCM, Mozilla, Apple)
 * et ce plugin envoie directement la notification chiffrée (RFC 8291) et signée (VAPID, RFC 8292).
 * Aucune bibliothèque tierce : seules les extensions PHP openssl et hash sont nécessaires.
 *
 * - Les abonnements (un par navigateur/appareil) sont stockés dans {prefix}ispag_push_subscriptions.
 * - Les clés VAPID sont créées au premier usage et conservées dans l'option ispag_push_vapid.
 *   Les perdre ou les changer oblige chaque utilisateur à se réabonner.
 * - Le service worker est servi par WordPress à l'adresse /ispag-push-sw.js (portée « / »).
 */
class ISPAG_WebPush_Handler {

    const OPTION_KEYS  = 'ispag_push_vapid';
    const SW_FILENAME  = 'ispag-push-sw.js';
    const QUERY_PARAM  = 'ispag_notif';
    const MAX_PAYLOAD  = 3800;

    public function __construct() {
        self::init();
    }

    public static function init() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        add_action('init', [__CLASS__, 'maybe_serve_service_worker'], 0);
        add_action('init', [__CLASS__, 'mark_read_from_click'], 20);
        add_action('wp_ajax_ispag_push_subscribe', [__CLASS__, 'ajax_subscribe']);
        add_action('wp_ajax_ispag_push_unsubscribe', [__CLASS__, 'ajax_unsubscribe']);
    }

    // =========================================================================
    // Journal
    // =========================================================================

    private static function log_event($message, $data = null) {
        if (!class_exists('ISPAG_Logger')) {
            return;
        }
        $log_data = $data === null ? [] : (array) $data;
        ISPAG_Logger::get_instance()->log_user_action('webpush', $message, $log_data, get_current_user_id());
    }

    // =========================================================================
    // Base64 URL
    // =========================================================================

    public static function b64url_encode($bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64url_decode($str) {
        return base64_decode(strtr($str, '-_', '+/'));
    }

    // =========================================================================
    // Clés VAPID
    // =========================================================================

    /**
     * @return array{private_pem:string, public:string}|null public = point non compressé de 65 octets, en base64url
     */
    public static function get_vapid_keys() {
        $keys = get_option(self::OPTION_KEYS);
        if (is_array($keys) && !empty($keys['private_pem']) && !empty($keys['public'])) {
            return $keys;
        }

        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$res || !openssl_pkey_export($res, $pem)) {
            self::log_event('Erreur : génération des clés VAPID impossible', ['openssl' => openssl_error_string()]);
            return null;
        }
        $details = openssl_pkey_get_details($res);
        $public  = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $keys = ['private_pem' => $pem, 'public' => self::b64url_encode($public)];
        update_option(self::OPTION_KEYS, $keys, false);
        return $keys;
    }

    public static function get_public_key() {
        $keys = self::get_vapid_keys();
        return $keys ? $keys['public'] : '';
    }

    /** Le serveur peut-il envoyer des push ? (openssl + courbe P-256 + GCM + dérivation de clé) */
    public static function is_supported() {
        return function_exists('openssl_pkey_derive')
            && function_exists('hash_hkdf')
            && in_array('prime256v1', openssl_get_curve_names() ?: [], true)
            && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    // =========================================================================
    // Chiffrement (RFC 8291, aes128gcm) et signature (RFC 8292)
    // =========================================================================

    private static function der_len($len) {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** Clé publique P-256 (65 octets) -> PEM SubjectPublicKeyInfo */
    private static function public_pem($point) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Clé privée brute (32 octets) + clé publique (65 octets) -> PEM ECPrivateKey */
    private static function private_pem($d, $point) {
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Chiffre un message pour un abonnement.
     *
     * @param string      $payload      texte à chiffrer
     * @param string      $ua_public    clé p256dh de l'abonnement (base64url)
     * @param string      $auth_secret  clé auth de l'abonnement (base64url)
     * @param string|null $as_private   (tests) clé privée éphémère brute, 32 octets
     * @param string|null $salt         (tests) sel, 16 octets
     * @param string|null $as_public    (tests) clé publique éphémère, 65 octets
     * @return string|false corps de la requête (en-tête aes128gcm + données chiffrées)
     */
    public static function encrypt($payload, $ua_public, $auth_secret, $as_private = null, $salt = null, $as_public = null) {
        $ua_public   = self::b64url_decode($ua_public);
        $auth_secret = self::b64url_decode($auth_secret);
        if (strlen($ua_public) !== 65 || $ua_public[0] !== "\x04" || strlen($auth_secret) < 16) {
            return false;
        }

        if ($as_private === null) {
            $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if (!$res) {
                return false;
            }
            $details    = openssl_pkey_get_details($res);
            $as_private = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
            $as_public  = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $private_key = $res;
        } else {
            // Clé fournie (tests) : la clé publique correspondante doit l'être aussi
            if ($as_public === null) {
                return false;
            }
            $private_key = openssl_pkey_get_private(self::private_pem($as_private, $as_public));
            if (!$private_key) {
                return false;
            }
        }

        $peer = openssl_pkey_get_public(self::public_pem($ua_public));
        if (!$peer) {
            return false;
        }
        $ecdh = openssl_pkey_derive($peer, $private_key, 32);
        if ($ecdh === false) {
            return false;
        }

        $salt = $salt ?? random_bytes(16);
        $ikm  = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua_public . $as_public, $auth_secret);
        $cek  = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Un seul enregistrement : données + délimiteur 0x02 (dernier enregistrement)
        $tag    = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            return false;
        }

        return $salt . pack('N', 4096) . chr(strlen($as_public)) . $as_public . $cipher . $tag;
    }

    /** Signature ES256 : le DER d'OpenSSL est converti en R||S (64 octets) comme l'exige JWT. */
    private static function der_to_raw_signature($der) {
        $offset = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
        $parts  = [];
        for ($i = 0; $i < 2; $i++) {
            $len   = ord($der[$offset + 1]);
            $int   = substr($der, $offset + 2, $len);
            $parts[] = str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $parts[0] . $parts[1];
    }

    /** En-tête Authorization VAPID pour le service de push de l'abonnement. */
    private static function vapid_authorization($endpoint, $keys) {
        $parts = wp_parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $subject = apply_filters('ispag_push_vapid_subject', 'mailto:' . get_option('admin_email'));
        $header  = self::b64url_encode(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims  = self::b64url_encode(wp_json_encode(['aud' => $audience, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => $subject]));

        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, $keys['private_pem'], OPENSSL_ALGO_SHA256)) {
            return false;
        }
        $jwt = $header . '.' . $claims . '.' . self::b64url_encode(self::der_to_raw_signature($signature));

        return 'vapid t=' . $jwt . ', k=' . $keys['public'];
    }

    // =========================================================================
    // Abonnements
    // =========================================================================

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ispag_push_subscriptions';
    }

    public static function ajax_subscribe() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'User not logged in.'], 401);
        }

        $endpoint = isset($_POST['endpoint']) ? esc_url_raw(wp_unslash($_POST['endpoint'])) : '';
        $p256dh   = isset($_POST['p256dh']) ? sanitize_text_field(wp_unslash($_POST['p256dh'])) : '';
        $auth     = isset($_POST['auth']) ? sanitize_text_field(wp_unslash($_POST['auth'])) : '';

        // Le service de push doit être en https ; les clés doivent avoir la bonne longueur
        if (strpos($endpoint, 'https://') !== 0 || strlen(self::b64url_decode($p256dh)) !== 65 || strlen(self::b64url_decode($auth)) < 16) {
            wp_send_json_error(['message' => 'Invalid subscription.'], 400);
        }

        global $wpdb;
        $hash = hash('sha256', $endpoint);
        $now  = current_time('mysql');

        // Un même navigateur peut changer d'utilisateur : l'abonnement suit le dernier compte connecté
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, created_at, last_used_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh),
                                     auth = VALUES(auth), user_agent = VALUES(user_agent)",
            $user_id, $endpoint, $hash, $p256dh, $auth,
            isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
            $now, $now
        ));

        self::log_event('Abonnement enregistré', ['user_id' => $user_id]);
        wp_send_json_success();
    }

    public static function ajax_unsubscribe() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $user_id  = get_current_user_id();
        $endpoint = isset($_POST['endpoint']) ? esc_url_raw(wp_unslash($_POST['endpoint'])) : '';
        if ($user_id && $endpoint) {
            global $wpdb;
            $wpdb->delete(self::table(), ['endpoint_hash' => hash('sha256', $endpoint), 'user_id' => $user_id], ['%s', '%d']);
        }
        wp_send_json_success();
    }

    // =========================================================================
    // Envoi
    // =========================================================================

    /**
     * Envoie une notification push à tous les appareils d'un utilisateur.
     *
     * @param int         $user_id
     * @param string      $title
     * @param string      $content
     * @param string      $url_or_type  URL complète, ou chemin relatif au site
     * @param int|null    $id           identifiant d'entité (ajouté au chemin relatif)
     * @param int|null    $notification_id  ligne de la cloche CRM : la notification sera marquée lue au clic
     * @return int nombre d'appareils atteints
     */
    public static function send_push_notification($user_id, $title, $content, $url_or_type = '', $id = null, $notification_id = null) {
        global $wpdb;

        if (!self::is_supported()) {
            self::log_event('Erreur : openssl ne gère pas Web Push sur ce serveur');
            return 0;
        }
        $keys = self::get_vapid_keys();
        if (!$keys) {
            return 0;
        }

        $user_id = (int) preg_replace('/^WP_/', '', (string) $user_id);
        $subs = $wpdb->get_results($wpdb->prepare(
            'SELECT id, endpoint, p256dh, auth FROM ' . self::table() . ' WHERE user_id = %d', $user_id
        ));
        if (!$subs) {
            return 0;
        }

        $clean_content = wp_strip_all_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
        $clean_content = mb_strimwidth($clean_content, 0, 150, '...');

        $target = self::build_url($url_or_type, $id);
        if ($notification_id) {
            $target = add_query_arg(self::QUERY_PARAM, (int) $notification_id, $target);
        }

        $payload = wp_json_encode([
            'title'     => wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8')),
            'body'      => $clean_content,
            'url'       => $target,
            'icon'      => apply_filters('ispag_push_icon', home_url('/wp-content/uploads/2025/03/favicon.png')),
            'tag'       => $notification_id ? 'ispag-' . (int) $notification_id : null,
            'entity_id' => $id,
        ]);
        if (strlen($payload) > self::MAX_PAYLOAD) {
            return 0;
        }

        $sent = 0;
        foreach ($subs as $sub) {
            $body = self::encrypt($payload, $sub->p256dh, $sub->auth);
            $auth = self::vapid_authorization($sub->endpoint, $keys);
            if ($body === false || $auth === false) {
                self::log_event('Erreur : chiffrement impossible', ['subscription_id' => $sub->id]);
                continue;
            }

            $response = wp_remote_post($sub->endpoint, [
                'timeout'   => 8,
                'body'      => $body,
                'headers'   => [
                    'Authorization'    => $auth,
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type'     => 'application/octet-stream',
                    'TTL'              => (string) DAY_IN_SECONDS,
                    'Urgency'          => 'normal',
                ],
            ]);

            if (is_wp_error($response)) {
                self::log_event('Erreur réseau', ['subscription_id' => $sub->id, 'error' => $response->get_error_message()]);
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $sent++;
                $wpdb->update(self::table(), ['last_used_at' => current_time('mysql')], ['id' => $sub->id]);
            } elseif ($code === 404 || $code === 410) {
                // Abonnement expiré ou révoqué par l'utilisateur
                $wpdb->delete(self::table(), ['id' => $sub->id], ['%d']);
                self::log_event('Abonnement expiré supprimé', ['subscription_id' => $sub->id, 'http' => $code]);
            } else {
                self::log_event('Refus du service de push', ['subscription_id' => $sub->id, 'http' => $code, 'body' => wp_remote_retrieve_body($response)]);
            }
        }

        return $sent;
    }

    private static function build_url($url_or_type, $id) {
        if (empty($url_or_type)) {
            return get_home_url();
        }
        if (strpos($url_or_type, 'http') === 0) {
            $final = $url_or_type;
        } else {
            $clean_path = trim($url_or_type, '/');
            $base       = trailingslashit(get_home_url());
            // On évite d'ajouter l'ID s'il est déjà présent à la fin du chemin
            $final = (!empty($id) && !str_ends_with($clean_path, (string) $id))
                ? $base . $clean_path . '/' . $id
                : $base . $clean_path;
        }
        // Nettoyage du doublon / slash dans les paramètres de type deal_id=XXX/XXX
        return preg_replace('/(=[^\/]+)\/[^\/]+/', '$1', $final);
    }

    // =========================================================================
    // Service worker et lecture au clic
    // =========================================================================

    /** Sert le service worker depuis le plugin, avec la portée « / ». */
    public static function maybe_serve_service_worker() {
        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        if (!$path || basename($path) !== self::SW_FILENAME) {
            return;
        }
        $file = dirname(__DIR__) . '/assets/js/' . self::SW_FILENAME;
        if (!is_readable($file)) {
            return;
        }
        status_header(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . self::scope());
        header('Cache-Control: no-cache, max-age=0');
        readfile($file);
        exit;
    }

    public static function service_worker_url() {
        return home_url('/' . self::SW_FILENAME);
    }

    public static function scope() {
        $path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        return $path ?: '/';
    }

    /** Ouverture d'un lien suivi (push, mail, Telegram, cloche) : la ligne correspondante de la cloche passe en « lue ». */
    public static function mark_read_from_click() {
        if (empty($_GET[self::QUERY_PARAM]) || !is_user_logged_in()) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ispag_notifications SET is_read = 1, read_at = %s WHERE id = %d AND user_id = %d AND is_read = 0",
            current_time('mysql'), (int) $_GET[self::QUERY_PARAM], get_current_user_id()
        ));
    }
}
