<?php
defined('ABSPATH') || exit;

/**
 * Migration unique : les liens vers une entreprise passent de « viag_id » à « Id » (ispag_companies.Id).
 *
 * Valeurs converties (ancienne valeur = viag_id, nouvelle = Id de la ligne portant ce viag_id) :
 *  - ispag_deals_list.associated_company_id           (liste « 12,45 »)
 *  - achats_liste_commande.AssociatedCompanyID        (projets)      et .ingenieur_id (bureau d'ingénieur)
 *  - usermeta « ispag_company_id »                    (entreprises d'un contact)
 *  - ispag_contact_notes.company_id                   (liste « 12,45 »)
 *  - ispag_companies_owners.company_id                (responsables)
 *  - ispag_user_priorities.entity_id (entity_type = company)
 *
 * Sécurités :
 *  - exécutée une seule fois (option ispag_company_link_migrated) ;
 *  - tout ou rien (transaction) : en cas d'erreur, rien n'est modifié et la migration sera retentée ;
 *  - chaque valeur modifiée est sauvegardée dans {prefix}ispag_company_link_backup (table, clé, colonne, ancienne valeur) ;
 *  - un viag_id sans entreprise correspondante est retiré du lien (l'ancienne valeur reste dans la sauvegarde).
 *
 * Non converties (déjà basées sur Id ou ambiguës) : ispag_companies_meta, remises, cycle de vie.
 */
class ISPAG_Crm_Company_Link_Migration {

    const FLAG = 'ispag_company_link_migrated';

    /** @return bool true si terminée (ou déjà faite), false si à retenter */
    public static function run() {
        global $wpdb;

        if (get_option(self::FLAG)) {
            return true;
        }

        $companies = $wpdb->prefix . 'ispag_companies';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $companies)) !== $companies) {
            return true; // rien à migrer, et on ne mémorise rien : la table n'existe pas encore
        }

        // viag_id => Id (viag_id > 0 ; en cas de doublon, la plus ancienne ligne)
        $map = [];
        foreach ((array) $wpdb->get_results("SELECT viag_id, MIN(Id) AS Id FROM {$companies} WHERE viag_id > 0 GROUP BY viag_id") as $r) {
            $map[(string) $r->viag_id] = (int) $r->Id;
        }
        if (!$map) {
            update_option(self::FLAG, ['date' => current_time('mysql'), 'note' => 'aucune entreprise avec viag_id : rien à convertir']);
            return true;
        }

        $backup = $wpdb->prefix . 'ispag_company_link_backup';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$backup}` (
            `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            `table_name` varchar(64) NOT NULL,
            `pk` varchar(64) NOT NULL,
            `col` varchar(64) NOT NULL,
            `old_value` text,
            `new_value` text,
            `migrated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tbl` (`table_name`)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate());

        $counts = [];
        $wpdb->query('START TRANSACTION');
        try {
            $counts['deals']   = self::convert_column($wpdb->prefix . 'ispag_deals_list', 'id', 'associated_company_id', $map, $backup);
            $counts['notes']   = self::convert_column($wpdb->prefix . 'ispag_contact_notes', 'id', 'company_id', $map, $backup);
            $counts['projects'] = self::convert_column($wpdb->prefix . 'achats_liste_commande', 'Id', 'AssociatedCompanyID', $map, $backup)
                                + self::convert_column($wpdb->prefix . 'achats_liste_commande', 'Id', 'ingenieur_id', $map, $backup);
            $counts['owners']  = self::convert_column($wpdb->prefix . 'ispag_companies_owners', 'id', 'company_id', $map, $backup);
            $counts['contacts'] = self::convert_column($wpdb->usermeta, 'umeta_id', 'meta_value', $map, $backup, "meta_key = 'ispag_company_id'");
            $counts['priorities'] = self::convert_priorities($map, $backup);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('[ISPAG CRM] Migration des liens entreprise (viag_id -> Id) annulée : ' . $e->getMessage());
            return false;
        }
        $wpdb->query('COMMIT');
        wp_cache_flush(); // les métadonnées de contacts ont été modifiées directement en base

        update_option(self::FLAG, ['date' => current_time('mysql'), 'converted' => $counts]);
        return true;
    }

    /** Convertit une colonne contenant un ou plusieurs viag_id (séparés par des virgules). Retourne le nombre de lignes modifiées. */
    private static function convert_column($table, $pk, $col, array $map, $backup, $extra_where = '') {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }
        if (!$wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE '" . esc_sql($col) . "'")) {
            return 0;
        }
        $where = "`{$col}` IS NOT NULL AND `{$col}` <> '' AND `{$col}` <> '0'" . ($extra_where ? " AND {$extra_where}" : '');
        $rows  = $wpdb->get_results("SELECT `{$pk}` AS pk, `{$col}` AS val FROM `{$table}` WHERE {$where}");
        $n = 0;
        foreach ((array) $rows as $row) {
            $new = self::convert_value((string) $row->val, $map);
            if ($new === (string) $row->val) {
                continue;
            }
            $ok = $wpdb->insert($backup, [
                'table_name' => $table, 'pk' => (string) $row->pk, 'col' => $col,
                'old_value' => $row->val, 'new_value' => $new, 'migrated_at' => current_time('mysql'),
            ]);
            if ($ok === false || $wpdb->update($table, [$col => $new], [$pk => $row->pk]) === false) {
                throw new Exception($table . '.' . $col . ' : ' . $wpdb->last_error);
            }
            $n++;
        }
        return $n;
    }

    /** « 123,456 » -> « 5,9 » ; les viag_id inconnus sont retirés ; valeur numérique seule inconnue -> « 0 ». */
    private static function convert_value($val, array $map) {
        $is_list = (strpos($val, ',') !== false);
        $out = [];
        foreach (explode(',', $val) as $tok) {
            $tok = trim($tok);
            if ($tok !== '' && isset($map[$tok])) {
                $out[] = $map[$tok];
            }
        }
        $out = array_values(array_unique($out));
        if (!$out) {
            return $is_list || !ctype_digit(trim($val)) ? '' : '0';
        }
        return implode(',', $out);
    }

    /** Priorités des entreprises : la clé primaire contient entity_id, on recrée donc les lignes. */
    private static function convert_priorities(array $map, $backup) {
        global $wpdb;
        $table = $wpdb->prefix . 'ispag_user_priorities';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` WHERE entity_type = 'company'", ARRAY_A);
        if (!$rows) {
            return 0;
        }
        if ($wpdb->query("DELETE FROM `{$table}` WHERE entity_type = 'company'") === false) {
            throw new Exception($table . ' : ' . $wpdb->last_error);
        }
        $n = 0;
        foreach ($rows as $row) {
            $old = (string) $row['entity_id'];
            if (isset($map[$old])) {
                $row['entity_id'] = $map[$old];
                $n++;
                $wpdb->insert($backup, ['table_name' => $table, 'pk' => $row['user_id'] . ':' . $old, 'col' => 'entity_id', 'old_value' => $old, 'new_value' => (string) $map[$old], 'migrated_at' => current_time('mysql')]);
            } else {
                $wpdb->insert($backup, ['table_name' => $table, 'pk' => $row['user_id'] . ':' . $old, 'col' => 'entity_id', 'old_value' => $old, 'new_value' => null, 'migrated_at' => current_time('mysql')]);
                continue; // viag_id sans entreprise : priorité retirée (sauvegardée)
            }
            if ($wpdb->replace($table, $row) === false) {
                throw new Exception($table . ' : ' . $wpdb->last_error);
            }
        }
        return $n;
    }
}
