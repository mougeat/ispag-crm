<?php
if (!class_exists('ISPAG_Workflow_Logger')) {
    class ISPAG_Workflow_Logger {
        private static $logger = null;
        private static $initialized = false;
        private const LOG_NAME = 'workflow';

        public static function init() {
            if (self::$initialized) {
                return;
            }
            if (class_exists('ISPAG_Logger')) {
                self::$logger = ISPAG_Logger::get_instance();
            }
            
            // On ne logue que si le logger parent est bien disponible
            if (self::$logger !== null) {
                $user_id = get_current_user_id();
                // self::$logger->log_user_action(self::LOG_NAME, 'logger_initialized', [], $user_id);
            }
            
            self::$initialized = true;
        }

        public static function set_enabled($enabled) {
            if (self::$logger !== null) {
                $user_id = get_current_user_id();
                self::$logger->log_user_action(self::LOG_NAME, 'logging_toggled', ['enabled' => $enabled], $user_id);
            }
        }

        public static function log($message, $level = 'INFO', $context = []) {
            if (!self::$initialized) {
                self::init();
            }

            // Si aucun logger n'est disponible, on arrête l'exécution proprement sans planter
            if (self::$logger === null) {
                return;
            }

            $user_id = get_current_user_id();

            switch ($level) {
                case 'DEBUG':
                case 'INFO':
                    self::$logger->log_user_action(self::LOG_NAME, $level . ': ' . $message, $context, $user_id);
                    break;
                case 'WARNING':
                case 'ERROR':
                    // Assurez-vous que la méthode existe sur votre ISPAG_Logger principal, 
                    // sinon utilisez log_user_action par sécurité
                    if (method_exists(self::$logger, 'log')) {
                        self::$logger->log(self::LOG_NAME, $level . ': ' . $message, $user_id);
                    } else {
                        self::$logger->log_user_action(self::LOG_NAME, $level . ': ' . $message, $context, $user_id);
                    }
                    break;
                default:
                    self::$logger->log_user_action(self::LOG_NAME, $level . ': ' . $message, $context, $user_id);
            }
        }

        public static function debug($message, $context = []) {
            self::log($message, 'DEBUG', $context);
        }

        public static function info($message, $context = []) {
            self::log($message, 'INFO', $context);
        }

        public static function warning($message, $context = []) {
            self::log($message, 'WARNING', $context);
        }

        public static function error($message, $context = []) {
            self::log($message, 'ERROR', $context);
        }
    }

    add_action('plugins_loaded', ['ISPAG_Workflow_Logger', 'init']);
}