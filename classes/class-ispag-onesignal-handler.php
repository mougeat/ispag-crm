<?php

class ISPAG_OneSignal_Handler {

    /**
     * Log les événements OneSignal via la classe centrale ISPAG_Logger
     */
    private static function log_event($message, $data = null) {
        $logger = ISPAG_Logger::get_instance();
        
        $log_data = [];
        if ($data) {
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                $log_data = !empty($decoded) ? $decoded : ['raw_response' => $data];
            } else {
                $log_data = (array)$data;
            }
        }

        $logger->log_user_action('onesignal', $message, $log_data, get_current_user_id());
    }

    /**
     * Charge le SDK OneSignal et initialise le lien avec l'ID WordPress
     */
    public static function enqueue_scripts() {
        $app_id = defined('CRM_ONE_SIGNAL_APP_ID') ? CRM_ONE_SIGNAL_APP_ID : getenv('CRM_ONE_SIGNAL_APP_ID');
        if (empty($app_id)) {
            self::log_event("JS Init Error : APP ID non défini dans WP_head.");
            return;
        }

        $current_user_id = get_current_user_id();
        $wp_id = (string)$current_user_id;

        add_action('wp_head', function() use ($app_id, $wp_id) {
            ?>
            <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
            <script>
                window.OneSignalDeferred = window.OneSignalDeferred || [];
                OneSignalDeferred.push(async function(OneSignal) {
                    // 1. Initialisation avec les paramètres
                    await OneSignal.init({
                        appId: "<?php echo esc_js($app_id); ?>",
                        safari_web_id: "web.onesignal.auto.4dbe0dd2-36c1-4474-980b-740086f7dd0e",
                        notifyButton: {
                            enable: false, // Désactive la cloche
                        }, 
                        serviceWorkerPath: 'OneSignalSDKWorker.js',
                        serviceWorkerParam: { scope: '/' },
                        autoRegister: false
                    });

                    // 2. Lien avec l'utilisateur WordPress
                    if ("<?php echo $wp_id; ?>" !== "0") {
                        // console.log('🔗 ISPAG CRM : Tentative de login pour ID: <?php echo $wp_id; ?>');
                        await OneSignal.login("WP_<?php echo $wp_id; ?>");
                        // console.log('✅ ISPAG CRM : Login réussi.');

                        // 3. Demander les permissions de notification
                        // console.log('🔔 ISPAG CRM : Demande de permission pour les notifications.');
                        await OneSignal.Notifications.requestPermission();

                        // 4. (Optionnel) Demander la permission de géolocalisation
                        // Décommentez si vous utilisez la géolocalisation
                        // await OneSignal.Location.requestPermission();
                    }

                    // 5. Écoute des événements de notification
                    OneSignal.Notifications.addEventListener('click', function(event) {
                        // console.log('Notification cliquée:', event);
                        markNotificationAsRead(event.notification.id);
                    });

                    // Seul le clic marque la notification comme lue (pas son affichage)

                    // Fonction pour marquer comme lue en AJAX
                    function markNotificationAsRead(onesignalNotificationId) {
                        jQuery.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ispag_mark_notification_as_read',
                                onesignal_notification_id: onesignalNotificationId,
                                _ajax_nonce: ispag_ajax_obj.nonce
                            },
                            success: function(response) {
                                // console.log('Notification marquée comme lue:', response);
                            }
                        });
                    }
                });
            </script>
            <?php
        }, 99);
    }

    // Dans votre classe ISPAG_OneSignal_Handler
    public static function send_welcome_notification($user_id) {
        $title = "Welcome to ISPAG!";
        $content = "Thank you for subscribing to our notifications. You will now receive our latest news.";
        $url = home_url();

        return self::send_os_push_notification($user_id, $title, $content, $url);
    }

    /**
     * Envoi de la notification Push via API OneSignal (délégué par le Manager)
     */
    public static function send_os_push_notification($user_id, $title, $content, $url_or_type = '', $id = null) {
        self::log_event("Appel send_os_push_notification lancé", [
            'target_user_id' => $user_id,
            'title'          => $title,
            'content_raw'    => $content,
            'url_or_type'    => $url_or_type,
            'entity_id'      => $id
        ]);

        $app_id  = defined('CRM_ONE_SIGNAL_APP_ID') ? CRM_ONE_SIGNAL_APP_ID : getenv('CRM_ONE_SIGNAL_APP_ID');
        $api_key = defined('CRM_ONE_SIGNAL_API_KEY') ? CRM_ONE_SIGNAL_API_KEY : getenv('CRM_ONE_SIGNAL_API_KEY');

        if (empty($app_id) || empty($api_key)) {
            self::log_event("Error API : Credentials manquants.");
            return false;
        }

        // Nettoyage du contenu
        $clean_content = wp_strip_all_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
        $clean_content = mb_strimwidth($clean_content, 0, 150, "...");

        // Détermination et nettoyage de l'URL de base
        if (!empty($url_or_type)) {
            if (strpos($url_or_type, 'http') === 0) {
                $final_url = $url_or_type;
            } else {
                // Nettoyage des slashes superflus au début ou à la fin
                $clean_path = trim($url_or_type, '/');
                $base = trailingslashit(get_home_url());
                
                // On évite d'ajouter l'ID s'il est déjà présent à la fin du chemin
                if (!empty($id) && !str_ends_with($clean_path, (string)$id)) {
                    $final_url = $base . $clean_path . '/' . $id;
                } else {
                    $final_url = $base . $clean_path;
                }
            }
        } else {
            $final_url = get_home_url();
        }

        // Nettoyage du doublon / slash dans les paramètres de type deal_id=XXX/XXX
        $final_url = preg_replace('/(=[^\/]+)\/[^\/]+/', '$1', $final_url);

        // Payload temporaire
        $external_user_id = (strpos((string)$user_id, 'WP_') === 0) ? $user_id : 'WP_' . $user_id;

        $fields = array(
            'app_id' => $app_id,
            'include_external_user_ids' => array($external_user_id),
            'headings' => array("fr" => $title, "en" => $title),
            'contents' => array("fr" => $clean_content, "en" => $clean_content),
            'chrome_web_icon' => home_url() . "/wp-content/uploads/2025/03/favicon.png",
            'url' => $final_url,
            'data' => array(
                'custom_data' => 'ispag_notification',
                'entity_id' => $id
            )
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

        $response_data = json_decode($response, true);
        self::log_event("Retour API OneSignal reçu | Code HTTP: $http_code", $response_data);

        if (isset($response_data['id'])) {
            $onesignal_id = $response_data['id'];

            $separator = (parse_url($final_url, PHP_URL_QUERY) === null) ? '?' : '&';
            $final_url_with_param = $final_url . $separator . 'onesignal_id=' . $onesignal_id;

            // Mise à jour de l'URL avec l'ID OneSignal
            self::update_notification_url($app_id, $api_key, $onesignal_id, $final_url_with_param);

            return $onesignal_id;
        }

        return false;
    }

    /**
     * Méthode utilitaire pour mettre à jour l'URL d'une notification OneSignal existante
     */
    private static function update_notification_url($app_id, $api_key, $onesignal_id, $new_url) {
        $url = "https://onesignal.com/api/v1/apps/{$app_id}/notifications/{$onesignal_id}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $api_key
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('url' => $new_url)));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Initialisation des hooks WordPress (uniquement assets et JS)
     */
    public static function init() {
        // Enregistrer les scripts OneSignal
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);

        // Localiser le script pour le nonce AJAX
        add_action('wp_enqueue_scripts', function() {
            if (!wp_script_is('jquery', 'enqueued')) {
                wp_enqueue_script('jquery');
            }
            wp_localize_script('jquery', 'ispag_ajax_obj', [
                'nonce' => wp_create_nonce('ispag_nonce')
            ]);
        });
    }

    /**
     * Marque une notification comme lue sur l'API OneSignal
     */
    public static function mark_as_read_on_onesignal($onesignal_id) {
        if (empty($onesignal_id)) {
            return false;
        }

        $app_id  = defined('CRM_ONE_SIGNAL_APP_ID') ? CRM_ONE_SIGNAL_APP_ID : getenv('CRM_ONE_SIGNAL_APP_ID');
        $api_key = defined('CRM_ONE_SIGNAL_API_KEY') ? CRM_ONE_SIGNAL_API_KEY : getenv('CRM_ONE_SIGNAL_API_KEY');

        if (empty($app_id) || empty($api_key)) {
            return false;
        }

        $url = "https://onesignal.com/api/v1/apps/{$app_id}/notifications/{$onesignal_id}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $api_key
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('viewed' => true)));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($http_code >= 200 && $http_code < 300);
    }
}

// Initialisation de la classe
// ISPAG_OneSignal_Handler::init();