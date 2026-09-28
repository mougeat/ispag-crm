<?php
/**
 * Class ISPAG_Brevo_Webhook_Handler
 * Gère les webhooks entrants de Brevo.
 * Utilise ISPAG_Logger pour centraliser les logs dans wp-content/ispag_logs/ispag_brevo_webhook.log.
 */
if (!defined('ISPAG_CRM_BREVO_SECRET'))
{
    define('ISPAG_CRM_BREVO_SECRET', 'aG1xZjdYek15V2t3c3d5RGl0V3lU');
}

class ISPAG_Brevo_Webhook_Handler
{
    const ENDPOINT_NAMESPACE = 'ispag-crm/v1';
    const ENDPOINT_ROUTE = '/brevo-webhook/';
    const LOG_PREFIX = '[ISPAG Brevo Webhook] ';
    private const LOG_NAME = 'brevo_webhook_handler';

    private $contact_repository;
    // private $note_repository;
    private $logger;

    /**
     * Constructeur.
     *
     * @param ISPAG_Crm_Contacts_Repository $contact_repo Référence au repository des contacts.
     * @param ISPAG_Note_Repository $note_repo Référence au repository des notes.
     */
    public function __construct($contact_repo, $note_repo)
    {
        $this->contact_repository = $contact_repo;
        // $this->note_repository = $note_repo;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // $this->logger->log_user_action(self::LOG_NAME, 'class_initialized', [], $user_id);
    }

    /**
     * Enregistre les routes REST.
     */
    public function register_routes()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'registering_routes', [], $user_id);

        register_rest_route(self::ENDPOINT_NAMESPACE, self::ENDPOINT_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_request'],
            'permission_callback' => [$this, 'verify_webhook_request'],
        ]);

        $this->logger->log_user_action(self::LOG_NAME, 'routes_registered', ['namespace' => self::ENDPOINT_NAMESPACE, 'route' => self::ENDPOINT_ROUTE], $user_id);
    }

    /**
     * Vérification de sécurité du webhook.
     *
     * @param WP_REST_Request $request Requête REST.
     * @return bool|WP_Error
     */
    public function verify_webhook_request($request)
    {
        $user_id = get_current_user_id();
        $received_secret = $request->get_param('secret');

        if (empty($received_secret) || $received_secret !== ISPAG_CRM_BREVO_SECRET)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid or missing secret', $user_id);
            return new WP_Error(
                'brevo_security_fail',
                'Unauthorized access.',
                ['status' => 401]
            );
        }

        $this->logger->log_user_action(self::LOG_NAME, 'security_verification_passed', [], $user_id);
        return true;
    }

    /**
     * Traitement du webhook.
     *
     * @param WP_REST_Request $request Requête REST.
     * @return WP_REST_Response
     */
    public function handle_webhook_request($request)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'webhook_request_start', [], $user_id);

        $data = $request->get_json_params();
        $this->logger->log_user_action(self::LOG_NAME, 'payload_received', ['data' => $data], $user_id);

        $event = $data['event'] ?? null;
        $accepted_events = [
            'campaign_sent',
            'delivered',
            'delivered_for_transactional',
            'processed'
        ];

        if (!in_array($event, $accepted_events))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'event_ignored', ['event' => $event], $user_id);
            return new WP_REST_Response(['message' => 'Ignored'], 200);
        }

        // Extraction et typage
        $email = sanitize_email($data['email'] ?? '');
        $subject = sanitize_text_field($data['subject'] ?? 'Sans objet');
        $template_id = isset($data['template_id']) ? (int)$data['template_id'] : null;
        $is_transactional = (bool)$template_id;

        if ($is_transactional)
        {
            $activity_title = $subject;
            $activity_type = 'EMAIL_TRANSACTIONAL';
            $this->logger->log_user_action(self::LOG_NAME, 'transactional_email_detected', ['template_id' => $template_id], $user_id);
        }
        else
        {
            $activity_title = 'Campagne e-mail : ' . sanitize_text_field($data['campaignName'] ?? 'Inconnue');
            $activity_type = 'EMAIL_CAMPAIGN';
            $this->logger->log_user_action(self::LOG_NAME, 'campaign_email_detected', [], $user_id);
        }

        if (empty($email))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Email missing in Brevo data', $user_id);
            return new WP_REST_Response(['message' => 'Email missing'], 400);
        }

        // Identification du contact
        $user = get_user_by('email', $email);

        if (!$user)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'user_not_found', ['email' => $email], $user_id);
            return new WP_REST_Response(['message' => 'User not found'], 200);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'user_identified', ['user_id' => $user->ID, 'email' => $email], $user_id);

        // Création de la Note CRM
        $activity_content = sprintf(
            "Événement Brevo : %s. Objet du mail : %s.",
            $event,
            $subject
        );

        $note_data = new stdClass();
        $note_data->contact_id = $user->ID;
        $note_data->activity_type = $activity_type;
        $note_data->title = $activity_title;
        $note_data->content = $activity_content;
        $note_data->date_time = isset($data['date']) ? $data['date'] : current_time('mysql');

        $this->logger->log_db_change(self::LOG_NAME, ISPAG_Note_Manager::TABLE_NOTE, 'INSERT_NOTE_PREPARE', ['contact_id' => $user->ID, 'activity_type' => $activity_type], $user_id);

        // $result = $this->note_repository->create_note($note_data);

        // if (is_wp_error($result))
        // {
        //     $error_message = $result->get_error_message();
        //     $this->logger->log(self::LOG_NAME, 'ERROR: Database error - ' . $error_message, $user_id);
        //     return new WP_REST_Response(['message' => 'Database error'], 500);
        // }

        // $this->logger->log_user_action(self::LOG_NAME, 'note_created_successfully', ['note_id' => $result, 'contact_id' => $user->ID], $user_id);

        return new WP_REST_Response([
            'message' => 'Success',
            // 'note_id' => $result,
            'contact_id' => $user->ID
        ], 200);
    }
}