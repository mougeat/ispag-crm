<?php
/**
 * Class ISPAG_Workflow_Step
 * Classe abstraite pour les étapes d'un workflow.
 */

if (!class_exists('ISPAG_Workflow_Step')) {
    abstract class ISPAG_Workflow_Step {
        protected $title;
        protected $content;
        protected $delay;
        protected $type;
        
        public function __construct($title, $content, $delay = null) {
            $this->title = $title;
            $this->content = $content;
            $this->delay = $delay;
            ISPAG_Workflow_Logger::debug("Étape créée: Type={$this->type}, Titre={$this->title}");
        }

        // ✅ Ajoutez ces méthodes publiques pour accéder aux propriétés protégées
        public function get_title() {
            return $this->title;
        }

        public function get_content() {
            return $this->content;
        }

        public function get_delay() {
            return $this->delay;
        }

        public function get_type() {
            return $this->type;
        }

        /**
         * Remplace les variables dynamiques dans le contenu.
         */
        protected function replace_variables($content, $group_ref, $entity_type) {
            return ISPAG_Variable_Replacer::replace_variables($content, $group_ref, $entity_type);
        }

        /**
         * Vérifie si l'étape doit être exécutée.
         */
        public function should_execute($entity_id, $entity_type) {
            ISPAG_Workflow_Logger::debug(
                "Vérification si l'étape '{$this->title}' doit être exécutée pour l'entité {$entity_id} (type: {$entity_type})"
            );
            // Par défaut, on retourne true. À surcharger dans les classes filles si nécessaire.
            return true;
        }

        /**
         * Exécute l'étape.
         */
        abstract public function execute($entity_id, $entity_type);
    }

    /**
     * Classe pour les étapes de type "e-mail".
     */
    class ISPAG_Email_Step extends ISPAG_Workflow_Step {
        public function __construct($title, $content, $delay = null) {
            parent::__construct($title, $content, $delay);
            $this->type = 'email';
        }

        public function execute($entity_id, $entity_type) {
            ISPAG_Workflow_Logger::info(
                "Envoi d'un e-mail pour l'entité {$entity_id} (type: {$entity_type}): {$this->get_title()}"
            );

            try {
                // 1. Remplacer les variables dans le titre et le contenu
                $subject = $this->replace_variables($this->get_title(), $entity_id, $entity_type);
                $body = $this->replace_variables($this->get_content(), $entity_id, $entity_type);

                // 2. Récupérer l'e-mail du destinataire
                $to = $this->get_recipient_email($entity_id, $entity_type);
                if (empty($to)) {
                    ISPAG_Workflow_Logger::warning(
                        "Aucun e-mail de destinataire trouvé pour l'entité {$entity_id} (type: {$entity_type})"
                    );
                    return;
                }

                // 3. Récupérer le nom du destinataire (pour Brevo)
                $to_name = '';
                if ($entity_type === 'deal') {
                    $deal = $this->get_deal($entity_id);
                    if ($deal && !empty($deal->associated_contact_ids)) {
                        $contact_ids = explode(',', $deal->associated_contact_ids);
                        if (!empty($contact_ids[0])) {
                            $contact = $this->get_contact($contact_ids[0]);
                            $to_name = ($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '');
                        }
                    }
                } elseif ($entity_type === 'contact') {
                    $contact = $this->get_contact($entity_id);
                    $to_name = ($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '');
                }

                ISPAG_Workflow_Logger::debug(
                    "Envoi de l'e-mail à: {$to} (Nom: {$to_name}), Sujet: {$subject}"
                );

                // 4. Utiliser ISPAG_Mail_Service pour envoyer l'e-mail
                $use_brevo = false; // Mettez à true si vous voulez utiliser Brevo
                $sent = ISPAG_Mail_Service::send_mail(
                    $to,
                    $to_name,
                    $subject,
                    nl2br($body), // Convertir les sauts de ligne en <br>
                    [],
                    $use_brevo
                );

                if ($sent) {
                    ISPAG_Workflow_Logger::info("E-mail envoyé avec succès à: {$to}");
                } else {
                    ISPAG_Workflow_Logger::error("Échec de l'envoi de l'e-mail à: {$to}");
                }

            } catch (Exception $e) {
                ISPAG_Workflow_Logger::error(
                    "Exception dans ISPAG_Email_Step::execute: " . $e->getMessage(),
                    ['exception' => $e->getTraceAsString()]
                );
            }
        }

        private function get_contact($contact_id) {
            if (function_exists('get_userdata')) {
                $contact = get_userdata($contact_id);
                ISPAG_Workflow_Logger::debug("Contact récupéré: ID={$contact_id}");
                return $contact;
            }
            ISPAG_Workflow_Logger::warning("Impossible de récupérer le contact: ID={$contact_id}");
            return null;
        }

        private function get_deal($deal_id) {
            global $wpdb;
            $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            $deal = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE deal_group_ref = %s", $deal_id)
            );
            ISPAG_Workflow_Logger::debug("Deal récupéré: ID={$deal_id}" . print_r($deal, true));
            return $deal;
        }

        private function get_recipient_email($entity_id, $entity_type) {
            if ($entity_type === 'contact') {
                $contact = $this->get_contact($entity_id);
                return $contact ? $contact->user_email : null;
            } elseif ($entity_type === 'deal') {
                // Récupérer l'e-mail du contact associé au deal
                $deal = $this->get_deal($entity_id);
                if ($deal && !empty($deal->associated_contact_ids)) {
                    $contact_ids = explode(',', $deal->associated_contact_ids);
                    if (!empty($contact_ids[0])) {
                        $contact = $this->get_contact($contact_ids[0]);
                        return $contact ? $contact->user_email : null;
                    }
                }
            }
            return null;
        }
    }

    /**
     * Classe pour les étapes de type "tâche".
     */
    class ISPAG_Task_Step extends ISPAG_Workflow_Step {
        public function __construct($title, $content, $delay = null) {
            parent::__construct($title, $content, $delay);
            $this->type = 'task';
        }

        public function execute($group_ref, $entity_type) {
            ISPAG_Workflow_Logger::info(
                "Début de l'exécution de la tâche: {$this->get_title()} pour le group_ref: {$group_ref}"
            );

            try {
                // 1. Récupérer les informations du deal
                global $wpdb;
                $deal_table = ISPAG_Crm_Deal_Constants::TABLE_NAME;
                $deal = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT
                            MAX(Id) as id,
                            deal_group_ref,
                            MAX(project_name) as project_name,
                            MAX(current_stage_key) as current_stage_key,
                            GROUP_CONCAT(DISTINCT associated_contact_ids SEPARATOR ',') as associated_contact_ids,
                            GROUP_CONCAT(DISTINCT associated_company_id SEPARATOR ',') as associated_company_id,
                            MAX(deal_owner) as deal_owner 
                        FROM {$deal_table}
                        WHERE deal_group_ref = %s
                        GROUP BY deal_group_ref",
                        $group_ref
                    )
                );

                if (!$deal) {
                    ISPAG_Workflow_Logger::error("Aucun deal trouvé avec group_ref: {$group_ref}");
                    return;
                }
                else{
                    ISPAG_Workflow_Logger::debug("Contenu du deal trouvé: " . print_r($deal, true));
                }

                // 2. Remplacer les variables dans le titre et le contenu
                $title = $this->replace_variables($this->get_title(), $group_ref, $entity_type);
                $content = $this->replace_variables($this->get_content(), $group_ref, $entity_type);

                ISPAG_Workflow_Logger::debug(
                    "Titre après remplacement des variables: {$title}"
                );
                ISPAG_Workflow_Logger::debug(
                    "Contenu après remplacement des variables: " . substr($content, 0, 100) . "..."
                );

                // 3. Vérifier que les classes nécessaires existent
                if (!class_exists('ISPAG_Note_Repository')) {
                    ISPAG_Workflow_Logger::error("ISPAG_Note_Repository n'existe pas !");
                    return;
                }

                if (!class_exists('ISPAG_Note_Ajax_Handler')) {
                    ISPAG_Workflow_Logger::error("ISPAG_Note_Ajax_Handler n'existe pas !");
                    return;
                }

                // 4. Instancier le repository et le handler
                $note_repository = new ISPAG_Note_Repository();
                $note_handler = new ISPAG_Note_Ajax_Handler($note_repository);

                // 5. Préparer les données de la tâche avec les variables remplacées
                $task_data = [
                    'type' => 'TASK',
                    'title' => $title,
                    'content' => $content,
                    'created_by' => $deal->deal_owner,
                    'deal_id' => $group_ref,
                    'deal_group_ref' => $group_ref,
                    'contact_id' => $deal->associated_contact_ids ?? '',
                    'company_id' => $deal->associated_company_id ?? '',
                    'is_task' => 1,
                    'due_date' => current_time('mysql'),
                    'reminder_date' => current_time('mysql')
                ];

                // 6. Sauvegarder la tâche
                $result = $note_handler->handle_save_note($task_data, null, null, true);

                if ($result) {
                    ISPAG_Workflow_Logger::info(
                        "Tâche créée avec succès (ID: {$result}) pour le group_ref: {$group_ref}"
                    );
                } else {
                    ISPAG_Workflow_Logger::error(
                        "Échec de la création de la tâche pour le group_ref: {$group_ref}"
                    );
                }

            } catch (Exception $e) {
                ISPAG_Workflow_Logger::error(
                    "Exception dans ISPAG_Task_Step::execute: " . $e->getMessage(),
                    ['exception' => $e->getTraceAsString()]
                );
            } catch (Error $e) {
                ISPAG_Workflow_Logger::error(
                    "Erreur fatale dans ISPAG_Task_Step::execute: " . $e->getMessage(),
                    ['error' => $e->getTraceAsString()]
                );
            }

            ISPAG_Workflow_Logger::info(
                "Fin de l'exécution de la tâche: {$this->get_title()} pour le group_ref: {$group_ref}"
            );
        }
    }

    /**
     * Classe pour les étapes de type "appel".
     */
    class ISPAG_Call_Step extends ISPAG_Workflow_Step {
        public function __construct($title, $content, $delay = null) {
            parent::__construct($title, $content, $delay);
            $this->type = 'call';
        }

        public function execute($entity_id, $entity_type) {
            ISPAG_Workflow_Logger::info(
                "Création d'un rappel d'appel pour l'entité {$entity_id} (type: {$entity_type}): {$this->get_title()}"
            );

            try {
                // 1. Remplacer les variables dans le titre et le contenu
                $title = $this->replace_variables($this->get_title(), $entity_id, $entity_type);
                $content = $this->replace_variables($this->get_content(), $entity_id, $entity_type);

                // 2. Utiliser ISPAG_Note_Ajax_Handler pour sauvegarder l'appel
                if (class_exists('ISPAG_Note_Ajax_Handler')) {
                    $note_handler = new ISPAG_Note_Ajax_Handler(new ISPAG_Note_Repository());
                    $call_data = [
                        'type' => 'CALL',
                        'title' => $title,
                        'content' => $content,
                        'created_by' => get_current_user_id(),
                        'deal_id' => $entity_type === 'deal' ? $entity_id : null,
                        'is_task' => 0
                    ];

                    if ($entity_type === 'contact') {
                        $call_data['contact_id'] = $entity_id;
                    }

                    $result = $note_handler->handle_save_note($call_data, null, $entity_id, true);
                    ISPAG_Workflow_Logger::debug(
                        "Appel sauvegardé via ISPAG_Note_Ajax_Handler",
                        ['result' => $result, 'call_data' => $call_data]
                    );

                    if ($result) {
                        ISPAG_Workflow_Logger::info("Appel créé avec succès pour l'entité {$entity_id}");
                    } else {
                        ISPAG_Workflow_Logger::error("Échec de la création de l'appel pour l'entité {$entity_id}");
                    }
                } else {
                    ISPAG_Workflow_Logger::error(
                        "La classe ISPAG_Note_Ajax_Handler n'est pas disponible pour créer l'appel"
                    );
                }
            } catch (Exception $e) {
                ISPAG_Workflow_Logger::error(
                    "Exception dans ISPAG_Call_Step::execute: " . $e->getMessage(),
                    ['exception' => $e->getTraceAsString()]
                );
            }
        }
    }
}