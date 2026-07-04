<?php
/**
 * Plugin Name: ISPAG CRM Manager
 * Description: Contact management and lead tracking (CRM) system for ISPAG users and businesses.
 * Version: 2.1.0
 * Author: Cyril Barthel
 */

// ✅ Ajoutez ceci TOUT AU DÉBUT, avant même le check ABSPATH
// error_log("ISPAG CRM: Début du chargement du plugin");

if (!defined('ABSPATH')) {
    die;
}

// ✅ Ajoutez un autre log ici
// error_log("ISPAG CRM: ABSPATH est défini");

// ----------------------------------------------------------------------------
// 1. CONSTANTES ET ENVIRONNEMENT
// ----------------------------------------------------------------------------
define( 'ISPAG_CRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ISPAG_CRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Après les constantes, avant tout le reste
add_action('plugins_loaded', function() {
    // error_log("ISPAG CRM: muplugins_loaded déclenché");
    require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-logger.php';
    ISPAG_Workflow_Logger::init();
    ISPAG_Workflow_Logger::info('Logger ISPAG Workflow initialisé via muplugins_loaded');
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

//Fichier dummy pour traduire les textes de la base de donnée
require_once plugin_dir_path(__FILE__) . 'classes/helpers/ispag-translations-support.php';

add_action('init', 'ispag_crm_load_textdomain');

function ispag_crm_load_textdomain() {
    // Le premier paramètre DOIT être identique au "Text domain" de l'image (ispag-crm)
    load_plugin_textdomain('ispag-crm', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// ----------------------------------------------------------------------------
// 2. AUTOLOADER (Standard ISPAG)
// ----------------------------------------------------------------------------
// require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-logger.php';
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-step.php';
require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-trigger.php';


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
function ispag_crm_activate() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    
    $table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';
    $charset_collate    = $wpdb->get_charset_collate();

    $sql_fournisseurs = "CREATE TABLE $table_fournisseurs (
        Id INT NOT NULL AUTO_INCREMENT,
        isSupplier INT NOT NULL DEFAULT 0,
        isIngenieur INT NOT NULL DEFAULT 0,
        Fournisseur TEXT NOT NULL,
        compagnyDomain TEXT NOT NULL,
        Mail TEXT NOT NULL,
        PRIMARY KEY (Id),
        KEY compagnyDomain_idx (compagnyDomain(100))
    ) $charset_collate;";

    dbDelta( $sql_fournisseurs );

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
function ispag_run_crm_manager() {
    $classes = [
        'ISPAG_Crm_Contacts_Repository',
        'ISPAG_Entreprise_Manager',
        'ISPAG_Contact_Manager',
        'ISPAG_Status_Manager',
        'ISPAG_Contact_Ajax_Handler',
        'ISPAG_Note_Manager',
        'ISPAG_Crm_Deal_Model',
        'ISPAG_Company_Importer',
        'ISPAG_Crm_Company_Repository',
        'ISPAG_Crm_Company_Modal',
        'ISPAG_Crm_Contact_Modal',
        'ISPAG_Template_Repository',
        'ISPAG_Template_AJAX',
        'ISPAG_Reminder_Cron',
        'ISPAG_Brevo_Cron_Sync',
        'ISPAG_Cron_Task_Reminder',
        'ISPAG_Cron_Weekly_Deal_Report',
        'ISPAG_Cron_Contact_Health',
        'ISPAG_Cron_Lost_Deals_Reporting',
        'ISPAG_Cron_Contact_Matcher',
        'ISPAG_Cron_Lifecycle',
        'ISPAG_Cron_LeadStatus',
        'ISPAG_CSV_Importer',
        'ISPAG_Baikal_Sync',
        'ISPAG_Sequence_Admin',
        'ISPAG_Sequence_Repository',
        'ISPAG_OneSignal_Handler',
        'ISPAG_Simap_Service',
        'Ispag_Agent_Commercial_API',

        'ISPAG_Workflow_CPT',
        'ISPAG_Workflow_Meta_Box',
        // 'ISPAG_Workflow_Manager',
        'ISPAG_Workflow_Execution',
        'ISPAG_Workflow_Admin_Page',
    ];

    foreach ( $classes as $class ) {
        if ( class_exists( $class ) ) {
            new $class();
        }
    }

    // Dans ispag_run_crm_manager(), avant ISPAG_Workflow_Manager::get_instance()
    require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-step.php';
    require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-trigger.php';
    require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow-execution.php';
    require_once ISPAG_CRM_PLUGIN_DIR . 'classes/class-ispag-workflow.php';
    // Initialisez le gestionnaire de workflows
    ISPAG_Workflow_Manager::get_instance();

    // CHARGEMENT DU SDK ONESIGNAL (Géré par la classe)
    if ( class_exists( 'ISPAG_OneSignal_Handler' ) ) {
        add_action( 'wp_enqueue_scripts', array( 'ISPAG_OneSignal_Handler', 'enqueue_scripts' ) );
    }

    if ( class_exists( 'ISPAG_Crm_Deals_Repository' ) ) {
        $deals_repo = new ISPAG_Crm_Deals_Repository();
        add_action( 'wp_ajax_ispag_update_deal_stage', array( $deals_repo, 'ispag_handle_deal_stage_update' ) );
        add_action( 'wp_ajax_ispag_bulk_update_deals', array( $deals_repo, 'ispag_handle_bulk_deal_update' ) );
    }

    if ( class_exists( 'ISPAG_Crm_Gemini' ) ) ISPAG_Crm_Gemini::init();
    if ( class_exists( 'ISPAG_Crm_Mistral' ) ) ISPAG_Crm_Mistral::init();
    
    add_action( 'rest_api_init', function() {
        $contacts_repo = new ISPAG_Crm_Contacts_Repository();
        $notes_repo    = new ISPAG_Note_Manager();
        
        $handlers = [
            new ISPAG_Brevo_Webhook_Handler( $contacts_repo, $notes_repo ),
            new ISPAG_Iphone_Shortcut_Webhook_Handler( $contacts_repo, $notes_repo ),
            new ISPAG_OneSignal_Handler(), 
            new ISPAG_Mailgun_Webhook_Handler($contacts_repo, $notes_repo)
        ];

        foreach ( $handlers as $h ) {
            if ( method_exists($h, 'register_routes') ) {
                $h->register_routes();
            }
        }
    });
}
add_action( 'plugins_loaded', 'ispag_run_crm_manager' );



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
    add_rewrite_rule( '^deal/([0-9]+)/?$', 'index.php?pagename=deal&ispag_deal_id=$matches[1]', 'top' );
    add_rewrite_rule( '^contact/([0-9]+)/?$', 'index.php?pagename=contact-detail&user_id=$matches[1]', 'top' );
    add_rewrite_rule( '^company/([0-9]+)/?$', 'index.php?pagename=entreprise-detail&company_id=$matches[1]', 'top' );
    add_rewrite_rule( '^purchase/([0-9]+)/?$', 'index.php?pagename=details-achats&poid=$matches[1]', 'top' );
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
        wp_enqueue_script( 'ispag-drag-drop', ISPAG_CRM_PLUGIN_URL . 'assets/js/ispag-drag-and-drop-deals.js', ['jquery'], '1.2.0', true );
        wp_enqueue_script( 'ispag-sequence-loader-js', ISPAG_CRM_PLUGIN_URL . 'assets/js/sequence-loader.js', ['jquery', 'ispag-crm-js'], '1.2.1', true );
    }

    $contacts_repo = new ISPAG_Crm_Contacts_Repository();
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
        return new WP_Error('disabled_account', __('Votre compte ISPAG a été suspendu.', 'ispag-crm'));
    }
    return $user;
}, 10, 1);

add_action('ispag_run_sequences_cron', 'ispag_process_pending_sequences');
function ispag_process_pending_sequences() {
    $repository = new ISPAG_Sequence_Repository();
    $repository->process_scheduled_steps();
}

if ( ! wp_next_scheduled( 'ispag_run_sequences_cron' ) ) {
    wp_schedule_event( time(), 'hourly', 'ispag_run_sequences_cron' );
}

// ----------------------------------------------------------------------------
// 8. TESTS (À supprimer en production)
// ----------------------------------------------------------------------------
if (isset($_GET['test_notif'])) {
    // DOIT correspondre exactement à ce qu'affiche la console : "WP_1"
    $target_id = "WP_1"; 
    
    $res = ISPAG_OneSignal_Handler::send_os_push_notification(
        $target_id, 
        "Connexion ISPAG Réussie !", 
        "Félicitations Cyril, le système de notification est 100% opérationnel."
    );
    
    echo "<h3>Résultat OneSignal :</h3><pre>";
    // Si tu vois "recipients: 1", la notification arrive sur ton écran
    print_r($res); 
    echo "</pre>";
    exit;
}

/**
 * Initialisation du département CRM
 */
add_action('init', 'ispag_init_global_department');

function ispag_init_global_department() {
    global $current_user_department;

    if(isset($_GET['dept']) AND !empty($_GET['dept'])){
        $current_user_department = $_GET['dept'];
    }
    elseif ( is_user_logged_in() ) {
        $user_id = get_current_user_id();
        // On récupère le département stocké en meta
        $dept = get_user_meta($user_id, 'user_department', true);
        
        // Valeur de secours si vide
        $current_user_department = !empty($dept) ? $dept : 'vaulruz_ispag';
    } else {
        $current_user_department = 'vaulruz_ispag';
    }
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
        $note_manager = new ISPAG_Note_Manager();
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
        "SELECT viag_id FROM $table_companies WHERE company_mail LIKE %s LIMIT 1",
        '%' . $wpdb->esc_like($domain) . '%'
    ));

    if ($company_id) {
        update_user_meta($user_id, 'ispag_company_id', $company_id);
    }
}

add_action('admin_head', function() {
    if (isset($_GET['baikal_ispag_contact_sync'])) {
        // Forçage du buffer pour affichage immédiat
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
        ini_set('zlib.output_compression', 0);
        ini_set('implicit_flush', 1);
        while (ob_get_level()) { ob_end_flush(); }
        ob_implicit_flush(1);
        header('X-Accel-Buffering: no'); 

        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        $purge  = isset($_GET['purge']) ? intval($_GET['purge']) : 0;
        $limit  = 10; // Transfert unique pour sécurité maximale

        if (class_exists('ISPAG_Baikal_Sync')) {
            $sync = new ISPAG_Baikal_Sync();
            global $wpdb;
            $table_owners = ISPAG_Crm_Contact_Constants::TABLE_CONTACT_OWNER;

            echo "<div style='background:#1d2327; color:#f0f0f1; padding:20px; font-family:monospace; min-height:100vh;'>";
            
            // --- ÉTAPE : PURGE ---
            if ($purge === 1 && $offset === 0) {
                echo "<h3 style='color:#ff4444;'>🧹 Action : Purge complète de Baïkal</h3>";
                $sync->purge_baikal_addressbook('cyril');
                $sync->purge_baikal_addressbook('claudio');
                $url = admin_url('?baikal_ispag_contact_sync=1&offset=0&purge=0');
                echo "✅ Carnets vidés. Démarrage de la synchro propre...<br>";
                echo "<script>setTimeout(function(){ window.location.href='$url'; }, 2000);</script>";
                echo "</div>"; flush(); exit;
            }

            // --- ÉTAPE : SYNCHRO ---
            // On récupère les infos complètes de la ligne pour le log
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT contact_id, department_key, user_id 
                 FROM $table_owners 
                 WHERE department_key = 'vaulruz_ispag' AND status = 'active' 
                 ORDER BY contact_id ASC LIMIT 1 OFFSET %d", 
                $offset
            ));

            if ($row) {
                $contact_id = $row->contact_id;
                $succursale = strtoupper($row->department_key);
                
                // Récupération du nom du owner pour le log
                $owner_info = get_userdata($row->user_id);
                $owner_name = $owner_info ? $owner_info->display_name : "ID: " . $row->user_id;

                echo "<h3>🚀 Synchro Lot #$offset</h3>";
                echo "<div style='background:#2c3338; padding:15px; border-left:4px solid #72aee6; margin-bottom:10px;'>";
                echo "📍 <b>Succursale :</b> $succursale<br>";
                echo "👤 <b>Owner :</b> $owner_name<br>";
                echo "🆔 <b>Contact ID :</b> $contact_id<br>";
                
                // Exécution
                $sync->sync_contact_to_baikal($contact_id);
                
                echo "<span style='color:#00ff00;'>✅ Transmis avec succès à Baïkal.</span>";
                echo "</div>";

                $next = $offset + 1;
                $url = admin_url("?baikal_ispag_contact_sync=1&offset=$next&purge=0");
                
                echo "<p style='color:#72aee6;'>Prochain contact dans 1s...</p>";
                echo "<script>setTimeout(function(){ window.location.href='$url'; }, 1000);</script>";
                echo "</div>";
                flush();
                exit;
            } else {
                echo "<div style='background:#46b450; padding:20px; color:#fff;'>";
                echo "<h2>🏁 Mission accomplie !</h2>";
                echo "Tous les contacts de Vaulruz sont synchronisés.";
                echo "</div><br><a href='".admin_url()."' style='color:#72aee6;'>Retour au CRM</a>";
                echo "</div>";
                flush();
                exit;
            }
        }
    }
});

add_action('admin_head', function() {
    // On vérifie si on a demandé uniquement la purge
    if (isset($_GET['baikal_ispag_purge_only'])) {
        
        if (class_exists('ISPAG_Baikal_Sync')) {
            $sync = new ISPAG_Baikal_Sync();

            echo "<div style='background:#d63638; color:white; padding:20px; font-family:sans-serif; position:fixed; top:0; left:0; right:0; z-index:9999;'>";
            echo "<h2>🧹 Nettoyage complet des carnets Baïkal</h2>";
            
            echo "Nettoyage en cours pour <b>Cyril</b>...<br>";
            $sync->purge_baikal_addressbook('cyril');
            
            echo "Nettoyage en cours pour <b>Claudio</b>...<br>";
            $sync->purge_baikal_addressbook('claudio');
            
            echo "<h3 style='color:#00ff00;'>✅ Les carnets sont maintenant vides.</h3>";
            echo "<p>Vous pouvez maintenant vérifier sur vos iPhones / Android, les contacts 'ispag-crm' doivent disparaître.</p>";
            echo "<a href='".admin_url()."' style='color:white; text-decoration:underline;'>Retour au CRM</a>";
            echo "</div>";
            
            // On stoppe l'exécution ici pour ne pas charger le reste de WordPress
            exit;
        }
    }
});


add_action('admin_head', function() {
    if (isset($_GET['ispag_sync_owner'])) {
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
            $company_name = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}viag_items WHERE id = %d", $co_id));

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
                        echo "🔹 Contact ID $contact_id : <span style='color:#00ff00;'>Mis à jour</span><br>";
                    }
                }
            }

            echo "<p style='color:#72aee6;'>✅ Fin du lot. $updates modifications effectuées.</p>";

            // 3. Redirection automatique vers le lot suivant
            $next = $offset + 1;
            $url = admin_url("?ispag_sync_owner=1&offset=$next");
            echo "<script>setTimeout(function(){ window.location.href='$url'; }, 500);</script>";
            
        } else {
            echo "<div style='background:#46b450; padding:20px; color:#fff;'>";
            echo "<h2>🏁 Terminé !</h2>";
            echo "Tous les contacts ont été alignés sur les propriétaires de leurs entreprises.";
            echo "</div><br><a href='".admin_url()."' style='color:#72aee6;'>Retour au CRM</a>";
        }

        echo "</div>";
        flush();
        exit;
    }
});

