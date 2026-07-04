<?php
/**
 * Class ISPAG_Workflow_Trigger
 * Classe abstraite pour les déclencheurs d'un workflow.
 */

if (!class_exists('ISPAG_Workflow_Trigger')) {
    abstract class ISPAG_Workflow_Trigger {
        protected $type;

        public function __construct($type) {
            $this->type = $type;
            ISPAG_Workflow_Logger::debug("Déclencheur créé: Type={$type}");
        }

        /**
         * Vérifie si le déclencheur est satisfait pour une entité donnée.
         */
        abstract public function is_met($entity_id, $entity_type);

        /**
         * Vérifie si ce déclencheur est un déclencheur de changement de statut.
         */
        public function is_status_change_trigger($old_status, $new_status) {
            ISPAG_Workflow_Logger::debug(
                "Vérification du déclencheur de type {$this->type} pour le changement de statut: {$old_status} -> {$new_status}"
            );
            return false;
        }

        /**
         * Récupère le type du déclencheur.
         */
        public function get_type() {
            return $this->type;
        }
    }

    /**
     * Déclencheur pour un changement de statut de deal.
     */
    class ISPAG_Status_Change_Trigger extends ISPAG_Workflow_Trigger {
        private $from_status;
        private $to_status;

        public function __construct($from_status = null, $to_status = null) {
            parent::__construct('status_change');
            $this->from_status = $from_status;
            $this->to_status = $to_status;
            ISPAG_Workflow_Logger::debug(
                "Déclencheur de changement de statut créé: from={$from_status}, to={$to_status}"
            );
        }

        public function is_met($entity_id, $entity_type) {
            ISPAG_Workflow_Logger::debug(
                "Vérification si le déclencheur de statut est satisfait pour l'entité {$entity_id} (type: {$entity_type})"
            );

            if ($entity_type === 'contact') {
                $current_status = $this->get_contact_status($entity_id);
            } elseif ($entity_type === 'deal') {
                $current_status = $this->get_deal_status($entity_id);
            } else {
                ISPAG_Workflow_Logger::warning(
                    "Type d'entité inconnu: {$entity_type}"
                );
                return false;
            }

            $from_ok = empty($this->from_status) || $this->from_status === $this->get_previous_status($entity_id, $entity_type);
            $to_ok = empty($this->to_status) || $this->to_status === $current_status;

            ISPAG_Workflow_Logger::debug(
                "Résultat de la vérification: from_ok={$from_ok}, to_ok={$to_ok}",
                ['from_status' => $this->from_status, 'to_status' => $this->to_status, 'current_status' => $current_status]
            );

            return $from_ok && $to_ok;
        }

        public function is_status_change_trigger($old_status, $new_status) {
            ISPAG_Workflow_Logger::debug(
                "Vérification du déclencheur: from_status={$this->from_status}, to_status={$this->to_status}, old={$old_status}, new={$new_status}"
            );

            // Si from_status est vide, on accepte n'importe quel statut précédent
            $from_ok = empty($this->from_status) || $this->from_status === $old_status;

            // Si to_status est vide, on accepte n'importe quel nouveau statut
            $to_ok = empty($this->to_status) || $this->to_status === $new_status;

            $result = $from_ok && $to_ok;
            ISPAG_Workflow_Logger::debug("Résultat: " . ($result ? 'TRUE' : 'FALSE'));

            return $result;
        }

        private function get_contact_status($contact_id) {
            $status = get_user_meta($contact_id, 'ispag_contact_status', true);
            ISPAG_Workflow_Logger::debug("Statut du contact {$contact_id} récupéré: {$status}");
            return $status;
        }

        private function get_deal_status($deal_id) {
            global $wpdb;
            $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            $status = $wpdb->get_var(
                $wpdb->prepare("SELECT current_stage_key FROM {$table_name} WHERE id = %d", $deal_id)
            );
            ISPAG_Workflow_Logger::debug("Statut du deal {$deal_id} récupéré: {$status}");
            return $status;
        }

        private function get_previous_status($entity_id, $entity_type) {
            // Optionnel: Implémentez cette méthode si vous stockez l'historique des statuts
            ISPAG_Workflow_Logger::debug("Récupération du statut précédent pour l'entité {$entity_id} (type: {$entity_type})");
            return null;
        }
    }

    /**
     * Déclencheur pour une réponse à un e-mail.
     */
    class ISPAG_Email_Response_Trigger extends ISPAG_Workflow_Trigger {
        public function __construct() {
            parent::__construct('email_response');
        }

        public function is_met($entity_id, $entity_type) {
            ISPAG_Workflow_Logger::debug(
                "Vérification si l'entité {$entity_id} (type: {$entity_type}) a répondu à un e-mail"
            );

            if ($entity_type === 'contact') {
                $has_responded = $this->has_contact_responded($entity_id);
                ISPAG_Workflow_Logger::debug(
                    "Le contact {$entity_id} a répondu: " . ($has_responded ? 'OUI' : 'NON')
                );
                return $has_responded;
            } elseif ($entity_type === 'deal') {
                $has_responded = $this->has_deal_responded($entity_id);
                ISPAG_Workflow_Logger::debug(
                    "Le deal {$entity_id} a une réponse: " . ($has_responded ? 'OUI' : 'NON')
                );
                return $has_responded;
            }

            return false;
        }

        private function has_contact_responded($contact_id) {
            global $wpdb;
            $table_name = ISPAG_Crm_Note_Constants::TABLE_NOTE;
            $response = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name}
                     WHERE contact_id LIKE %s
                     AND type IN ('EMAIL', 'REPONSE', 'EMAIL_TRANSACTIONAL')
                     AND is_completed = 1",
                    '%' . $wpdb->esc_like($contact_id) . '%'
                )
            );
            return $response > 0;
        }

        private function has_deal_responded($deal_id) {
            global $wpdb;
            $table_name = ISPAG_Crm_Note_Constants::TABLE_NOTE;
            $response = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name}
                     WHERE deal_id LIKE %s
                     AND type IN ('EMAIL', 'REPONSE')
                     AND is_completed = 1",
                    '%' . $wpdb->esc_like($deal_id) . '%'
                )
            );
            return $response > 0;
        }
    }

    /**
     * Déclencheur pour une tâche terminée.
     */
    class ISPAG_Task_Completed_Trigger extends ISPAG_Workflow_Trigger {
        public function __construct() {
            parent::__construct('task_completed');
        }

        public function is_met($entity_id, $entity_type) {
            ISPAG_Workflow_Logger::debug(
                "Vérification si une tâche est terminée pour l'entité {$entity_id} (type: {$entity_type})"
            );

            $is_completed = $this->is_task_completed_for_entity($entity_id, $entity_type);
            ISPAG_Workflow_Logger::debug(
                "L'entité {$entity_id} a une tâche terminée: " . ($is_completed ? 'OUI' : 'NON')
            );
            return $is_completed;
        }

        private function is_task_completed_for_entity($entity_id, $entity_type) {
            global $wpdb;
            $table_name = ISPAG_Crm_Note_Constants::TABLE_NOTE;

            if ($entity_type === 'contact') {
                $where = $wpdb->prepare("contact_id LIKE %s", '%' . $wpdb->esc_like($entity_id) . '%');
            } elseif ($entity_type === 'deal') {
                $where = $wpdb->prepare("deal_id LIKE %s", '%' . $wpdb->esc_like($entity_id) . '%');
            } else {
                return false;
            }

            $completed_tasks = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_name}
                 WHERE {$where}
                 AND type = 'TASK'
                 AND is_task = 1
                 AND is_completed = 1"
            );

            return $completed_tasks > 0;
        }
    }
}