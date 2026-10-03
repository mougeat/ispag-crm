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
                array('key' => 'connected', 'label' => 'Connected', 'order' => 30, 'bg' => '#2ecc71', 'text' => '#ffffff'),
                array('key' => 'awaiting_response', 'label' => 'Awaiting response', 'order' => 40, 'bg' => '#e67e22', 'text' => '#ffffff'),
                array('key' => 'unqualified', 'label' => 'Unqualified', 'order' => 90, 'bg' => '#e74c3c', 'text' => '#ffffff'),
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
                $output[ $status->status_key ] = ispag_crm_db_label($status->status_label);
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


// L'édition des statuts (table ispag_lead_statuses) se fait dans ISPAG Settings → Reference tables
// (voir ISPAG_Crm_Reference_Tables).

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
                <th><label for="<?php echo esc_attr( self::META_LEAD_STATUS ); ?>"><?php echo esc_html( __( 'Lead status', 'ispag-crm' ) ); ?></label></th>
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
                $new_columns['ispag_lead_status'] = __( 'Lead status', 'ispag-crm' );
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
                    <span class="title"><?php echo esc_html( __( 'Lead status', 'ispag-crm' ) ); ?></span>
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