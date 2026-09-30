<?php
defined('ABSPATH') || exit;

/**
 * Création du schéma de base de données de ISPAG CRM.
 *
 * - À l'activation du plugin : install() est appelé (register_activation_hook).
 * - À chaque chargement : maybe_install() compare la version du schéma stockée en option
 *   à DB_VERSION et relance install() si elle diffère. Un plugin mis à jour par FTP
 *   (donc sans réactivation) crée ses tables manquantes dès la prochaine requête.
 *
 * Toutes les requêtes sont des CREATE TABLE IF NOT EXISTS (voir install/schema.php) :
 * sur un site existant, aucune table ni donnée n'est modifiée.
 *
 * Pour faire évoluer le schéma plus tard : ajouter la nouvelle table à schema.php, ou une
 * migration ALTER dans migrate(), puis incrémenter DB_VERSION.
 */
class ISPAG_CRM_Installer {

    const DB_VERSION = '1.2.0';
    const OPTION     = 'ispag_crm_db_version';

    /** Droits utilisés par ce plugin (voir grant_default_caps()). */
    const CAPS = ['manage_order', 'edit_supplier_order', 'read_orders'];

    /**
     * Droits du menu « CRM ISPAG » (liste des entreprises, Add Company). Rien ne les crée : sans eux le menu est invisible
     * et « Add Company » répond 403. Donnés à l'administrateur dans tous les cas, y compris sur un site déjà installé :
     * les actions correspondantes exigent déjà manage_options, donc cela n'ouvre rien de plus à un administrateur.
     */
    const ADMIN_EXTRA_CAPS = ['edit_company', 'add_company'];

    public static function init() {
        add_action('plugins_loaded', [self::class, 'maybe_install'], 5);
    }

    public static function maybe_install() {
        if (get_option(self::OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install() {
        global $wpdb;

        $schema  = require dirname(__DIR__) . '/install/schema.php';
        $charset = $wpdb->get_charset_collate();
        $ok      = true;

        $suppress = $wpdb->suppress_errors(true);
        foreach ($schema as $name => $sql) {
            $sql = str_replace(['{prefix}', '{charset}'], [$wpdb->prefix, $charset], $sql);
            if ($wpdb->query($sql) === false) {
                $ok = false;
                error_log('[ISPAG CRM] Création de la table ' . $wpdb->prefix . $name . ' impossible : ' . $wpdb->last_error);
            }
        }
        // Liens vers les entreprises : viag_id -> Id (une seule fois, tout ou rien)
        require_once __DIR__ . '/class-ispag-crm-company-link-migration.php';
        if (!ISPAG_Crm_Company_Link_Migration::run()) {
            $ok = false;
        }
        if (!self::seed()) {
            $ok = false;
        }
        $wpdb->suppress_errors($suppress);

        self::grant_default_caps();

        // On ne mémorise la version que si tout est passé : sinon on réessaie à la requête suivante.
        if ($ok) {
            update_option(self::OPTION, self::DB_VERSION);
        }
        return $ok;
    }

    /**
     * Valeurs initiales des tables de référence (install/seeds.php : ['table_sans_prefixe' => [ [colonne => valeur, …], … ]]).
     * Une table n'est remplie QUE si elle est vide : sur un site existant (production), rien n'est jamais ajouté ni modifié.
     */
    private static function seed() {
        global $wpdb;
        $dir  = dirname(__DIR__) . '/install';
        $sets = [];
        if (is_readable($dir . '/seeds.php')) {
            $sets = (array) require $dir . '/seeds.php';
        }
        // Gros jeux de données : un fichier install/seeds/<table_sans_prefixe>.php par table
        foreach ((array) glob($dir . '/seeds/*.php') as $file) {
            $sets[basename($file, '.php')] = require $file;
        }
        $ok = true;
        foreach ($sets as $name => $rows) {
            $table = $wpdb->prefix . $name;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }
            if ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") > 0) {
                continue;
            }
            foreach ($rows as $row) {
                if ($wpdb->insert($table, $row) === false) {
                    $ok = false;
                    error_log('[ISPAG CRM] Valeur initiale refusée dans ' . $table . ' : ' . $wpdb->last_error);
                }
            }
        }
        return $ok;
    }

    /**
     * Site neuf : ces droits n'existent nulle part, donc les pages affichent « accès restreint ».
     * On les donne au rôle administrateur, mais UNIQUEMENT s'il n'en a encore aucun : sur un site
     * existant (qui gère ses droits autrement, par un plugin de rôles par ex.), rien n'est touché.
     */
    private static function grant_default_caps() {
        $admin = get_role('administrator');
        if (!$admin) {
            return;
        }
        // Site neuf uniquement (aucun droit ISPAG encore) : les droits généraux.
        if (!$admin->has_cap('manage_order')) {
            foreach (self::CAPS as $cap) {
                $admin->add_cap($cap);
            }
        }
        foreach (self::ADMIN_EXTRA_CAPS as $cap) {
            if (!$admin->has_cap($cap)) {
                $admin->add_cap($cap);
            }
        }
    }
}
