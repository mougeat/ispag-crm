<?php
/**
 * Class ISPAG_Workflow
 * Représente un workflow (séquence) avec ses étapes et déclencheurs.
 */

if (!class_exists('ISPAG_Workflow')) {
    class ISPAG_Workflow {
        private $id;
        private $name;
        private $type; // "contact" ou "deal"
        private $is_active;
        private $working_hours;
        private $steps = [];
        private $triggers = [];

        /**
         * Constructeur.
         */
        public function __construct($post_id) {
            ISPAG_Workflow_Logger::debug("Chargement du workflow avec post_id: {$post_id}");

            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'ispag_workflow') {
                ISPAG_Workflow_Logger::error("Le post ID {$post_id} n'est pas un workflow valide");
                throw new InvalidArgumentException("Le post ID {$post_id} n'est pas un workflow valide.");
            }

            $this->id = $post_id;
            $this->name = $post->post_title;
            $this->type = get_post_meta($post_id, '_ispag_workflow_type', true) ?: 'contact';
            $this->is_active = get_post_meta($post_id, '_ispag_workflow_is_active', true) === '1';
            $this->working_hours = get_post_meta($post_id, '_ispag_workflow_working_hours', true) ?: '08:00-18:00';

            ISPAG_Workflow_Logger::debug(
                "Workflow chargé: ID={$this->id}, Name={$this->name}, Type={$this->type}, Active=" . ($this->is_active ? 'Yes' : 'No')
            );

            $this->load_steps();
            $this->load_triggers();
        }

        /**
         * Charge les étapes depuis les meta.
         */
        private function load_steps() {
            ISPAG_Workflow_Logger::debug("Chargement des étapes pour le workflow {$this->id}");

            $steps = get_post_meta($this->id, '_ispag_workflow_steps', true);
            if (is_array($steps)) {
                foreach ($steps as $index => $step_data) {
                    try {
                        $step = $this->create_step($step_data);
                        $this->steps[] = $step;
                        ISPAG_Workflow_Logger::debug(
                            "Étape chargée: Type={$step_data['type']}, Titre={$step_data['title']}",
                            ['step_index' => $index, 'step_data' => $step_data]
                        );
                    } catch (Exception $e) {
                        ISPAG_Workflow_Logger::error(
                            "Erreur lors du chargement de l'étape {$index} pour le workflow {$this->id}: " . $e->getMessage(),
                            ['step_data' => $step_data, 'error' => $e->getMessage()]
                        );
                    }
                }
            } else {
                ISPAG_Workflow_Logger::warning("Aucune étape trouvée pour le workflow {$this->id}");
            }
        }

        /**
         * Charge les déclencheurs depuis les meta.
         */
        private function load_triggers() {
            ISPAG_Workflow_Logger::debug("Chargement des déclencheurs pour le workflow {$this->id}");

            $triggers = get_post_meta($this->id, '_ispag_workflow_triggers', true);
            $triggers = maybe_unserialize($triggers); // ✅ Désérialiser si nécessaire

            ISPAG_Workflow_Logger::debug(
                "Valeur désérialisée de _ispag_workflow_triggers: " . print_r($triggers, true)
            );

            if (!is_array($triggers)) {
                ISPAG_Workflow_Logger::warning(
                    "Aucun déclencheur trouvé pour le workflow {$this->id} (valeur: " . print_r($triggers, true) . ")"
                );
                return;
            }

            foreach ($triggers as $index => $trigger_data) {
                try {
                    $trigger = $this->create_trigger($trigger_data);
                    $this->triggers[] = $trigger;
                } catch (Exception $e) {
                    ISPAG_Workflow_Logger::error(
                        "Erreur lors du chargement du déclencheur {$index}: " . $e->getMessage()
                    );
                }
            }
        }

        /**
         * Crée une étape en fonction de son type.
         */
        private function create_step($step_data) {
            ISPAG_Workflow_Logger::debug("Création d'une étape de type: {$step_data['type']}");

            switch ($step_data['type']) {
                case 'email':
                    return new ISPAG_Email_Step(
                        $step_data['title'],
                        $step_data['content'],
                        $step_data['delay'] ?? null
                    );
                case 'task':
                    return new ISPAG_Task_Step(
                        $step_data['title'],
                        $step_data['content'],
                        $step_data['delay'] ?? null
                    );
                case 'call':
                    return new ISPAG_Call_Step(
                        $step_data['title'],
                        $step_data['content'],
                        $step_data['delay'] ?? null
                    );
                default:
                    ISPAG_Workflow_Logger::error("Type d'étape inconnu: {$step_data['type']}");
                    throw new InvalidArgumentException("Type d'étape inconnu : {$step_data['type']}");
            }
        }

        /**
         * Crée un déclencheur en fonction de son type.
         */
        private function create_trigger($trigger_data) {
            ISPAG_Workflow_Logger::debug("Création d'un déclencheur de type: {$trigger_data['type']}");

            switch ($trigger_data['type']) {
                case 'status_change':
                    return new ISPAG_Status_Change_Trigger(
                        $trigger_data['from_status'] ?? null,
                        $trigger_data['to_status'] ?? null
                    );
                case 'email_response':
                    return new ISPAG_Email_Response_Trigger();
                case 'task_completed':
                    return new ISPAG_Task_Completed_Trigger();
                default:
                    ISPAG_Workflow_Logger::error("Type de déclencheur inconnu: {$trigger_data['type']}");
                    throw new InvalidArgumentException("Type de déclencheur inconnu : {$trigger_data['type']}");
            }
        }

        /**
         * Ajoute une étape.
         */
        public function add_step(ISPAG_Workflow_Step $step) {
            $this->steps[] = $step;
            ISPAG_Workflow_Logger::debug("Étape ajoutée au workflow {$this->id}");
        }

        /**
         * Ajoute un déclencheur.
         */
        public function add_trigger(ISPAG_Workflow_Trigger $trigger) {
            $this->triggers[] = $trigger;
            ISPAG_Workflow_Logger::debug("Déclencheur ajouté au workflow {$this->id}");
        }

        /**
         * Exécute le workflow pour une entité (group_ref).
         */
        public function execute_for_entity($group_ref) {
            ISPAG_Workflow_Logger::debug(
                "Exécution du workflow {$this->id} pour le group_ref: {$group_ref}"
            );

            if (!$this->is_active) {
                ISPAG_Workflow_Logger::warning(
                    "Workflow {$this->id} n'est pas actif, exécution ignorée pour le group_ref: {$group_ref}"
                );
                return;
            }

            $execution_repository = new ISPAG_Workflow_Execution();
            $executions = $execution_repository->is_deal_in_workflow($group_ref);
            $current_execution = null;

            foreach ($executions as $execution) {
                if ($execution->workflow_id == $this->id) {
                    $current_execution = $execution;
                    break;
                }
            }

            if (!$current_execution) {
                ISPAG_Workflow_Logger::warning(
                    "Aucune exécution trouvée pour le workflow {$this->id} et le group_ref: {$group_ref}. Ignoré."
                );
                return;
            }

            $current_step_index = $current_execution->current_step_index;
            if ($current_step_index >= count($this->steps)) {
                ISPAG_Workflow_Logger::warning(
                    "Toutes les étapes ont déjà été exécutées pour le workflow {$this->id} et le group_ref: {$group_ref}. Ignoré."
                );
                return;
            }

            $step = $this->steps[$current_step_index];

            // ✅ Utilisez les getters pour accéder aux propriétés
            if (!empty($step->get_delay())) { // ✅ Utilisez get_delay() au lieu de ->delay
                ISPAG_Workflow_Logger::debug(
                    "L'étape {$current_step_index} a un délai: {$step->get_delay()}. Elle sera exécutée par le Cron."
                );
                return;
            }

            if ($step->should_execute($group_ref, $this->type)) {
                ISPAG_Workflow_Logger::debug(
                    "Exécution de l'étape {$current_step_index}: {$step->get_title()}" // ✅ Utilisez get_title()
                );
                $step->execute($group_ref, $this->type);
                $next_step_index = $current_step_index + 1;
                $execution_repository->update_current_step($current_execution->id, $next_step_index);

                if ($next_step_index >= count($this->steps)) {
                    $execution_repository->complete_execution($current_execution->id);
                }
            }
        }

        /**
         * Vérifie si ce workflow doit être déclenché pour un changement de statut.
         */
        public function should_trigger_on_status_change($old_status, $new_status) {
            ISPAG_Workflow_Logger::debug(
                "Vérification des déclencheurs pour le workflow {$this->id} (statut: {$old_status} -> {$new_status})"
            );

            foreach ($this->triggers as $trigger) {
                ISPAG_Workflow_Logger::debug(
                    "Test du déclencheur: type={$trigger->get_type()}, from_status=" .
                    (isset($trigger->from_status) ? $trigger->from_status : 'NULL') .
                    ", to_status=" . (isset($trigger->to_status) ? $trigger->to_status : 'NULL')
                );

                if ($trigger->is_status_change_trigger($old_status, $new_status)) {
                    ISPAG_Workflow_Logger::debug("✅ Déclencheur correspondant trouvé !");
                    return true;
                }
            }

            ISPAG_Workflow_Logger::debug("❌ Aucun déclencheur correspondant trouvé.");
            return false;
        }

        /**
         * Vérifie si le workflow doit être interrompu.
         */
        public function should_interrupt($entity_id) {
            foreach ($this->triggers as $trigger) {
                if ($trigger->is_met($entity_id, $this->type)) {
                    ISPAG_Workflow_Logger::debug(
                        "Workflow {$this->id} doit être interrompu pour l'entité {$entity_id} (déclencheur: " . $trigger->get_type() . ")",
                        ['entity_id' => $entity_id, 'trigger_type' => $trigger->get_type()]
                    );
                    return true;
                }
            }
            return false;
        }

        /**
         * Déclenche le workflow lors d'un changement de statut.
         */
        public function trigger_on_status_change($entity_id, $old_status, $new_status) {
            ISPAG_Workflow_Logger::debug(
                "Déclenchement du workflow {$this->id} pour l'entité {$entity_id} (statut: {$old_status} -> {$new_status})"
            );

            foreach ($this->triggers as $trigger) {
                if ($trigger->is_status_change_trigger($old_status, $new_status)) {
                    ISPAG_Workflow_Logger::info(
                        "Workflow {$this->id} déclenché par le déclencheur de type: " . $trigger->get_type()
                    );
                    // $this->execute_for_entity($entity_id);
                }
            }
        }

        // Getters
        public function get_id() { return $this->id; }
        public function get_name() { return $this->name; }
        public function get_type() { return $this->type; }
        public function is_active() { return $this->is_active; }
        public function get_steps() { return $this->steps; }
        public function get_triggers() { return $this->triggers; }
    }
}