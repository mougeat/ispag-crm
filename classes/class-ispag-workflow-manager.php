<?php
/**
 * Class ISPAG_Workflow_Manager
 * Gère la création, l'exécution et la suppression des workflows.
 */

if (!class_exists('ISPAG_Workflow_Manager')) {
    class ISPAG_Workflow_Manager {
        private static $instance = null;
        private $execution_repository;
        private $hooks_registered = false;

        public static function get_instance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct() {
            if (self::$instance !== null) {
                // ✅ Évitez les instanciations multiples
                return;
            }

            $this->execution_repository = new ISPAG_Workflow_Execution();
            add_action('init', [$this, 'init_hooks']);
        }

        public function init_hooks() {
            if ($this->hooks_registered) {
                return;
            }

            ISPAG_Workflow_Logger::debug('Initialisation des hooks pour ISPAG_Workflow_Manager');

            add_action('ispag_deal_status_changed', [$this, 'handle_deal_status_change'], 10, 3);
            ISPAG_Workflow_Logger::debug('Hook ispag_deal_status_changed enregistré');

            add_action('ispag_contact_status_changed', [$this, 'handle_contact_status_change'], 10, 3);
            ISPAG_Workflow_Logger::debug('Hook ispag_contact_status_changed enregistré');

            add_action('ispag_execute_workflow_steps', [$this, 'execute_workflow_steps']);
            if (!wp_next_scheduled('ispag_execute_workflow_steps')) {
                wp_schedule_event(time(), 'hourly', 'ispag_execute_workflow_steps');
                ISPAG_Workflow_Logger::debug("WP Cron pour ispag_execute_workflow_steps planifié");
            }

            $this->hooks_registered = true;
        }

        /**
         * Gère le changement de statut d'un deal.
         * @param string $group_ref Référence du groupe de deal (ex: OF99-99999).
         * @param string $old_status Ancien statut.
         * @param string $new_status Nouveau statut.
         */
        public function handle_deal_status_change($group_ref, $old_status, $new_status) {
            ISPAG_Workflow_Logger::info(
                "Changement de statut pour le deal (group_ref: {$group_ref}): {$old_status} -> {$new_status}",
                ['group_ref' => $group_ref, 'old_status' => $old_status, 'new_status' => $new_status]
            );

            $this->trigger_workflows_for_entity($group_ref, 'deal', $old_status, $new_status);
        }

        /**
         * Gère le changement de statut d'un contact.
         */
        public function handle_contact_status_change($contact_id, $old_status, $new_status) {
            ISPAG_Workflow_Logger::info(
                "Changement de statut pour le contact {$contact_id}: {$old_status} -> {$new_status}",
                ['contact_id' => $contact_id, 'old_status' => $old_status, 'new_status' => $new_status]
            );

            $this->trigger_workflows_for_entity($contact_id, 'contact', $old_status, $new_status);
        }

        /**
         * Déclenche les workflows pour une entité.
         */
        private function trigger_workflows_for_entity($group_ref, $entity_type, $old_status, $new_status) {
            ISPAG_Workflow_Logger::debug(
                "Recherche des workflows pour l'entité {$group_ref} (type: {$entity_type}) avec changement de statut: {$old_status} -> {$new_status}"
            );

            $workflows = $this->get_workflows_by_type($entity_type);

            ISPAG_Workflow_Logger::debug(
                "Trouvé " . count($workflows) . " workflows de type {$entity_type}"
            );

            foreach ($workflows as $workflow) {
                ISPAG_Workflow_Logger::debug(
                    "Test du workflow {$workflow->get_id()}: {$workflow->get_name()}"
                );

                // ✅ Vérifiez si le workflow doit être déclenché
                if ($workflow->should_trigger_on_status_change($old_status, $new_status)) {
                    ISPAG_Workflow_Logger::info(
                        "✅ Workflow {$workflow->get_id()} DOIT être déclenché !"
                    );

                    // ✅ Démarrez une exécution et exécutez le workflow
                    $this->execution_repository->start_execution($workflow->get_id(), $group_ref);
                    $workflow->execute_for_entity($group_ref);
                } else {
                    ISPAG_Workflow_Logger::debug(
                        "❌ Workflow {$workflow->get_id()} ne doit PAS être déclenché."
                    );
                }
            }
        }

        /**
         * Exécute les étapes des workflows.
         */
        public function execute_workflow_steps() {
            ISPAG_Workflow_Logger::debug('Début de l\'exécution des étapes des workflows via WP Cron');

            $active_executions = $this->execution_repository->get_active_executions();
            foreach ($active_executions as $execution) {
                try {
                    // ✅ Vérifiez si le délai est écoulé
                    if (!empty($execution->next_step_due) && strtotime($execution->next_step_due) > time()) {
                        ISPAG_Workflow_Logger::debug(
                            "L'exécution {$execution->id} a un délai non écoulé (next_step_due: {$execution->next_step_due}). Ignoré."
                        );
                        continue;
                    }

                    $workflow = new ISPAG_Workflow($execution->workflow_id);
                    if ($workflow->is_active()) {
                        ISPAG_Workflow_Logger::debug(
                            "Exécution du workflow {$execution->workflow_id} pour le deal {$execution->deal_id}"
                        );
                        $workflow->execute_for_entity($execution->deal_id);

                        // ✅ Mettre à jour next_step_due pour l'étape suivante
                        $this->update_next_step_due($execution->id, $workflow);
                    } else {
                        ISPAG_Workflow_Logger::warning(
                            "Workflow {$execution->workflow_id} n'est pas actif, exécution ignorée"
                        );
                    }
                } catch (Exception $e) {
                    ISPAG_Workflow_Logger::error(
                        "Erreur lors de l'exécution du workflow {$execution->workflow_id} : " . $e->getMessage(),
                        ['execution_id' => $execution->id, 'error' => $e->getMessage()]
                    );
                }
            }

            ISPAG_Workflow_Logger::debug('Fin de l\'exécution des étapes des workflows via WP Cron');
        }

        // ✅ Méthode pour mettre à jour next_step_due après l'exécution d'une étape
        private function update_next_step_due($execution_id, $workflow) {
            global $wpdb;
            $execution = $this->execution_repository->get_executions_by_deal($execution_id);
            if (empty($execution)) {
                return;
            }

            $execution = $execution[0];
            $current_step_index = $execution->current_step_index;
            $steps = $workflow->get_steps();

            if (isset($steps[$current_step_index + 1])) {
                $next_step = $steps[$current_step_index + 1];
                // ✅ CORRECTION : Accédez à la propriété 'delay' de l'objet
                if (!empty($next_step->delay)) { // ✅ Utilisez ->delay au lieu de ['delay']
                    $delay = $this->execution_repository->parse_delay($next_step->delay);
                    $next_step_due = date('Y-m-d H:i:s', strtotime("+{$delay} seconds"));
                    ISPAG_Workflow_Logger::debug(
                        "Mise à jour de next_step_due pour l'exécution {$execution_id}: {$next_step_due}"
                    );
                    $wpdb->update(
                        $this->execution_repository->table_name,
                        ['next_step_due' => $next_step_due],
                        ['id' => $execution_id],
                        ['%s'],
                        ['%d']
                    );
                }
            }
        }

        /**
         * Récupère tous les workflows.
         */
        public function get_all_workflows() {
            ISPAG_Workflow_Logger::debug("Récupération de tous les workflows");

            $workflow_posts = get_posts([
                'post_type'      => 'ispag_workflow',
                'numberposts'    => -1,
                'post_status'    => 'publish',
            ]);

            $workflows = [];
            foreach ($workflow_posts as $post) {
                try {
                    $workflow = new ISPAG_Workflow($post->ID);
                    $workflows[] = $workflow;
                    ISPAG_Workflow_Logger::debug("Workflow chargé: ID={$post->ID}, Name={$post->post_title}");
                } catch (Exception $e) {
                    ISPAG_Workflow_Logger::error(
                        "Erreur lors du chargement du workflow {$post->ID} : " . $e->getMessage()
                    );
                }
            }

            ISPAG_Workflow_Logger::debug("Trouvé " . count($workflows) . " workflows");
            return $workflows;
        }

        /**
         * Récupère les workflows par type (contact ou deal).
         */
        public function get_workflows_by_type($type) {
            ISPAG_Workflow_Logger::debug("Récupération des workflows de type: {$type}");

            $workflow_posts = get_posts([
                'post_type'      => 'ispag_workflow',
                'numberposts'    => -1,
                'post_status'    => 'publish',
                'meta_query'     => [
                    [
                        'key'   => '_ispag_workflow_type',
                        'value' => $type,
                    ],
                ],
            ]);

            ISPAG_Workflow_Logger::debug(
                "Requête get_posts exécutée. Trouvé " . count($workflow_posts) . " posts.",
                ['workflow_posts' => $workflow_posts]
            );

            $workflows = [];
            foreach ($workflow_posts as $post) {
                try {
                    $workflow = new ISPAG_Workflow($post->ID);
                    $workflows[] = $workflow;
                } catch (Exception $e) {
                    ISPAG_Workflow_Logger::error(
                        "Erreur lors du chargement du workflow {$post->ID}: " . $e->getMessage()
                    );
                }
            }

            return $workflows;
        }

        /**
         * Récupère les entités (contacts ou deals) pour un workflow.
         */
        private function get_entities_for_workflow($workflow) {
            $entity_type = $workflow->get_type();
            ISPAG_Workflow_Logger::debug(
                "Récupération des entités pour le workflow {$workflow->get_id()} (type: {$entity_type})"
            );

            if ($entity_type === 'contact') {
                $entities = get_users(['fields' => 'ID']);
                ISPAG_Workflow_Logger::debug("Trouvé " . count($entities) . " contacts");
            } elseif ($entity_type === 'deal') {
                global $wpdb;
                $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
                $entities = $wpdb->get_col(
                    "SELECT id FROM {$table_name}"
                );
                ISPAG_Workflow_Logger::debug("Trouvé " . count($entities) . " deals");
            } else {
                $entities = [];
                ISPAG_Workflow_Logger::warning("Type d'entité inconnu: {$entity_type}");
            }

            return $entities;
        }

        /**
         * Vérifie si un deal est dans une séquence active.
         */
        public function is_deal_in_workflow($deal_id) {
            ISPAG_Workflow_Logger::debug("Vérification si le deal {$deal_id} est dans une séquence");
            return $this->execution_repository->is_deal_in_workflow($deal_id);
        }

        /**
         * Récupère les workflows actifs pour un deal.
         */
        public function get_workflows_for_deal($deal_id) {
            ISPAG_Workflow_Logger::debug("Récupération des workflows pour le deal {$deal_id}");
            $executions = $this->execution_repository->is_deal_in_workflow($deal_id);
            $workflows = [];

            foreach ($executions as $execution) {
                try {
                    $workflow = new ISPAG_Workflow($execution->workflow_id);
                    $workflows[] = [
                        'workflow' => $workflow,
                        'execution' => $execution
                    ];
                    ISPAG_Workflow_Logger::debug(
                        "Workflow trouvé pour le deal {$deal_id}: ID={$execution->workflow_id}"
                    );
                } catch (Exception $e) {
                    ISPAG_Workflow_Logger::error(
                        "Erreur lors du chargement du workflow {$execution->workflow_id} : " . $e->getMessage()
                    );
                }
            }

            return $workflows;
        }

        /**
         * Vérifie si un deal est dans une séquence active.
         */
        public function is_deal_in_any_workflow($deal_id) {
            ISPAG_Workflow_Logger::debug("Vérification si le deal {$deal_id} est dans une séquence quelconque");
            return $this->execution_repository->is_deal_in_workflow($deal_id);
        }

        /**
         * Récupère les workflows actifs pour un deal.
         */
        public function get_active_workflows_for_deal($deal_id) {
            ISPAG_Workflow_Logger::debug("Récupération des workflows actifs pour le deal {$deal_id}");
            return $this->get_workflows_for_deal($deal_id);
        }
    }
}