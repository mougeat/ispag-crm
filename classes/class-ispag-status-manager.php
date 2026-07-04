<?php
// Fichier: class-ispag-status-manager.php

class ISPAG_Status_Manager {

    private $menu_slug = 'ispag-lead-statuses';
    private $full_table_name;
    
    // Clé meta utilisée dans la table wp_usermeta pour les contacts
    const META_LEAD_STATUS = 'ispag_lead_status'; 

    public function __construct() {
        global $wpdb;
        
        $this->full_table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME; 

        // 1. Initialisation de l'Admin UI pour la gestion des statuts
        add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
        
        // 💡 AJOUT : Appel de la méthode pour charger le sélecteur de couleurs
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_color_picker' ) ); 
        
        // 2. Intégration dans le Profil Utilisateur
        add_action( 'show_user_profile', array( $this, 'add_contact_status_field' ) );
        add_action( 'edit_user_profile', array( $this, 'add_contact_status_field' ) );
        add_action( 'personal_options_update', array( $this, 'save_contact_status_field' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_contact_status_field' ) );
        
        // 3. Intégration dans la liste des utilisateurs
        add_filter( 'manage_users_columns', array( $this, 'add_user_list_column' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'display_user_list_column' ), 10, 3 );
        add_action( 'quick_edit_custom_box', array( $this, 'add_quick_edit_field' ), 10, 2 );
        add_action( 'admin_footer', array( $this, 'add_quick_edit_javascript' ) );
    }

// -----------------------------------------------------------------
// ENQUEUE SCRIPTS
// -----------------------------------------------------------------
    
    /**
     * Charge le sélecteur de couleurs de WordPress (farbtastic/iris).
     */
    public function enqueue_color_picker( $hook ) {
        // Chargement uniquement sur notre page d'administration
        // On vérifie le hook exact de notre page de menu, sinon ça ne s'activera pas
        $target_hook = 'ispag-entreprises_page_' . $this->menu_slug;

        if ( $hook === $target_hook || (isset($_GET['page']) && $_GET['page'] === $this->menu_slug)) {
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
            
            // Script d'initialisation du sélecteur
            add_action( 'admin_footer', function() {
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($){
                    // Assure que le sélecteur de couleurs est appliqué sur les champs dédiés
                    $('.ispag-color-picker').wpColorPicker();
                });
                </script>
                <?php
            });
        }
    }
// -----------------------------------------------------------------
// UTILITAIRES STATIQUES & DB
// -----------------------------------------------------------------

    /**
     * Crée la table SQL lors de l'activation du plugin.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            Id INT NOT NULL AUTO_INCREMENT,
            status_key VARCHAR(50) NOT NULL UNIQUE,
            status_label VARCHAR(100) NOT NULL,
            bg_color VARCHAR(7) NOT NULL DEFAULT '#cccccc', 
            text_color VARCHAR(7) NOT NULL DEFAULT '#333333', 
            status_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (Id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        // Pour l'exécution unique de l'insertion des données initiales
        self::insert_initial_data();
    }
    
    /**
     * Insère les données initiales après la création de la table.
     */
    public static function insert_initial_data() {
        global $wpdb;
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;
        
        // Vérifie si la table est vide pour éviter les doublons
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $data = array(
                array('key' => 'new', 'label' => 'Nouveau', 'order' => 10, 'bg' => '#3498db', 'text' => '#ffffff'),
                array('key' => 'in_progress', 'label' => 'En cours', 'order' => 20, 'bg' => '#f39c12', 'text' => '#ffffff'),
                array('key' => 'connected', 'label' => 'Connecté', 'order' => 30, 'bg' => '#2ecc71', 'text' => '#ffffff'),
                array('key' => 'awaiting_response', 'label' => 'En attente de réponse', 'order' => 40, 'bg' => '#e67e22', 'text' => '#ffffff'),
                array('key' => 'unqualified', 'label' => 'Non qualifié', 'order' => 90, 'bg' => '#e74c3c', 'text' => '#ffffff'),
            );
            
            foreach ($data as $item) {
                 $wpdb->insert( $table_name, 
                     array(
                         'status_key' => $item['key'], 
                         'status_label' => $item['label'], 
                         'status_order' => $item['order'],
                         'bg_color' => $item['bg'],
                         'text_color' => $item['text'],
                     ),
                     array( '%s', '%s', '%d', '%s', '%s' )
                 );
            }
        }
    }
    
    /**
     * Récupère la liste des statuts triés pour les sélections.
     * @return array Tableau associatif (status_key => status_label).
     */
    public static function get_statuses_for_select() {
        global $wpdb;
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;
        
        $statuses = $wpdb->get_results( 
            "SELECT status_key, status_label FROM {$table_name} ORDER BY status_order ASC, status_label ASC" 
        );
        
        $output = array();
        if ( $statuses ) {
            foreach ( $statuses as $status ) {
                $output[ $status->status_key ] = $status->status_label;
            }
        }
        return $output;
    }

    /**
     * Récupère TOUS les statuts avec leurs couleurs (key => Data Array).
     * Utilisé pour la génération du CSS dynamique.
     * @return array Tableau associatif [status_key => ['label' => '...', 'bg_color' => '...', 'text_color' => '...']]
     */
    public static function get_all_statuses_data() {
        global $wpdb;
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME;
        
        $results = $wpdb->get_results( 
            "SELECT status_key, status_label as label, bg_color, text_color 
             FROM {$table_name} 
             ORDER BY status_order ASC, status_label ASC", 
             ARRAY_A
        );
        
        $data = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $data[ $row['status_key'] ] = array(
                    'label'      => $row['label'],
                    'bg_color'   => $row['bg_color'],
                    'text_color' => $row['text_color'],
                );
            }
        }
        return $data;
    }


// -----------------------------------------------------------------
// ADMIN UI : GESTION DES STATUTS (Ajout/Édition/Suppression)
// -----------------------------------------------------------------

    public function add_admin_menu_page() {
        // Ajout en sous-menu du menu principal (ispag-entreprises)
        add_submenu_page(
            'ispag-entreprises', 
            __( 'Manage Lead Statuses', 'ispag-crm' ),
            __( 'Lead Statuses', 'ispag-crm' ),
            'manage_options',
            $this->menu_slug,
            array( $this, 'render_statuses_page' )
        );
    }
    
    /**
     * Récupère la liste des statuts triés (pour l'affichage du tableau dans l'admin).
     */
    private function get_all_statuses() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->full_table_name} ORDER BY status_order ASC, status_label ASC" );
    }

    public function render_statuses_page() {
        if ( ! current_user_can( 'manage_order' ) ) {
            return;
        }

        $statuses = $this->get_all_statuses();
        $editing_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $edit_item = null;
        
        if ($editing_id > 0) {
            global $wpdb;
            $edit_item = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$this->full_table_name} WHERE Id = %d", $editing_id) );
        }
        
        // Valeurs par défaut pour le formulaire
        $default_bg_color = $edit_item ? $edit_item->bg_color : '#cccccc';
        $default_text_color = $edit_item ? $edit_item->text_color : '#333333';
        
        // --- Affichage des messages de notification ---
        if ( isset( $_GET['message'] ) ) {
             $message_text = '';
             $class = 'notice-success';
             switch ( $_GET['message'] ) {
                 case 'added':
                     $message_text = __( 'New status added successfully.', 'ispag-crm' );
                     break;
                 case 'updated':
                     $message_text = __( 'Status updated successfully.', 'ispag-crm' );
                     break;
                 case 'deleted':
                     $message_text = __( 'Status deleted successfully.', 'ispag-crm' );
                     break;
                 case 'error':
                     $message_text = __( 'An error occurred during the operation.', 'ispag-crm' );
                     $class = 'notice-error';
                     break;
                 case 'key_exists':
                     $message_text = __( 'Error: The key must be unique.', 'ispag-crm' );
                     $class = 'notice-error';
                     break;
             }
             if ( ! empty( $message_text ) ) {
                 echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message_text ) . '</p></div>';
             }
        }
        
        ?>
        <style>
            .ispag-status-preview {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 15px;
                font-size: 0.9em;
                font-weight: bold;
                text-transform: capitalize;
                white-space: nowrap;
                min-width: 80px; 
                text-align: center;
            }
        </style>
        <div class="wrap">
            <h1><?php echo esc_html( __( 'Lead Statuses Management', 'ispag-crm' ) ); ?></h1>
            <p class="description"><?php echo esc_html( __( 'These statuses are used to categorize the current activity level of your contacts. The colors below are also visible in the contact lists.', 'ispag-crm' ) ); ?></p>
            
            <div id="col-container" class="wp-clearfix">
                
                <div id="col-right">
                    <div class="col-wrap">
                        <h2><?php echo esc_html( __( 'Existing Statuses', 'ispag-crm' ) ); ?></h2>
                        <table class="wp-list-table widefat fixed striped tags">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo esc_html( __( 'Label', 'ispag-crm' ) ); ?></th>
                                    <th scope="col"><?php echo esc_html( __( 'Key', 'ispag-crm' ) ); ?></th>
                                    <th scope="col"><?php echo esc_html( __( 'Preview', 'ispag-crm' ) ); ?></th>
                                    <th scope="col"><?php echo esc_html( __( 'Order', 'ispag-crm' ) ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="the-list">
                                <?php if ($statuses) : ?>
                                    <?php foreach ($statuses as $status) : ?>
                                        <tr>
                                            <td class="name column-name">
                                                <strong><a href="<?php echo esc_url( add_query_arg( 'edit', $status->Id, admin_url( 'admin.php?page=' . $this->menu_slug ) ) ); ?>"><?php echo esc_html( $status->status_label ); ?></a></strong>
                                                <div class="row-actions">
                                                    <span class="edit"><a href="<?php echo esc_url( add_query_arg( 'edit', $status->Id, admin_url( 'admin.php?page=' . $this->menu_slug ) ) ); ?>"><?php echo esc_html( __( 'Edit', 'ispag-crm' ) ); ?></a> | </span>
                                                    <span class="delete"><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array('action' => 'delete', 'status_id' => $status->Id), admin_url( 'admin.php?page=' . $this->menu_slug ) ), 'delete_status_' . $status->Id ) ); ?>" onclick="return confirm('<?php echo esc_attr( __( 'Are you sure you want to delete this status?', 'ispag-crm' ) ); ?>');"><?php echo esc_html( __( 'Delete', 'ispag-crm' ) ); ?></a></span>
                                                </div>
                                            </td>
                                            <td class="slug column-slug"><?php echo esc_html( $status->status_key ); ?></td>
                                            <td class="preview column-preview">
                                                <span class="ispag-status-preview" style="background-color:<?php echo esc_attr($status->bg_color); ?>; color:<?php echo esc_attr($status->text_color); ?>;">
                                                    <?php echo esc_html( $status->status_label ); ?>
                                                </span>
                                            </td>
                                            <td class="order column-order"><?php echo absint( $status->status_order ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr><td colspan="4"><?php echo esc_html( __( 'No statuses defined.', 'ispag-crm' ) ); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="col-left">
                    <div class="col-wrap">
                        <h2><?php echo esc_html( $editing_id > 0 ? __( 'Edit Status', 'ispag-crm' ) : __( 'Add New Status', 'ispag-crm' ) ); ?></h2>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ); ?>">
                            <?php wp_nonce_field( 'ispag_save_status', 'ispag_status_nonce' ); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr( $editing_id > 0 ? 'update' : 'insert' ); ?>" />
                            <?php if ($editing_id > 0) : ?>
                                <input type="hidden" name="status_id" value="<?php echo absint( $editing_id ); ?>" />
                            <?php endif; ?>

                            <div class="form-field form-required">
                                <label for="status_label"><?php echo esc_html( __( 'Label', 'ispag-crm' ) ); ?></label>
                                <input name="status_label" id="status_label" type="text" value="<?php echo esc_attr( $edit_item ? $edit_item->status_label : '' ); ?>" size="40" required />
                                <p><?php echo esc_html( __( 'The name as it appears in the dropdown list.', 'ispag-crm' ) ); ?></p>
                            </div>
                            
                            <div class="form-field form-required">
                                <label for="status_key"><?php echo esc_html( __( 'Key (Unique ID)', 'ispag-crm' ) ); ?></label>
                                <input name="status_key" id="status_key" type="text" value="<?php echo esc_attr( $edit_item ? $edit_item->status_key : '' ); ?>" size="40" <?php echo $editing_id > 0 ? 'readonly' : 'required'; ?> />
                                <p><?php echo esc_html( __( 'A unique identifier (lowercase, no spaces) used in the database. Cannot be changed after creation.', 'ispag-crm' ) ); ?></p>
                            </div>
                            
                            <div class="form-field">
                                <label for="bg_color"><?php echo esc_html( __( 'Background Color', 'ispag-crm' ) ); ?></label>
                                <input name="bg_color" id="bg_color" type="text" class="ispag-color-picker" value="<?php echo esc_attr( $default_bg_color ); ?>" data-default-color="#cccccc" />
                                <p><?php echo esc_html( __( 'The background color of the status badge.', 'ispag-crm' ) ); ?></p>
                            </div>
                            
                            <div class="form-field">
                                <label for="text_color"><?php echo esc_html( __( 'Text Color', 'ispag-crm' ) ); ?></label>
                                <input name="text_color" id="text_color" type="text" class="ispag-color-picker" value="<?php echo esc_attr( $default_text_color ); ?>" data-default-color="#333333" />
                                <p><?php echo esc_html( __( 'The text color of the status badge (choose black or white for contrast).', 'ispag-crm' ) ); ?></p>
                            </div>
                            <div class="form-field">
                                <label for="status_order"><?php echo esc_html( __( 'Order', 'ispag-crm' ) ); ?></label>
                                <input name="status_order" id="status_order" type="number" value="<?php echo esc_attr( $edit_item ? $edit_item->status_order : 0 ); ?>" min="0" />
                                <p><?php echo esc_html( __( 'Used to define the display order.', 'ispag-crm' ) ); ?></p>
                            </div>

                            <?php submit_button( $editing_id > 0 ? __( 'Update Status', 'ispag-crm' ) : __( 'Add New Status', 'ispag-crm' ) ); ?>
                            
                            <?php if ($editing_id > 0) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ); ?>"><?php echo esc_html( __( 'Cancel Edit', 'ispag-crm' ) ); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_form_submission() {
        global $wpdb;

        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== $this->menu_slug || ! current_user_can( 'manage_order' ) ) {
            return;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';

        // --- GESTION DE LA SUPPRESSION ---
        if ( $action === 'delete' ) {
            $status_id = isset( $_GET['status_id'] ) ? absint( $_GET['status_id'] ) : 0;

            if ( $status_id > 0 && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'delete_status_' . $status_id ) ) {
                $wpdb->delete( $this->full_table_name, array( 'Id' => $status_id ), array( '%d' ) );
                // Redirection après succès
                wp_safe_redirect( remove_query_arg( array('action', 'status_id', '_wpnonce'), admin_url( 'admin.php?page=' . $this->menu_slug . '&message=deleted' ) ) );
                exit;
            }
        }
        
        // --- GESTION DE L'INSERTION / MISE À JOUR ---
        if ( in_array( $action, array( 'insert', 'update' ) ) && isset( $_POST['ispag_status_nonce'] ) && wp_verify_nonce( $_POST['ispag_status_nonce'], 'ispag_save_status' ) ) {
            
            // Récupération et validation des champs
            $status_label = sanitize_text_field( $_POST['status_label'] );
            $status_order = absint( $_POST['status_order'] );
            // 💡 Les champs de couleur sont déjà gérés ici
            $bg_color     = sanitize_hex_color( $_POST['bg_color'] );
            $text_color   = sanitize_hex_color( $_POST['text_color'] );
            
            $data = array(
                'status_label' => $status_label,
                'status_order' => $status_order,
                'bg_color'     => $bg_color,    
                'text_color'   => $text_color,  
            );
            $data_format = array( '%s', '%d', '%s', '%s' ); 
            $message = 'error';

            if ( $action === 'insert' ) {
                $status_key = sanitize_title( $_POST['status_key'] ); 
                
                // Vérification de l'existence de la clé avant insertion
                $key_exists = $wpdb->get_var( $wpdb->prepare( "SELECT Id FROM {$this->full_table_name} WHERE status_key = %s", $status_key ) );
                
                if ($key_exists) {
                    $message = 'key_exists';
                } else {
                    $data['status_key'] = $status_key;
                    $data_format[] = '%s'; 
                    
                    $result = $wpdb->insert( $this->full_table_name, $data, $data_format );
                    $message = $result ? 'added' : 'error';
                }
                
            } elseif ( $action === 'update' ) {
                $status_id = absint( $_POST['status_id'] );
                $where = array( 'Id' => $status_id );
                $where_format = array( '%d' );
                
                $result = $wpdb->update( $this->full_table_name, $data, $where, $data_format, $where_format );
                $message = $result !== false ? 'updated' : 'error';
            }
            
            // Redirection après succès/échec
            wp_safe_redirect( remove_query_arg( array('action', 'status_id', 'edit', '_wpnonce'), admin_url( 'admin.php?page=' . $this->menu_slug . '&message=' . $message ) ) );
            exit;
        }
    }


// -----------------------------------------------------------------
// INTÉGRATION PROFIL UTILISATEUR ET LISTE
// -----------------------------------------------------------------

    /**
     * Ajoute le champ de sélection du statut à la page de profil détaillée.
     */
    public function add_contact_status_field( $user ) {
        if ( ! current_user_can( 'manage_order' ) ) return;

        $statuses = self::get_statuses_for_select();
        $current_status = get_user_meta( $user->ID, self::META_LEAD_STATUS, true );
        ?>
        
        <table class="form-table">
            <tr>
                <th><label for="<?php echo esc_attr( self::META_LEAD_STATUS ); ?>"><?php echo esc_html( __( 'Lead Status', 'ispag-crm' ) ); ?></label></th>
                <td>
                    <select name="<?php echo esc_attr( self::META_LEAD_STATUS ); ?>" id="<?php echo esc_attr( self::META_LEAD_STATUS ); ?>">
                        <option value=""><?php echo esc_html( __( '-- Select Status --', 'ispag-crm' ) ); ?></option>
                        <?php foreach ( $statuses as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_status, $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php echo esc_html( __( 'Define the current engagement status of this contact (e.g., New, In Progress, Unqualified).', 'ispag-crm' ) ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Sauvegarde le champ de statut du profil utilisateur.
     */
    public function save_contact_status_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST[ self::META_LEAD_STATUS ] ) ) {
            return; 
        }

        $new_status = sanitize_key( $_POST[ self::META_LEAD_STATUS ] );
        
        if ( ! empty( $new_status ) ) {
            update_user_meta( $user_id, self::META_LEAD_STATUS, $new_status );
        } else {
            // Optionnel: Si vide, on supprime la meta (ce qui peut être le choix par défaut)
            delete_user_meta( $user_id, self::META_LEAD_STATUS );
        }
    }
    
    // --- Colonne de liste d'utilisateurs ---

    public function add_user_list_column( $columns ) {
        // Ajout après la colonne 'role' et avant 'posts'
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            $new_columns[$key] = $title;
            if ( $key === 'role' ) { 
                $new_columns['ispag_lead_status'] = __( 'Lead Status', 'ispag-crm' );
            }
        }
        return $new_columns;
    }

    public function display_user_list_column( $output, $column_name, $user_id ) {
        if ( 'ispag_lead_status' !== $column_name ) {
            return $output;
        }

        $current_key = get_user_meta( $user_id, self::META_LEAD_STATUS, true );
        $statuses = self::get_statuses_for_select();
            
        if ( isset( $statuses[ $current_key ] ) ) {
            // Ajout d'un attribut data pour le JS du Quick Edit
            return '<span data-status-key="' . esc_attr($current_key) . '" id="ispag_lead_status-' . absint($user_id) . '">' . esc_html( $statuses[ $current_key ] ) . '</span>';
        }
        
        return '<span data-status-key="" id="ispag_lead_status-' . absint($user_id) . '">—</span>';
    }
    
    // --- Quick Edit (pour la liste d'utilisateurs) ---
    
    public function add_quick_edit_field( $column_name, $post_type ) {
        if ( 'ispag_lead_status' !== $column_name ) return;
        
        $statuses = self::get_statuses_for_select();
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="alignleft">
                    <span class="title"><?php echo esc_html( __( 'Lead Status', 'ispag-crm' ) ); ?></span>
                    <span class="input-text-wrap">
                        <select name="<?php echo esc_attr( self::META_LEAD_STATUS ); ?>" class="ispag-status-quick-edit">
                            <option value="">— <?php echo esc_html( __( 'No changes', 'ispag-crm' ) ); ?> —</option>
                            <?php foreach ( $statuses as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>">
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    public function add_quick_edit_javascript() {
        global $current_screen;

        if ( ! is_object( $current_screen ) || 'users' !== $current_screen->id ) return;
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $wp_inline_edit = $('#the-list').find('.inline-edit-row');
            
            $wp_inline_edit.on('quick-edit-display', function(event, row) {
                var $status_select = $(this).find('.ispag-status-quick-edit');
                var user_id = $(row).attr('id').replace('user-', '');
                
                // Récupère la clé de statut stockée dans l'attribut data-status-key de la colonne
                var current_status_key = $('#ispag_lead_status-' + user_id).data('status-key');
                
                // Pré-sélectionne le statut actuel (si elle est définie)
                if (current_status_key) {
                    $status_select.val(current_status_key);
                } else {
                    // Sinon, réinitialise à "No changes" (l'option vide)
                    $status_select.val('');
                }
            });
        });
        </script>
        <?php
    }
}