<?php
/**
 * Class ISPAG_Workflow_Logger
 * Gère les logs pour le système de workflows.
 * Tous les logs sont écrits dans /wp-content/uploads/ispag-workflows.log
 */

if (!class_exists('ISPAG_Workflow_Logger')) {
    class ISPAG_Workflow_Logger {
        private static $log_file = 'ispag-workflows.log';
        private static $log_path;
        private static $enabled = true;
        private static $initialized = false;

        /**
         * Initialise le logger.
         */
        public static function init() {
            if (self::$initialized) {
                return;
            }

            $upload_dir = wp_upload_dir();
            self::$log_path = WP_CONTENT_DIR . '/'.self::$log_file;

            // Créer le fichier s'il n'existe pas
            if (!file_exists(self::$log_path)) {
                file_put_contents(self::$log_path, '');
                chmod(self::$log_path, 0644);
            }

            self::$initialized = true;
            self::log('Logger ISPAG Workflow initialisé', 'INFO');
        }

        /**
         * Active ou désactive les logs.
         */
        public static function set_enabled($enabled) {
            self::$enabled = $enabled;
            self::log("Logging " . ($enabled ? 'activé' : 'désactivé'), 'INFO');
        }

        /**
         * Écrit un message de log.
         */
        public static function log($message, $level = 'INFO', $context = []) {
            if (!self::$enabled || !self::$initialized) {
                return;
            }

            $timestamp = current_time('Y-m-d H:i:s');
            $context_str = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
            $log_entry = sprintf("[%s] [%s] %s%s\n", $timestamp, strtoupper($level), $message, $context_str);

            // Écrire dans le fichier de log
            file_put_contents(self::$log_path, $log_entry, FILE_APPEND | LOCK_EX);

            // Écrire aussi dans les logs PHP (optionnel)
            // error_log($log_entry);
        }

        /**
         * Log un message de debug.
         */
        public static function debug($message, $context = []) {
            self::log($message, 'DEBUG', $context);
        }

        /**
         * Log un message d'information.
         */
        public static function info($message, $context = []) {
            self::log($message, 'INFO', $context);
        }

        /**
         * Log un avertissement.
         */
        public static function warning($message, $context = []) {
            self::log($message, 'WARNING', $context);
        }

        /**
         * Log une erreur.
         */
        public static function error($message, $context = []) {
            self::log($message, 'ERROR', $context);
        }

        /**
         * Récupère le chemin du fichier de log.
         */
        public static function get_log_path() {
            return self::$log_path;
        }
    }

    // Initialiser le logger automatiquement
    add_action('plugins_loaded', ['ISPAG_Workflow_Logger', 'init']);
}