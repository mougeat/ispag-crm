<?php
/**
 * Class ISPAG_Variable_Replacer
 * Gère le remplacement des variables dynamiques dans le contenu des étapes.
 */

if (!class_exists('ISPAG_Variable_Replacer')) {
    class ISPAG_Variable_Replacer {
        /**
         * Remplace toutes les variables dynamiques dans un contenu.
         *
         * @param string $content Contenu avec des variables (ex: "Bonjour {{contact.firstname}}").
         * @param string $group_ref Référence du groupe de deal.
         * @param string $entity_type Type d'entité ('deal' ou 'contact').
         * @return string Contenu avec les variables remplacées.
         */
        public static function replace_variables($content, $group_ref, $entity_type) {
            ISPAG_Workflow_Logger::debug(
                "Remplacement des variables dans le contenu pour group_ref: {$group_ref}, type: {$entity_type}"
            );

            // Récupérer les données nécessaires
            $deal_data = self::get_deal_data($group_ref);
            $contact_data = null;
            $company_data = null;

            // Si l'entité est un deal, récupérer les contacts et entreprises associés
            if ($entity_type === 'deal' && $deal_data) {
                if (!empty($deal_data->associated_contact_ids)) {
                    $contact_ids = explode(',', $deal_data->associated_contact_ids);
                    if (!empty($contact_ids[0])) {
                        $contact_data = self::get_contact_data($contact_ids[0]);
                    }
                }
                if (!empty($deal_data->associated_company_id)) {
                    $company_data = self::get_company_data($deal_data->associated_company_id);
                }
            }

            // Remplacer les variables
            $content = self::replace_deal_variables($content, $deal_data);
            $content = self::replace_contact_variables($content, $contact_data);
            $content = self::replace_company_variables($content, $company_data);

            return $content;
        }

        /**
         * Remplace les variables liées au deal.
         */
        private static function replace_deal_variables($content, $deal) {
            if (!$deal) {
                return $content;
            }

            $replacements = [
                '{{deal.name}}' => $deal->project_name ?? '',
                '{{deal.id}}' => $deal->id ?? '',
                '{{deal.group_ref}}' => $deal->deal_group_ref ?? '',
                '{{deal.status}}' => $deal->current_stage_key ?? '',
                '{{deal_name}}' => $deal->project_name ?? '', // ✅ Ajout pour {{deal_name}}
            ];

            foreach ($replacements as $placeholder => $value) {
                $content = str_replace($placeholder, $value, $content);
            }

            return $content;
        }

        /**
         * Remplace les variables liées au contact.
         */
        private static function replace_contact_variables($content, $contact) {
            if (!$contact) {
                return $content;
            }

            $replacements = [
                '{{contact.firstname}}' => $contact->first_name ?? '',
                '{{contact.lastname}}' => $contact->last_name ?? '',
                '{{contact.fullname}}' => ($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''),
                '{{contact.email}}' => $contact->user_email ?? '',
                '{{contact.phone}}' => get_user_meta($contact->ID, 'phone', true) ?? '',
                '{{contact.firstname}}' => $contact->first_name ?? '', // ✅ Pour {{contact.firstname}}
                '{{contact.lastname}}' => $contact->last_name ?? '', // ✅ Pour {{contact.lastname}}
            ];

            foreach ($replacements as $placeholder => $value) {
                $content = str_replace($placeholder, $value, $content);
            }

            return $content;
        }

        /**
         * Remplace les variables liées à l'entreprise.
         */
        private static function replace_company_variables($content, $company) {
            if (!$company) {
                return $content;
            }

            $replacements = [
                '{{company.name}}' => $company->title ?? '',
                '{{company.id}}' => $company->id ?? '',
                '{{company.mail}}' => $company->company_mail ?? '',
                '{{company.phone}}' => $company->company_phone ?? '',
                '{{company_name}}' => $company->title ?? '', // ✅ Pour {{company_name}}
            ];

            foreach ($replacements as $placeholder => $value) {
                $content = str_replace($placeholder, $value, $content);
            }

            return $content;
        }

        /**
         * Récupère les données du deal via group_ref.
         */
        private static function get_deal_data($group_ref) {
            global $wpdb;
            $table_name = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            $deal = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE deal_group_ref = %s", $group_ref)
            );
            ISPAG_Workflow_Logger::debug("Deal récupéré: " . ($deal ? "ID={$deal->id}" : "Aucun deal trouvé"));
            return $deal;
        }

        /**
         * Récupère les données du contact via son ID.
         */
        private static function get_contact_data($contact_id) {
            $contact = get_userdata($contact_id);
            ISPAG_Workflow_Logger::debug("Contact récupéré: " . ($contact ? "ID={$contact->ID}" : "Aucun contact trouvé"));
            return $contact;
        }

        /**
         * Récupère les données de l'entreprise via son ID.
         */
        private static function get_company_data($company_id) {
            global $wpdb;
            $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;
            $company = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $company_id)
            );
            ISPAG_Workflow_Logger::debug("Entreprise récupérée: " . ($company ? "ID={$company->id}" : "Aucune entreprise trouvée"));
            return $company;
        }
    }
}