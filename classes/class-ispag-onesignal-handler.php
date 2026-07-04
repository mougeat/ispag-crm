<?php

class ISPAG_OneSignal_Handler {

    /**
     * Log les événements OneSignal dans wp-content/log_one_signal.log
     */
    private static function log_event($message, $data = null) {
        $log_file = WP_CONTENT_DIR . '/log_one_signal.log';
        $timestamp = date("Y-m-d H:i:s");
        $entry = "[$timestamp] $message";
        if ($data) {
            $entry .= " | Data: " . (is_array($data) || is_object($data) ? json_encode($data) : $data);
        }
        // file_put_contents($log_file, $entry . PHP_EOL, FILE_APPEND);
    }

    /**
     * Charge le SDK OneSignal et initialise le lien avec l'ID WordPress
     */
    public static function enqueue_scripts() {
        $app_id = defined('CRM_ONE_SIGNAL_APP_ID') ? CRM_ONE_SIGNAL_APP_ID : getenv('CRM_ONE_SIGNAL_APP_ID');
        if (empty($app_id)) return;

        $current_user_id = get_current_user_id();
        $wp_id = (string)$current_user_id;

        add_action('wp_head', function() use ($app_id, $wp_id) {
            ?>
            <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
            <script>
                window.OneSignalDeferred = window.OneSignalDeferred || [];
                OneSignalDeferred.push(async function(OneSignal) {
                    // 1. Initialisation avec tes nouveaux paramètres
                    await OneSignal.init({
                        appId: "<?php echo esc_js($app_id); ?>",
                        safari_web_id: "web.onesignal.auto.4dbe0dd2-36c1-4474-980b-740086f7dd0e",
                        notifyButton: {
                            enable: true, // Affiche la petite cloche OneSignal
                        },
                        // On force le chemin vers la racine pour éviter le bug 404 de tout à l'heure
                        serviceWorkerPath: 'OneSignalSDKWorker.js',
                        serviceWorkerParam: { scope: '/' }
                    });

                    // 2. Lien avec ton utilisateur WordPress
                    if ("<?php echo $wp_id; ?>" !== "0") {
                        console.log('🔗 ISPAG CRM : Tentative de login pour ID: <?php echo $wp_id; ?>');
                        await OneSignal.login("WP_<?php echo $wp_id; ?>");
                        console.log('✅ ISPAG CRM : Login réussi.');
                    }
                });
            </script>
            <?php
        }, 99);
    }

    /**
     * Envoi de la notification Push via API OneSignal
     */
    public static function send_os_push_notification($user_id, $title, $content, $typ = 'contact', $id = null) {
        $app_id  = defined('CRM_ONE_SIGNAL_APP_ID') ? CRM_ONE_SIGNAL_APP_ID : getenv('CRM_ONE_SIGNAL_APP_ID');
        $api_key = defined('CRM_ONE_SIGNAL_API_KEY') ? CRM_ONE_SIGNAL_API_KEY : getenv('CRM_ONE_SIGNAL_API_KEY');

        if (empty($app_id) || empty($api_key)) {
            self::log_event("Erreur API : Credentials manquants.");
            return false;
        }

        // Nettoyage du contenu
        $clean_content = wp_strip_all_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
        $clean_content = mb_strimwidth($clean_content, 0, 150, "...");

        // Payload pour l'API OneSignal
        $fields = array(
            'app_id' => $app_id,
            // C'est ici qu'on cible l'ID WordPress que OneSignal connaît grâce au .login()
            'include_external_user_ids' => array((string)$user_id), 
            'headings' => array("fr" => $title, "en" => $title),
            'contents' => array("fr" => $clean_content, "en" => $clean_content),
            'chrome_web_icon' => "https://app.ispag-asp.ch/wp-content/uploads/2025/03/Logo_RGB.png",
            'url' => get_home_url() . "/".$typ."/".$id,
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $api_key
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::log_event("Envoi Push ID $user_id | Code: $http_code", $response);

        return $response;
    }
}