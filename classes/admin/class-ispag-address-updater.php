<?php

if ( ! class_exists( 'ISPAG_Address_Updater' ) ) :

/**
 * Gère la migration des données d'adresse depuis wor9711_01_mappage_adresse 
 * vers la table fournisseur wor9711_achats_fournisseurs et ses metas.
 * * ATTENTION : Cette version utilise le 'viag_id' de la source directement comme
 * l'ID du post/fournisseur dans update_post_meta().
 */
class ISPAG_Address_Updater {

    private $wpdb;

    // Constantes de tables
    private $source_table        = 'wor9711_01_mappage_adresse';
    private $fournisseurs_table  = 'wor9711_achats_fournisseurs';
    
    // Constantes de Méta-données (INCHANGÉES)
    const META_ADDRESS_2          = 'ispag_company_adress2'; 
    const META_RABATT_AGREE       = 'ispag_rabatt_agree';
    const META_PRIMARY_CONTACT    = 'ispag_primary_contact';
    const META_IS_ACTIVE          = 'ispag_is_active';

    const META_COMPANY_CITY       = 'ispag_company_city';
    const META_COMPANY_ADRESS     = 'ispag_company_adress';
    const META_COMPANY_POSTAL_CODE = 'ispag_company_postal_code';
    const META_COMPANY_REGION     = 'ispag_company_region'; 
    const META_COMPANY_COUNTRY    = 'ispag_company_country'; 
    const META_COMPANY_INDUSTRY   = 'ispag_company_industry'; 
    const META_COMPANY_PHONE      = 'ispag_company_phone';
    const META_COMPANY_MAIL       = 'ispag_company_mail';

    
    // Slugs et Actions (INCHANGÉS)
    private $menu_parent_slug    = 'ispag_main_menu'; 
    private $migration_page_slug = 'ispag_address_migration'; 
    private $migration_action    = 'ispag_start_db_migration'; 

    /**
     * Définit le mappage des colonnes entre la source et la destination.
     */
    private $migration_map = [
        'viag_id'              => 'viag_id',
        'Nom'                  => 'Fournisseur',
        'Adresse'              => 'META_COMPANY_ADRESS', 
        'Adresse 2'            => 'META_ADDRESS_2', 
        'PLZ'                  => 'META_COMPANY_POSTAL_CODE', 
        'Localité'             => 'META_COMPANY_CITY', 
        'Tél.'                 => 'META_COMPANY_PHONE', 
        'Adresse e-mail'       => 'META_COMPANY_MAIL', 
        'Vereinbarter Rabatt'  => 'META_RABATT_AGREE', 
        'Contact primaire'     => 'META_PRIMARY_CONTACT',
        'Actif'                => 'META_IS_ACTIVE',
        
    ];


    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );
            add_action( 'admin_post_' . $this->migration_action, array( $this, 'handle_database_migration' ) );
        }
    }

    // ----------------------------------------------------------------------------------
    // --- 1. Interface d'Administration (INCHANGÉE) ---
    // ----------------------------------------------------------------------------------

    public function add_admin_menu_page() {
        add_submenu_page(
            $this->menu_parent_slug, 
            __( '3. Maj Adresses DB', 'ispag-crm' ),
            __( '3. Maj Adresses DB', 'ispag-crm' ),
            'manage_options',
            $this->migration_page_slug,
            array( $this, 'render_migration_page' )
        );
    }
    
    public function render_migration_page() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'Mise à Jour des Adresses Fournisseur via Migration DB', 'ispag-crm' ); ?></h1>
            <hr>
            
            <?php $this->display_admin_message( $this->migration_page_slug ); ?>

            <p class="description">
                <?php _e( "Cette action va parcourir la table source **`{$this->source_table}`** et mettre à jour les informations correspondantes dans la table fournisseur **`{$this->fournisseurs_table}`** et ses **méta-données** en se basant sur la colonne **`viag_id`**.", 'ispag-crm' ); ?>
            </p>
            <p class="description">
                **ATTENTION :** Cette opération est immédiate et irréversible.
            </p>
            
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($this->migration_action); ?>">
                <?php wp_nonce_field( $this->migration_action . '_nonce' ); ?>
                
                <?php submit_button( __( 'Lancer la Migration des Adresses', 'ispag-crm' ), 'primary large', 'start_db_migration', true, ['onclick' => "return confirm('Êtes-vous sûr de vouloir lancer la migration ?')"] ); ?>
            </form>
        </div>
        <?php
    }

    // ----------------------------------------------------------------------------------
    // --- 2. Logique Principale (Migration) ---
    // ----------------------------------------------------------------------------------

    /**
     * Gère le traitement final de la migration DB.
     */
    public function handle_database_migration() {
        
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], $this->migration_action . '_nonce' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Erreur de sécurité. Nonce invalide.', 'ispag-crm' ) );
        }
        
        $updated_count = 0;
        
        // 1. Récupérer toutes les données de la table source
        $source_data = $this->wpdb->get_results( 
            "SELECT 
                CAST(TRIM(viag_id) AS UNSIGNED) AS viag_id_clean, 
                viag_id, Nom, Adresse, `Adresse 2`, PLZ, Localité, `Tél.`, `Vereinbarter Rabatt`, `Adresse e-mail`, `Contact primaire`, Actif
            FROM {$this->source_table} WHERE viag_id IS NOT NULL AND viag_id != '' AND CAST(TRIM(viag_id) AS UNSIGNED) != 0", // ⭐ Ajout du filtre pour viag_id non nul
            ARRAY_A 
        );
        
        if ( empty( $source_data ) ) {
            $this->log_and_exit( 'Aucune donnée valide (viag_id non nul) trouvée dans la table source.', 'notice', $this->migration_page_slug );
        }
        
        $viag_ids_clean = array_column( $source_data, 'viag_id_clean' );
        
        // 2. Récupérer les ID de la table fournisseur pour le mappage (Utile pour vérifier l'existence)
        $placeholders = implode( ',', array_fill( 0, count( $viag_ids_clean ), '%d' ) );
        $safe_viag_ids = array_map('absint', $viag_ids_clean); 
        
        // On vérifie que le viag_id existe dans la table fournisseur pour avoir le ID interne ($fournisseur_id)
        $query = $this->wpdb->prepare( 
            "SELECT Id, TRIM(viag_id) AS viag_id_key FROM {$this->fournisseurs_table} WHERE viag_id IN ({$placeholders})", 
            ...$safe_viag_ids 
        ); 
        
        $fournisseurs_map_raw = $this->wpdb->get_results( $query, ARRAY_A ); 
        
        $fournisseurs_map = [];
        foreach ($fournisseurs_map_raw as $row) {
            $fournisseurs_map[(string) absint($row['viag_id_key'])] = (object)$row; 
        }

        $matched_count = count( $fournisseurs_map ); 
        
        $this->log_data_to_file("--- DÉBUT DE MIGRATION ---");
        $this->log_data_to_file("Total de viag_id source à traiter: " . count($source_data));
        $this->log_data_to_file("Nombre de correspondances (viag_id) trouvées dans la table cible: " . $matched_count);

        if ( $matched_count === 0 ) {
            $this->log_data_to_file("--- FIN DE MIGRATION. Total mis à jour: 0 ---");
            $this->log_and_exit( 
                sprintf('ÉCHEC CRITIQUE : 0 viag_id trouvés dans la table fournisseur. Total de %d lignes sources traitées.', count($source_data)),
                'error', 
                $this->migration_page_slug 
            );
        }
        
        // 3. Boucle et Application des Mises à Jour
        foreach ( $source_data as $row_data ) {
            
            $source_viag_id_int = absint( $row_data['viag_id_clean'] );
            $source_viag_id_str = (string) $source_viag_id_int;
            
            // ⭐ On utilise le viag_id (INT) comme ID de référence pour la meta
            $fournisseur_reference_id = $source_viag_id_int; 
            
            $is_matched = isset( $fournisseurs_map[$source_viag_id_str] );
            $log_message = "Source viag_id: {$source_viag_id_str}";
            
            if ( ! $is_matched ) {
                $this->log_data_to_file("-> IGNORÉ: {$log_message}. Aucune correspondance trouvée dans {$this->fournisseurs_table}.");
                continue;
            }
            
            $fournisseur_id_interne = absint( $fournisseurs_map[$source_viag_id_str]->Id );
            $this->log_data_to_file("-> MATCH TROUVÉ: {$log_message} -> Id fournisseur interne: {$fournisseur_id_interne}");

            $table_update_data = [];
            $meta_update_data = [];
            $update_performed = false;
            
            // Mappage des données
            foreach ( $this->migration_map as $source_key => $target_key ) {
                
                if ( ! isset( $row_data[$source_key] ) ) {
                    continue;
                }
                
                $value = sanitize_text_field( $row_data[$source_key] );
                
                if ( $value === '' ) {
                    continue;
                }
                
                $constant_name = $target_key;
                
                // Champ de table (Nom du fournisseur)
                if ( $constant_name === 'Fournisseur' ) {
                    $table_update_data['Fournisseur'] = $value;
                } 
                // Méta-donnée
                else if ( defined( 'self::' . $constant_name ) ) {
                    $meta_key_value = constant( 'self::' . $constant_name );
                    $meta_update_data[$meta_key_value] = $value;
                }
            }
            
            // Mise à jour de la table (décommenter si nécessaire, utilise l'ID INTERNE)
            // if ( ! empty( $table_update_data ) ) {
            //     $updated = $this->wpdb->update( 
            //         $this->fournisseurs_table, 
            //         $table_update_data, 
            //         [ 'Id' => $fournisseur_id_interne ], // Utilise l'ID Interne
            //         null, 
            //         [ '%d' ]
            //     );
            //     if ( $updated !== false && $updated > 0 ) $update_performed = true;
            // }
            
            // Mise à jour des Meta-données (Utilisation du viag_id comme Post ID)
            if ( ! empty( $meta_update_data ) ) {
                $meta_was_updated = false;
                foreach ( $meta_update_data as $meta_key => $meta_value ) {
                    // ⭐ Utilisation du VIAG_ID comme ID pour la meta
                    $result = update_post_meta( $fournisseur_reference_id, $meta_key, $meta_value ); 
                    
                    if ( $result !== false && $result !== null ) {
                        $meta_was_updated = true;
                        $this->log_data_to_file("  -> META UPDATED: {$meta_key} = {$meta_value} (Post ID: {$fournisseur_reference_id})");
                    }
                }
                if ($meta_was_updated) {
                    $update_performed = true;
                }
            }

            if ( $update_performed ) {
                $updated_count++;
                $this->log_data_to_file("  -> FOURNISSEUR ID {$fournisseur_id_interne} MIS À JOUR AVEC SUCCÈS (Meta via Viag_ID {$fournisseur_reference_id}).");
            }
        }

        // 4. Nettoyage et Redirection
        $this->log_data_to_file("--- FIN DE MIGRATION. Total mis à jour: {$updated_count} ---");

        $this->log_and_exit( 
            sprintf( 
                'Migration des adresses terminée : **%d fournisseurs mis à jour** (Table et/ou Meta). %d viag_id correspondants trouvés. Total de %d lignes sources traitées.', 
                $updated_count,
                $matched_count,
                count($source_data)
            ), 
            'success',
            $this->migration_page_slug
        );
    }

    // ----------------------------------------------------------------------------------
    // --- 3. Fonctions Utilitaires (INCHANGÉES) ---
    // ----------------------------------------------------------------------------------
    
    /**
     * Écrit un message dans un fichier de log dédié.
     */
    private function log_data_to_file($message) {
        // if ( ! defined('WP_CONTENT_DIR') ) return;
        // $log_file = WP_CONTENT_DIR . '/debug-migration.log'; 
        // $timestamp = date('Y-m-d H:i:s');
        // file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
 
    /**
     * Affiche les messages de succès/erreur après la redirection.
     */
    private function display_admin_message( $page_slug ) {
        if ( isset( $_GET['migration_message'], $_GET['migration_type'] ) && sanitize_text_field( $_GET['page'] ) === $page_slug ) {
            $message = sanitize_text_field( urldecode($_GET['migration_message']) );
            $type = sanitize_text_field( $_GET['migration_type'] );
            
            $class = ($type === 'success') ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>' . esc_html( ucfirst($type) ) . ' :</strong> ' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Redirige vers la page de migration avec un message de statut.
     */
    private function log_and_exit( $message, $type = 'notice', $page_slug ) {
        $redirect_url = add_query_arg(
            array(
                'page' => $page_slug, 
                'migration_message' => urlencode($message),
                'migration_type' => $type,
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }
}

// new ISPAG_Address_Updater();

endif;