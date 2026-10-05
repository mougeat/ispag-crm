<?php
/**
 * Plugin Name: ISPAG CRM Manager
 * Description: Contact management and lead tracking (CRM) system for ISPAG users and businesses.
 * Version: 4.1.0
 * Author: Cyril Barthel
 */

if (!defined('ABSPATH')) {
    die;
}

/**
 * Les traductions FR / DE sont actives. Pour les désactiver (tout en anglais) : add_filter('ispag_disable_translations', '__return_true');
 */
add_filter('override_load_textdomain', function ($override, $domain) {
    if (in_array($domain, ['creation-reservoir', 'ispag-crm', 'ispag'], true) && apply_filters('ispag_disable_translations', false)) {
        return true;
    }
    return $override;
}, 10, 2);

// ----------------------------------------------------------------------------
// 1. CONSTANTES ET ENVIRONNEMENT
// ----------------------------------------------------------------------------
define( 'ISPAG_CRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ISPAG_CRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Logger : chargé une seule fois, tôt
add_action('plugins_loaded', function() {
    require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-logger.php';
    ISPAG_Workflow_Logger::init();
});

crm_ispag_load_env( ISPAG_CRM_PLUGIN_DIR . '.env' );

function crm_ispag_load_env( $path ) {
    if ( ! file_exists( $path ) ) return;
    $lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    foreach ( $lines as $line ) {
        if ( strpos( trim( $line ), '#' ) === 0 ) continue;
        list( $name, $value ) = explode( '=', $line, 2 );
        $name  = trim( $name );
        $value = trim( $value );
        if ( ! getenv( $name ) ) {
            putenv( "$name=$value" );
            $_ENV[$name] = $value;
        }
    }
}

// Fichier dummy pour traduire les textes de la base de donnée
require_once plugin_dir_path(__FILE__) . 'classes/helpers/ispag-translations-support.php';

add_action('plugins_loaded', 'ispag_crm_load_textdomain');

/**
 * Traductions FR / DE (fichiers dans languages/ : <domaine>-fr_FR.mo, <domaine>-de_DE.mo ; générés par tools/i18n/build.py d'ISPAG Project Manager).
 * Toute variante de langue du site est couverte : fr_CH, fr_BE… utilisent le français ; de_CH, de_DE_formal, de_AT… l'allemand.
 * Les textes de base sont en anglais : sans fichier pour la langue du site, l'anglais est affiché.
 * Le chargement est refait quand la langue change (Polylang la fixe après le chargement des plugins, switch_to_locale…).
 */
if (!function_exists('ispag_i18n_register_dir')) {
    /** Dossiers languages/ enregistrés par les plugins et le thème ISPAG. */
    function ispag_i18n_dirs($add = null) {
        static $dirs = [];
        if ($add !== null && !in_array($add, $dirs, true)) $dirs[] = $add;
        return $dirs;
    }
    function ispag_i18n_register_dir($dir) {
        ispag_i18n_dirs($dir);
        if (!did_action('ispag_i18n_hooked')) {
            do_action('ispag_i18n_hooked');
            add_action('plugins_loaded', 'ispag_i18n_reload', 20);
            add_action('after_setup_theme', 'ispag_i18n_reload', 20);
            add_action('init', 'ispag_i18n_reload', 1);
            add_action('pll_language_defined', 'ispag_i18n_reload', 1);
            add_action('change_locale', 'ispag_i18n_reload', 20);
            add_action('restore_previous_locale', 'ispag_i18n_reload', 20);
        }
    }
    /** (Re)charge les traductions pour la langue courante, uniquement si elle a changé depuis le dernier chargement. */
    function ispag_i18n_reload() {
        static $done = null;
        $locale   = determine_locale();
        $fallback = ['fr' => 'fr_FR', 'de' => 'de_DE'][substr($locale, 0, 2)] ?? '';
        $signature = $locale . '|' . implode(',', ispag_i18n_dirs()); // langue + dossiers connus (le thème s'enregistre après les plugins)
        if ($done === $signature) return;
        if ($done !== null) {
            foreach (ispag_i18n_dirs() as $dir) {
                foreach ((array) glob(rtrim($dir, '/\\') . '/*-{fr_FR,de_DE}.mo', GLOB_BRACE) as $mo) {
                    unload_textdomain(preg_replace('/-(fr_FR|de_DE)\.mo$/', '', basename($mo)), true);
                }
            }
        }
        $done = $signature;
        if ($fallback === '') return;
        foreach (ispag_i18n_dirs() as $dir) {
            foreach ((array) glob(rtrim($dir, '/\\') . '/*-' . $fallback . '.mo') as $mo) {
                $domain = basename($mo, '-' . $fallback . '.mo');
                $exact  = rtrim($dir, '/\\') . '/' . $domain . '-' . $locale . '.mo';
                load_textdomain($domain, is_readable($exact) ? $exact : $mo);
            }
        }
    }
}

ispag_i18n_register_dir(__DIR__ . '/languages');

/** Traduit un libellé stocké en base (étapes, statuts, phases…) : le texte anglais d'origine sert de clé dans languages/ispag-crm-*.mo. */
if (!function_exists('ispag_crm_db_label')) {
    function ispag_crm_db_label($label) {
        return (is_string($label) && $label !== '') ? __($label, 'ispag-crm') : $label;
    }
}
require_once __DIR__ . '/includes/js-strings.php';

function ispag_crm_load_textdomain() {
    ispag_i18n_reload();
}

// ----------------------------------------------------------------------------
// 2. AUTOLOADER (Standard ISPAG)
// ----------------------------------------------------------------------------
// Classes Workflow chargées explicitement AVANT l'autoloader car elles ont
// des dépendances d'ordre entre elles (Step/Trigger doivent exister avant
// que Workflow/Execution ne soient parsées). Ne pas les re-require ailleurs
// dans ce fichier : c'était fait 3x avant, ça masque un vrai souci d'ordre
// de chargement si jamais l'un de ces require échoue silencieusement.
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-step.php';
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-trigger.php';
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-execution.php';
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow.php';

require_once ISPAG_CRM_PLUGIN_DIR . 'ispag-attachments-init.php';
require_once ISPAG_CRM_PLUGIN_DIR . 'ispag-whatsapp-init.php';


spl_autoload_register(function($class) {
    $prefix = 'ISPAG_';
    if (strpos($class, $prefix) !== 0) return;

    $class_name = strtolower(str_replace('_', '-', $class));
    $file_name  = 'class-' . $class_name . '.php';

    // Liste des fichiers déjà chargés
    static $loaded_files = [];

    $dirs = [
        ISPAG_CRM_PLUGIN_DIR . 'classes/',
        ISPAG_CRM_PLUGIN_DIR . 'classes/admin/',
    ];

    foreach ($dirs as $dir) {
        $file = $dir . $file_name;
        if (file_exists($file) && !isset($loaded_files[$file])) {
            require_once $file;
            $loaded_files[$file] = true;
            return;
        }
    }
});

// ----------------------------------------------------------------------------
// 3. ACTIVATION DU PLUGIN
// ----------------------------------------------------------------------------

// Mise à jour depuis une branche GitHub (jeton + branche : wp-config.php ou Outils → Updates ISPAG ; « main » par défaut)
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-github-updater.php';
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-crm');

// Schéma de base de données : créé à l'activation, et re-vérifié à chaque chargement si la version change
register_activation_hook(__FILE__, ['ISPAG_CRM_Installer', 'install']);
ISPAG_CRM_Installer::init();

// ISPAG_Logger : le vrai (classes/class-ispag-logger.php d'ISPAG Project Manager, s'il est présent) passe en premier ;
// sinon classe de secours. Enregistré dès le chargement : l'activation du plugin utilise déjà le logger.
spl_autoload_register(function ($class) {
    if ($class !== 'ISPAG_Logger') return;
    $real = defined('ISPAG_PROJECT_MANAGER_DIR') ? ISPAG_PROJECT_MANAGER_DIR . 'classes/class-ispag-logger.php' : '';
    if ($real && is_readable($real)) { require_once $real; return; }
    require_once ISPAG_CRM_PLUGIN_DIR . 'install/fallback-logger.php';
});


// Les pages du CRM sont des modèles de page du thème (créées par le thème). Le plugin, lui, ajoute des adresses
// /deal/, /contact/, /company/ qui donnent une 404 tant que les permaliens ne sont pas rafraîchis.
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-page-installer.php';
register_activation_hook(__FILE__, ['ISPAG_Page_Installer', 'schedule_flush']);

// 2. Enregistrer le hook d'activation (s'exécute uniquement au clic sur "Activer")
register_activation_hook(__FILE__, ['ISPAG_Notifications_Manager', 'activate']);

// 3. Enregistrer le hook de désactivation (s'exécute au clic sur "Désactiver")
register_deactivation_hook(__FILE__, ['ISPAG_Notifications_Manager', 'deactivate']);


function ispag_crm_activate() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // Les tables (dont ispag_companies : clients, fournisseurs, ingénieurs) sont créées par ISPAG_CRM_Installer (install/schema.php).
    ISPAG_CRM_Installer::install();

    if ( class_exists( 'ISPAG_Status_Manager' ) ) {
        ISPAG_Status_Manager::insert_initial_data();
    }

    if ( class_exists( 'ISPAG_Reminder_Cron' ) ) {
        ISPAG_Reminder_Cron::schedule_reminder_check();
    }

    // Création de la table pour les exécutions de workflows
    $table_workflow_executions = 'wor9711_ispag_workflow_executions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql_workflow_executions = "CREATE TABLE {$table_workflow_executions} (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        workflow_id bigint UNSIGNED NOT NULL,
        deal_id bigint UNSIGNED NOT NULL,
        current_step_index int NOT NULL DEFAULT 0,
        status enum('pending','running','completed','interrupted') NOT NULL DEFAULT 'pending',
        started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at datetime DEFAULT NULL,
        interrupted_reason varchar(255) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY workflow_deal_idx (workflow_id, deal_id),
        KEY deal_idx (deal_id),
        KEY status_idx (status)
    ) {$charset_collate};";

    dbDelta($sql_workflow_executions);
}
register_activation_hook( __FILE__, 'ispag_crm_activate' );

// ----------------------------------------------------------------------------
// 4. INITIALISATION DES SERVICES (Core, AJAX, REST)
// ----------------------------------------------------------------------------

/**
 * Registre central des instances de services.
 *
 * Avant : `new $class()` était appelé ici ET re-appelé plus loin
 * (wp_enqueue_scripts, rest_api_init) pour ISPAG_Crm_Contacts_Repository,
 * ce qui double les hooks internes si le constructeur fait des
 * add_action/add_filter (emails/notes en double, AJAX répondant 2x...).
 *
 * Maintenant : une seule instance par classe, réutilisable via
 * ispag_crm_get_instance().
 *
 * @return array<string, object> class_name => instance
 */
function ispag_run_crm_manager() {
    static $instances = [];

    if ( ! empty( $instances ) ) {
        return $instances; // déjà initialisé, on ne refait rien
    }

    $classes = [
        'ISPAG_Crm_Contacts_Repository',
        'ISPAG_Entreprise_Manager',
        'ISPAG_Contact_Manager',
        'ISPAG_Status_Manager',
        'ISPAG_Contact_Ajax_Handler',
        'ISPAG_Note_Manager',
        'ISPAG_Eml_Builder',
        'ISPAG_Crm_Deal_Model',
        'ISPAG_Company_Importer',
        'ISPAG_Crm_Company_Repository',
        'ISPAG_Crm_Company_Modal',
        'ISPAG_Crm_Company_Creator',
        'ISPAG_Crm_Supplier_Tab',
        'ISPAG_Crm_Reference_Tables',
        'ISPAG_Crm_Contact_Modal',
        'ISPAG_Template_Repository',
        'ISPAG_Template_AJAX',
        'ISPAG_Reminder_Cron',
        'ISPAG_Brevo_Cron_Sync',
        'ISPAG_Cron_Task_Reminder',
        'ISPAG_Cron_Weekly_Deal_Report',
        'ISPAG_Cron_Contact_Health',
        'ISPAG_Crm_Follow_Up_Settings',
        'ISPAG_Crm_Decision_Date',
        'ISPAG_Cron_Lost_Deals_Reporting',
        'ISPAG_Cron_Contact_Matcher',
        'ISPAG_Cron_Lifecycle',
        'ISPAG_Cron_LeadStatus',
        'ISPAG_CSV_Importer',
        'ISPAG_Baikal_Sync',
        'ISPAG_Crm_Project_Associations',
        'ISPAG_Sequence_Admin',
        'ISPAG_Sequence_Repository',
        'ISPAG_WebPush_Handler',
        'ISPAG_Notifications_Manager',
        'ISPAG_Notifications_Renderer',
        'ISPAG_Simap_Service',
        'Ispag_Agent_Commercial_API',
        'ISPAG_Mail_Template_API',

        'ISPAG_Workflow_CPT',
        'ISPAG_Workflow_Meta_Box',
        'ISPAG_Workflow_Execution',
        'ISPAG_Workflow_Admin_Page',
        'ISPAG_User_Display_Name_Regenerator',
        'ISPAG_Crm_Discount_Manager',
        'ISPAG_Crm_Lifecycle_Manager',
    ];

    foreach ( $classes as $class ) {
        if ( class_exists( $class ) && ! isset( $instances[ $class ] ) ) {
            $instances[ $class ] = new $class();
        }
    }

    // Initialisation explicite de la classe de rendu (au cas où)
    if ( isset( $instances['ISPAG_Notifications_Renderer'] ) ) {
        ISPAG_Notifications_Renderer::init();
    }

    // Initialisation explicite de la classe de rendu (au cas où)
    if ( isset( $instances['ISPAG_Mail_Template_API'] ) ) {
        ISPAG_Mail_Template_API::init();
    }

    // Gestionnaire de workflows (singleton propre, pas de doublon possible)
    ISPAG_Workflow_Manager::get_instance();

    if ( class_exists( 'ISPAG_Crm_Deals_Repository' ) ) {
        $instances['ISPAG_Crm_Deals_Repository'] = new ISPAG_Crm_Deals_Repository();
        $deals_repo = $instances['ISPAG_Crm_Deals_Repository'];
        add_action( 'wp_ajax_ispag_update_deal_stage', array( $deals_repo, 'ispag_ajax_handle_deal_stage_update' ) );
        add_action( 'wp_ajax_ispag_kanban_load_more', array( $deals_repo, 'ajax_kanban_load_more' ) );
        add_action( 'wp_ajax_ispag_bulk_update_deals', array( $deals_repo, 'ispag_handle_bulk_deal_update' ) );
    }

    if ( class_exists( 'ISPAG_Crm_Gemini' ) ) ISPAG_Crm_Gemini::init();
    if ( class_exists( 'ISPAG_Crm_Mistral' ) ) ISPAG_Crm_Mistral::init();

    add_action( 'rest_api_init', function() use ( &$instances ) {
        // On réutilise les instances déjà créées plutôt que d'en recréer
        $contacts_repo = $instances['ISPAG_Crm_Contacts_Repository'] ?? new ISPAG_Crm_Contacts_Repository();
        $notes_repo    = $instances['ISPAG_Note_Manager'] ?? new ISPAG_Note_Manager();

        $handlers = [
            new ISPAG_Brevo_Webhook_Handler( $contacts_repo, $notes_repo ),
            new ISPAG_Iphone_Shortcut_Webhook_Handler( $contacts_repo, $notes_repo ),
            new ISPAG_Mailgun_Webhook_Handler( $contacts_repo, $notes_repo ),
        ];

        foreach ( $handlers as $h ) {
            if ( method_exists( $h, 'register_routes' ) ) {
                $h->register_routes();
            }
        }
    } );

    return $instances;
}
add_action( 'plugins_loaded', 'ispag_run_crm_manager' );

/**
 * Accès à une instance de service déjà initialisée par ispag_run_crm_manager(),
 * pour éviter tout `new` sauvage ailleurs dans le plugin.
 *
 * Exemple : $repo = ispag_crm_get_instance('ISPAG_Crm_Contacts_Repository');
 */
function ispag_crm_get_instance( string $class_name ) {
    $instances = ispag_run_crm_manager();
    return $instances[ $class_name ] ?? null;
}

// ----------------------------------------------------------------------------
// 5. UPLOADS & REWRITE RULES
// ----------------------------------------------------------------------------
function ispag_allow_xlsx_upload( $mimes ) {
    $mimes['msg']  = 'application/vnd.ms-outlook';
    $mimes['eml']  = 'message/rfc822';
    $mimes['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $mimes['xls']  = 'application/vnd.ms-excel';
    return $mimes;
}
add_filter( 'upload_mimes', 'ispag_allow_xlsx_upload' );

function ispag_disable_real_mime_check( $data, $file, $filename, $mimes ) {
    $ext = pathinfo( $filename, PATHINFO_EXTENSION );
    if ( in_array( $ext, ['xlsx', 'xls', 'eml'] ) ) {
        if ($ext === 'eml') {
            $data['type'] = 'message/rfc822';
        } elseif ($ext === 'xlsx') {
            $data['type'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $data['type'] = 'application/vnd.ms-excel';
        }
        $data['ext']  = $ext;
        $data['proper_filename'] = $filename;
    }
    return $data;
}
add_filter( 'wp_check_filetype_and_ext', 'ispag_disable_real_mime_check', 10, 4 );

add_action( 'init', function() {
    // Modification de la regex pour accepter soit des chiffres seuls ([0-9]+),
    // soit le format OF suivi de caractères alphanumériques et d'un tiret (ex: OF12-345)
    add_rewrite_rule( '^deal/([A-Za-z0-9\-]+)/?$', 'index.php?pagename=deal&ispag_deal_id=$matches[1]', 'top' );

    add_rewrite_rule( '^contact/([0-9]+)/?$', 'index.php?pagename=contact-detail&user_id=$matches[1]', 'top' );
    add_rewrite_rule( '^company/([0-9]+)/?$', 'index.php?pagename=entreprise-detail&company_id=$matches[1]', 'top' );
} );

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'ispag_deal_id';
    $vars[] = 'user_id';
    $vars[] = 'company_id';
    $vars[] = 'poid';

    return $vars;
} );

// ----------------------------------------------------------------------------
// 6. SCRIPTS ET STYLES
// ----------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', function() {
    $crm_pages = ['deal', 'deals', 'contact-detail', 'entreprise-detail', 'listes-des-contacts'];

    if ( is_page( $crm_pages ) ) {
        wp_enqueue_style( 'ispag-crm-main', ISPAG_CRM_PLUGIN_URL . 'assets/css/ispag-crm-styles.css' );
        wp_enqueue_script( 'ispag-crm-js', ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-contact-detail-edit.js', ['jquery'], '1.2.0', true );
        wp_enqueue_script( 'ispag-ai-loader', ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-load-ai-datas.js', ['jquery'], '1.2.0', true );
        wp_enqueue_script( 'ispag-drag-drop', ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-drag-and-drop-deals.js', ['jquery'], (int) @filemtime( ISPAG_CRM_PLUGIN_DIR . 'assets/js/ispag-drag-and-drop-deals.js' ), true );
        wp_enqueue_script( 'ispag-sequence-loader-js', ISPAG_CRM_PLUGIN_URL . 'assets/js/sequence-loader.js', ['jquery', 'ispag-crm-js'], '1.2.1', true );
    }

    // Réutilise l'instance déjà créée par ispag_run_crm_manager() au lieu d'en
    // recréer une (évite un 2e enregistrement des hooks internes du repository).
    $contacts_repo = ispag_crm_get_instance( 'ISPAG_Crm_Contacts_Repository' ) ?? new ISPAG_Crm_Contacts_Repository();

    $global_crm_data = [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'ispag_crm_nonce' ),
        'owners'   => $contacts_repo->get_ispag_owners_options(),
        'i18n' => [
            'select_action'  => __('Please select an action.', 'ispag-crm'),
            'select_contact' => __('Please select at least one contact.', 'ispag-crm'),
            'confirm_delete' => __('Are you sure you want to delete the selected contacts?', 'ispag-crm'),
            'high'           => __('A - High', 'ispag-crm'),
            'medium'         => __('B - Medium', 'ispag-crm'),
            'low'            => __('C - Low', 'ispag-crm'),
            'company_id'     => __('Company ID', 'ispag-crm'),
            'select_owner'   => __('-- Select owner --', 'ispag-crm'),
            'preparing'      => __('Preparing...', 'ispag-crm'),
            'prepare_meeting'=> __('Prepare meeting', 'ispag-crm'),
        ]
    ];

    wp_localize_script( 'ispag-crm-js', 'ispag_ajax', $global_crm_data );
    wp_enqueue_script( 'ispag-deal-ajax', ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-deal-ajax.js', ['jquery'], '1.0', true );
} );

// ----------------------------------------------------------------------------
// 7. SÉCURITÉ ET CRON
// ----------------------------------------------------------------------------
add_filter('wp_authenticate_user', function($user) {
    if (is_wp_error($user)) return $user;
    $status = get_user_meta($user->ID, 'ispag_account_status', true);
    if ($status === 'disabled') {
        return new WP_Error('disabled_account', __('Your ISPAG account has been suspended.', 'ispag-crm'));
    }
    return $user;
}, 10, 1);

add_action('ispag_run_sequences_cron', 'ispag_process_pending_sequences');
function ispag_process_pending_sequences() {
    $repository = ispag_crm_get_instance( 'ISPAG_Sequence_Repository' ) ?? new ISPAG_Sequence_Repository();
    $repository->process_scheduled_steps();
}

if ( ! wp_next_scheduled( 'ispag_run_sequences_cron' ) ) {
    wp_schedule_event( time(), 'hourly', 'ispag_run_sequences_cron' );
}


//**************************************************************************** */
// On intercepte l'envoie d'un formualire CF7 et on enregistre dans le CRM
//**************************************************************************** */
add_action('wpcf7_mail_sent', 'ispag_crm_integrate_cf7');

function ispag_crm_integrate_cf7($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) return;

    $posted_data = $submission->get_posted_data();

    // 1. Extraction des données (adaptez les clés [your-...] à vos champs CF7)
    $email   = sanitize_email($posted_data['your-email']);
    $name    = sanitize_text_field($posted_data['your-name']);
    $message = wp_kses_post($posted_data['your-message']);
    $subject = wp_kses_post($posted_data['your-v']);

    $user_id = email_exists($email);

    // 2. Si l'utilisateur n'existe pas, on le crée via votre Repository
    if (!$user_id) {
        $user_id = wp_insert_user([
            'user_email' => $email,
            'user_login' => $email,
            'first_name' => $name,
            'user_pass'  => wp_generate_password(),
            'role'       => 'subscriber'
        ]);

        if (!is_wp_error($user_id)) {
            // Tentative de liaison automatique à l'entreprise par domaine email
            ispag_link_contact_to_company_by_domain($user_id, $email);
        }
    }

    // 3. Enregistrement du message comme "Note" via votre ISPAG_Note_Manager
    if ($user_id && class_exists('ISPAG_Note_Manager')) {
        $wpdb = $GLOBALS['wpdb'];

        // On prépare l'objet pour la méthode create_note de votre manager
        $note_data = (object)[
            'contact_id' => $user_id,
            'type'       => 'note',
            'title'      => 'Message Formulaire (Landing Page) ' . $subject,
            'content'    => $message,
            'is_task'    => 0,
            'user_id'    => 1 // ID de l'admin ou du responsable par défaut
        ];

        // Utilisation de votre table personnalisée définie dans ISPAG_Note_Manager
        $wpdb->insert(
            $wpdb->prefix . 'ispag_contact_notes',
            [
                'contact_id' => $user_id,
                'type'       => 'note',
                'title'      => $note_data->title,
                'content'    => $note_data->content,
                'created_at' => current_time('mysql'),
                'user_id'    => $note_data->user_id
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d']
        );
    }
}

/**
 * Fonction pour lier le contact à une entreprise selon le domaine mail
 */
function ispag_link_contact_to_company_by_domain($user_id, $email) {
    global $wpdb;
    $domain = substr(strrchr($email, "@"), 1);

    // Liste des domaines génériques à ignorer
    $ignored_domains = ['gmail.com', 'outlook.com', 'wanadoo.fr', 'bluewin.ch'];
    if (in_array($domain, $ignored_domains)) return;

    // On cherche une entreprise qui a ce domaine dans son mail ou site web
    $table_companies = 'wor9711_ispag_companies'; // Selon votre classe constants
    $company_id = $wpdb->get_var($wpdb->prepare(
        "SELECT Id FROM $table_companies WHERE company_mail LIKE %s LIMIT 1",
        '%' . $wpdb->esc_like($domain) . '%'
    ));

    if ($company_id) {
        update_user_meta($user_id, 'ispag_company_id', $company_id);
    }
}

// ----------------------------------------------------------------------------
// 8. ENDPOINTS DE DEBUG / MAINTENANCE (Baikal sync, alignement owners)
//
// Ajout par rapport à l'original : current_user_can('manage_options') sur
// chaque bloc. Avant, n'importe quel utilisateur connecté au back-office
// pouvait déclencher une purge complète des carnets Baïkal ou un
// réalignement en masse des owners juste en visitant l'URL avec le bon
// paramètre GET. Le comportement fonctionnel est identique pour un admin,
// seul l'accès est maintenant restreint.
// ----------------------------------------------------------------------------
// Exemple pour tester via l'admin WordPress ou une URL spécifique
add_action('admin_init', function() {
    if (isset($_GET['run_baikal_sync']) && current_user_can('manage_options')) {
        $sync = new ISPAG_Baikal_Sync();
        $sync->sync_all_contacts();
    }
});


add_action('admin_head', function() {
    if (isset($_GET['ispag_sync_owner'])) {
        if ( ! current_user_can('manage_options') ) {
            wp_die( __('Unauthorized access.', 'ispag-crm') );
        }

        // --- CONFIGURATION DU FLUSH ---
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
        ini_set('zlib.output_compression', 0);
        ini_set('implicit_flush', 1);
        while (ob_get_level()) { ob_end_flush(); }
        ob_implicit_flush(1);
        header('X-Accel-Buffering: no');

        global $wpdb;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

        // Tables
        $table_co = $wpdb->prefix . 'ispag_companies_owners';
        $table_uo = $wpdb->prefix . 'ispag_contacts_owners';

        echo "<div style='background:#1d2327; color:#f0f0f1; padding:20px; font-family:monospace; min-height:100vh;'>";
        echo "<h1>🔄 Alignement Owners : Entreprise > Contacts</h1>";

        // 1. Récupérer l'entreprise actuelle (basé sur l'offset)
        $company_data = $wpdb->get_row($wpdb->prepare(
            "SELECT company_id, user_id, department_key FROM $table_co
             WHERE status = 'active'
             ORDER BY id ASC LIMIT 1 OFFSET %d",
            $offset
        ));

        if ($company_data) {
            $co_id     = $company_data->company_id;
            $new_owner = $company_data->user_id;
            $dept      = $company_data->department_key;

            // Récupérer le nom de l'entreprise (optionnel, pour le log)
            $company_name = $wpdb->get_var($wpdb->prepare("SELECT company_name FROM {$wpdb->prefix}ispag_companies WHERE Id = %d", $co_id));

            echo "<h3>🏢 Entreprise : $company_name (ID $co_id)</h3>";
            echo "<p>Owner cible : <b>" . (get_userdata($new_owner)->display_name ?? $new_owner) . "</b></p>";

            // 2. Trouver les contacts liés à cette entreprise
            $contacts = get_users([
                'meta_key'   => ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
                'meta_value' => $co_id,
                'fields'     => 'ID'
            ]);

            $updates = 0;
            if (!empty($contacts)) {
                foreach ($contacts as $contact_id) {
                    // Vérifier l'owner actuel du contact
                    $current_owner_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT user_id FROM $table_uo WHERE contact_id = %d AND status = 'active'",
                        $contact_id
                    ));

                    if ($current_owner_id != $new_owner) {
                        // Désactivation de l'ancien si différent
                        if ($current_owner_id) {
                            $wpdb->update($table_uo,
                                ['status' => 'unassigned', 'unassigned_at' => current_time('mysql')],
                                ['contact_id' => $contact_id, 'status' => 'active']
                            );
                        }

                        // Insertion du nouveau
                        $wpdb->insert($table_uo, [
                            'contact_id'     => $contact_id,
                            'user_id'        => $new_owner,
                            'status'         => 'active',
                            'department_key' => $dept,
                            'assigned_at'    => current_time('mysql')
                        ]);
                        $updates++;
                        echo "🔹 Contact ID $contact_id : <span style='color:#00ff00;'>Updated</span><br>";
                    }
                }
            }

            echo "<p style='color:#72aee6;'>✅ End of batch. $updates changes made.</p>";

            // 3. Redirection automatique vers le lot suivant
            $next = $offset + 1;
            $url = admin_url("?ispag_sync_owner=1&offset=$next");
            echo "<script>setTimeout(function(){ window.location.href='$url'; }, 500);</script>";

        } else {
            echo "<div style='background:#46b450; padding:20px; color:#fff;'>";
            echo "<h2>🏁 Done!</h2>";
            echo "All contacts have been aligned with the owners of their companies.";
            echo "</div><br><a href='".admin_url()."' style='color:#72aee6;'>Back to CRM</a>";
        }

        echo "</div>";
        flush();
        exit;
    }
});