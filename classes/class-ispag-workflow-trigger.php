<?php
/**
 * Class ISPAG_Workflow_Trigger
 * Classe abstraite pour les déclencheurs d'un workflow.
 */
if (!class_exists('ISPAG_Workflow_Trigger')) {
    abstract class ISPAG_Workflow_Trigger {
        protected $type;

        /**
         * Constructeur.
         *
         * @param string $type Type de déclencheur (ex: 'status_change', 'email_response').
         */
        public function __construct($type) {
            $user_id = get_current_user_id();
            $this->type = $type;
            ISPAG_Workflow_Logger::debug("Déclencheur créé: Type=$type par l'utilisateur $user_id");
        }

        /**
         * Vérifie si le déclencheur est satisfait pour une entité donnée.
         *
         * @param int|string $entity_id ID de l'entité (contact ou deal).
         * @param string $entity_type Type de l'entité ('contact' ou 'deal').
         * @return bool True si le déclencheur est satisfait, false sinon.
         */
        abstract public function is_met($entity_id, $entity_type);

        /**
         * Vérifie si ce déclencheur correspond à un changement de statut spécifique.
         *
         * @param string $old_status Ancien statut.
         * @param string $new_status Nouveau statut.
         * @return bool True si c'est un déclencheur de changement de statut et que les statuts correspondent.
         */
        public function is_status_change_trigger($old_status, $new_status) {
            $user_id = get_current_user_id();
            ISPAG_Workflow_Logger::debug(
                "Vérification du déclencheur de type {$this->type} pour le changement de statut: $old_status -> $new_status par l'utilisateur $user_id"
            );
            return false;
        }

        /**
         * Récupère le type du déclencheur.
         *
         * @return string
         */
        public function get_type() {
            return $this->type;
        }
    }

    /**
     * Déclencheur pour un changement de statut de deal.
     */
    if (!class_exists('ISPAG_Status_Change_Trigger')) {
        class ISPAG_Status_Change_Trigger extends ISPAG_Workflow_Trigger {
            private $from_status;
            private $to_status;

            /**
             * Constructeur.
             *
             * @param string|null $from_status Statut de départ.
             * @param string|null $to_status Statut d'arrivée.
             */
            public function __construct($from_status = null, $to_status = null) {
                $user_id = get_current_user_id();
                parent::__construct('status_change');
                $this->from_status = $from_status;
                $this->to_status = $to_status;
                ISPAG_Workflow_Logger::debug(
                    "Déclencheur de changement de statut créé: from=$from_status, to=$to_status par l'utilisateur $user_id"
                );
            }

            /**
             * Vérifie si le déclencheur est satisfait pour une entité donnée.
             *
             * @param int|string $entity_id ID de l'entité.
             * @param string $entity_type Type de l'entité ('contact' ou 'deal').
             * @return bool
             */
            public function is_met($entity_id, $entity_type) {
                $user_id = get_current_user_id();
                ISPAG_Workflow_Logger::debug(
                    "Vérification si le déclencheur de statut est satisfait pour l'entité $entity_id (type: $entity_type) par l'utilisateur $user_id"
                );

                if ($entity_type === 'contact') {
                    $current_status = $this->get_contact_status($entity_id);
                } elseif ($entity_type === 'deal') {
                    $current_status = $this->get_deal_status($entity_id);
                } else {
                    ISPAG_Workflow_Logger::warning("Type d'entité inconnu: $entity_type");
                    return false;
                }

                $from_ok = empty($this->from_status) || $this->from_status === $this->get_previous_status($entity_id, $entity_type);
                $to_ok = empty($this->to_status) || $this->to_status === $current_status;

                ISPAG_Workflow_Logger::debug(
                    "Résultat de la vérification: from_ok=" . ($from_ok ? 'true' : 'false') . ", to_ok=" . ($to_ok ? 'true' : 'false'),
                    ['from_status' => $this->from_status, 'to_status' => $this->to_status, 'current_status' => $current_status]
                );

                return $from_ok && $to_ok;
            }

            /**
             * Vérifie si ce déclencheur correspond à un changement de statut spécifique.
             *
             * @param string $old_status Ancien statut.
             * @param string $new_status Nouveau statut.
             * @return bool
             */
            public function is_status_change_trigger($old_status, $new_status) {
                $user_id = get_current_user_id();
                ISPAG_Workflow_Logger::debug(
                    "Vérification du déclencheur: from_status={$this->from_status}, to_status={$this->to_status}, old=$old_status, new=$new_status par l'utilisateur $user_id"
                );

                // Normaliser les statuts (trim, lowercase)
                $from_status = isset($this->from_status) ? trim(strtolower($this->from_status)) : null;
                $to_status = isset($this->to_status) ? trim(strtolower($this->to_status)) : null;
                $old_status = trim(strtolower($old_status));
                $new_status = trim(strtolower($new_status));

                // Si from_status est vide, on accepte n'importe quel statut précédent
                $from_ok = empty($from_status) || $from_status === $old_status;
                // Si to_status est vide, on accepte n'importe quel nouveau statut
                $to_ok = empty($to_status) || $to_status === $new_status;

                $result = $from_ok && $to_ok;
                ISPAG_Workflow_Logger::debug("Résultat: " . ($result ? 'TRUE' : 'FALSE'));

                return $result;
            }

            /**
             * Récupère le statut d'un contact.
             *
             * @param int $contact_id ID du contact.
             * @return string|null
             */
            private function get_contact_status($contact_id) {
                $user_id = get_current_user_id();
                $status = get_user_meta($contact_id, 'ispag_contact_status', true);
                ISPAG_Workflow_Logger::debug("Statut du contact $contact_id récupéré: $status par l'utilisateur $user_id");
                return $status;
            }

            /**
             * Récupère le statut d'un deal.
             *
             * @param int $deal_id ID du deal.
             * @return string|null
             */
            private function get_deal_status($deal_id) {
                $user_id = get_current_user_id();
                global $wpdb;
                $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
                $status = $wpdb->get_var(
                    $wpdb->prepare("SELECT current_stage_key FROM {$table_name} WHERE id = %d", $deal_id)
                );
                ISPAG_Workflow_Logger::debug("Statut du deal $deal_id récupéré: $status par l'utilisateur $user_id");
                return $status;
            }

            /**
             * Récupère le statut précédent d'une entité.
             *
             * @param int|string $entity_id ID de l'entité.
             * @param string $entity_type Type de l'entité.
             * @return string|null
             */
            private function get_previous_status($entity_id, $entity_type) {
                $user_id = get_current_user_id();
                ISPAG_Workflow_Logger::debug("Récupération du statut précédent pour l'entité $entity_id (type: $entity_type) par l'utilisateur $user_id");
                // Optionnel: Implémentez cette méthode si vous stockez l'historique des statuts
                return null;
            }
        }
    }

    /**
     * Déclencheur pour une réponse à un e-mail.
     */
    if (!class_exists('ISPAG_Email_Response_Trigger')) {
        class ISPAG_Email_Response_Trigger extends ISPAG_Workflow_Trigger {
            /**
             * Constructeur.
             */
            public function __construct() {
                $user_id = get_current_user_id();
                parent::__construct('email_response');
                ISPAG_Workflow_Logger::debug("Déclencheur de réponse à un e-mail créé par l'utilisateur $user_id");
            }

            /**
             * Vérifie si le déclencheur est satisfait pour une entité donnée.
             *
             * @param int|string $entity_id ID de l'entité.
             * @param string $entity_type Type de l'entité ('contact' ou 'deal').
             * @return bool
             */
            public function is_met($entity_id, $entity_type) {
                $user_id = get_current_user_id();
                ISPAG_Workflow_Logger::debug(
                    "Vérification si l'entité $entity_id (type: $entity_type) a répondu à un e-mail par l'utilisateur $user_id"
                );

                if ($entity_type === 'contact') {
                    $has_responded = $this->has_contact_responded($entity_id);
                    ISPAG_Workflow_Logger::debug(
                        "Le contact $entity_id a répondu: " . ($has_responded ? 'OUI' : 'NON')
                    );
                    return $has_responded;
                } elseif ($entity_type === 'deal') {
                    $has_responded = $this->has_deal_responded($entity_id);
                    ISPAG_Workflow_Logger::debug(
                        "Le deal $entity_id a une réponse: " . ($has_responded ? 'OUI' : 'NON')
                    );
                    return $has_responded;
                }

                return false;
            }

            /**
             * Vérifie si un contact a répondu à un e-mail.
             *
             * @param int $contact_id ID du contact.
             * @return bool
             */
            private function has_contact_responded($contact_id) {
                $user_id = get_current_user_id();
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
                ISPAG_Workflow_Logger::debug("Vérification des réponses pour le contact $contact_id: $response réponses trouvées par l'utilisateur $user_id");
                return $response > 0;
            }

            /**
             * Vérifie si un deal a une réponse à un e-mail.
             *
             * @param int $deal_id ID du deal.
             * @return bool
             */
            private function has_deal_responded($deal_id) {
                $user_id = get_current_user_id();
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
                ISPAG_Workflow_Logger::debug("Vérification des réponses pour le deal $deal_id: $response réponses trouvées par l'utilisateur $user_id");
                return $response > 0;
            }
        }
    }

    /**
     * Déclencheur pour une tâche terminée.
     */
    if (!class_exists('ISPAG_Task_Completed_Trigger')) {
        class ISPAG_Task_Completed_Trigger extends ISPAG_Workflow_Trigger {
            /**
             * Constructeur.
             */
            public function __construct() {
                $user_id = get_current_user_id();
                parent::__construct('task_completed');
                ISPAG_Workflow_Logger::debug("Déclencheur de tâche terminée créé par l'utilisateur $user_id");
            }

            /**
             * Vérifie si le déclencheur est satisfait pour une entité donnée.
             *
             * @param int|string $entity_id ID de l'entité.
             * @param string $entity_type Type de l'entité ('contact' ou 'deal').
             * @return bool
             */
            public function is_met($entity_id, $entity_type) {
                $user_id = get_current_user_id();
                ISPAG_Workflow_Logger::debug(
                    "Vérification si une tâche est terminée pour l'entité $entity_id (type: $entity_type) par l'utilisateur $user_id"
                );

                $is_completed = $this->is_task_completed_for_entity($entity_id, $entity_type);
                ISPAG_Workflow_Logger::debug(
                    "L'entité $entity_id a une tâche terminée: " . ($is_completed ? 'OUI' : 'NON')
                );
                return $is_completed;
            }

            /**
             * Vérifie si une tâche est terminée pour une entité.
             *
             * @param int|string $entity_id ID de l'entité.
             * @param string $entity_type Type de l'entité.
             * @return bool
             */
            private function is_task_completed_for_entity($entity_id, $entity_type) {
                $user_id = get_current_user_id();
                global $wpdb;
                $table_name = ISPAG_Crm_Note_Constants::TABLE_NOTE;

                if ($entity_type === 'contact') {
                    $where = $wpdb->prepare("contact_id LIKE %s", '%' . $wpdb->esc_like($entity_id) . '%');
                } elseif ($entity_type === 'deal') {
                    $where = $wpdb->prepare("deal_id LIKE %s", '%' . $wpdb->esc_like($entity_id) . '%');
                } else {
                    ISPAG_Workflow_Logger::warning("Type d'entité inconnu: $entity_type");
                    return false;
                }

                $completed_tasks = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$table_name}
                     WHERE {$where}
                     AND type = 'TASK'
                     AND is_task = 1
                     AND is_completed = 1"
                );

                ISPAG_Workflow_Logger::debug("Vérification des tâches terminées pour l'entité $entity_id (type: $entity_type): $completed_tasks tâches trouvées par l'utilisateur $user_id");
                return $completed_tasks > 0;
            }
        }
    }
}