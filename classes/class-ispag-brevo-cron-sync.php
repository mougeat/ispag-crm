<?php
/**
 * Class ISPAG_Brevo_Cron_Sync
 * Gère la synchronisation des contacts avec Brevo.
 * Utilise ISPAG_Logger pour centraliser les logs dans wp-content/ispag_logs/ispag_brevo_cron_sync.log.
 */
class ISPAG_Brevo_Cron_Sync
{
    private $api_key;
    private $api_url = 'https://api.brevo.com/v3/contacts';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    /**
     * Constructeur.
     */
    public function __construct()
    {
        $this->api_key = getenv('BREVO_API_KEY');
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // $this->logger->log_user_action('brevo_cron_sync', 'class_initialized', [], $user_id);

        // ✅ Solution 2 : Utiliser une méthode de classe
        add_action('ispag_daily_brevo_sync_event', [$this, 'handle_daily_sync']);
    }

    /**
     * Méthode dédiée pour gérer la synchronisation quotidienne.
     */
    public function handle_daily_sync()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('brevo_cron_sync', 'daily_sync_triggered', [], $user_id);
        $this->sync_all_contacts();
    }

    /**
     * Synchronise tous les contacts avec Brevo.
     */
    public function sync_all_contacts()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('brevo_cron_sync', 'sync_all_contacts_start', [], $user_id);

        // On récupère les utilisateurs (on peut exclure les administrateurs si besoin)
        $users = get_users(array(
            'fields' => 'all',
            'role__not_in' => array('administrator') // Optionnel : ne pas synchroniser les admins
        ));

        $this->logger->log_user_action('brevo_cron_sync', 'users_fetched', ['count' => count($users)], $user_id);

        foreach ($users as $user)
        {
            $this->logger->log_user_action('brevo_cron_sync', 'syncing_user', ['user_id' => $user->ID, 'email' => $user->user_email], $user_id);
            $this->sync_one_contact($user);
            usleep(100000); // 0.1s de pause
        }

        $this->logger->log_user_action('brevo_cron_sync', 'sync_all_contacts_complete', [], $user_id);
    }

    /**
     * Synchronise un contact avec Brevo.
     *
     * @param WP_User $user Utilisateur WordPress.
     */
    private function sync_one_contact($user)
    {
        $user_id = get_current_user_id();
        if (empty($user->user_email))
        {
            $this->logger->log('brevo_cron_sync', 'ERROR: User has no email - ID: ' . $user->ID, $user_id);
            return;
        }

        $this->logger->log_user_action('brevo_cron_sync', 'sync_user_start', ['user_id' => $user->ID, 'email' => $user->user_email], $user_id);

        $phone = get_user_meta($user->ID, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, true);
        $owner_id = get_user_meta($user->ID, ISPAG_Crm_Contact_Constants::META_OWNER, true);
        $owner = get_userdata($owner_id);
        $job_title = get_user_meta($user->ID, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, true);
        $company_id = get_user_meta($user->ID, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true);
        $birthday = get_user_meta($user->ID, ISPAG_Crm_Contact_Constants::USER_BIRTHDAY, true);

        $company_rep = new ISPAG_Crm_Company_Repository();
        $company = $company_rep->get_company_by_id($company_id);

        $this->logger->log_db_change('brevo_cron_sync', 'user_meta', 'FETCH_META', ['user_id' => $user->ID, 'phone' => $phone, 'owner_id' => $owner_id, 'job_title' => $job_title, 'company_id' => $company_id], $user_id);

        // Construction des attributs de base
        $attributes = array(
            'SMS'           => $this->format_phone($phone),
            'WHATSAPP'      => $this->format_phone($phone),
            'JOB_TITLE'     => $job_title,
            'ROLE'          => $user->roles[0] ?? '',
            'OWNER'         => $owner ? $owner->display_name : 'Not assigned',
            'ENTREPRISE'    => ($company && isset($company->company_name)) ? $company->company_name : '',
            'BIRTHDAY'      => $birthday,
        );

        // Protection des données d'identité
        if (!empty(trim($user->first_name)))
        {
            $attributes['PRENOM'] = $user->first_name;
            $this->logger->log_user_action('brevo_cron_sync', 'first_name_added', ['user_id' => $user->ID, 'first_name' => $user->first_name], $user_id);
        }
        if (!empty(trim($user->last_name)))
        {
            $attributes['NOM'] = $user->last_name;
            $this->logger->log_user_action('brevo_cron_sync', 'last_name_added', ['user_id' => $user->ID, 'last_name' => $user->last_name], $user_id);
        }

        $data = array(
            'email' => $user->user_email,
            'attributes' => $attributes,
            'updateEnabled' => true // Crée le contact s'il n'existe pas, met à jour sinon
        );

        $this->logger->log_user_action('brevo_cron_sync', 'data_prepared', ['email' => $user->user_email, 'attributes' => array_keys($attributes)], $user_id);

        $response = wp_remote_post($this->api_url, array(
            'body' => json_encode($data),
            'headers' => array(
                'api-key' => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 10
        ));

        if (is_wp_error($response))
        {
            $error_message = $response->get_error_message();
            $this->logger->log('brevo_cron_sync', 'ERROR: WP_REMOTE_POST_FAILED - ' . $error_message, $user_id);
        }
        else
        {
            $code = wp_remote_retrieve_response_code($response);
            $this->logger->log_user_action('brevo_cron_sync', 'api_response_received', ['user_id' => $user->ID, 'http_code' => $code], $user_id);
        }
    }

    /**
     * Formate un numéro de téléphone pour Brevo.
     *
     * @param string $phone Numéro de téléphone.
     * @return string
     */
    private function format_phone($phone)
    {
        $user_id = get_current_user_id();
        if (empty($phone))
        {
            $this->logger->log_user_action('brevo_cron_sync', 'empty_phone_received', [], $user_id);
            return '';
        }

        $original_phone = $phone;
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Format spécifique pour Brevo (E.164 conseillé)
        if (strpos($phone, '0') === 0)
        {
            $phone = '41' . substr($phone, 1);
            $this->logger->log_user_action('brevo_cron_sync', 'phone_formatted', ['original' => $original_phone, 'formatted' => $phone], $user_id);
        }

        return $phone;
    }
}