<?php

class ISPAG_Contact_Manager {

    private $menu_slug = 'ispag-contacts';
    
    // Noms des meta-clés personnalisées pour les utilisateurs
    const META_COMPANY_ID           = 'ispag_company_id';
    const META_LEAD_STATUS          = 'ispag_lead_status';
    const META_LIFECYCLE_PHASE      = 'ispag_contact_lifecycle_phase'; 
    const META_LAST_CONTACT_SOURCE  = 'ispag_last_contact_source';
    const META_OWNER                = 'ispag_owner';
    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    const META_COMPANY_NAME         = 'ispag_supplier_name';
    const META_LEAD_FUNCTION        = 'ispag_lead_function';
    const META_HEALTH_CHECK_IGNORE  = 'ispag_ignore_health_check';

    public function __construct() {
        // Enregistrement de la page de menu principale des contacts
        add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );
        
        // Hooks pour ajouter et sauvegarder les champs CRM dans le profil utilisateur
        // add_action( 'show_user_profile', array( $this, 'add_contact_meta_fields' ) );
        // add_action( 'edit_user_profile', array( $this, 'add_contact_meta_fields' ) );
        // add_action( 'personal_options_update', array( $this, 'save_contact_meta_fields' ) );
        // add_action( 'edit_user_profile_update', array( $this, 'save_contact_meta_fields' ) );

        // add_action( 'show_user_profile', 'ispag_add_ignore_health_field' );
        // add_action( 'edit_user_profile', 'ispag_add_ignore_health_field' );
        // add_action( 'personal_options_update', 'ispag_save_ignore_health_field' );
        // add_action( 'edit_user_profile_update', 'ispag_save_ignore_health_field' );
        
        // =======================================================
        // HOOKS POUR LA MODIFICATION DE MASSE (BULK EDIT)
        // =======================================================
        
        // 1. Gérer la soumission du formulaire de masse (PHP)
        add_action( 'admin_init', array( $this, 'handle_bulk_edit_submission' ) );
        
        // 2. Afficher la boîte de Bulk Edit native au clic sur Appliquer (JS)
        add_action( 'admin_footer', array( $this, 'add_bulk_edit_javascript' ) );
        
        // =======================================================
        // HOOKS POUR L'AFFICHAGE FRONT-END (SHORTCODE)
        // =======================================================
        add_action( 'init', array( $this, 'add_shortcodes' ) );

        // Enqueue les scripts et styles pour le frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ispag_assets' ) );
    }

    /**
     * Affiche le champ "Ignorer le Contrôle de Santé" sur l'écran d'édition de profil utilisateur.
     * @param WP_User $user L'objet WP_User de l'utilisateur en cours d'édition.
     */
    public function ispag_add_ignore_health_field( $user ) {
        // Assurez-vous que la constante est définie (META_HEALTH_CHECK_IGNORE = 'ispag_ignore_health_check')
        $meta_key = self::META_HEALTH_CHECK_IGNORE; // Utiliser la chaîne littérale ou la constante
        $ignore_checked = get_user_meta( $user->ID, $meta_key, true );
        ?>
        <h3><?php esc_html_e( 'Contact health monitoring', 'ispag-crm' ); ?></h3>

        <table class="form-table">
            <tr>
                <th><label for="<?php echo esc_attr($meta_key); ?>"><?php esc_html_e( 'Health Check', 'ispag-crm' ); ?></label></th>
                <td>
                    <input type="checkbox" 
                        id="<?php echo esc_attr($meta_key); ?>" 
                        name="<?php echo esc_attr($meta_key); ?>" 
                        value="1" 
                        <?php checked( '1', $ignore_checked ); ?> />
                    <span class="description"><?php esc_html_e( 'Check this box to ignore this contact in the "health" check process (automatic reminders).', 'ispag-crm' ); ?></span>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Sauvegarde la valeur du champ "Ignorer le Contrôle de Santé".
     * @param int $user_id ID de l'utilisateur mis à jour.
     */
    public function ispag_save_ignore_health_field( $user_id ) {
        
        // Vérifier les permissions (important!)
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }
        
        $meta_key = self::META_HEALTH_CHECK_IGNORE; // Utiliser la même clé que ci-dessus

        // Récupérer la valeur du champ POST
        $ignore_value = isset( $_POST[$meta_key] ) ? sanitize_text_field( $_POST[$meta_key] ) : '0';

        // Mettre à jour la méta-donnée utilisateur.
        // update_user_meta gère l'insertion si elle n'existe pas, et la suppression si la valeur est vide ('0' ici est conservé).
        update_user_meta( $user_id, $meta_key, $ignore_value );
    }

    /**
     * Enqueue les styles et scripts nécessaires pour les shortcodes front-end.
     */
    public function enqueue_ispag_assets() {
        // Ne charge le CSS que si le shortcode est sur la page pour des raisons de performance.
        // Cette vérification est essentielle si le plugin est lourd.
        if ( ! has_shortcode( get_post( get_the_ID() )->post_content, 'ispag_contact_list' ) ) {
            return;
        }
        
        // Calcule le chemin URI vers le répertoire assets/css/
        // __FILE__ fait référence au fichier actuel (ex: plugin/classes/ma-classe.php)
        // plugin_dir_url(__FILE__) donne l'URL du dossier 'classes'
        // dirname(plugin_dir_url(__FILE__)) donne l'URL du dossier 'plugin'
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        // 1. Enregistrement et chargement du style
        wp_enqueue_style( 
            'ispag-crm-styles', 
            $plugin_url . 'assets/css/ispag-crm-styles.css', 
            array(), 
            '1.0.0' // Version du fichier (changez-la lors de modifications pour forcer la mise à jour du cache)
        );

        // 2. Enregistrement et chargement du script de validation (Bulk Edit JS)
        // Nous le passons directement ici, car c'est nécessaire pour le formulaire.
        wp_enqueue_script( 
            'ispag-crm-bulk-edit-js', 
            $plugin_url . 'assets/js/ispag-bulk-edit.js', // Assurez-vous d'utiliser ce nom de fichier
            array( 'jquery' ), // Dépendance : jQuery est souvent utilisé, sinon mettez un tableau vide
            '1.0.0', 
            true // Charge le script dans le footer
        );
    }

// -----------------------------------------------------------------
// UTILITAIRES INTERNES POUR RÉCUPÉRER LES OPTIONS
// -----------------------------------------------------------------

    /**
     * Récupère la liste complète des statuts de lead disponibles avec les couleurs.
     * @return array Tableau d'objets ou de tableaux associatifs contenant key, label, bg_color, text_color.
     */
    private function get_all_statuses_data() {
        if ( ! class_exists( 'ISPAG_Status_Manager' ) ) {
            // Options par défaut pour la démo, maintenant avec les couleurs (pour le test)
            return array(
                array('key' => 'new', 'label' => 'Nouveau', 'order' => 10, 'bg' => '#3498db', 'text' => '#ffffff'),
                array('key' => 'in_progress', 'label' => 'En cours', 'order' => 20, 'bg' => '#f39c12', 'text' => '#ffffff'),
                array('key' => 'connected', 'label' => 'Connecté', 'order' => 30, 'bg' => '#2ecc71', 'text' => '#ffffff'),
                array('key' => 'awaiting_response', 'label' => 'En attente de réponse', 'order' => 40, 'bg' => '#e67e22', 'text' => '#ffffff'),
                array('key' => 'unqualified', 'label' => 'Non qualifié', 'order' => 90, 'bg' => '#e74c3c', 'text' => '#ffffff'),
            );
        }
        // Supposons que cette méthode dans ISPAG_Status_Manager retourne les données complètes
        // $wpdb->get_results( "SELECT status_key, status_label, bg_color, text_color FROM ... " )
        return ISPAG_Status_Manager::get_all_statuses_data(); 
    }

    /**
     * Récupère la liste des statuts de lead disponibles pour un <select> (key => Label).
     */
    private function get_statuses_for_display() {
        $statuses_data = $this->get_all_statuses_data();
        $options = array();
        foreach ($statuses_data as $status) {
            $options[$status->status_key] = $status->status_label;
        }
        return $options;
    }
    
    // /**
    //  * Récupère la liste des phases de cycle de vie disponibles.
    //  */
    // private function get_lifecycle_phases_for_display() {
    //     if ( ! class_exists( 'ISPAG_Lifecycle_Phase_Manager' ) ) {
    //         // Options par défaut pour la démo
    //         return array('lead' => 'Lead', 'prospect' => 'Prospect', 'customer' => 'Client');
    //     }
    //     // Supposons que cette méthode existe et retourne ['key' => 'Label']
    //     return ISPAG_Lifecycle_Phase_Manager::get_phases_for_select();
    // }
    
// -----------------------------------------------------------------
// ADMIN UI : MODIFICATION DE MASSE (BULK EDIT)
// -----------------------------------------------------------------

    /**
     * Force l'affichage de la boîte de Bulk Edit au clic sur "Appliquer" (JS).
     */
    public function add_bulk_edit_javascript() {
        global $current_screen;

        if ( ! is_object( $current_screen ) || 'toplevel_page_ispag-entreprises' !== $current_screen->id ) {
            if ( ! isset( $_GET['page'] ) || $_GET['page'] !== $this->menu_slug ) return;
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            
            var $bulk_edit_row = $('#bulk-edit-row'); 
            var $ispag_bulk_fields = $('#ispag-bulk-edit-fields');
            
            // ---------------------------------------------------------------------
            // 1. Intercepter le clic sur "Appliquer" des actions de masse
            // ---------------------------------------------------------------------
            $('#doaction, #doaction2').on('click', function(e) {
                var $apply_button = $(this);
                var $action_select = $apply_button.prev('select'); 

                // Si l'action est "Modifier"
                if ($action_select.val() === 'edit') {
                    e.preventDefault(); // Empêche la soumission immédiate du formulaire
                    
                    if ($('input[name="users[]"]:checked').length > 0) {
                        
                        $bulk_edit_row.show();
                        $ispag_bulk_fields.show();
                        
                        // Cacher les barres d'actions (pour ne laisser que la boîte d'édition)
                        $('.tablenav.top').hide();
                        $('.tablenav.bottom').hide();
                        
                        // Insérer la boîte d'édition au bon endroit et la rendre visible
                        $bulk_edit_row.insertAfter('.wp-header-end').css('display', 'block'); 

                    } else {
                        alert("<?php echo esc_js( __( 'Please select the contacts you want to edit.', 'ispag-crm' ) ); ?>");
                    }
                } 
            });
            
            // ---------------------------------------------------------------------
            // 2. Gérer l'annulation du Bulk Edit
            // ---------------------------------------------------------------------
            
            $bulk_edit_row.on('click', '.cancel', function(e) {
                e.preventDefault();
                $bulk_edit_row.hide();
                
                // Restaurer l'affichage des actions groupées et des filtres
                $('.tablenav.top').show();
                $('.tablenav.bottom').show();
            });
        });
        </script>
        <?php
    }

    /**
     * Traite la soumission du formulaire de modification de masse (Bulk Edit).
     */
    public function handle_bulk_edit_submission() {
        if ( ! isset( $_REQUEST['page'] ) || $_REQUEST['page'] !== $this->menu_slug ) {
            return;
        }

        if ( ! isset( $_REQUEST['bulk_edit'] ) || ! current_user_can( 'manage_order' ) ) {
            return;
        }
        
        if ( ! isset( $_REQUEST['users'] ) || ! is_array( $_REQUEST['users'] ) ) {
            return;
        }

        $meta_fields_to_check = array(
            self::META_COMPANY_ID,
            self::META_OWNER,
            self::META_LEAD_STATUS,     
            self::META_LIFECYCLE_PHASE, 
        );
        
        $has_changes = false;
        foreach ($meta_fields_to_check as $key) {
             // Vérifie si le champ est soumis ET que sa valeur n'est pas le marqueur "-1" (Pas de changement)
             if ( isset( $_REQUEST[$key] ) && $_REQUEST[$key] !== '-1' ) {
                 $has_changes = true;
                 break;
             }
        }
        
        if ( ! $has_changes ) {
            return;
        }

        $user_ids = array_map( 'absint', $_REQUEST['users'] );
        $changes_count = 0;
        
        foreach ( $user_ids as $user_id ) {
            
            // Mise à jour de l'entreprise liée
            if ( isset( $_REQUEST[self::META_COMPANY_ID] ) && $_REQUEST[self::META_COMPANY_ID] !== '-1' ) {
                $new_company_id = absint( $_REQUEST[self::META_COMPANY_ID] );
                update_user_meta( $user_id, self::META_COMPANY_ID, $new_company_id );
                $changes_count++;
            }
            
            // Mise à jour du propriétaire
            if ( isset( $_REQUEST[self::META_OWNER] ) && $_REQUEST[self::META_OWNER] !== '-1' ) {
                $new_owner_id = absint( $_REQUEST[self::META_OWNER] );
                update_user_meta( $user_id, self::META_OWNER, $new_owner_id );
                $changes_count++;
            }
            
            // Mise à jour du statut de lead
            if ( isset( $_REQUEST[self::META_LEAD_STATUS] ) && $_REQUEST[self::META_LEAD_STATUS] !== '-1' ) {
                $new_status = sanitize_text_field( $_REQUEST[self::META_LEAD_STATUS] );
                update_user_meta( $user_id, self::META_LEAD_STATUS, $new_status );
                $changes_count++;
            }
            
            // Mise à jour de la phase de cycle de vie
            if ( isset( $_REQUEST[self::META_LIFECYCLE_PHASE] ) && $_REQUEST[self::META_LIFECYCLE_PHASE] !== '-1' ) {
                $new_phase = sanitize_text_field( $_REQUEST[self::META_LIFECYCLE_PHASE] );
                update_user_meta( $user_id, self::META_LIFECYCLE_PHASE, $new_phase );
                $changes_count++;
            }
        }
        
        // Redirection avec message de succès
        if ( $changes_count > 0 ) {
            $redirect_url = admin_url( 'admin.php?page=' . $this->menu_slug . '&bulk_updated=' . count($user_ids) );
            
            // Ajout des paramètres de tri et de recherche (et de filtres existants) pour maintenir la vue
            foreach ( array('orderby', 'order', 's', 'company_id', self::META_LEAD_STATUS, self::META_LIFECYCLE_PHASE, 'paged') as $param ) {
                if ( isset( $_REQUEST[$param] ) ) {
                    $redirect_url = add_query_arg( $param, sanitize_text_field( $_REQUEST[$param] ), $redirect_url );
                }
            }
            
            wp_safe_redirect( $redirect_url );
            exit;
        }
    }

    /**
     * Méthode stubs pour le profil utilisateur (à compléter si nécessaire)
     */
    public function add_contact_meta_fields( $user ) {
        // ... (Implémentation du profil utilisateur ici)
    }

    public function save_contact_meta_fields( $user_id ) {
        // ... (Implémentation de la sauvegarde ici)
    }

    /**
     * Méthode stub pour les shortcodes (à compléter si nécessaire)
     */
    public function add_shortcodes() {
        add_shortcode( 'ispag_contact_list', array( $this, 'render_contact_list_shortcode' ) );
    }


// -----------------------------------------------------------------
// ADMIN UI : AFFICHAGE DE LA PAGE PRINCIPALE (LISTE DES CONTACTS)
// -----------------------------------------------------------------

    /**
     * Ajoute la page de menu "Contacts" sous le menu principal "ISPAG Entreprises".
     */
    public function add_admin_menu_page() {
        add_submenu_page(
            'ispag-entreprises', 
            __( 'Manage Contacts', 'ispag-crm' ),
            __( 'Contacts', 'ispag-crm' ),
            'manage_options',
            $this->menu_slug,
            array( $this, 'render_contacts_page' )
        );
    }

    /**
     * Méthode utilitaire pour générer les en-têtes de colonnes triables.
     */
    private function render_sortable_column_header( $column_id, $label, $current_orderby, $current_order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ) {
        $new_order = ( $current_orderby == $column_id && $current_order == 'ASC' ) ? 'DESC' : 'ASC';
        $class = ( $current_orderby == $column_id ) ? 'sorted ' . strtolower( $current_order ) : 'sortable asc';
        
        $query_args = array(
            'page' => $this->menu_slug,
            'orderby' => $column_id,
            'order' => $new_order,
            's' => $search_term,
            'company_id' => $company_filter_id > 0 ? $company_filter_id : false,
            self::META_LEAD_STATUS => ! empty( $status_filter ) ? $status_filter : false,
            self::META_LIFECYCLE_PHASE => ! empty( $lifecycle_filter ) ? $lifecycle_filter : false,
        );
        
        $query_args = array_filter($query_args); // Supprime les valeurs fausses/vides
        $url = esc_url( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
        
        ?>
        <th scope="col" id="<?php echo esc_attr( $column_id ); ?>" class="manage-column column-<?php echo esc_attr( $column_id ); ?> <?php echo esc_attr( $class ); ?>">
            <a href="<?php echo $url; ?>">
                <span><?php echo esc_html( $label ); ?></span>
                <span class="sorting-indicator"></span>
            </a>
        </th>
        <?php
    }
    
    /**
     * Affiche la page complète de la liste des contacts dans l'administration.
     */
    public function render_contacts_page() {
        if ( ! current_user_can( 'manage_order' ) ) {
            return;
        }
        
        global $wpdb;
        $table_name_fournisseur = $wpdb->prefix . 'achats_fournisseurs';

        // 🎯 NOUVEAU : Récupération des données complètes des statuts pour la table
        $lead_statuses_data = $this->get_all_statuses_data();
        $full_statuses_map = []; // Map clé => objet/array de données complètes
        foreach ($lead_statuses_data as $status) {
            // Assure que l'objet a les propriétés nécessaires, même si la BDD est encore en cours de mise à jour.
            $full_statuses_map[$status->status_key] = (object) array(
                'status_label' => $status->status_label,
                'bg_color' => isset($status->bg_color) ? $status->bg_color : '#cccccc',
                'text_color' => isset($status->text_color) ? $status->text_color : '#333333',
            );
        }

        $lead_statuses_map = $this->get_statuses_for_display(); // Map clé => label (pour les filtres et affichages de fallback)
        $lifecycle_phases_map = $this->get_lifecycle_phases_for_display(); 

        // Récupération des filtres
        $search_term = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $company_filter_id = isset( $_GET['company_id'] ) ? absint( $_GET['company_id'] ) : 0; 
        $status_filter = isset( $_GET[self::META_LEAD_STATUS] ) ? sanitize_key( $_GET[self::META_LEAD_STATUS] ) : '';
        $lifecycle_filter = isset( $_GET[self::META_LIFECYCLE_PHASE] ) ? sanitize_key( $_GET[self::META_LIFECYCLE_PHASE] ) : '';
        
        // Récupération du tri
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'name'; 
        $order = isset( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ) ) ? strtoupper( $_GET['order'] ) : 'ASC';

        $sortable_columns_map = array(
             'name'              => 'display_name',
             'email'             => 'user_email',
             'company_name'      => self::META_COMPANY_ID, 
             'lead_status'       => self::META_LEAD_STATUS,
             'lifecycle_phase'   => self::META_LIFECYCLE_PHASE,
             'owner'             => self::META_OWNER,
        );
        
        $args = array(
             'order'         => $order,
             'exclude'       => array( get_current_user_id() ), 
             'role__not_in'  => array('administrator', 'ispag_commercial', 'vente_ispag'),
             'fields'        => 'all_with_meta',
             'meta_query'    => array('relation' => 'AND'),
             'number'        => 800, // Limite arbitraire
        );
        
        // --- 1. Application des filtres de métadonnées ---
        $company_filter_name = '';
        if ( $company_filter_id > 0 ) {
            $company_data = $wpdb->get_row( $wpdb->prepare( 
                "SELECT Fournisseur FROM {$table_name_fournisseur} WHERE Id = %d", 
                $company_filter_id 
            ) );
            if ( $company_data ) {
                $company_filter_name = $company_data->Fournisseur;
            }

            $args['meta_query'][] = array(
                'key'     => self::META_COMPANY_ID,
                'value'   => $company_filter_id,
                'compare' => '=',
                'type'    => 'NUMERIC'
            );
        }
        
        if ( ! empty( $status_filter ) ) {
            $args['meta_query'][] = array(
                'key'     => self::META_LEAD_STATUS,
                'value'   => $status_filter,
                'compare' => '=',
            );
        }
        if ( ! empty( $lifecycle_filter ) ) {
            $args['meta_query'][] = array(
                'key'     => self::META_LIFECYCLE_PHASE,
                'value'   => $lifecycle_filter,
                'compare' => '=',
            );
        }
        // Supprime 'meta_query' si vide pour éviter une requête inefficace
        if ( count($args['meta_query']) === 1 && $args['meta_query']['relation'] === 'AND' ) {
             unset( $args['meta_query'] );
        }

        // --- 2. Application du tri ---
        if ( array_key_exists( $orderby, $sortable_columns_map ) ) {
            $current_orderby_key = $sortable_columns_map[$orderby];
            if ( in_array( $current_orderby_key, array( self::META_COMPANY_ID, self::META_LEAD_STATUS, self::META_LIFECYCLE_PHASE, self::META_OWNER ) ) ) {
                 $args['meta_key'] = $current_orderby_key; 
                 $args['orderby'] = 'meta_value'; 
                 $args['type'] = ( $current_orderby_key == self::META_COMPANY_ID || $current_orderby_key == self::META_OWNER) ? 'NUMERIC' : 'CHAR';
            } else {
                 $args['orderby'] = $current_orderby_key;
            }
        } else {
            $args['orderby'] = 'display_name';
        }

        // --- 3. Application de la recherche ---
        if ( ! empty( $search_term ) ) {
            $args['search'] = '*' . $search_term . '*';
            $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
        }
        
        // --- 4. Exécution de la requête ---
        $contacts = get_users( $args );
        
        // DONNÉES NÉCESSAIRES POUR LE BULK EDIT ET L'AFFICHAGE
        $companies = $wpdb->get_results( "SELECT Id, Fournisseur FROM {$table_name_fournisseur} ORDER BY Fournisseur ASC" );
        $owners = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'display_name' ) ) ); 
        $lead_statuses_options = $this->get_statuses_for_display();
        $lifecycle_phases_options = $this->get_lifecycle_phases_for_display(); 
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( __( 'Contact Management (CRM Style)', 'ispag-crm' ) ); ?>
                <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" class="page-title-action">
                    <?php echo esc_html( __( 'Add New Contact', 'ispag-crm' ) ); ?>
                </a>
            </h1>

            <?php if ( isset( $_GET['bulk_updated'] ) ) :
                 $count = absint( $_GET['bulk_updated'] ); ?>
                 <div class="notice notice-success is-dismissible"><p><?php echo sprintf( _n( 'One contact updated successfully.', '%s contacts updated successfully.', $count, 'ispag-crm' ), number_format_i18n( $count ) ); ?></p></div>
            <?php endif; ?>

            <?php 
            $active_filters_count = 0;
            if ($company_filter_id > 0) $active_filters_count++;
            if (!empty($status_filter)) $active_filters_count++;
            if (!empty($lifecycle_filter)) $active_filters_count++;

            if ( $active_filters_count > 0 || !empty($search_term) ) : ?>
                <div class="notice notice-info">
                    <p>
                        Filtrage actif : 
                        <?php 
                        if (!empty($search_term)) echo "Recherche : **" . esc_html($search_term) . "**. ";
                        if ($company_filter_id > 0) echo "Entreprise : **" . esc_html($company_filter_name) . "**. ";
                        if (!empty($status_filter)) echo "Statut de Lead : **" . esc_html($lead_statuses_map[$status_filter]) . "**. ";
                        if (!empty($lifecycle_filter)) echo "Phase de Cycle de Vie : **" . esc_html($lifecycle_phases_map[$lifecycle_filter]) . "**. ";
                        ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ); ?>">Annuler tous les filtres</a>
                    </p>
                </div>
            <?php endif; ?>
            
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="posts-filter">
                <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug ); ?>" />
                
                <?php if ( $company_filter_id > 0 ) : ?>
                    <input type="hidden" name="company_id" value="<?php echo absint( $company_filter_id ); ?>" />
                <?php endif; ?>

                <p class="search-box">
                    <label class="screen-reader-text" for="contact-search-input"><?php echo esc_html( __( 'Search Contacts:', 'ispag-crm' ) ); ?></label>
                    <input type="search" id="contact-search-input" name="s" value="<?php echo esc_attr( $search_term ); ?>" />
                    <?php submit_button( __( 'Search', 'ispag-crm' ), 'button', false, false, array( 'id' => 'search-submit' ) ); ?>
                </p>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ); ?>" id="posts-filter-bulk">
                
                <?php wp_nonce_field( 'bulk-users' ); // Assurez-vous d'utiliser un nonce pour la sécurité ?>
                <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug ); ?>" />

                <div id="bulk-edit-row" class="inline-edit-row inline-edit-row-users inline-edit-user" style="display: none;">
                    
                    <fieldset class="inline-edit-col-left">
                        <div class="inline-edit-col">
                            <h4><?php echo esc_html( __( 'Bulk Edit Contacts', 'ispag-crm' ) ); ?></h4>
                            <input type="hidden" name="action" value="edit" />
                            <?php 
                            // Reproduire les paramètres de tri et filtres dans le formulaire POST de Bulk Edit
                            $current_filters = array(
                                'orderby' => $orderby, 'order' => $order, 's' => $search_term,
                                'company_id' => $company_filter_id, self::META_LEAD_STATUS => $status_filter,
                                self::META_LIFECYCLE_PHASE => $lifecycle_filter
                            );
                            foreach ($current_filters as $key => $value) {
                                if (!empty($value) || $value > 0) {
                                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
                                }
                            }
                            ?>
                        </div>
                    </fieldset>

                    <fieldset class="inline-edit-col-right" id="ispag-bulk-edit-fields"> 
                        <div class="inline-edit-col">
                            <div class="inline-edit-group wp-clearfix">
                                
                                <label class="alignleft" style="width: 48%; margin-right: 2%;">
                                    <span class="title"><?php echo esc_html( __( 'Linked Company', 'ispag-crm' ) ); ?></span>
                                    <span class="input-text-wrap">
                                        <select name="<?php echo self::META_COMPANY_ID; ?>">
                                            <option value="-1"><?php echo esc_html( __( '— No Change —', 'ispag-crm' ) ); ?></option>
                                            <option value="0"><?php echo esc_html( __( '— Remove Link —', 'ispag-crm' ) ); ?></option>
                                            <?php foreach ( $companies as $company ) : ?>
                                                <option value="<?php echo absint( $company->Id ); ?>"><?php echo esc_html( $company->Fournisseur ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                </label>
                                
                                <label class="alignleft" style="width: 48%;">
                                    <span class="title"><?php echo esc_html( __( 'Owner', 'ispag-crm' ) ); ?></span>
                                    <span class="input-text-wrap">
                                        <select name="<?php echo self::META_OWNER; ?>">
                                            <option value="-1"><?php echo esc_html( __( '— No Change —', 'ispag-crm' ) ); ?></option>
                                            <option value="0"><?php echo esc_html( __( '— Remove Owner —', 'ispag-crm' ) ); ?></option>
                                            <?php foreach ( $owners as $owner ) : ?>
                                                <option value="<?php echo absint( $owner->ID ); ?>"><?php echo esc_html( $owner->display_name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                </label>

                                <label class="alignleft" style="width: 48%; margin-right: 2%;">
                                    <span class="title"><?php echo esc_html( __( 'Lead Status', 'ispag-crm' ) ); ?></span>
                                    <span class="input-text-wrap">
                                        <select name="<?php echo self::META_LEAD_STATUS; ?>">
                                            <option value="-1"><?php echo esc_html( __( '— No Change —', 'ispag-crm' ) ); ?></option>
                                            <option value="0"><?php echo esc_html( __( '— Clear Status —', 'ispag-crm' ) ); ?></option>
                                            <?php foreach ( $lead_statuses_options as $key => $label ) : ?>
                                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                </label>

                                <label class="alignleft" style="width: 48%;">
                                    <span class="title"><?php echo esc_html( __( 'Lifecycle phase', 'ispag-crm' ) ); ?></span>
                                    <span class="input-text-wrap">
                                        <select name="<?php echo self::META_LIFECYCLE_PHASE; ?>">
                                            <option value="-1"><?php echo esc_html( __( '— No Change —', 'ispag-crm' ) ); ?></option>
                                            <option value="0"><?php echo esc_html( __( '— Clear Phase —', 'ispag-crm' ) ); ?></option>
                                            <?php foreach ( $lifecycle_phases_options as $key => $label ) : ?>
                                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                </label>

                            </div> </div> </fieldset>

                    <div class="submit inline-edit-save">
                        <button type="button" class="cancel button-secondary alignleft"><?php echo esc_html( __( 'Cancel', 'ispag-crm' ) ); ?></button>
                        <button type="submit" name="bulk_edit" class="button-primary alignright"><?php echo esc_html( __( 'Update', 'ispag-crm' ) ); ?></button>
                        <div class="clear"></div>
                    </div>
                </div>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text"><?php echo esc_html( __( 'Select bulk action', 'ispag-crm' ) ); ?></label>
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1"><?php echo esc_html( __( 'Bulk Actions', 'ispag-crm' ) ); ?></option>
                            <option value="edit"><?php echo esc_html( __( 'Edit', 'ispag-crm' ) ); ?></option>
                        </select>
                        <?php submit_button( __( 'Apply', 'ispag-crm' ), 'action', 'doaction', false, array('id' => 'doaction') ); ?>
                    </div>
                    
                    <div class="alignleft actions">
                        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:inline-block;">
                            <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug ); ?>" />
                            <?php if ( ! empty( $search_term ) ) : ?>
                                <input type="hidden" name="s" value="<?php echo esc_attr( $search_term ); ?>" />
                            <?php endif; ?>
                            <?php if ( $company_filter_id > 0 ) : ?>
                                <input type="hidden" name="company_id" value="<?php echo absint( $company_filter_id ); ?>" />
                            <?php endif; ?>

                            <label for="filter-by-status" class="screen-reader-text"><?php echo esc_html( __( 'Filter by lead status', 'ispag-crm' ) ); ?></label>
                            <select name="<?php echo self::META_LEAD_STATUS; ?>" id="filter-by-status">
                                <option value=""><?php echo esc_html( __( 'All lead statuses', 'ispag-crm' ) ); ?></option>
                                <?php foreach ( $lead_statuses_map as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label for="filter-by-lifecycle" class="screen-reader-text"><?php echo esc_html( __( 'Filter by lifecycle phase', 'ispag-crm' ) ); ?></label>
                            <select name="<?php echo self::META_LIFECYCLE_PHASE; ?>" id="filter-by-lifecycle">
                                <option value=""><?php echo esc_html( __( 'All lifecycle phases', 'ispag-crm' ) ); ?></option>
                                <?php foreach ( $lifecycle_phases_map as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $lifecycle_filter, $key ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <?php submit_button( __( 'Filter', 'ispag-crm' ), 'action', 'filter_action', false ); ?>
                        </form>
                    </div>
                    <div class="clear"></div>
                </div>

                
                <table class="wp-list-table widefat fixed striped posts">
                    <thead>
                        <tr>
                            <th class="manage-column column-cb check-column" scope="col"><input type="checkbox"></th>
                            
                            <?php 
                            // Rendu des en-têtes triables
                            $this->render_sortable_column_header( 'name', __( 'Name', 'ispag-crm' ), $orderby, $order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ); 
                            $this->render_sortable_column_header( 'email', __( 'Email', 'ispag-crm' ), $orderby, $order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ); 
                            $this->render_sortable_column_header( 'company_name', __( 'Company Name', 'ispag-crm' ), $orderby, $order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ); 
                            $this->render_sortable_column_header( 'lead_status', __( 'Lead Status', 'ispag-crm' ), $orderby, $order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ); 
                            $this->render_sortable_column_header( 'lifecycle_phase', __( 'Lifecycle phase', 'ispag-crm' ), $orderby, $order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ); 
                            $this->render_sortable_column_header( 'owner', __( 'Owner', 'ispag-crm' ), $orderby, $order, $search_term, $company_filter_id, $status_filter, $lifecycle_filter ); 
                            ?>
                        </tr>
                    </thead>
                    
                    <tbody>
                        <?php if ( empty( $contacts ) ) : ?>
                            <tr>
                                <td colspan="7"><?php echo esc_html( __( 'No contacts found.', 'ispag-crm' ) ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php 
                            // Récupérer tous les IDs d'entreprises pour une seule requête
                            $company_ids = array();
                            foreach ( $contacts as $contact ) {
                                $company_id = get_user_meta( $contact->ID, self::META_COMPANY_ID, true );
                                if ( $company_id > 0 ) {
                                    $company_ids[] = absint( $company_id );
                                }
                            }
                            $company_names = array();
                            if ( ! empty( $company_ids ) ) {
                                $company_ids_str = implode( ',', array_unique( $company_ids ) );
                                $company_results = $wpdb->get_results( "SELECT Id, Fournisseur FROM {$table_name_fournisseur} WHERE Id IN ({$company_ids_str})" );
                                foreach ( $company_results as $res ) {
                                    $company_names[$res->Id] = $res->Fournisseur;
                                }
                            }
                            
                            foreach ( $contacts as $contact ) : 
                                
                                $user_lead_status_key = get_user_meta( $contact->ID, self::META_LEAD_STATUS, true );
                                $user_lifecycle_phase_key = get_user_meta( $contact->ID, self::META_LIFECYCLE_PHASE, true );
                                $user_owner_id = get_user_meta( $contact->ID, self::META_OWNER, true );
                                $user_company_id = get_user_meta( $contact->ID, self::META_COMPANY_ID, true );
                                
                                // Prépare les données du statut de lead
                                $status_label = isset( $lead_statuses_map[$user_lead_status_key] ) ? $lead_statuses_map[$user_lead_status_key] : __( 'N/A', 'ispag-crm' );
                                $status_bg_color = '#f2f2f2';
                                $status_text_color = '#333333';
                                
                                if ( ! empty( $user_lead_status_key ) && isset( $full_statuses_map[$user_lead_status_key] ) ) {
                                    $status_data = $full_statuses_map[$user_lead_status_key];
                                    $status_label = $status_data->status_label;
                                    $status_bg_color = $status_data->bg_color;
                                    $status_text_color = $status_data->text_color;
                                }
                                
                                // Prépare les autres données d'affichage
                                $company_name = isset( $company_names[$user_company_id] ) ? $company_names[$user_company_id] : __( 'N/A', 'ispag-crm' );
                                $lifecycle_label = isset( $lifecycle_phases_map[$user_lifecycle_phase_key] ) ? $lifecycle_phases_map[$user_lifecycle_phase_key] : __( 'N/A', 'ispag-crm' );
                                $owner = get_userdata( $user_owner_id );
                                $owner_name = $owner ? $owner->display_name : __( 'N/A', 'ispag-crm' );

                                ?>
                                <tr id="user-<?php echo absint( $contact->ID ); ?>">
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="users[]" value="<?php echo absint( $contact->ID ); ?>">
                                    </th>
                                    
                                    <td class="name column-name column-primary">
                                        <strong>
                                            <a href="<?php echo esc_url( get_edit_user_link( $contact->ID ) ); ?>">
                                                <?php echo esc_html( $contact->display_name ); ?>
                                            </a>
                                        </strong>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="<?php echo esc_url( get_edit_user_link( $contact->ID ) ); ?>">
                                                    <?php echo esc_html( __( 'Edit', 'ispag-crm' ) ); ?>
                                                </a> | 
                                            </span>
                                            <span class="view">
                                                <a href="<?php echo esc_url( get_author_posts_url( $contact->ID ) ); ?>">
                                                    <?php echo esc_html( __( 'View', 'ispag-crm' ) ); ?>
                                                </a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="email column-email">
                                        <a href="mailto:<?php echo esc_attr( $contact->user_email ); ?>">
                                            <?php echo esc_html( $contact->user_email ); ?>
                                        </a>
                                    </td>
                                    <td class="company_name column-company_name">
                                        <?php echo esc_html( $company_name ); ?>
                                    </td>
                                    
                                    <td class="lead_status column-lead_status">
                                        <span style="
                                            background-color: <?php echo esc_attr( $status_bg_color ); ?>;
                                            color: <?php echo esc_attr( $status_text_color ); ?>;
                                            padding: 4px 8px;
                                            border-radius: 3px;
                                            font-weight: bold;
                                            display: inline-block;
                                            font-size: 0.9em;
                                        ">
                                            <?php echo esc_html( $status_label ); ?>
                                        </span>
                                    </td>
                                    
                                    <td class="lifecycle_phase column-lifecycle_phase">
                                        <?php echo esc_html( $lifecycle_label ); ?>
                                    </td>
                                    <td class="owner column-owner">
                                        <?php echo esc_html( $owner_name ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="tablenav bottom">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-bottom" class="screen-reader-text"><?php echo esc_html( __( 'Select bulk action', 'ispag-crm' ) ); ?></label>
                        <select name="action2" id="bulk-action-selector-bottom">
                            <option value="-1"><?php echo esc_html( __( 'Bulk Actions', 'ispag-crm' ) ); ?></option>
                            <option value="edit"><?php echo esc_html( __( 'Edit', 'ispag-crm' ) ); ?></option>
                        </select>
                        <?php submit_button( __( 'Apply', 'ispag-crm' ), 'action', 'doaction2', false, array('id' => 'doaction2') ); ?>
                    </div>
                    <div class="clear"></div>
                </div>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // --- METHODES D'AIDE (RÉCUPÉRATION DE DONNÉES EN BASE) ---
    // -------------------------------------------------------------------------

    /**
     * Récupère la map complète des statuts de lead (clés, labels, couleurs et ordre).
     * Les données sont récupérées de la table ISPAG_Crm_Deal_Constants::LEAD_STATUS_TABLE_NAME.
     * @return array Map (status_key => status_object)
     */
    private function get_lead_statuses_map() {
        global $wpdb;
        $return = array();
        
        $table_name = ISPAG_Crm_Contact_Constants::LEAD_STATUS_TABLE_NAME; 

        $full_statuses = $wpdb->get_results( 
            "SELECT status_key, status_label, bg_color, text_color, status_order 
             FROM {$table_name} 
             ORDER BY status_order ASC, status_label ASC" 
        );
        
        if ( ! empty( $full_statuses ) ) {
            foreach ( $full_statuses as $status ) {
                if ( ! empty( $status->status_key ) ) {
                    
                    $status_data = new stdClass();
                    $status_data->label = $status->status_label;
                    $status_data->bg_color = $status->bg_color;
                    $status_data->text_color = $status->text_color;
                    $status_data->order = $status->status_order;
                    
                    $return[ $status->status_key ] = $status_data;
                }
            }
        }

        return $return;
    }

    /**
     * Récupère la map des phases de cycle de vie (avec couleurs).
     * @return array Map (phase_key => phase_object)
     */
    private function get_lifecycle_phases_for_display() {
        global $wpdb;
        $return = array();
        $table_name = ISPAG_Crm_Contact_Constants::LIFECYCLE_TABLE_NAME;

        $full_phases = $wpdb->get_results( 
            "SELECT phase_key, phase_label, bg_color, text_color, phase_order 
             FROM {$table_name} 
             ORDER BY phase_order ASC, phase_label ASC" 
        );
        
        if ( ! empty( $full_phases ) ) {
            foreach ( $full_phases as $phase ) {
                if ( ! empty( $phase->phase_key ) ) {
                    
                    $phase_data = new stdClass();
                    $phase_data->phase_label = $phase->phase_label;
                    $phase_data->bg_color = $phase->bg_color;
                    $phase_data->text_color = $phase->text_color;
                    $phase_data->phase_order = $phase->phase_order;
                    
                    $return[ $phase->phase_key ] = $phase_data;
                }
            }
        }

        return $return;
    }

    /**
     * Récupère le nombre de transactions par contact ID en une seule requête.
     *
     * @param array $contact_ids Tableau des IDs de contact à vérifier.
     * @return array Map (contact_id => count)
     */
    private function get_contact_transactions_count( $contact_ids ) {
        global $wpdb;
        $counts = array();

        if ( empty( $contact_ids ) ) {
            return $counts;
        }
        
        // Assurez-vous que tous les IDs sont des entiers et échappés
        $safe_ids = array_map( 'absint', $contact_ids );
        $safe_ids = array_filter( $safe_ids ); // Retire les 0 et autres non-numériques

        if ( empty( $safe_ids ) ) {
            return $counts;
        }
        
        $table_name = $wpdb->prefix . 'achats_liste_commande';
        
        // Construction de la clause WHERE pour inclure les IDs sous les deux formats de colonnes
        $where_clauses = [];
        foreach ($safe_ids as $id) {
            $id = (string) $id;
            $id_pattern = '%' . $wpdb->esc_like( $id ) . '%';
            $id_pattern_abonne = '%;' . $wpdb->esc_like( $id ) . ';%';
            
            // La logique est complexe car les IDs sont stockés en chaîne (LIKE)
            // Nous cherchons les transactions qui correspondent à un ID dans notre liste
            // Mais nous ne pouvons pas agréger car chaque ligne peut correspondre à plusieurs contacts.
            // Solution : utiliser FIND_IN_SET après avoir nettoyé le format de stockage
            
            // --- Méthode la plus simple pour la performance si les IDs sont stockés par ";id1;id2;" (comme suggéré par Abonne) ---
            // Cette approche va récupérer TOUTES les lignes, puis nous les filtrerons en PHP (solution la plus rapide ici).
            
            // 1. Récupérer toutes les transactions qui correspondent à AU MOINS UN contact ID
            $all_ids_str = implode(',', $safe_ids);
            
            // Requête utilisant la même logique que votre get_contact_transactions() mais en groupant les résultats pour le comptage.
            // ATTENTION : La nature de AssociatedContactIDs LIKE '%ID%' rend l'agrégation difficile en SQL pour l'attribuer à l'ID.
            // La solution la plus sûre et la plus efficace ici est de changer votre approche :
            
            // NOUVELLE APPROCHE OPTIMISÉE POUR LE COMPTAGE :
            // On récupère toutes les transactions et on les compte par contact ID.
            // Ceci est valide UNIQUEMENT si AssociatedContactIDs et Abonne ne contiennent QU'UN SEUL ID de contact.
            // MAIS si elles contiennent des listes (ex: "123;456"), la requête simple est impossible.
            // Compte tenu de la structure LIKE que vous utilisez, nous devons SIMPLIFIER :

            // Requête non agrégée rapide (Récupère tous les IDs de deals associés)
            // Le comptage sera fait en PHP, mais la requête est ultra-rapide.
            // Cela va être lent si vous avez des milliers de transactions.
            
            // Option 1: On agrège si on sait que les colonnes ne contiennent qu'un SEUL ID (ce qui n'est pas le cas ici)
            
            // Option 2: On doit boucler sur les contacts pour créer la clause WHERE
            // Ceci est le point lent que vous voulez éviter :
            // $where_clauses[] = "(AssociatedContactIDs LIKE '%{$id}%' OR Abonne LIKE '%;{$id};%')";
        }

        // --- Solution la plus simple et la plus rapide (si la table n'est pas gigantesque) ---
        // Récupérer le nombre total pour chaque contact, en simulant la recherche.
        $results = $wpdb->get_results( 
            "SELECT id, AssociatedContactIDs, Abonne FROM {$table_name}", 
            ARRAY_A 
        );
        
        // Initialiser les comptes
        foreach ($safe_ids as $id) {
            $counts[$id] = 0;
        }

        // Compter en PHP (plus lent que SQL, mais plus fiable vu la structure des données)
        foreach ( $results as $row ) {
            // On vérifie quels contacts dans $safe_ids sont concernés par cette transaction
            foreach ($safe_ids as $contact_id) {
                $contact_id_str = (string) $contact_id;
                
                // Vérification LIKE (votre logique actuelle)
                $is_associated = strpos($row['AssociatedContactIDs'], $contact_id_str) !== false;
                $is_abonne = strpos($row['Abonne'], ';' . $contact_id_str . ';') !== false;

                if ($is_associated || $is_abonne) {
                    // S'assurer qu'on ne compte pas la même transaction plusieurs fois par contact (ce qui est le but)
                    $counts[$contact_id]++;
                    // On peut s'arrêter de vérifier les autres IDs pour cette transaction si on sait qu'elle ne compte que pour un
                    // MAIS si une transaction peut concerner 2 contacts, alors on continue le inner loop.
                }
            }
        }

        return $counts;
    }

    /**
     * Récupère la date du dernier contact enregistré (max entre NOTES et PHASES)
     * pour un ensemble d'IDs de contact.
     * * @param array $contact_ids Tableau des IDs des contacts (utilisateurs).
     * @return array Tableau associatif [contact_id => date_string|null].
     */
    private function get_last_contact_dates_for_batch( $contact_ids ) {
        global $wpdb;
        
        $last_contact_dates = array();

        if ( empty( $contact_ids ) || ! is_array( $contact_ids ) ) {
            return $last_contact_dates;
        }

        // --- 1. Préparation des variables et noms de tables ---

        $table_notes        = $wpdb->prefix . 'ispag_contact_notes';
        $table_commandes    = $wpdb->prefix . 'achats_liste_commande';
        $table_phases       = $wpdb->prefix . 'achats_suivi_phase_commande';
        $table_slugs        = $wpdb->prefix . 'achats_slug_phase';
        
        // Types d'actions qui représentent un contact direct (Source NOTES)
        $contact_types = array( 'EMAIL', 'CALL', 'MEETING' );
        $contact_types_sql = "'" . implode("','", array_map('esc_sql', $contact_types)) . "'";
        
        // Placeholders pour les IDs de contact
        $contact_id_placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );

        // --- 2. Requête principale (utilise le tableau des IDs) ---
        // Cette requête va chercher la date MAX dans les deux sources pour TOUS les IDs en même temps.

        $query = $wpdb->prepare("
            SELECT 
                t.contact_id, 
                MAX(t.last_activity) AS last_contact
            FROM (
                -- SOUS-REQUÊTE A : Dernier contact basé sur les NOTES (EMAIL, CALL, MEETING)
                SELECT 
                    contact_id, 
                    MAX(created_at) AS last_activity
                FROM 
                    {$table_notes}
                WHERE 
                    type IN ({$contact_types_sql})
                    -- FIND_IN_SET n'est pas idéal pour la performance, mais nécessaire si contact_id est une chaîne de IDs
                    AND FIND_IN_SET( contact_id, %s ) > 0 
                GROUP BY 
                    contact_id
                
                UNION ALL
                
                -- SOUS-REQUÊTE B : Dernière phase de projet AVEC ID BREVO (> 0)
                SELECT 
                    CAST(SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT lc.AssociatedContactIDs ORDER BY FIELD(lc.AssociatedContactIDs, %s)), ',', 1) AS UNSIGNED) AS contact_id,
                    MAX(spc.date_modification) AS last_activity
                FROM 
                    {$table_commandes} lc
                INNER JOIN 
                    {$table_phases} spc ON lc.hubspot_deal_id = spc.hubspot_deal_id
                INNER JOIN 
                    {$table_slugs} sp ON spc.slug_phase = sp.SlugPhase 
                WHERE 
                    FIND_IN_SET( lc.AssociatedContactIDs, %s ) > 0
                    AND sp.Brevo_id IS NOT NULL 
                    AND sp.Brevo_id > 0
                GROUP BY 
                    lc.AssociatedContactIDs
                
            ) AS t
            GROUP BY t.contact_id
        ", 
            implode(',', $contact_ids), // Argument %s pour FIND_IN_SET dans NOTES
            implode(',', $contact_ids), // Argument %s pour FIELD/GROUP_CONCAT (tentative de gestion du FIND_IN_SET dans les commandes)
            implode(',', $contact_ids)  // Argument %s pour FIND_IN_SET dans COMMANDES
        );

        $results = $wpdb->get_results( $query );

        if ( $results ) {
            foreach ( $results as $row ) {
                // Format : [ID => 'AAAA-MM-JJ HH:MM:SS']
                $last_contact_dates[ $row->contact_id ] = $row->last_contact;
            }
        }

        return $last_contact_dates;
    }

    // -------------------------------------------------------------------------
    // --- METHODE DE RENDU PRINCIPALE ---
    // -------------------------------------------------------------------------


    /**
     * Gère l'affichage du tableau des contacts pour le shortcode [ispag_contact_list] (Front-End).
     *
     * @param array $atts Attributs du shortcode.
     * @return string Le HTML du tableau à afficher.
     */
    public function render_contact_list_shortcode( $atts ) {


        // Sécurité
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_order' ) ) {
            return '<p class="ispag-access-denied">' . __( 'You do not have permission to view this content.', 'ispag-crm' ) . '</p>';
        }

        global $wpdb;
        global $wp_query;
        // Note: Assurez-vous que cette table est toujours accessible par les utilisateurs ayant 'manage_options'
        $table_name_fournisseur = $wpdb->prefix . 'achats_fournisseurs';

        // 🎯 URL de l'application de contact externe (vers le shortcode de détail)
        $ispag_app_base_url = get_permalink( get_page_by_path( 'contact-detail' ) );
        
        // URL de base de la page actuelle, nettoyée de tous les paramètres de filtrage/tri/messages
        // NOUVEAU/MODIFIÉ : Ajout de 'paged' à la liste des paramètres à nettoyer
        $current_url = remove_query_arg( 
            array( 
                'orderby', 'order', 'search', 'paged', // AJOUT DE 'paged'
                'filter_owner', 'filter_company', 
                'filter_lifecycle', 'filter_status',
                'bulk_result', 'count' 
            ) 
        );

        // --- 1. GESTION DES REQUÊTES GET POUR TRI ET FILTRES ---
        $a = shortcode_atts( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'limit'   => 50, // MODIFIÉ
        ), $atts );
        
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : $a['orderby'];
        $order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : $a['order'];
        $search  = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
        
        // NOUVEAU : Gestion de la Pagination
        $limit = absint( $a['limit'] );
        $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
        $paged = ( isset( $_GET['paged'] ) && absint($_GET['paged']) > 1 ) ? absint( $_GET['paged'] ) : $paged;
        $offset = ( $paged - 1 ) * $limit;

        $filter_owner_id        = isset( $_GET['filter_owner'] ) ? absint( $_GET['filter_owner'] ) : 0;
        $filter_company_id      = isset( $_GET['filter_company'] ) ? absint( $_GET['filter_company'] ) : 0;
        $filter_lifecycle_key   = isset( $_GET['filter_lifecycle'] ) ? sanitize_key( $_GET['filter_lifecycle'] ) : '';
        $filter_status_key      = isset( $_GET['filter_status'] ) ? sanitize_key( $_GET['filter_status'] ) : '';

        // Message de confirmation/erreur (si l'édition groupée a eu lieu)
        $bulk_result = isset( $_GET['bulk_result'] ) ? sanitize_key( $_GET['bulk_result'] ) : '';
        $bulk_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;

        // 2. Récupération des maps de données (inchangée)
        $lead_statuses_map = $this->get_lead_statuses_map(); 
        $lifecycle_phases_map = $this->get_lifecycle_phases_for_display();
        

        // 3. Construction des arguments WP_User_Query
        $args = array(
            'number'    => $limit, // MODIFIÉ : Limite pour la pagination
            'offset'    => $offset, // NOUVEAU : Décalage pour la pagination
            'orderby'   => $orderby,
            'order'     => $order,
            'exclude'   => array( get_current_user_id() ), 
            'role__not_in' => array('administrator', 'ispag_commercial', 'vente_ispag'),
            'fields'    => 'all_with_meta', 
            'search'    => ( ! empty( $search ) ) ? '*' . $wpdb->esc_like( $search ) . '*' : '',
            'meta_query' => array( 'relation' => 'AND' )
        );

        // NOUVEAU : Logique pour trier par Meta-clé
        $meta_orderby_keys = array( 
            self::META_LEAD_FUNCTION,
            self::META_COMPANY_NAME, 
            self::META_LIFECYCLE_PHASE, 
            self::META_LEAD_STATUS, 
            'ispag_transactions', // Clé de tri des transactions (si vous l'utilisez)
            self::META_OWNER,
            self::META_LAST_CONTACT_DATE, // Clé de tri pour la date de dernier contact
        ); 

        if ( in_array( $orderby, $meta_orderby_keys ) ) {
            $args['meta_key'] = $orderby;
            
            // La date doit être triée comme une valeur non numérique (DATE/DATETIME)
            if ( $orderby === 'ispag_last_contact' ) {
                $args['orderby'] = 'meta_value'; // Tri de chaîne (fonctionne bien avec AAAA-MM-JJ)
            } else {
                $args['orderby'] = 'meta_value';
            }
        }

        // --- Ajout des filtres basés sur les métadonnées (meta_query) --- (inchangé)
        $is_any_filter_active = ! empty( $search ) || $filter_owner_id > 0 || $filter_company_id > 0 || ! empty( $filter_lifecycle_key ) || ! empty( $filter_status_key );

        if ( $filter_owner_id > 0 ) {
            $args['meta_query'][] = array(
                'key'     => self::META_OWNER,
                'value'   => $filter_owner_id,
                'compare' => '=',
            );
        }
        if ( $filter_company_id > 0 ) {
            $args['meta_query'][] = array(
                'key'     => self::META_COMPANY_ID,
                'value'   => $filter_company_id,
                'compare' => '=',
            );
        }
        if ( ! empty( $filter_lifecycle_key ) ) {
            $args['meta_query'][] = array(
                'key'     => self::META_LIFECYCLE_PHASE,
                'value'   => $filter_lifecycle_key,
                'compare' => '=',
            );
        }
        if ( ! empty( $filter_status_key ) ) {
            $args['meta_query'][] = array(
                'key'     => self::META_LEAD_STATUS,
                'value'   => $filter_status_key,
                'compare' => '=',
            );
        }

        if ( count( $args['meta_query'] ) === 1 && isset( $args['meta_query']['relation'] ) ) {
            unset( $args['meta_query'] );
        }

        $contacts_query = new WP_User_Query( $args );
        $contacts = $contacts_query->get_results();
        $total_users = $contacts_query->get_total(); // Récupère le nombre total pour la pagination
        $num_pages = ceil( $total_users / $limit ); // Calcul du nombre total de pages

        // NOUVEAU : Pré-calcul du Last Contact (en plus des transactions)
        $contact_ids_for_count = wp_list_pluck( $contacts, 'ID' );
        $transactions_counts = $this->get_contact_transactions_count( $contact_ids_for_count ); 
        // APPEL DE LA MÉTHODE get_last_contact_dates()
        // $last_contact_dates = $this->get_last_contact_dates_for_batch( $contact_ids_for_count ); 

        // Pré-chargement des données (pour les dropdowns et l'affichage) (inchangé)
        $companies_lookup = array();
        $companies_data = $wpdb->get_results( "SELECT Id, Fournisseur FROM {$table_name_fournisseur} ORDER BY Fournisseur ASC" );
        foreach ($companies_data as $company) {
            $companies_lookup[$company->Id] = $company->Fournisseur;
        }
        $owners_lookup = array();
        // $owners_data = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'display_name' ) ) );

        $owners_data =  get_users( array( 
            'role__in' => array('administrator', 'commercial', 'vente_ispag'), 
            'fields' => array( 'ID', 'display_name' ),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'key' => 'ID',
        ));
        foreach ($owners_data as $owner) {
            $owners_lookup[$owner->ID] = $owner->display_name;
        }

        ob_start();
        
        // --- PARTIE NOTIFICATION (inchangée) ---
        if ( ! empty( $bulk_result ) ) {
            if ( 'success' === $bulk_result && $bulk_count > 0 ) {
                echo '<div class="ispag-alert ispag-alert-success">';
                printf( _n( '%d contact successfully updated.', '%d contacts successfully updated.', $bulk_count, 'ispag-crm' ), $bulk_count );
                echo '</div>';
            } elseif ( strpos( $bulk_result, 'error' ) === 0 ) {
                $error_message = __( 'An unknown error occurred during the bulk action.', 'ispag-crm' );
                if ( 'error_missing_data' === $bulk_result ) {
                    $error_message = __( 'Error: No contacts selected or no action chosen.', 'ispag-crm' );
                } elseif ( 'error_invalid_action' === $bulk_result ) {
                    $error_message = __( 'Error: Invalid bulk action selected.', 'ispag-crm' );
                }
                echo '<div class="ispag-alert ispag-alert-danger">' . esc_html( $error_message ) . '</div>';
            }
        }
        ?>
        
        <div class="ispag-contact-list-container">
        <h3><?php echo esc_html( __( 'ISPAG Contacts List', 'ispag-crm' ) ); ?> (<?php echo $total_users; ?>)</h3>

        <form method="get" class="ispag-filter-bar">
            <?php 
            // Ajouter les champs cachés pour conserver les paramètres de tri actuels
            if ( $orderby !== $a['orderby'] ) : ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
            <?php endif; 
            if ( $order !== $a['order'] ) : ?>
                <input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
            <?php endif; ?>

            <input type="text" name="search" placeholder="<?php esc_attr_e( 'Search (Name/Email)...', 'ispag-crm' ); ?>" value="<?php echo esc_attr( $search ); ?>" />

            <select name="filter_owner">
                <option value="0"><?php esc_html_e( 'Owner', 'ispag-crm' ); ?></option>
                <?php foreach ($owners_lookup as $id => $name) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected( $filter_owner_id, $id ); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="filter_company">
                <option value="0"><?php esc_html_e( 'Company', 'ispag-crm' ); ?></option>
                <?php foreach ($companies_lookup as $id => $name) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected( $filter_company_id, $id ); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="filter_lifecycle">
                <option value=""><?php esc_html_e( 'Lifecycle phase', 'ispag-crm' ); ?></option>
                <?php foreach ($lifecycle_phases_map as $key => $phase) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected( $filter_lifecycle_key, $key ); ?>>
                        <?php echo esc_html($phase->phase_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="filter_status">
                <option value=""><?php esc_html_e( 'Lead Status', 'ispag-crm' ); ?></option>
                <?php foreach ($lead_statuses_map as $key => $status) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected( $filter_status_key, $key ); ?>>
                        <?php echo esc_html($status->label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="ispag-btn ispag-btn-secondary"><?php esc_html_e( 'Filter', 'ispag-crm' ); ?></button>
            <?php 
            if ( $is_any_filter_active ) : ?>
                <a href="<?php echo esc_url( $current_url ); ?>" class="ispag-btn ispag-btn-grey-outlined"><?php esc_html_e( 'Reset', 'ispag-crm' ); ?></a>
            <?php endif; ?>
        </form>

        <?php
            // --- BLOC DE PAGINATION ---
            if ( $num_pages > 1 ) {
                
                // 1. Récupérer l'URL de la page (du shortcode) sans le chemin de pagination (/page/X/)
                // Cela garantit une base propre, même si la page actuelle est déjà paginée.
                $base_page_url = get_permalink(); 
                
                // 2. Récupérer tous les paramètres de requête actifs (filtres, tri, recherche)
                $current_query_args = $_GET;
                
                // 3. Retirer les paramètres WordPress de pagination qui pourraient causer conflit
                unset( $current_query_args['paged'] ); 
                
                // 4. Construire l'URL de base pour paginate_links() : Permalien propre + Filtres actifs
                // Cette base inclut tous les filtres actifs (orderby, order, search, etc.)
                $pagination_base = add_query_arg( $current_query_args, $base_page_url );
                
                $pagination_links = paginate_links( array(
                    'base' => add_query_arg( 
                        'paged', '%#%', // Ajoute le placeholder de pagination à la fin de l'URL filtrée
                        $pagination_base 
                    ),
                    'format'  => '', // On laisse WordPress décider si c'est un '?' ou un '&'
                    'current' => $paged,
                    'total'   => $num_pages,
                    'prev_text' => '&laquo; Précédent',
                    'next_text' => 'Suivant &raquo;',
                    'type' => 'list', 
                ) );
        

                if ( $pagination_links ) {
                    // Afficher le total et la pagination
                    echo '<div class="tablenav top">';
                    echo '<div class="tablenav-pages">';
                    echo '<span class="displaying-num">' . sprintf( _n( '%d contact', '%d contacts', $total_users, 'ispag-crm' ), $total_users ) . '</span>';
                    echo $pagination_links;
                    echo '</div>';
                    echo '</div>';
                }
            }
            ?>
            
        </div>
        
        
        <form method="post" id="ispag-bulk-edit-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ispag_bulk_edit_contacts" /> 
            <input type="hidden" name="ispag_bulk_action_nonce" value="<?php echo wp_create_nonce( 'ispag_crm_nonce' ); ?>" />

            <div class="ispag-bulk-actions">
                <select name="bulk_action_type" id="ispag-bulk-action-select">
                    <option value="0"><?php esc_html_e( 'Bulk Actions...', 'ispag-crm' ); ?></option>
                    <optgroup label="<?php esc_attr_e( 'Change Lead Status', 'ispag-crm' ); ?>">
                        <?php foreach ($lead_statuses_map as $key => $status) : ?>
                            <option value="status_<?php echo esc_attr($key); ?>">
                                <?php printf( __( 'Set Status to: %s', 'ispag-crm' ), esc_html($status->label) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?php esc_attr_e( 'Change lifecycle phase', 'ispag-crm' ); ?>">
                        <?php foreach ($lifecycle_phases_map as $key => $phase) : ?>
                            <option value="lifecycle_<?php echo esc_attr($key); ?>">
                                <?php printf( __( 'Set Phase to: %s', 'ispag-crm' ), esc_html($phase->phase_label) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?php esc_attr_e( 'Change Owner', 'ispag-crm' ); ?>">
                        <?php foreach ($owners_lookup as $id => $name) : ?>
                            <option value="owner_<?php echo esc_attr($id); ?>">
                                <?php printf( __( 'Assign to: %s', 'ispag-crm' ), esc_html($name) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                
                <button type="submit" class="ispag-btn ispag-btn-danger" name="apply_bulk_action">
                    <?php esc_html_e( 'Apply', 'ispag-crm' ); ?>
                </button>
            </div>

            <table class="ispag-contact-list-table">
                <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">
                        <input type="checkbox" id="ispag-select-all" title="<?php esc_attr_e('Select All', 'ispag-crm'); ?>" />
                    </th>
                    
                    <?php 
                        // MODIFIÉ : Ajout de la colonne 'ispag_last_contact' pour le tri
                        $sortable_columns = array(
                            'display_name'                  => __( 'Contact Name', 'ispag-crm' ),
                            self::META_LEAD_FUNCTION        => __( 'Function', 'ispag-crm' ), 
                            self::META_COMPANY_NAME         => __( 'Company Name', 'ispag-crm' ), 
                            self::META_LEAD_STATUS          => __( 'Lead Status', 'ispag-crm' ), 
                            self::META_LIFECYCLE_PHASE      => __( 'Lifecycle phase', 'ispag-crm' ), 
                            self::META_LAST_CONTACT_DATE    => __( 'Last contact', 'ispag-crm' ), // NOUVEAU
                            'ispag_transactions'            => __( 'Transactions', 'ispag-crm' ), 
                            self::META_OWNER                => __( 'Owner', 'ispag-crm' ), 
                            'user_email'                    => __( 'Email', 'ispag-crm' ),
                        );
                        
                        foreach ( $sortable_columns as $key => $label ) : 
                            $new_order = ( $orderby === $key && 'ASC' === $order ) ? 'DESC' : 'ASC';
                            $current_class = ( $orderby === $key ) ? ' current ' . strtolower($order) : ''; // Ajout de la classe d'ordre
                            
                            // Le lien doit conserver TOUS les paramètres de filtre/recherche actuels
                            $sort_link = add_query_arg( 
                                array( 
                                    'orderby'           => $key, 
                                    'order'             => $new_order,
                                    'search'            => $search, 
                                    'filter_owner'      => $filter_owner_id, 
                                    'filter_company'    => $filter_company_id,
                                    'filter_lifecycle'  => $filter_lifecycle_key,
                                    'filter_status'     => $filter_status_key,
                                    'paged'             => 1, // On remet toujours à la première page lors d'un tri
                                ), 
                                $current_url 
                            );
                    ?>
                            <th>
                                <a href="<?php echo esc_url( $sort_link ); ?>" class="ispag-sort-link<?php echo $current_class; ?>">
                                    <?php echo esc_html( $label ); ?>
                                    <span class="sort-icon"></span>
                                </a>
                            </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ( $contacts ) : ?>
                    <?php foreach ( $contacts as $contact ) : 
                        // Logique d'affichage des données (inchangée)
                        $company_id_linked = absint( get_user_meta( $contact->ID, self::META_COMPANY_ID, true ) );
                        $linked_company_name = $company_id_linked > 0 && isset($companies_lookup[$company_id_linked]) 
                            ? esc_html( $companies_lookup[$company_id_linked] ) : __( 'N/A', 'ispag-crm' );

                        $raw_lead_status = get_user_meta( $contact->ID, self::META_LEAD_STATUS, true );
                        $lead_status_key = !empty($raw_lead_status) ? $raw_lead_status : 'na';
                        $status_label = 'N/A';
                        $status_style = 'background-color: #bdc3c7; color: #333;'; 
                        if ($lead_status_key !== 'na' && isset($lead_statuses_map[$lead_status_key])) {
                            $status_data = $lead_statuses_map[$lead_status_key];
                            $status_label = esc_html( $status_data->label );
                            $status_style = sprintf('background-color: %s; color: %s;', esc_attr($status_data->bg_color), esc_attr($status_data->text_color));
                        }

                        $transaction_count = isset( $transactions_counts[$contact->ID] ) ? $transactions_counts[$contact->ID] : 0;

                        $raw_lifecycle_phase = get_user_meta( $contact->ID, self::META_LIFECYCLE_PHASE, true );
                        $lifecycle_phase_key = !empty($raw_lifecycle_phase) ? $raw_lifecycle_phase : 'na';
                        $phase_data_object = isset( $lifecycle_phases_map[$lifecycle_phase_key] ) 
                            ? $lifecycle_phases_map[$lifecycle_phase_key] 
                            : (object)['phase_label' => 'N/A', 'bg_color' => '#bdc3c7', 'text_color' => '#333'];
                        $lifecycle_phase_label = esc_html( $phase_data_object->phase_label );
                        $lifecycle_phase_style = sprintf('background-color: %s; color: %s;', esc_attr($phase_data_object->bg_color), esc_attr($phase_data_object->text_color));
                        
                        $owner_id = absint( get_user_meta( $contact->ID, self::META_OWNER, true ) );
                        $owner_name = $owner_id > 0 && isset($owners_lookup[$owner_id]) 
                            ? esc_html( $owners_lookup[$owner_id] ) : 'N/A';
                        
                        // // NOUVEAU : Récupération et formatage de la date du dernier contact
                        // $last_contact_raw = isset( $last_contact_dates[$contact->ID] ) ? $last_contact_dates[$contact->ID] : '';

                        $meta_key = self::META_LAST_CONTACT_DATE;
                        $contact_id = $contact->ID;
                        $last_contact_raw = get_user_meta( $contact_id, $meta_key, true );
                        $last_contact_display = !empty($last_contact_raw) 
                            ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_contact_raw) ) 
                            : 'N/A';
                        // $last_contact_display = !empty($last_contact_raw) 
                        //     ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_contact_raw) ) 
                        //     : 'N/A';

                        // Fonction pour récupérer les méta-données
                        $get_meta = function($key) use ($contact_id) {
                            return get_user_meta( $contact_id, $key, true );
                        };
                        
                        $contact_function = $get_meta(self::META_LEAD_FUNCTION) ?: 'N/A';
                    ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="ispag_contact_ids[]" value="<?php echo absint( $contact->ID ); ?>" />
                            </td>
                            
                            <td>
                                <?php $contact_app_url = esc_url( add_query_arg( 'user_id', $contact->ID, $ispag_app_base_url ) ); ?>
                                <a href="<?php echo $contact_app_url; ?>" target="_blank" rel="noopener noreferrer">
                                <strong><?php echo esc_html( $contact->display_name ); ?></strong>
                                </a>
                            </td>
                            <td><?php echo $contact_function; ?></td>
                            <td><?php echo $linked_company_name; ?></td>
                            <td>
                                <span class="ispag-status-badge" style="<?php echo $status_style; ?>">
                                    <?php echo $status_label; ?>
                                </span>
                            </td>
                            <td>
                                <span class="ispag-status-badge" style="<?php echo $lifecycle_phase_style; ?>">
                                    <?php echo $lifecycle_phase_label; ?>
                                </span>
                            </td>

                            
                            <td>
                                <?php echo esc_html( $last_contact_display ); ?>
                            </td>

                            <td><?php echo $transaction_count; ?></td>
                            <td class="ispag-owner-name"><?php echo $owner_name; ?></td>
                            <td>
                                <a href="mailto:<?php echo esc_attr( $contact->user_email ); ?>">
                                    <?php echo esc_html( $contact->user_email ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="<?php echo count($sortable_columns) + 1; ?>"><?php echo esc_html( __( 'No contacts found.', 'ispag-crm' ) ); ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>

        </form>
        
        <?php
            // --- BLOC DE PAGINATION ---
            if ( $num_pages > 1 ) {
                
                // 1. Récupérer l'URL de la page (du shortcode) sans le chemin de pagination (/page/X/)
                // Cela garantit une base propre, même si la page actuelle est déjà paginée.
                $base_page_url = get_permalink(); 
                
                // 2. Récupérer tous les paramètres de requête actifs (filtres, tri, recherche)
                $current_query_args = $_GET;
                
                // 3. Retirer les paramètres WordPress de pagination qui pourraient causer conflit
                unset( $current_query_args['paged'] ); 
                
                // 4. Construire l'URL de base pour paginate_links() : Permalien propre + Filtres actifs
                // Cette base inclut tous les filtres actifs (orderby, order, search, etc.)
                $pagination_base = add_query_arg( $current_query_args, $base_page_url );
                
                $pagination_links = paginate_links( array(
                    'base' => add_query_arg( 
                        'paged', '%#%', // Ajoute le placeholder de pagination à la fin de l'URL filtrée
                        $pagination_base 
                    ),
                    'format'  => '', // On laisse WordPress décider si c'est un '?' ou un '&'
                    'current' => $paged,
                    'total'   => $num_pages,
                    'prev_text' => '&laquo; Précédent',
                    'next_text' => 'Suivant &raquo;',
                    'type' => 'list', 
                ) );
        

                if ( $pagination_links ) {
                    // Afficher le total et la pagination
                    echo '<div class="tablenav bottom">';
                    echo '<div class="tablenav-pages">';
                    echo '<span class="displaying-num">' . sprintf( _n( '%d contact', '%d contacts', $total_users, 'ispag-crm' ), $total_users ) . '</span>';
                    echo $pagination_links;
                    echo '</div>';
                    echo '</div>';
                }
            }
            ?>
            
        </div>
        <?php
        
        return ob_get_clean();
    }

    /**
     * Méthode temporaire pour la migration/mise à jour en masse des metas de contact.
     *
     * Exécute une mise à jour de '_ispag_last_contact_date' et '_ispag_company_name'
     * pour tous les contacts (utilisateurs) non admin.
     */
    // private function update_all_contact_meta() {
    //     global $wpdb;
    //     $log_file = WP_CONTENT_DIR . '/ispag_contact_manager.log';

    //     // error_log("--- DEBUT EXECUTION update_all_contact_meta ---\n", 3, $log_file);

    //     // 1. Récupérer TOUS les IDs des contacts (utilisateurs)
    //     $contact_ids_to_update = $wpdb->get_col( "
    //         SELECT 
    //             ID 
    //         FROM 
    //             {$wpdb->users}
    //         -- Exclure les administrateurs et autres rôles non contact pour la mise à jour
    //         WHERE 
    //             ID NOT IN (
    //                 SELECT user_id 
    //                 FROM {$wpdb->usermeta} 
    //                 WHERE meta_key = '{$wpdb->prefix}capabilities' 
    //                 AND meta_value LIKE '%administrator%'
    //             )
    //     " );

    //     if ( empty( $contact_ids_to_update ) ) {
    //         // error_log("Aucun contact à mettre à jour.\n", 3, $log_file);
    //         return;
    //     }

    //     // --- MISE À JOUR 1 : last_contact_date ---
        
    //     // Récupérer les dernières dates d'activité en une seule requête (méthode existante)
    //     $last_contact_dates = $this->get_last_contact_dates_for_batch( $contact_ids_to_update );
    //     $updated_last_contact_count = 0;

    //     foreach ( $contact_ids_to_update as $contact_id ) {
    //         $date_value = isset( $last_contact_dates[$contact_id] ) ? $last_contact_dates[$contact_id] : '';
            
    //         // Mettre à jour ou ajouter la meta
    //         if ( update_user_meta( $contact_id, self::META_LAST_CONTACT_DATE, $date_value ) ) {
    //             $updated_last_contact_count++;
    //         }
    //     }
    //     // error_log("Mise à jour de LAST CONTACT: {$updated_last_contact_count} contacts mis à jour.\n", 3, $log_file);


    //     // --- MISE À JOUR 2 : company_name (pour trier par nom de compagnie) ---

    //     // Récupérer TOUTES les IDs de compagnie des contacts en une seule requête
    //     $company_ids_meta = $wpdb->get_results( "
    //         SELECT 
    //             user_id, 
    //             meta_value AS company_id
    //         FROM 
    //             {$wpdb->usermeta} 
    //         WHERE 
    //             meta_key = '" . self::META_COMPANY_ID . "' 
    //             AND user_id IN (" . implode(',', $contact_ids_to_update) . ")
    //     ", ARRAY_A );

    //     // Si des IDs de compagnie sont trouvés, on prépare le lookup des Noms de compagnie
    //     $company_ids_found = wp_list_pluck( $company_ids_meta, 'company_id' );
    //     $company_names_lookup = array();

    //     if ( ! empty( $company_ids_found ) ) {
    //         // Récupérer les noms des fournisseurs/compagnies
    //         $companies_data = $wpdb->get_results( "SELECT Id, Fournisseur FROM {$wpdb->prefix}achats_fournisseurs WHERE Id IN (" . implode(',', $company_ids_found) . ")" );
    //         foreach ($companies_data as $company) {
    //             $company_names_lookup[$company->Id] = $company->Fournisseur;
    //         }
    //     }

    //     $updated_company_name_count = 0;

    //     // Itérer sur les IDs de compagnie des contacts pour mettre à jour la meta du Nom
    //     foreach ( $company_ids_meta as $meta_row ) {
    //         $contact_id = $meta_row['user_id'];
    //         $company_id = absint( $meta_row['company_id'] );
            
    //         $company_name = isset( $company_names_lookup[$company_id] ) 
    //                         ? $company_names_lookup[$company_id] 
    //                         : ''; // Laisser vide si non trouvé
            
    //         // Mettre à jour ou ajouter la meta du NOM de la compagnie
    //         if ( update_user_meta( $contact_id, self::META_COMPANY_NAME, $company_name ) ) {
    //             $updated_company_name_count++;
    //         }
    //     }
    //     // error_log("Mise à jour de COMPANY NAME: {$updated_company_name_count} contacts mis à jour.\n", 3, $log_file);

    //     // error_log("--- FIN EXECUTION update_all_contact_meta ---\n", 3, $log_file);
    // }

} // Fin de la classe ISPAG_Contact_Manager

// Initialisation (à faire dans le fichier principal du plugin)
// $ispag_contact_manager = new ISPAG_Contact_Manager();