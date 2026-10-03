<?php
defined('ABSPATH') || exit;

/**
 * Classe de secours pour ISPAG_Logger.
 *
 * Le vrai ISPAG_Logger (« classe centrale » de journalisation) est fourni par un autre plugin ISPAG qui ne fait pas
 * partie de ces dépôts. Sans lui, achats / tank-builder / crm provoquent une erreur fatale.
 *
 * Ce fichier n'est chargé QUE si la vraie classe est introuvable au moment de son premier usage
 * (autoloader enregistré à plugins_loaded, donc après tous ceux des autres plugins) : sur un site qui a le vrai
 * logger, il n'est jamais chargé. La version de secours ne fait rien (les ~1 300 appels de journalisation ne coûtent
 * presque rien), sauf écrire dans le journal PHP quand WP_DEBUG_LOG est actif.
 */
if (!class_exists('ISPAG_Logger', false)) {
    class ISPAG_Logger {
        private static $instance = null;

        public static function get_instance() {
            if (self::$instance === null) self::$instance = new self();
            return self::$instance;
        }

        public function log(...$args)            { $this->write('log', $args); }
        public function log_error(...$args)      { $this->write('error', $args); }
        public function info(...$args)           { $this->write('info', $args); }
        public function log_user_action(...$args){ /* trop verbeux : ignoré */ }
        public function log_db_change(...$args)  { /* trop verbeux : ignoré */ }

        private function write($level, array $args) {
            if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
            $module  = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
            $message = isset($args[1]) ? (is_scalar($args[1]) ? (string) $args[1] : wp_json_encode($args[1])) : '';
            error_log("[ISPAG][$level][$module] $message");
        }
    }
}
