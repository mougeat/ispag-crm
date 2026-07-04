<?php

if ( ! class_exists( 'ISPAG_Company_Migrator' ) ) :

class ISPAG_Company_Migrator {

    // Constantes de tables
    private $companies_table = 'wor9711_ispag_companies';
    private $fournisseurs_table = 'wor9711_achats_fournisseurs';
    private $validation_table; // Nom de la nouvelle table de validation
    
    // Constantes de Metas
    const META_COMPANY_CITY = 'ispag_company_city'; 
    
    // Slugs pour le Sous-Menu
    private $menu_parent_slug = 'ispag_main_menu'; 
    private $migration_page_slug = 'ispag_migration_viag_ids';
    private $validation_page_slug = 'ispag_migration_validation'; 
    
    // Nom des actions POST
    private $migration_action = 'ispag_run_viag_migration';
    private $validation_action = 'ispag_validate_viag_migration'; 


    public function __construct() {
        global $wpdb;
        $this->validation_table = $wpdb->prefix . 'ispag_migration_matches';

        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'maybe_create_validation_table' ) );
            add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );
            
            add_action( 'admin_post_' . $this->migration_action, array( $this, 'handle_migration_trigger' ) );
            add_action( 'admin_post_' . $this->validation_action, array( $this, 'handle_validation_trigger' ) );
        }
    }

    // ----------------------------------------------------------------------------------
    // --- 1. Gestion de la Table de Validation (Aucun Changement) ---
    // ----------------------------------------------------------------------------------
    
    /**
     * Crée la table temporaire pour stocker les correspondances trouvées.
     */
    public function maybe_create_validation_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->validation_table (
            match_id BIGINT(20) NOT NULL AUTO_INCREMENT,
            fournisseur_id BIGINT(20) NOT NULL,
            fournisseur_name VARCHAR(255) NOT NULL,
            viag_id BIGINT(20) NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            distance INT(11) NOT NULL,
            created_at DATETIME NOT NULL,
            validated TINYINT(1) DEFAULT 0,
            PRIMARY KEY (match_id),
            UNIQUE KEY fournisseur_viag (fournisseur_id, viag_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    // ----------------------------------------------------------------------------------
    // --- 2. Interface d'Administration (Aucun Changement) ---
    // ----------------------------------------------------------------------------------

    /**
     * Ajoute les pages de sous-menus.
     */
    public function add_admin_menu_page() {
        // Page 1 : Lancement de la Migration
        add_submenu_page(
            $this->menu_parent_slug, 
            __( '1. Lancer la Migration', 'ispag-crm' ),
            __( '1. Lancer la Migration', 'ispag-crm' ),
            'manage_options',
            $this->migration_page_slug,
            array( $this, 'render_migration_page' )
        );
        
        // Page 2 : Validation des Correspondances
        add_submenu_page(
            $this->menu_parent_slug, 
            __( '2. Valider les IDs', 'ispag-crm' ),
            __( '2. Valider les IDs', 'ispag-crm' ),
            'manage_options',
            $this->validation_page_slug,
            array( $this, 'render_validation_page' )
        );
    }
    
    /**
     * Affiche la page de validation (tableau des correspondances).
     */
    public function render_validation_page() {
        global $wpdb;
        
        $matches = $wpdb->get_results( 
            "SELECT * FROM {$this->validation_table} WHERE validated = 0 ORDER BY distance ASC" 
        );
        
        ?>
        <div class="wrap">
            <h1><?php _e( 'Validation des Correspondances de Migration', 'ispag-crm' ); ?></h1>
            <hr>
            
            <?php $this->display_admin_message(); ?>

            <?php if ( empty( $matches ) ) : ?>
                <div class="notice notice-success"><p><?php _e( 'Aucune nouvelle correspondance à valider. Lancez la migration d\'abord.', 'ispag-crm' ); ?></p></div>
            <?php else : ?>
                <p class="description">
                    <?php _e( 'Veuillez examiner les correspondances ci-dessous. Les correspondances avec une faible distance (0 ou 1) sont plus fiables.', 'ispag-crm' ); ?>
                    <br>**Attention :** Les lignes cochées appliqueront le `viag_id` **ET** mettront à jour le nom du fournisseur pour qu'il corresponde au Nom de la Société.
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($this->validation_action); ?>">
                    <?php wp_nonce_field( $this->validation_action . '_nonce' ); ?>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><input type="checkbox" id="select-all"></th>
                                <th><?php _e( 'Fournisseur (Nom Actuel)', 'ispag-crm' ); ?></th>
                                <th><?php _e( 'Société (Nom Cible)', 'ispag-crm' ); ?></th>
                                <th><?php _e( 'viag_id (Cible)', 'ispag-crm' ); ?></th>
                                <th><?php _e( 'Distance (Fiabilité)', 'ispag-crm' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matches as $match) : ?>
                            <tr>
                                <th scope="row">
                                    <input type="checkbox" name="match_ids[]" value="<?php echo absint($match->match_id); ?>" <?php checked($match->distance < 2); ?>>
                                </th>
                                <td>**<?php echo esc_html($match->fournisseur_name); ?>** (ID: <?php echo absint($match->fournisseur_id); ?>)</td>
                                <td>**<?php echo esc_html($match->company_name); ?>**</td>
                                <td><?php echo absint($match->viag_id); ?></td>
                                <td style="font-weight: bold; color: <?php echo ($match->distance === 0) ? 'green' : (($match->distance === 1) ? 'orange' : 'red'); ?>;"><?php echo absint($match->distance); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <script>
                        document.getElementById('select-all').onclick = function() {
                            var checkboxes = document.getElementsByName('match_ids[]');
                            for (var i = 0; i < checkboxes.length; i++) {
                                checkboxes[i].checked = this.checked;
                            }
                        }
                    </script>
                    
                    <?php submit_button( __( 'Valider et Appliquer les Correspondances Sélectionnées', 'ispag-crm' ), 'primary large', 'validate_matches' ); ?>
                </form>

            <?php endif; ?>
        </div>
        <?php
    }

    public function render_migration_page() {
        // ... (Pas de changement ici) ...
        ?>
        <div class="wrap">
            <h1><?php _e( 'Lancement de la Migration des IDs Viag Fournisseurs', 'ispag-crm' ); ?></h1>
            <hr>
            
            <?php $this->display_admin_message(); ?>

            <p class="description">
                <?php _e( "Cette opération lance le processus de recherche floue (Fuzzy Match) uniquement sur le Nom de l'entreprise. Les résultats seront stockés dans une table temporaire pour la validation.", 'ispag-crm' ); ?>
                <br>**ATTENTION :** Cette étape ne met pas à jour la table des fournisseurs. Vous devez passer à l'étape **"2. Valider les IDs"** pour appliquer les changements.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php 
                echo '<input type="hidden" name="action" value="' . esc_attr($this->migration_action) . '">';
                wp_nonce_field( $this->migration_action . '_nonce' ); 
                
                submit_button( __( 'Démarrer la Recherche de Correspondances', 'ispag-crm' ), 'primary large', 'run_migration', true, ['onclick' => 'return confirm("' . esc_js(__('Êtes-vous sûr de vouloir lancer la recherche de correspondances ?', 'ispag-crm')) . '")'] ); 
                ?>
            </form>
        </div>
        <?php
    }
    
    private function display_admin_message() {
        if ( isset( $_GET['migration_message'], $_GET['migration_type'] ) && ( sanitize_text_field( $_GET['page'] ) === $this->migration_page_slug || sanitize_text_field( $_GET['page'] ) === $this->validation_page_slug ) ) {
            $message = sanitize_text_field( urldecode($_GET['migration_message']) );
            $type = sanitize_text_field( $_GET['migration_type'] );
            
            $class = ($type === 'success') ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>' . esc_html( ucfirst($type) ) . ' :</strong> ' . esc_html($message) . '</p></div>';
        }
    }


    // ----------------------------------------------------------------------------------
    // --- 3. Logique Principale (Génération des Correspondances - Aucun Changement) ---
    // ----------------------------------------------------------------------------------

    public function handle_migration_trigger() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], $this->migration_action . '_nonce' ) || ! current_user_can( 'manage_options' ) ) {
            $this->log_and_exit( 'Erreur de sécurité. Nonce invalide.', 'error', $this->migration_page_slug );
        }
        
        $this->execute_name_fuzzy_match();
    }


    /**
     * Exécute la jointure floue basée uniquement sur le Nom et stocke les résultats.
     */
    private function execute_name_fuzzy_match() {
        global $wpdb;

        $start_time = microtime(true);
        $matches_found = 0;
        
        // 1. Récupération des données de la SOCIÉTÉ (Cible : viag_id, Nom)
        $company_data = $wpdb->get_results( 
            "SELECT c.viag_id, c.company_name FROM {$this->companies_table} c WHERE c.viag_id != 0 AND c.company_name IS NOT NULL"
        );

        if ( empty( $company_data ) ) {
            $this->log_and_exit( 'Aucune donnée de société valide trouvée pour le mappage.', 'notice', $this->migration_page_slug );
        }

        // 2. Récupération des FOURNISSEURS à mettre à jour (Source : Nom, Id)
        $fournisseurs_to_update = $wpdb->get_results( 
            "SELECT Id, Fournisseur FROM {$this->fournisseurs_table} WHERE viag_id = 0 OR viag_id IS NULL"
        );
        
        if ( empty( $fournisseurs_to_update ) ) {
            $this->log_and_exit( 'Aucun fournisseur à mettre à jour (tous ont déjà un viag_id).', 'success', $this->migration_page_slug );
        }
        
        // 3. Préparation des données de référence normalisées
        $normalized_companies = [];
        foreach ($company_data as $company) {
             $normalized_companies[] = [
                 'viag_id' => absint($company->viag_id),
                 'norm_name' => $this->ispag_normalize_string( $company->company_name ),
                 'company_name' => $company->company_name
             ];
        }

        // Définition de la tolérance de distance de Levenshtein pour le Nom
        $MAX_NAME_DISTANCE = 3; 
        
        $insert_data = [];
        $wpdb->query( "TRUNCATE TABLE {$this->validation_table}" ); // Vide la table pour la nouvelle recherche
        
        // 4. Boucle de Jointure Floue (uniquement sur le nom)
        foreach ($fournisseurs_to_update as $fournisseur) {
            $norm_fournisseur_name = $this->ispag_normalize_string( $fournisseur->Fournisseur );

            $best_match = null;
            $best_distance = PHP_INT_MAX; 

            foreach ($normalized_companies as $company) {
                
                $name_distance = levenshtein($norm_fournisseur_name, $company['norm_name']);
                
                if ($name_distance <= $MAX_NAME_DISTANCE) {
                    
                    if ($name_distance < $best_distance) {
                        $best_distance = $name_distance;
                        $best_match = $company;
                        
                        if ($best_distance === 0) {
                            break; 
                        }
                    }
                }
            }
            
            // Stockage de la meilleure correspondance trouvée (même si la distance est > 0)
            if ($best_match) {
                 $insert_data[] = $wpdb->prepare(
                     "(%d, %s, %d, %s, %d, NOW())",
                     $fournisseur->Id,
                     $fournisseur->Fournisseur,
                     $best_match['viag_id'],
                     $best_match['company_name'],
                     $best_distance
                 );
                 $matches_found++;
            }
        }
        
        // 5. Insertion en masse dans la table de validation
        if ( ! empty( $insert_data ) ) {
             $query = "INSERT INTO {$this->validation_table} 
                       (fournisseur_id, fournisseur_name, viag_id, company_name, distance, created_at) 
                       VALUES " . implode( ', ', $insert_data );
             $wpdb->query( $query );
        }
        
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);

        $this->log_and_exit( 
            sprintf( 
                'Recherche terminée : %d correspondances stockées pour validation. Temps : %s secondes.', 
                $matches_found, 
                $execution_time 
            ), 
            'success',
            $this->validation_page_slug
        );
    }
    
    // ----------------------------------------------------------------------------------
    // --- 4. Logique Principale (Validation et Application) - MODIFIÉE ---
    // ----------------------------------------------------------------------------------

    /**
     * Gère la validation des correspondances cochées, leur application (viag_id ET nom).
     */
    public function handle_validation_trigger() {
        global $wpdb;

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], $this->validation_action . '_nonce' ) || ! current_user_can( 'manage_options' ) ) {
            $this->log_and_exit( 'Erreur de sécurité. Nonce invalide.', 'error', $this->validation_page_slug );
        }

        $match_ids = isset( $_POST['match_ids'] ) ? array_map( 'absint', (array) $_POST['match_ids'] ) : [];
        
        if ( empty( $match_ids ) ) {
            $this->log_and_exit( 'Aucune correspondance sélectionnée pour la validation.', 'notice', $this->validation_page_slug );
        }
        
        // 1. Récupérer les données des correspondances validées
        $ids_string = implode( ',', $match_ids );
        
        // On récupère le fournisseur_id, le viag_id ET le nom de la société (company_name)
        $matches_to_apply = $wpdb->get_results( 
            "SELECT fournisseur_id, viag_id, company_name FROM {$this->validation_table} WHERE match_id IN ({$ids_string})" 
        );

        if ( empty( $matches_to_apply ) ) {
             $this->log_and_exit( 'Erreur : Aucune correspondance trouvée dans la table temporaire pour les IDs sélectionnés.', 'error', $this->validation_page_slug );
        }

        // 2. Construire la requête de mise à jour en masse (viag_id ET Nom)
        $case_sql_viag = 'UPDATE ' . $this->fournisseurs_table . ' SET viag_id = CASE Id ';
        $case_sql_name = 'UPDATE ' . $this->fournisseurs_table . ' SET Fournisseur = CASE Id ';
        
        $fournisseur_ids_applied = [];
        $applied_count = 0;

        foreach ($matches_to_apply as $match) {
            // Mise à jour du viag_id
            $case_sql_viag .= $wpdb->prepare( ' WHEN %d THEN %d', $match->fournisseur_id, $match->viag_id );
            
            // Mise à jour du Nom du Fournisseur
            $case_sql_name .= $wpdb->prepare( ' WHEN %d THEN %s', $match->fournisseur_id, $match->company_name );
            
            $fournisseur_ids_applied[] = $match->fournisseur_id;
            $applied_count++;
        }
        
        // 3. Exécuter les Mises à Jour
        if ( ! empty( $fournisseur_ids_applied ) ) {
             $where_clause = ' WHERE Id IN (' . implode( ',', $fournisseur_ids_applied ) . ')';
             
             // Execution 1 : Mise à jour des viag_id
             $wpdb->query( $case_sql_viag . ' END' . $where_clause );
             
             // Execution 2 : Mise à jour des noms des Fournisseurs
             $wpdb->query( $case_sql_name . ' END' . $where_clause );
             
             // 4. Marquer les entrées comme validées dans la table temporaire
             $wpdb->query( "UPDATE {$this->validation_table} SET validated = 1 WHERE match_id IN ({$ids_string})" );
        }

        $this->log_and_exit( 
            sprintf( 
                '%d fournisseurs mis à jour (viag_id et Nom).', 
                $applied_count 
            ), 
            'success',
            $this->validation_page_slug
        );
    }

    // ----------------------------------------------------------------------------------
    // --- 5. Fonctions Utilitaires (Aucun Changement) ---
    // ----------------------------------------------------------------------------------

    /**
     * Fonction d'utilité pour normaliser les chaînes.
     */
    private function ispag_normalize_string($str) { 
        if (empty($str)) { return ''; }
        $str = trim($str);
        
        if (function_exists('mb_strtolower')) {
            $str = mb_strtolower($str, 'UTF-8');
        } else {
            $str = strtolower($str);
        }

        $unwanted_array = array(    'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ÿ'=>'y' );
        $str = strtr( $str, $unwanted_array );
        
        $str = preg_replace('/[^a-z0-9]/', '', $str); 

        return $str;
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

// Instanciation de la classe pour activer les hooks
// new ISPAG_Company_Migrator();

endif;