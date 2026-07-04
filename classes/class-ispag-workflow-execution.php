<?php
/**
 * Class ISPAG_Workflow_Execution
 * Gère les exécutions de workflows pour les deals.
 */

if (!class_exists('ISPAG_Workflow_Execution')) {
    class ISPAG_Workflow_Execution {
        private $wpdb;
        private $table_name;

        public function __construct() {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->table_name = 'wor9711_ispag_workflow_executions';
            ISPAG_Workflow_Logger::debug("ISPAG_Workflow_Execution initialisé");
        }

        /**
         * Démarre une exécution de workflow pour un deal (group_ref).
        */
       public function start_execution($workflow_id, $group_ref) {
            ISPAG_Workflow_Logger::debug(
                "Démarrage d'une exécution pour le workflow {$workflow_id} et le group_ref: {$group_ref}"
            );

            $workflow = new ISPAG_Workflow($workflow_id);
            $steps = $workflow->get_steps();

            // ✅ Utilisez les getters pour accéder aux propriétés
            $next_step_due = null;
            if (!empty($steps[0]) && !empty($steps[0]->get_delay())) { // ✅ Utilisez get_delay()
                $delay = $this->parse_delay($steps[0]->get_delay()); // ✅ Utilisez get_delay()
                $next_step_due = date('Y-m-d H:i:s', strtotime("+{$delay} seconds"));
                ISPAG_Workflow_Logger::debug(
                    "Première étape a un délai: {$steps[0]->get_delay()}. Exécution prévue le: {$next_step_due}"
                );
            }

            // Vérifiez si une exécution existe déjà
            $existing = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table_name}
                    WHERE workflow_id = %d AND deal_id = %s",
                    $workflow_id, $group_ref
                )
            );

            if ($existing) {
                ISPAG_Workflow_Logger::debug("Exécution existante trouvée (ID: {$existing->id}), mise à jour");
                $result = $this->wpdb->update(
                    $this->table_name,
                    [
                        'status' => 'running',
                        'current_step_index' => 0,
                        'started_at' => current_time('mysql'),
                        'next_step_due' => $next_step_due, // ✅ Stocker la date d'exécution
                        'completed_at' => null,
                        'interrupted_reason' => null
                    ],
                    ['id' => $existing->id],
                    ['%s', '%d', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
                return $existing->id;
            } else {
                ISPAG_Workflow_Logger::debug("Création d'une nouvelle exécution");
                $result = $this->wpdb->insert(
                    $this->table_name,
                    [
                        'workflow_id' => $workflow_id,
                        'deal_id' => $group_ref,
                        'current_step_index' => 0,
                        'status' => 'running',
                        'started_at' => current_time('mysql'),
                        'next_step_due' => $next_step_due // ✅ Stocker la date d'exécution
                    ],
                    ['%d', '%s', '%d', '%s', '%s', '%s']
                );
                return $result ? $this->wpdb->insert_id : false;
            }
        }

        // Méthode pour parser le délai (ex: "10 days", "1 hour")
        private function parse_delay($delay) {
            if (empty($delay)) return 0;

            $parts = explode(' ', $delay);
            $value = (int) $parts[0];
            $unit = $parts[1] ?? '';

            switch ($unit) {
                case 'day': case 'days': return $value * DAY_IN_SECONDS;
                case 'hour': case 'hours': return $value * HOUR_IN_SECONDS;
                case 'minute': case 'minutes': return $value * MINUTE_IN_SECONDS;
                default: return 0;
            }
        }

        /**
         * Met à jour l'étape actuelle d'une exécution.
         */
        public function update_current_step($execution_id, $step_index) {
            ISPAG_Workflow_Logger::debug(
                "Mise à jour de l'étape actuelle pour l'exécution {$execution_id}: nouvelle étape = {$step_index}"
            );

            $result = $this->wpdb->update(
                $this->table_name,
                ['current_step_index' => $step_index],
                ['id' => $execution_id],
                ['%d'],
                ['%d']
            );

            if ($result) {
                ISPAG_Workflow_Logger::debug("Étape mise à jour avec succès");
            } else {
                ISPAG_Workflow_Logger::error("Échec de la mise à jour de l'étape");
            }

            return $result;
        }

        /**
         * Termine une exécution de workflow.
         */
        public function complete_execution($execution_id) {
            ISPAG_Workflow_Logger::debug("Terminaison de l'exécution {$execution_id}");

            $result = $this->wpdb->update(
                $this->table_name,
                [
                    'status' => 'completed',
                    'completed_at' => current_time('mysql')
                ],
                ['id' => $execution_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($result) {
                ISPAG_Workflow_Logger::info("Exécution {$execution_id} marquée comme terminée");
            } else {
                ISPAG_Workflow_Logger::error("Échec de la terminaison de l'exécution {$execution_id}");
            }

            return $result;
        }

        /**
         * Interrompt une exécution de workflow.
         */
        public function interrupt_execution($execution_id, $reason) {
            ISPAG_Workflow_Logger::debug(
                "Interruption de l'exécution {$execution_id}, raison: {$reason}"
            );

            $result = $this->wpdb->update(
                $this->table_name,
                [
                    'status' => 'interrupted',
                    'interrupted_reason' => $reason,
                    'completed_at' => current_time('mysql')
                ],
                ['id' => $execution_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($result) {
                ISPAG_Workflow_Logger::info("Exécution {$execution_id} interrompue");
            } else {
                ISPAG_Workflow_Logger::error("Échec de l'interruption de l'exécution {$execution_id}");
            }

            return $result;
        }

        /**
         * Vérifie si un deal (group_ref) est dans une séquence active.
         */
        public function is_deal_in_workflow($group_ref) {
            ISPAG_Workflow_Logger::debug("Vérification si le group_ref {$group_ref} est dans une séquence active");

            $executions = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table_name}
                    WHERE deal_id = %s AND status IN ('pending', 'running')",
                    $group_ref
                )
            );

            return $executions;
        }

        /**
         * Récupère toutes les exécutions actives.
         */
        public function get_active_executions() {
            ISPAG_Workflow_Logger::debug("Récupération de toutes les exécutions actives");

            $executions = $this->wpdb->get_results(
                "SELECT * FROM {$this->table_name}
                 WHERE status IN ('pending', 'running')
                 ORDER BY started_at DESC"
            );

            ISPAG_Workflow_Logger::debug("Trouvé " . count($executions) . " exécutions actives");
            return $executions;
        }

        /**
         * Récupère les exécutions pour un workflow spécifique.
         */
        public function get_executions_by_workflow($workflow_id) {
            ISPAG_Workflow_Logger::debug("Récupération des exécutions pour le workflow {$workflow_id}");

            $executions = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table_name}
                     WHERE workflow_id = %d
                     ORDER BY started_at DESC",
                    $workflow_id
                )
            );

            ISPAG_Workflow_Logger::debug("Trouvé " . count($executions) . " exécutions pour le workflow {$workflow_id}");
            return $executions;
        }

        /**
         * Récupère les exécutions pour un deal spécifique.
         */
        public function get_executions_by_deal($deal_id) {
            ISPAG_Workflow_Logger::debug("Récupération des exécutions pour le deal {$deal_id}");

            $executions = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table_name}
                     WHERE deal_id = %d
                     ORDER BY started_at DESC",
                    $deal_id
                )
            );

            ISPAG_Workflow_Logger::debug("Trouvé " . count($executions) . " exécutions pour le deal {$deal_id}");
            return $executions;
        }
    }
}