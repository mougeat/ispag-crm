<?php
// Fichier : includes/crm/class-ispag-company-importer.php

if ( ! class_exists( 'ISPAG_Company_Importer' ) ) :

class ISPAG_Company_Importer {

    private $wpdb;
    
    private $menu_slug      = 'ispag-entreprises';
    private $import_slug    = 'ispag_import_companies';
    private $mapping_slug   = 'ispag_map_companies';
    private $import_action  = 'ispag_handle_company_upload';
    private $mapping_action = 'ispag_process_company_mapping';

    private $db_columns = array(
        'viag_id'         => 'ID Viag (N° entreprise) *obligatoire',
        'company_name'    => 'Nom de l\'entreprise',
        'compagny_domain' => 'Domaine (ex: entreprise.ch)', 
        'is_active'       => 'Statut Actif (VRAI/FAUX)',
        'city'            => 'City / Locality',
        'phone'           => 'Téléphone',
        'email'           => 'Email',
        'address'         => 'Adresse (Rue)',
        'address_2'       => 'Address 2 (Additional)',
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
        add_action( 'admin_post_' . $this->mapping_action, array( $this, 'start_async_csv_import' ) );

        // Traitement AJAX & Cron
        add_action( 'wp_ajax_ispag_check_company_import_status', array( $this, 'check_csv_import_status' ) );
        add_action( 'ispag_process_company_import_task', array( $this, 'process_csv_import_task' ), 10, 1 );

        // Nettoyage des anciens transients
        add_action( 'ispag_cleanup_old_company_imports', array( $this, 'cleanup_old_csv_imports' ) );
        if ( ! wp_next_scheduled( 'ispag_cleanup_old_company_imports' ) ) {
            wp_schedule_event( time(), 'daily', 'ispag_cleanup_old_company_imports' );
        }
    }

    public function add_admin_menu_pages() {
        add_submenu_page( $this->menu_slug, 'Importer Entreprises CSV', 'Importer Entreprises', 'manage_options', $this->import_slug, array( $this, 'admin_page_import' ) );
        add_submenu_page( null, 'Mappage CSV Entreprises', 'Mappage CSV', 'manage_options', $this->mapping_slug, array( $this, 'admin_page_map' ) );
    }

    public function handle_company_csv_upload() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied' );
        check_admin_referer( 'ispag_upload_company_csv' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_die( 'Please select a file.' );
        }

        $delimiter = sanitize_text_field( $_POST['csv_delimiter'] ?: ';' );
        $movefile  = wp_handle_upload( $_FILES['csv_file'], array( 'test_form' => false ) );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            wp_redirect( add_query_arg( array(
                'page'      => $this->mapping_slug,
                'file'      => urlencode( $movefile['file'] ),
                'delimiter' => urlencode( $delimiter )
            ), admin_url( 'admin.php' ) ) );
            exit;
        } else {
            wp_die( $movefile['error'] );
        }
    }

    public function admin_page_import() {
        if ( isset( $_GET['ispag_msg_type'] ) ) {
            echo "<div class='notice notice-{$_GET['ispag_msg_type']} is-dismissible'><p>" . esc_html( urldecode( $_GET['ispag_message'] ) ) . "</p></div>";
        }
        ?>
        <div class="wrap">
            <h1>Import CSV Entreprises</h1>
            <form action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo $this->import_action; ?>" />
                <?php wp_nonce_field( 'ispag_upload_company_csv' ); ?>
                <table class="form-table">
                    <tr><th>Fichier CSV</th><td><input type="file" name="csv_file" accept=".csv" required /></td></tr>
                    <tr><th>Delimiter</th><td><input type="text" name="csv_delimiter" value=";" style="width:40px" /></td></tr>
                </table>
                <?php submit_button( 'Next step' ); ?>
            </form>
        </div>
        <?php
    }

    public function admin_page_map() {
        $file_path = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
        $delimiter = isset( $_GET['delimiter'] ) ? sanitize_text_field( wp_unslash( $_GET['delimiter'] ) ) : ';';
        $task_id   = isset( $_GET['task_id'] ) ? sanitize_text_field( wp_unslash( $_GET['task_id'] ) ) : '';

        if ( ! file_exists( $file_path ) ) {
            echo '<div class="error"><p>Fichier introuvable.</p></div>';
            return;
        }

        // Vérifier si une tâche existe pour ce fichier
        $existing_task = $this->get_existing_task_for_file( $file_path );
        if ( $existing_task && ! $task_id ) {
            $task_id = $existing_task['task_id'];
        }

        if ( $task_id ) {
            $this->display_import_status( $task_id );
            return;
        }

        $headers = $this->get_csv_headers( $file_path, $delimiter );
        ?>
        <div class="wrap">
            <h1>Mappage des colonnes (Entreprises)</h1>
            <form action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post">
                <input type="hidden" name="action" value="<?php echo $this->mapping_action; ?>" />
                <input type="hidden" name="file_path" value="<?php echo esc_attr( $file_path ); ?>" />
                <input type="hidden" name="delimiter" value="<?php echo esc_attr( $delimiter ); ?>" />
                <?php wp_nonce_field( 'ispag_map_company_csv' ); ?>

                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Destination</th><th>Colonne CSV</th></tr></thead>
                    <tbody>
                        <?php foreach ( $this->db_columns as $key => $label ) : 
                            $sel = array_search( $this->default_mapping_keys[$key] ?? '', $headers ); ?>
                            <tr>
                                <td><strong><?php echo esc_html( $label ); ?></strong></td>
                                <td>
                                    <select name="mapping[<?php echo esc_attr( $key ); ?>]" style="width:100%">
                                        <option value="">-- Ignorer --</option>
                                        <?php foreach ( $headers as $i => $h ) : ?>
                                            <option value="<?php echo $i; ?>" <?php selected( $i, $sel ); ?>><?php echo esc_html( $h ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Lancer l\'importation Asynchrone' ); ?>
            </form>
        </div>
        <?php
    }

    public function start_async_csv_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied' );
        check_admin_referer( 'ispag_map_company_csv' );

        $user_id   = get_current_user_id();
        $file_path = isset( $_POST['file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['file_path'] ) ) : '';
        $delimiter = isset( $_POST['delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) : ';';
        $mapping   = isset( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', (array) $_POST['mapping'] ) : array();

        if ( ! file_exists( $file_path ) ) {
            $this->redirect_with_message( 'error', 'Fichier introuvable.' );
        }

        $task_id    = 'company_import_' . $user_id . '_' . time();
        $total_rows = $this->count_csv_rows( $file_path, $delimiter ) - 1;

        set_transient( 'ispag_company_import_' . $task_id, [
            'file_path'      => $file_path,
            'delimiter'      => $delimiter,
            'mapping'        => $mapping,
            'user_id'        => $user_id,
            'status'         => 'pending',
            'total_rows'     => max( 0, $total_rows ),
            'processed_rows' => 0,
            'insert_count'   => 0,
            'update_count'   => 0,
            'start_time'     => current_time( 'mysql' ),
        ], DAY_IN_SECONDS );

        wp_schedule_single_event( time(), 'ispag_process_company_import_task', [ $task_id ] );

        wp_redirect( admin_url( 'admin.php?page=' . $this->mapping_slug . '&file=' . urlencode( $file_path ) . '&delimiter=' . urlencode( $delimiter ) . '&task_id=' . $task_id ) );
        exit;
    }

    public function process_csv_import_task( $task_id ) {
        $task_data = get_transient( 'ispag_company_import_' . $task_id );
        if ( $task_data === false || $task_data['status'] !== 'pending' ) {
            return;
        }

        $task_data['status'] = 'processing';
        set_transient( 'ispag_company_import_' . $task_id, $task_data, DAY_IN_SECONDS );

        $file_path  = $task_data['file_path'];
        $delimiter  = $task_data['delimiter'];
        $mapping    = $task_data['mapping'];
        $user_id    = $task_data['user_id'];
        $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;

        $count_ins = 0;
        $count_upd = 0;
        $row_count = 0;

        try {
            if ( ( $handle = fopen( $file_path, 'r' ) ) !== FALSE ) {
                fgetcsv( $handle, 0, $delimiter ); // Skip header

                while ( ( $raw_data = fgetcsv( $handle, 0, $delimiter ) ) !== FALSE ) {
                    if ( empty( $raw_data ) || ! isset( $raw_data[0] ) ) continue;

                    $raw_data = array_map( function( $f ) { 
                        $f = (string)$f;
                        return mb_check_encoding( $f, 'UTF-8' ) ? $f : @iconv( 'Windows-1252', 'UTF-8//IGNORE', $f ); 
                    }, $raw_data );

                    $viag_idx = $mapping['viag_id'] ?? '';
                    $viag_id  = ( $viag_idx !== '' ) ? trim( $raw_data[$viag_idx] ) : '';

                    if ( empty( $viag_id ) ) {
                        $row_count++;
                        $task_data['processed_rows'] = $row_count;
                        set_transient( 'ispag_company_import_' . $task_id, $task_data, DAY_IN_SECONDS );
                        continue;
                    }

                    // Statut Actif
                    $active_idx = $mapping['is_active'] ?? '';
                    $active_val = ( $active_idx !== '' ) ? mb_strtolower( trim( $raw_data[$active_idx] ), 'UTF-8' ) : '';
                    $is_active  = in_array( $active_val, ['vrai', 'true', '1', 'oui', 'active'] ) ? 1 : 0;

                    $sql_data = array(
                        'viag_id'      => $viag_id,
                        'company_name' => ( ($mapping['company_name'] ?? '') !== '' ) ? trim( $raw_data[$mapping['company_name']] ) : '',
                        'city'         => ( ($mapping['city'] ?? '') !== '' ) ? trim( $raw_data[$mapping['city']] ) : '',
                        'phone'        => ( ($mapping['phone'] ?? '') !== '' ) ? trim( $raw_data[$mapping['phone']] ) : '',
                        'email'        => ( ($mapping['email'] ?? '') !== '' ) ? trim( $raw_data[$mapping['email']] ) : '',
                        'is_active'    => $is_active,
                    );

                    // Traitement spécifique pour compagny_domain
                    $domain_idx = $mapping['compagny_domain'] ?? '';
                    $domain_val = ( $domain_idx !== '' && isset( $raw_data[$domain_idx] ) ) ? $this->clean_domain( $raw_data[$domain_idx] ) : '';
                    if ( ! empty( $domain_val ) ) {
                        $sql_data['compagny_domain'] = $domain_val;
                    }

                    $exists = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$table_name} WHERE viag_id = %d", $viag_id ) );

                    if ( $exists ) {
                        $this->wpdb->update( $table_name, $sql_data, array( 'id' => $exists ) );
                        $count_upd++;
                    } else {
                        $sql_data['isSupplier']  = 0;
                        $sql_data['isIngenieur'] = 0;
                        $sql_data['created_at']  = current_time( 'mysql' );
                        $this->wpdb->insert( $table_name, $sql_data );
                        $count_ins++;
                    }

                    // Mise à jour des métadonnées associées
                    $this->update_company_metas( $viag_id, $raw_data, $mapping );

                    $row_count++;
                    $task_data['processed_rows'] = $row_count;
                    $task_data['insert_count']   = $count_ins;
                    $task_data['update_count']   = $count_upd;
                    set_transient( 'ispag_company_import_' . $task_id, $task_data, DAY_IN_SECONDS );
                }
                fclose( $handle );
            }

            @unlink( $file_path );

            $task_data['status']   = 'completed';
            $task_data['end_time'] = current_time( 'mysql' );
            set_transient( 'ispag_company_import_' . $task_id, $task_data, DAY_IN_SECONDS );

            $this->send_import_notification( $user_id, $task_id, $row_count, $count_ins, $count_upd, basename( $file_path ), true );

        } catch ( Exception $e ) {
            $task_data['status']   = 'failed';
            $task_data['error']    = $e->getMessage();
            $task_data['end_time'] = current_time( 'mysql' );
            set_transient( 'ispag_company_import_' . $task_id, $task_data, DAY_IN_SECONDS );

            $this->send_import_notification( $user_id, $task_id, $row_count, $count_ins, $count_upd, basename( $file_path ), false, $e->getMessage() );
        }
    }

    public function check_csv_import_status() {
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : '';
        if ( ! $task_id ) {
            wp_send_json_error( 'ID de tâche manquant.' );
        }

        $task_data = get_transient( 'ispag_company_import_' . $task_id );
        if ( $task_data === false ) {
            wp_send_json_error( 'Task not found or expired.' );
        }

        wp_send_json_success([
            'status'     => $task_data['status'],
            'progress'   => [
                'total_rows'     => $task_data['total_rows'],
                'processed_rows' => $task_data['processed_rows'],
                'insert_count'   => $task_data['insert_count'],
                'update_count'   => $task_data['update_count'],
            ],
            'start_time' => $task_data['start_time'],
            'end_time'   => $task_data['end_time'] ?? null,
            'error'      => $task_data['error'] ?? null,
        ]);
    }

    private function display_import_status( $task_id ) {
        $task_data = get_transient( 'ispag_company_import_' . $task_id );
        if ( ! $task_data ) {
            echo '<div class="error"><p>Task not found or expired.</p></div>';
            return;
        }

        $status   = $task_data['status'];
        $progress = [
            'total_rows'     => $task_data['total_rows'],
            'processed_rows' => $task_data['processed_rows'],
            'insert_count'   => $task_data['insert_count'],
            'update_count'   => $task_data['update_count'],
        ];
        ?>
        <div class="wrap">
            <h1>Statut de l'Import Entreprises CSV</h1>
            <div class="card" style="background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2>Task: <?php echo esc_html( $task_id ); ?></h2>
                <p><strong>Statut :</strong> <?php echo esc_html( ucfirst( $status ) ); ?></p>
                <p><strong>Fichier :</strong> <?php echo esc_html( basename( $task_data['file_path'] ) ); ?></p>
                <p><strong>Start:</strong> <?php echo esc_html( $task_data['start_time'] ); ?></p>
                <?php if ( isset( $task_data['end_time'] ) ) : ?>
                    <p><strong>Fin :</strong> <?php echo esc_html( $task_data['end_time'] ); ?></p>
                <?php endif; ?>

                <?php if ( $status === 'processing' || $status === 'pending' ) : ?>
                    <div style="margin: 20px 0; background: #f1f1f1; padding: 15px; border-radius: 5px;">
                        <div style="width: 100%; background: #e1e1e1; border-radius: 3px; height: 20px; margin-bottom: 5px;">
                            <div id="ispag-progress-bar"
                                style="width: <?php echo esc_attr( min( 100, ( $progress['processed_rows'] / max( 1, $progress['total_rows'] ) ) * 100 ) ); ?>%;
                                       height: 100%;
                                       background: #2271b1;
                                       border-radius: 3px;
                                       transition: width 0.3s ease;">
                            </div>
                        </div>
                        <p style="margin: 0;">
                            Progress: <?php echo esc_html( $progress['processed_rows'] ); ?> / <?php echo esc_html( $progress['total_rows'] ); ?> lignes
                            (<?php echo esc_html( round( min( 100, ( $progress['processed_rows'] / max( 1, $progress['total_rows'] ) ) * 100 ), 1 ) ); ?>%)
                        </p>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 20px;">
                    <p><strong>Insertions :</strong> <?php echo esc_html( $progress['insert_count'] ); ?></p>
                    <p><strong>Updates:</strong> <?php echo esc_html( $progress['update_count'] ); ?></p>
                </div>

                <?php if ( $status === 'failed' && isset( $task_data['error'] ) ) : ?>
                    <div class="notice notice-error" style="margin-top: 20px;">
                        <p><strong>Erreur :</strong> <?php echo esc_html( $task_data['error'] ); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ( $status === 'pending' || $status === 'processing' ) : ?>
                    <p style="margin-top: 20px;">
                        <button id="ispag-refresh-status" class="button button-secondary" data-task-id="<?php echo esc_attr( $task_id ); ?>">
                            Refresh status
                        </button>
                    </p>
                    <p style="margin-top: 10px; color: #666; font-style: italic;">
                        Vous pouvez fermer cette page. L'import continuera en arrière-plan.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#ispag-refresh-status').on('click', function() {
                const taskId = $(this).data('task-id');
                const progressBar = $('#ispag-progress-bar');
                const progressText = progressBar.parent().next('p');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ispag_check_company_import_status',
                        task_id: taskId,
                    },
                    beforeSend: function() {
                        $('#ispag-refresh-status').prop('disabled', true).text('Refreshing...');
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            const progress = data.progress;
                            const percentage = Math.min(100, (progress.processed_rows / Math.max(1, progress.total_rows)) * 100);

                            progressBar.css('width', percentage + '%');
                            progressText.html('Progress: ' + progress.processed_rows + ' / ' + progress.total_rows + ' lignes (' + percentage.toFixed(1) + '%)');

                            $('p:contains("Insertions")').html('<strong>Insertions :</strong> ' + progress.insert_count);
                            $('p:contains("Updates")').html('<strong>Updates:</strong> ' + progress.update_count);
                            $('p:contains("Statut")').html('<strong>Statut :</strong> ' + data.status.charAt(0).toUpperCase() + data.status.slice(1));

                            if (data.status === 'completed') {
                                $('p:contains("Fin")').html('<strong>Fin :</strong> ' + data.end_time).show();
                                $('#ispag-refresh-status').hide();
                                $('p:contains("Vous pouvez fermer")').hide();
                            } else if (data.status === 'failed') {
                                $('p:contains("Statut")').html('<strong>Status:</strong> Failed');
                                $('.notice-error').html('<p><strong>Erreur :</strong> ' + data.error + '</p>').show();
                                $('#ispag-refresh-status').hide();
                                $('p:contains("Vous pouvez fermer")').hide();
                            }
                        }
                    },
                    complete: function() {
                        $('#ispag-refresh-status').prop('disabled', false).text('Refresh status');
                    }
                });
            });

            <?php if ( $status === 'pending' || $status === 'processing' ) : ?>
            const refreshInterval = setInterval(function() {
                $('#ispag-refresh-status').trigger('click');
            }, 5000);

            $(window).on('beforeunload', function() {
                clearInterval(refreshInterval);
            });
            <?php endif; ?>
        });
        </script>
        <?php
    }

    private function send_import_notification( $user_id, $task_id, $row_count, $insert_count, $update_count, $filename, $success, $error = '' ) {
        if ( ! class_exists( 'ISPAG_Notifications_Manager' ) ) {
            return;
        }

        if ( $success ) {
            $title   = esc_html( __( '✅ Company import completed', 'ispag-crm' ) );
            $message = sprintf(
                'L\'importation du fichier CSV des entreprises s\'est déroulée avec succès.<br>
                - Lignes traitées : %1$d<br>
                - Créations : %2$d<br>
                - Updates : %3$d<br>
                - Fichier : %4$s<br>
                - ID Tâche : %5$s',
                $row_count,
                $insert_count,
                $update_count,
                $filename,
                $task_id
            );
        } else {
            $title   = esc_html( __( '❌ Company import failed', 'ispag-crm' ) );
            $message = sprintf(
                'L\'importation du fichier CSV des entreprises a échoué.<br>
                - Lignes traitées : %1$d<br>
                - Créations : %2$d<br>
                - Updates : %3$d<br>
                - Fichier : %4$s<br>
                - ID Tâche : %5$s<br>
                - Erreur : %6$s',
                $row_count,
                $insert_count,
                $update_count,
                $filename,
                $task_id,
                $error
            );
        }

        ISPAG_Notifications_Manager::send(
            [ $user_id ],
            'datas_export',
            $title,
            $message,
            '',
            null
        );
    }

    private function update_company_metas( $company_id, $raw_data, $mapping ) {
        if ( ( $mapping['city'] ?? '' ) !== '' )        $this->update_company_meta( $company_id, ISPAG_Crm_Company_Constants::META_COMPANY_CITY, trim( $raw_data[$mapping['city']] ) );
        if ( ( $mapping['address'] ?? '' ) !== '' )     $this->update_company_meta( $company_id, ISPAG_Crm_Company_Constants::META_COMPANY_ADDRESS, trim( $raw_data[$mapping['address']] ) );
        if ( ( $mapping['address_2'] ?? '' ) !== '' )   $this->update_company_meta( $company_id, 'ispag_company_address_2', trim( $raw_data[$mapping['address_2']] ) );
        if ( ( $mapping['postal_code'] ?? '' ) !== '' ) $this->update_company_meta( $company_id, ISPAG_Crm_Company_Constants::META_COMPANY_POSTAL_CODE, trim( $raw_data[$mapping['postal_code']] ) );
        if ( ( $mapping['phone'] ?? '' ) !== '' )       $this->update_company_meta( $company_id, ISPAG_Crm_Company_Constants::META_COMPANY_PHONE, trim( $raw_data[$mapping['phone']] ) );
        if ( ( $mapping['email'] ?? '' ) !== '' )       $this->update_company_meta( $company_id, ISPAG_Crm_Company_Constants::META_COMPANY_MAIL, trim( $raw_data[$mapping['email']] ) );
    }

    private function update_company_meta( $company_id, $meta_key, $meta_value ) {
        $table_name = ISPAG_Crm_Company_Constants::TABLE_COMPANY_META;

        if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
            $meta_value = maybe_serialize( $meta_value );
        }

        $existing_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT meta_id FROM {$table_name} WHERE company_id = %d AND meta_key = %s",
                $company_id,
                $meta_key
            )
        );

        if ( $existing_id ) {
            $updated = $this->wpdb->update(
                $table_name,
                array( 'meta_value' => $meta_value ),
                array(
                    'company_id' => $company_id,
                    'meta_key'   => $meta_key,
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );

            return $updated !== false;
        } else {
            $inserted = $this->wpdb->insert(
                $table_name,
                array(
                    'company_id' => $company_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $meta_value,
                ),
                array( '%d', '%s', '%s' )
            );

            return $inserted ? $this->wpdb->insert_id : false;
        }
    }

    private function clean_domain( $url ) {
        $url = trim( $url );
        if ( empty( $url ) ) {
            return '';
        }
        $domain = strtolower( preg_replace( '/^https?:\/\/(www\.)?/', '', sanitize_text_field( $url ) ) );
        return rtrim( $domain, '/' );
    } 

    private function count_csv_rows( $file_path, $delimiter ) {
        $count = 0;
        if ( ( $handle = fopen( $file_path, 'r' ) ) !== FALSE ) {
            while ( fgetcsv( $handle, 0, $delimiter ) !== FALSE ) {
                $count++;
            }
            fclose( $handle );
        }
        return $count;
    }

    private function get_csv_headers( $file, $delimiter ) {
        if ( ( $h = fopen( $file, 'r' ) ) !== FALSE ) {
            $line = fgets( $h ); fclose( $h );
            $line = mb_check_encoding( $line, 'UTF-8' ) ? $line : @iconv( 'Windows-1252', 'UTF-8//IGNORE', $line );
            $tmp  = fopen( 'php://temp', 'r+' ); fwrite( $tmp, $line ); rewind( $tmp );
            $header = fgetcsv( $tmp, 0, $delimiter ); fclose( $tmp );
            return array_map( 'trim', (array)$header );
        } 
        return [];
    }

    private function get_existing_task_for_file( $file_path ) {
        $transients = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT option_name, option_value FROM {$this->wpdb->options}
             WHERE option_name LIKE 'ispag_company_import_%%'
             AND option_value LIKE %s",
            '%' . $this->wpdb->esc_like( $file_path ) . '%'
        ) );

        foreach ( $transients as $transient ) {
            $task_data = maybe_unserialize( $transient->option_value );
            if ( $task_data && isset( $task_data['file_path'] ) && $task_data['file_path'] === $file_path ) {
                $task_data['task_id'] = str_replace( 'ispag_company_import_', '', $transient->option_name );
                return $task_data;
            }
        }
        return false;
    }

    public function cleanup_old_csv_imports() {
        $one_week_ago = time() - ( 7 * DAY_IN_SECONDS );
        $this->wpdb->query( $this->wpdb->prepare(
            "DELETE FROM {$this->wpdb->options}
             WHERE option_name LIKE 'ispag_company_import_%%'
             AND option_value LIKE %s",
            '%"start_time":"' . date( 'Y-m-d H:i:s', $one_week_ago ) . '%'
        ) );
    }

    private function redirect_with_message( $type, $msg ) {
        wp_redirect( add_query_arg( array(
            'page'             => $this->import_slug,
            'ispag_msg_type'   => $type,
            'ispag_message'    => urlencode( $msg )
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}

endif;