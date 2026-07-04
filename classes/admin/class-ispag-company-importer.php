<?php
// Fichier : includes/crm/class-ispag-company-importer.php

if ( ! class_exists( 'ISPAG_Company_Importer' ) ) :

class ISPAG_Company_Importer {

    private $wpdb;
    
    private $menu_slug      = 'ispag-entreprises';
    private $import_slug    = 'ispag_import_companies';
    private $mapping_slug   = 'ispag_map_companies';
    private $import_action  = 'ispag_handle_company_upload';

    // Définition des colonnes (Utilisation de compagny_domain avec un 'g')
    private $db_columns = array(
        'viag_id'         => 'ID Viag (N° entreprise) *obligatoire',
        'company_name'    => 'Nom de l\'entreprise',
        'compagny_domain' => 'Domaine (ex: entreprise.ch)', 
        'is_active'       => 'Statut Actif (VRAI/FAUX)',
        'city'            => 'Ville / Localité',
        'phone'           => 'Téléphone',
        'email'           => 'Email',
        'address'         => 'Adresse (Rue)',
        'address_2'       => 'Adresse 2 (Complément)',
        'postal_code'     => 'Code Postal (PLZ)',
    );

    private $default_mapping_keys = array(
        'viag_id'         => 'N°s entreprises',
        'company_name'    => 'Nom',
        'compagny_domain' => 'Domaine',
        'address'         => 'Adresse',
        'address_2'       => 'Adresse 2',
        'postal_code'     => 'PLZ',
        'city'            => 'Localité',
        'phone'           => 'Tél.',
        'email'           => 'Adresse e-mail',
        'is_active'       => 'Actif',
    );

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action( 'admin_menu', array( $this, 'add_admin_menu_pages' ) );
        add_action( 'admin_post_' . $this->import_action, array( $this, 'handle_company_csv_upload' ) );
        add_action( 'admin_post_ispag_process_company_mapping', array( $this, 'process_company_mapping_and_import' ) );
    }

    public function add_admin_menu_pages() {
        add_submenu_page($this->menu_slug, 'Importer Entreprises CSV', 'Importer Entreprises', 'manage_options', $this->import_slug, array($this, 'admin_page_import'));
        add_submenu_page(null, 'Mappage CSV Entreprises', 'Mappage CSV', 'manage_options', $this->mapping_slug, array($this, 'admin_page_map'));
    }

    public function handle_company_csv_upload() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Accès refusé');
        check_admin_referer( 'ispag_upload_company_csv' );
        $delimiter = sanitize_text_field($_POST['csv_delimiter'] ?: ';');
        $movefile = wp_handle_upload( $_FILES['csv_file'], array( 'test_form' => false ) );
        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $headers = $this->get_csv_headers($movefile['file'], $delimiter);
            wp_redirect( add_query_arg( array('page' => $this->mapping_slug, 'temp_file' => basename($movefile['file']), 'csv_headers' => urlencode(implode(',', $headers)), 'delimiter' => $delimiter), admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    public function process_company_mapping_and_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Accès refusé');
        check_admin_referer( 'ispag_map_company_csv' );

        $mapping       = array_map( 'sanitize_text_field', $_POST['mapping'] );
        $temp_filename = sanitize_file_name( $_POST['temp_file'] );
        $delimiter     = sanitize_text_field( $_POST['delimiter'] );
        $file_path     = wp_upload_dir()['path'] . '/' . $temp_filename;

        if ( ! file_exists( $file_path ) ) $this->redirect_with_message('error', 'Fichier introuvable.');

        $handle = fopen( $file_path, 'r' );
        fgetcsv( $handle, 0, $delimiter ); // Skip header

        $count_upd = 0; $count_ins = 0;
        $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;

        while ( ( $raw_data = fgetcsv( $handle, 0, $delimiter ) ) !== FALSE ) {
            $raw_data = array_map( function($f) { 
                $f = (string)$f;
                return mb_check_encoding($f, 'UTF-8') ? $f : iconv('Windows-1252', 'UTF-8//IGNORE', $f); 
            }, $raw_data);

            $viag_idx = $mapping['viag_id'] ?? '';
            $viag_id = ($viag_idx !== '') ? trim($raw_data[$viag_idx]) : '';
            if ( empty( $viag_id ) ) continue;

            // Logique Active
            $active_idx = $mapping['is_active'] ?? '';
            $active_val = ($active_idx !== '') ? mb_strtolower(trim($raw_data[$active_idx]), 'UTF-8') : '';
            $is_active = in_array($active_val, ['vrai', 'true', '1', 'oui', 'active']) ? 1 : 0;

            // Données SQL (Correction : compagny_domain avec un 'g')
            $sql_data = array(
                'viag_id'         => $viag_id,
                'company_name'    => ($mapping['company_name'] !== '') ? trim($raw_data[$mapping['company_name']]) : '',
                'compagny_domain' => ($mapping['compagny_domain'] !== '') ? $this->clean_domain($raw_data[$mapping['compagny_domain']]) : '',
                'city'            => ($mapping['city'] !== '') ? trim($raw_data[$mapping['city']]) : '',
                'phone'           => ($mapping['phone'] !== '') ? trim($raw_data[$mapping['phone']]) : '',
                'email'           => ($mapping['email'] !== '') ? trim($raw_data[$mapping['email']]) : '',
                'is_active'       => $is_active,
            );

            $exists = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM $table_name WHERE viag_id = %d", $viag_id ) );

            if ( $exists ) {
                $this->wpdb->update( $table_name, $sql_data, array( 'id' => $exists ) );
                $count_upd++;
            } else {
                $sql_data['isSupplier'] = 0;
                $sql_data['isIngenieur'] = 0;
                $sql_data['created_at'] = current_time( 'mysql' );
                $this->wpdb->insert( $table_name, $sql_data );
                $count_ins++;
            }

            $this->update_company_metas($viag_id, $raw_data, $mapping);
        }

        fclose( $handle ); @unlink( $file_path );
        $this->redirect_with_message( 'success', sprintf( 'Importation terminée : %d créées, %d mises à jour.', $count_ins, $count_upd ) );
    }

    private function update_company_metas($viag_id, $raw_data, $mapping) {
        if (($mapping['city'] ?? '') !== '') update_post_meta( $viag_id, ISPAG_Crm_Company_Constants::META_COMPANY_CITY, trim($raw_data[$mapping['city']]) );
        if (($mapping['address'] ?? '') !== '') update_post_meta( $viag_id, ISPAG_Crm_Company_Constants::META_COMPANY_ADDRESS, trim($raw_data[$mapping['address']]) );
        if (($mapping['address_2'] ?? '') !== '') update_post_meta( $viag_id, 'ispag_company_address_2', trim($raw_data[$mapping['address_2']]) );
        if (($mapping['postal_code'] ?? '') !== '') update_post_meta( $viag_id, ISPAG_Crm_Company_Constants::META_COMPANY_POSTAL_CODE, trim($raw_data[$mapping['postal_code']]) );
        if (($mapping['phone'] ?? '') !== '') update_post_meta( $viag_id, ISPAG_Crm_Company_Constants::META_COMPANY_PHONE, trim($raw_data[$mapping['phone']]) );
        if (($mapping['email'] ?? '') !== '') update_post_meta( $viag_id, ISPAG_Crm_Company_Constants::META_COMPANY_MAIL, trim($raw_data[$mapping['email']]) );
    }

    private function clean_domain($url) {
        $domain = strtolower(preg_replace('/^https?:\/\/(www\.)?/', '', sanitize_text_field($url)));
        return rtrim($domain, '/');
    }

    private function get_csv_headers($file, $delimiter) {
        if (($h = fopen($file, 'r')) !== FALSE) {
            $line = fgets($h); fclose($h);
            $line = mb_check_encoding($line, 'UTF-8') ? $line : iconv('Windows-1252', 'UTF-8//IGNORE', $line);
            $tmp = fopen('php://temp', 'r+'); fwrite($tmp, $line); rewind($tmp);
            $header = fgetcsv($tmp, 0, $delimiter); fclose($tmp);
            return array_map('trim', (array)$header);
        } return [];
    }

    public function admin_page_import() {
        if (isset($_GET['ispag_msg_type'])) {
            echo "<div class='notice notice-{$_GET['ispag_msg_type']} is-dismissible'><p>".esc_html(urldecode($_GET['ispag_message']))."</p></div>";
        }
        ?>
        <div class="wrap">
            <h1>Import CSV Entreprises</h1>
            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo $this->import_action; ?>" />
                <?php wp_nonce_field('ispag_upload_company_csv'); ?>
                <table class="form-table">
                    <tr><th>Fichier CSV</th><td><input type="file" name="csv_file" required /></td></tr>
                    <tr><th>Délimiteur</th><td><input type="text" name="csv_delimiter" value=";" style="width:40px" /></td></tr>
                </table>
                <?php submit_button('Étape suivante'); ?>
            </form>
        </div>
        <?php
    }

    public function admin_page_map() {
        $csv_headers = array_map('sanitize_text_field', explode(',', urldecode($_GET['csv_headers'])));
        ?>
        <div class="wrap">
            <h1>Mappage des colonnes</h1>
            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="ispag_process_company_mapping" />
                <?php wp_nonce_field('ispag_map_company_csv'); ?>
                <input type="hidden" name="temp_file" value="<?php echo esc_attr($_GET['temp_file']); ?>" />
                <input type="hidden" name="delimiter" value="<?php echo esc_attr($_GET['delimiter']); ?>" />
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Destination</th><th>Colonne CSV</th></tr></thead>
                    <tbody>
                        <?php foreach ($this->db_columns as $key => $label) : 
                            $sel = array_search($this->default_mapping_keys[$key] ?? '', $csv_headers); ?>
                            <tr><td><strong><?php echo $label; ?></strong></td>
                            <td><select name="mapping[<?php echo $key; ?>]" style="width:100%">
                                <option value="">-- Ignorer --</option>
                                <?php foreach ($csv_headers as $i => $h) : ?>
                                    <option value="<?php echo $i; ?>" <?php selected($i, $sel); ?>><?php echo $h; ?></option>
                                <?php endforeach; ?>
                            </select></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Lancer l\'importation'); ?>
            </form>
        </div>
        <?php
    }

    private function redirect_with_message($type, $msg) {
        wp_redirect(add_query_arg(array('page' => $this->import_slug, 'ispag_msg_type' => $type, 'ispag_message' => urlencode($msg)), admin_url('admin.php')));
        exit;
    }
}
endif;