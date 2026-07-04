<?php

if ( ! class_exists( 'ISPAG_CSV_Importer' ) ) :

class ISPAG_CSV_Importer {

    private $wpdb;
    
    private $menu_slug          = 'ispag-entreprises'; 
    private $import_slug        = 'ispag_import_projects'; 
    private $mapping_slug       = 'ispag_map_projects';    
    private $import_action      = 'ispag_handle_project_upload'; 

    private $target_table       = ISPAG_Crm_Deal_Constants::TABLE_NAME; 
    private $lookup_column      = 'identifiant_viag'; 

    private $db_columns = array(
        'project_name'              => 'Nom du Projet (Requis)',
        'current_stage_key'         => 'Clé de l\'Étape Kanban (Automatique)',
        'offer_num'                 => 'Numéro d\'Offre',
        'deal_group_ref'            => 'Référence Groupe (Auto-calculé)',
        'project_num'               => 'Numéro de Projet',
        'identifiant_viag'          => 'ID Viag (Clé unique)',
        'date_creation'             => 'Date de Création',
        'closing_date'              => 'Date de Clôture prévue',
        'customer_order_id'         => 'ID Commande Client',
        'associated_company_id'     => 'ID Entreprise Associée',
        'associated_contact_ids'    => 'IDs Contacts Associés',
        'project_status'            => 'Statut du Projet',
        'database_status'           => 'État de la base (Mapping)',
        'project_db_status'         => 'État (Mapping)',
        'engineer_id'               => 'ID Ingénieur',
        'process_type'              => 'Type de Processus',
        'reseller_offer'            => 'Offre Revendeur (0/1)',
        'sales_coef'                => 'Coeff Vente',
        'total_excl_vat'            => 'Total HT',
        'created_by'                => 'Créé par (ID)',
        'abonne'                    => 'Abonné',
        'deal_owner'                => 'Propriétaire du Deal (ID)',
        'csv_owner_full_name'       => '[Recherche] Nom complet Propriétaire',
        'csv_contact_lastname'      => '[Recherche] Nom Contact',
        'csv_contact_firstname'     => '[Recherche] Prénom Contact',
        'is_copie'                  => 'Est une copie',
    );

    private $default_mapping_keys = array(
        'project_name'              => 'Nom',
        'offer_num'                 => 'Offre',
        'project_num'               => 'N° du projet',
        'identifiant_viag'          => 'ID du processus',
        'date_creation'             => 'Date',
        'closing_date'              => 'Offertgültigkeit',
        'associated_company_id'     => 'N°s entreprises',
        'database_status'           => 'État de la base',
        'project_db_status'         => 'État',
        'process_type'              => 'Type de processus',
        'total_excl_vat'            => 'Total TVA non comprise',
        'csv_owner_full_name'       => 'Chargé de dossier',
        'csv_contact_lastname'      => 'Offerte Kontakt Nachname',
        'csv_contact_firstname'     => 'Offerte Kontakt Vorname',
        'is_copie'                  => 'Ignorer les statistiques',
    );

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action( 'admin_menu', array( $this, 'add_import_submenu' ), 20 );
        add_action( 'admin_post_' . $this->import_action, array( $this, 'handle_project_csv_upload' ) );
    }

    public function add_import_submenu() {
        add_submenu_page($this->menu_slug, 'Importer Projets CSV', 'Importer Projets', 'manage_options', $this->import_slug, array( $this, 'admin_page_import_projects' ));
        add_submenu_page(null, 'Mappage des Colonnes', 'Mappage', 'manage_options', $this->mapping_slug, array( $this, 'admin_page_map_projects' ));
    }

    public function admin_page_import_projects() {
        ?>
        <div class="wrap">
            <h1>Importer des Projets depuis un CSV</h1>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo $this->import_action; ?>">
                <?php wp_nonce_field( 'ispag_csv_upload', 'ispag_csv_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Fichier CSV</th>
                        <td><input type="file" name="csv_file" accept=".csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Délimiteur</th>
                        <td>
                            <select name="delimiter">
                                <option value=";">Point-virgule (;)</option>
                                <option value=",">Virgule (,)</option>
                                <option value="\t">Tabulation</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Téléverser et Configurer le Mappage' ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_project_csv_upload() {
        if ( ! isset( $_POST['ispag_csv_nonce'] ) || ! wp_verify_nonce( $_POST['ispag_csv_nonce'], 'ispag_csv_upload' ) ) wp_die( 'Sécurité échouée' );
        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) wp_die( 'Veuillez sélectionner un fichier.' );

        $upload = wp_handle_upload( $_FILES['csv_file'], array( 'test_form' => false ) );
        if ( isset( $upload['error'] ) ) wp_die( $upload['error'] );

        $file_path = $upload['file'];
        $delimiter = stripslashes( $_POST['delimiter'] );
        if ( $delimiter === '\t' ) $delimiter = "\t";

        wp_redirect( admin_url( 'admin.php?page=' . $this->mapping_slug . '&file=' . urlencode( $file_path ) . '&delim=' . urlencode( $delimiter ) ) );
        exit;
    }

    public function admin_page_map_projects() {
        $file_path = isset( $_GET['file'] ) ? $_GET['file'] : '';
        $delimiter = isset( $_GET['delim'] ) ? $_GET['delim'] : ';';

        if ( ! file_exists( $file_path ) ) {
            echo '<div class="error"><p>Fichier introuvable.</p></div>';
            return;
        }

        if ( isset( $_POST['process_import'] ) ) {
            $this->process_project_mapping_and_import();
            return;
        }

        $handle = fopen( $file_path, 'r' );
        $csv_headers = fgetcsv( $handle, 0, $delimiter );
        fclose( $handle );

        $normalized_csv_headers = array_map( function($h) { 
            return strtolower(trim(str_replace([' ', '_', '°'], '', remove_accents($h)))); 
        }, $csv_headers );

        ?>
        <div class="wrap">
            <h1>Étape 2 : Mappage des colonnes</h1>
            <form method="post">
                <input type="hidden" name="file_path" value="<?php echo esc_attr( $file_path ); ?>">
                <input type="hidden" name="delimiter" value="<?php echo esc_attr( $delimiter ); ?>">
                <table class="widefat striped">
                    <thead><tr><th>Champ Base de Données</th><th>Colonne CSV</th></tr></thead>
                    <tbody>
                        <?php foreach ( $this->db_columns as $db_key => $db_label ) : 
                            $selected_index = '';
                            if ( isset($this->default_mapping_keys[$db_key]) ) {
                                $target = strtolower(trim(str_replace([' ', '_', '°'], '', remove_accents($this->default_mapping_keys[$db_key]))));
                                $find = array_search($target, $normalized_csv_headers);
                                if ($find !== false) $selected_index = $find;
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $db_label ); ?></strong></td>
                            <td>
                                <select name="mapping[<?php echo esc_attr( $db_key ); ?>]">
                                    <option value="">-- Ignorer --</option>
                                    <?php foreach ( $csv_headers as $index => $header ) : ?>
                                        <option value="<?php echo $index; ?>" <?php selected( $selected_index, $index ); ?>>
                                            <?php echo esc_html( $header ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit"><input type="submit" name="process_import" class="button button-primary" value="Lancer l'Importation"></p>
            </form>
        </div>
        <?php
    }

    private function process_project_mapping_and_import() {
        $temp_file_path = $_POST['file_path'];
        $delimiter      = $_POST['delimiter'];
        $mapping        = isset( $_POST['mapping'] ) ? $_POST['mapping'] : array();

        if ( ( $handle = fopen( $temp_file_path, 'r' ) ) !== FALSE ) {
            fgetcsv( $handle, 0, $delimiter ); // Skip header
            while ( ( $raw_data = fgetcsv( $handle, 0, $delimiter ) ) !== FALSE ) {
                if ( empty( $raw_data ) || !isset($raw_data[0]) ) continue;
                
                $raw_data = array_map(function($f) {
                    return (mb_check_encoding($f, 'UTF-8')) ? $f : @iconv('Windows-1252', 'UTF-8//IGNORE', $f);
                }, $raw_data);

                $db_data = $this->prepare_data_for_db( $raw_data, $mapping );
                
                if ( empty( $db_data[$this->lookup_column] ) ) continue;

                $db_data['record_source'] = 'viag_crm';

                $existing_row = $this->wpdb->get_row( $this->wpdb->prepare( 
                    "SELECT id, associated_contact_ids, associated_company_id FROM {$this->target_table} WHERE {$this->lookup_column} = %s", 
                    $db_data[$this->lookup_column] 
                ) );

                if ( $existing_row ) {
                    if ( !empty($db_data['associated_contact_ids']) ) {
                        $db_data['associated_contact_ids'] = $this->merge_ids( $existing_row->associated_contact_ids, $db_data['associated_contact_ids'] );
                    } else {
                        $db_data['associated_contact_ids'] = $existing_row->associated_contact_ids;
                    }

                    if ( !empty($db_data['associated_company_id']) ) {
                        $db_data['associated_company_id'] = $this->merge_ids( $existing_row->associated_company_id, $db_data['associated_company_id'] );
                    } else {
                        $db_data['associated_company_id'] = $existing_row->associated_company_id;
                    }

                    $this->wpdb->update( $this->target_table, $db_data, array( 'id' => $existing_row->id ) );
                } else {
                    $this->wpdb->insert( $this->target_table, $db_data );
                }
            }
            fclose( $handle );
        }
        @unlink( $temp_file_path );
        echo "<div class='updated'><p>Importation terminée. Source : viag_crm.</p></div>";
    }

    private function merge_ids( $existing, $new ) {
        $existing_str = trim((string)$existing);
        $new_str = trim((string)$new);
        $existing_arr = !empty($existing_str) ? explode(',', $existing_str) : array();
        $new_arr      = !empty($new_str) ? explode(',', $new_str) : array();
        $merged = array_unique( array_merge( $existing_arr, $new_arr ) );
        $merged = array_filter( array_map( 'trim', $merged ) ); 
        return implode( ',', $merged );
    }

    /**
     * TRADUCTION DES STATUTS CSV EN STAGE_KEY CRM
     */
    private function map_csv_to_stage_key($db_status, $project_status) {
        if ($project_status == '1') return 'closed_won';
        if ($project_status == '2') return 'closed_lost';

        if ($project_status == '0' || $project_status === '') {
            switch ($db_status) {
                case '1':  return 'submission_received'; // En travail[cite: 2]
                case '11': return 'open_won';            // In accomplishment[cite: 2]
                case '3':  return 'proposal_sent';       // Envoyé par mail[cite: 2]
                default:   return 'submission_received';
            }
        }
        return 'submission_received';
    }

    private function prepare_data_for_db( $raw_data, $mapping ) {
        $db_data = array();
        $owner_fn = ''; $contact_ln = ''; $contact_fn = '';
        $tmp_db_status = ''; $tmp_project_status = '';

        foreach ( $this->db_columns as $db_key => $db_label ) {
            if ( !isset($mapping[$db_key]) || $mapping[$db_key] === "" ) continue;

            $csv_index = intval($mapping[$db_key]);
            if ( ! isset( $raw_data[$csv_index] ) ) continue;

            $value = trim( $raw_data[$csv_index] );
            
            // Stockage pour calcul de l'étape
            if ($db_key === 'database_status') $tmp_db_status = $value;
            if ($db_key === 'project_db_status') $tmp_project_status = $value;

            if ( $value === '' ) continue; 

            if ( strpos($db_key, 'csv_') === 0 ) {
                if ( $db_key === 'csv_owner_full_name' ) $owner_fn = $value;
                elseif ( $db_key === 'csv_contact_lastname' ) $contact_ln = $value;
                elseif ( $db_key === 'csv_contact_firstname' ) $contact_fn = $value;
                continue;
            }

            switch ( $db_key ) {
                case 'date_creation':
                case 'closing_date':
                    $date = $this->parse_date_to_mysql( $value );
                    if ( $date ) $db_data[$db_key] = $date;
                    break;
                case 'total_excl_vat':
                case 'sales_coef':
                    $db_data[$db_key] = (float) str_replace( array(' ', ','), array('', '.'), $value );
                    break;
                case 'is_copie':
                    // Si la valeur CSV est "VRAI" (ou "TRUE", "1", etc.), on met is_copie = 1, sinon 0
                    $db_data[$db_key] = (strtoupper(trim($value)) === 'VRAI' || strtoupper(trim($value)) === 'TRUE' || $value === '1') ? 1 : 0;
                    break;
                default:
                    $db_data[$db_key] = $value;
                    break;
            }
        }

        // Mapping automatique de l'étape Kanban
        $db_data['current_stage_key'] = $this->map_csv_to_stage_key($tmp_db_status, $tmp_project_status);

        if ( ! empty( $db_data['offer_num'] ) ) {
            $parts = explode( '.', $db_data['offer_num'] );
            $db_data['deal_group_ref'] = trim( $parts[0] );
        }

        $this->process_users_mapping($db_data, $owner_fn, $contact_fn, $contact_ln);

        return $db_data;
    }

    private function parse_date_to_mysql( $date_str ) {
        $formats = array( 'd.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.y' );
        foreach ( $formats as $f ) {
            $d = DateTime::createFromFormat( $f, trim($date_str) );
            if ( $d && $d->format( $f ) === trim($date_str) ) return $d->format( 'Y-m-d' );
        }
        return null;
    }

    private function process_users_mapping(&$db_data, $owner_full, $contact_fn, $contact_ln) {
        if ( !empty($owner_full) ) {
            $parts = explode(' ', $owner_full);
            $ln = (count($parts) >= 2) ? array_pop($parts) : '';
            $fn = implode(' ', $parts) ?: $owner_full;
            $uid = $this->lookup_user_id_by_name($fn, $ln, 'OWNER');
            if ($uid) $db_data['deal_owner'] = $uid;
        }

        if ( !empty($contact_ln) ) {
            $cid = $this->lookup_user_id_by_name($contact_fn, $contact_ln, 'CONTACT');
            if ($cid) {
                $db_data['associated_contact_ids'] = (string)$cid;
            }
        }
    }

    private function lookup_user_id_by_name( $first, $last, $context = '' ) {
        if ( empty($last) ) return null;
        $meta_key   = 'ispag_account_status';
        $meta_value = 'disabled';
        $user = $this->wpdb->get_row( $this->wpdb->prepare( 
            "SELECT u.ID FROM {$this->wpdb->users} u
             LEFT JOIN {$this->wpdb->usermeta} m ON u.ID = m.user_id AND m.meta_key = %s
             WHERE (u.display_name = %s OR u.user_login = %s)
             AND (m.meta_value IS NULL OR m.meta_value != %s)
             LIMIT 1", 
            $meta_key, "$first $last", strtolower($last), $meta_value
        ) );
        if ( $user ) return $user->ID;
        $user = $this->wpdb->get_row( $this->wpdb->prepare( 
            "SELECT u.ID FROM {$this->wpdb->users} u
             LEFT JOIN {$this->wpdb->usermeta} m ON u.ID = m.user_id AND m.meta_key = %s
             WHERE (u.display_name LIKE %s OR u.display_name LIKE %s)
             AND (m.meta_value IS NULL OR m.meta_value != %s)
             LIMIT 1", 
            $meta_key, '% ' . $this->wpdb->esc_like($last), $this->wpdb->esc_like($last) . ' %', $meta_value
        ) );
        return $user ? $user->ID : null;
    }
}
endif;