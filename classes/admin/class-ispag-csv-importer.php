<?php
if (!class_exists('ISPAG_CSV_Importer')) :

/**
 * Classe ISPAG_CSV_Importer
 * Gère l'import de projets depuis des fichiers CSV avec mappage des colonnes et traitement asynchrone.
 */
class ISPAG_CSV_Importer
{
    private $wpdb;
    private $menu_slug = 'ispag-entreprises';
    private $import_slug = 'ispag_import_projects';
    private $mapping_slug = 'ispag_map_projects';
    private $import_action = 'ispag_handle_project_upload';
    private $mapping_action = 'ispag_process_csv_mapping';
    private $target_table = ISPAG_Crm_Deal_Constants::TABLE_NAME;
    private $stages_table = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
    private $lookup_column = 'identifiant_viag';

    /**
     * Colonnes de la base de données avec leurs libellés
     */
    private $db_columns = array(
        'project_name' => 'Nom du Projet (Requis)',
        'current_stage_key' => 'Clé de l\'Étape Kanban (Automatique)',
        'offer_num' => 'Numéro d\'Offre',
        'deal_group_ref' => 'Référence Groupe (Auto-calculé)',
        'project_num' => 'Numéro de Projet',
        'identifiant_viag' => 'ID Viag (Clé unique)',
        'date_creation' => 'Date de Création',
        'closing_date' => 'Date de Clôture prévue',
        'customer_order_id' => 'ID Commande Client',
        'associated_company_id' => 'ID Entreprise Associée',
        'associated_contact_ids' => 'IDs Contacts Associés',
        'project_status' => 'Statut du Projet',
        'database_status' => 'État de la base (Mapping)',
        'project_db_status' => 'État (Mapping)',
        'engineer_id' => 'ID Ingénieur',
        'process_type' => 'Type de Processus',
        'reseller_offer' => 'Offre Revendeur (0/1)',
        'sales_coef' => 'Coeff Vente',
        'total_excl_vat' => 'Total HT',
        'created_by' => 'Créé par (ID)',
        'abonne' => 'Abonné',
        'deal_owner' => 'Propriétaire du Deal (ID)',
        'csv_owner_full_name' => '[Recherche] Nom complet Propriétaire',
        'csv_contact_lastname' => '[Recherche] Nom Contact',
        'csv_contact_firstname' => '[Recherche] Prénom Contact',
        'is_copie' => 'Est une copie',
    );

    /**
     * Mappage par défaut des colonnes CSV vers les champs de la base de données
     */
    private $default_mapping_keys = array(
        'project_name' => 'Nom',
        'offer_num' => 'Offre',
        'project_num' => 'N° du projet',
        'identifiant_viag' => 'ID du processus',
        'date_creation' => 'Date',
        'closing_date' => 'Offertgültigkeit',
        'associated_company_id' => 'N°s entreprises',
        'database_status' => 'État de la base',
        'project_db_status' => 'État',
        'process_type' => 'Type de processus',
        'total_excl_vat' => 'Total TVA non comprise',
        'csv_owner_full_name' => 'Chargé de dossier',
        'csv_contact_lastname' => 'Offerte Kontakt Nachname',
        'csv_contact_firstname' => 'Offerte Kontakt Vorname',
        'is_copie' => 'Ignorer les statistiques',
    );

    /**
     * Constructeur
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Ajouter les pages d'administration
        add_action('admin_menu', array($this, 'add_import_submenu'), 20);

        // Gérer l'upload du fichier CSV (étape 1)
        add_action('admin_post_' . $this->import_action, array($this, 'handle_project_csv_upload'));

        // Gérer la soumission du mappage (étape 2)
        add_action('admin_post_' . $this->mapping_action, array($this, 'start_async_csv_import'));

        // Actions AJAX pour le suivi de progression
        add_action('wp_ajax_ispag_check_csv_import_status', array($this, 'check_csv_import_status'));
        add_action('ispag_process_csv_import_task', array($this, 'process_csv_import_task'), 10, 1);

        // Nettoyage des anciens transients
        add_action('ispag_cleanup_old_csv_imports', array($this, 'cleanup_old_csv_imports'));
        if (!wp_next_scheduled('ispag_cleanup_old_csv_imports')) {
            wp_schedule_event(time(), 'daily', 'ispag_cleanup_old_csv_imports');
        }
    }

    /**
     * Ajoute les sous-menus pour l'import CSV
     */
    public function add_import_submenu()
    {
        add_submenu_page(
            $this->menu_slug,
            'Importer Projets CSV',
            'Importer Projets',
            'manage_options',
            $this->import_slug,
            array($this, 'admin_page_import_projects')
        );

        add_submenu_page(
            null,
            'Mappage des Colonnes',
            'Mappage',
            'manage_options',
            $this->mapping_slug,
            array($this, 'admin_page_map_projects')
        );
    }

    /**
     * Affiche la page d'import des projets
     */
    public function admin_page_import_projects()
    {
        ?>
        <div class="wrap">
            <h1>Importer des Projets depuis un CSV</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo esc_attr($this->import_action); ?>">
                <?php wp_nonce_field('ispag_csv_upload', 'ispag_csv_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Fichier CSV</th>
                        <td><input type="file" name="csv_file" accept=".csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Delimiter</th>
                        <td>
                            <select name="delimiter">
                                <option value=";">Point-virgule (;)</option>
                                <option value=",">Virgule (,)</option>
                                <option value="\t">Tabulation</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Upload and configure mapping'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Gère l'upload du fichier CSV
     */
    public function handle_project_csv_upload()
    {
        if (!isset($_POST['ispag_csv_nonce']) || !wp_verify_nonce($_POST['ispag_csv_nonce'], 'ispag_csv_upload')) {
            wp_die('Security check failed');
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            wp_die('Please select a file.');
        }

        $upload = wp_handle_upload($_FILES['csv_file'], array('test_form' => false));
        if (isset($upload['error'])) {
            wp_die($upload['error']);
        }

        $file_path = $upload['file'];
        $delimiter = stripslashes($_POST['delimiter']);
        if ($delimiter === '\t') {
            $delimiter = "\t";
        }

        wp_redirect(admin_url('admin.php?page=' . $this->mapping_slug . '&file=' . urlencode($file_path) . '&delim=' . urlencode($delimiter)));
        exit;
    }

    /**
     * Affiche la page de mappage des colonnes
     */
    public function admin_page_map_projects()
    {
        $file_path = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
        $delimiter = isset($_GET['delim']) ? sanitize_text_field(wp_unslash($_GET['delim'])) : ';';
        $task_id = isset($_GET['task_id']) ? sanitize_text_field(wp_unslash($_GET['task_id'])) : '';

        if (!file_exists($file_path)) {
            echo '<div class="error"><p>Fichier introuvable.</p></div>';
            return;
        }

        // Vérifier si une tâche existe déjà pour ce fichier
        $existing_task = $this->get_existing_task_for_file($file_path);
        if ($existing_task && !$task_id) {
            $task_id = $existing_task['task_id'];
        }

        if ($task_id) {
            $this->display_import_status($task_id);
            return;
        }

        $handle = fopen($file_path, 'r');
        $csv_headers = fgetcsv($handle, 0, $delimiter);
        fclose($handle);

        $normalized_csv_headers = array_map(function($h) {
            return strtolower(trim(str_replace([' ', '_', '°'], '', remove_accents($h))));
        }, $csv_headers);

        ?>
        <div class="wrap">
            <h1>Step 2: Column mapping</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($this->mapping_action); ?>">
                <input type="hidden" name="file_path" value="<?php echo esc_attr($file_path); ?>">
                <input type="hidden" name="delimiter" value="<?php echo esc_attr($delimiter); ?>">
                <?php wp_nonce_field('ispag_csv_upload', 'ispag_csv_nonce'); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Database field</th>
                            <th>Colonne CSV</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->db_columns as $db_key => $db_label) :
                            $selected_index = '';
                            if (isset($this->default_mapping_keys[$db_key])) {
                                $target = strtolower(trim(str_replace([' ', '_', '°'], '', remove_accents($this->default_mapping_keys[$db_key]))));
                                $find = array_search($target, $normalized_csv_headers);
                                if ($find !== false) {
                                    $selected_index = $find;
                                }
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($db_label); ?></strong></td>
                            <td>
                                <select name="mapping[<?php echo esc_attr($db_key); ?>]">
                                    <option value="">-- Ignorer --</option>
                                    <?php foreach ($csv_headers as $index => $header) : ?>
                                        <option value="<?php echo esc_attr($index); ?>" <?php selected($selected_index, $index); ?>>
                                            <?php echo esc_html($header); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="process_import" class="button button-primary" value="Lancer l'Importation Asynchrone">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Démarre un import CSV asynchrone après le mappage.
     */
    public function start_async_csv_import()
    {
        check_admin_referer('ispag_csv_upload', 'ispag_csv_nonce');

        $user_id = get_current_user_id();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        $delimiter = isset($_POST['delimiter']) ? sanitize_text_field(wp_unslash($_POST['delimiter'])) : ';';
        $mapping = isset($_POST['mapping']) ? (array) $_POST['mapping'] : array();
        $mapping = array_map('sanitize_text_field', wp_unslash($mapping));

        if (!file_exists($file_path)) {
            wp_die('Fichier introuvable.');
        }

        // Générer un ID de tâche unique
        $task_id = 'csv_import_' . $user_id . '_' . time();

        // Compter le nombre total de lignes (pour la progression)
        $total_rows = $this->count_csv_rows($file_path, $delimiter) - 1; // -1 pour l'en-tête

        // Stocker les données de la tâche dans un transient
        set_transient('ispag_csv_import_' . $task_id, [
            'file_path' => $file_path,
            'delimiter' => $delimiter,
            'mapping' => $mapping,
            'user_id' => $user_id,
            'status' => 'pending',
            'total_rows' => $total_rows,
            'processed_rows' => 0,
            'insert_count' => 0,
            'update_count' => 0,
            'start_time' => current_time('mysql'),
        ], DAY_IN_SECONDS);

        // Planifier la tâche pour exécution immédiate (via WP Cron)
        wp_schedule_single_event(time(), 'ispag_process_csv_import_task', [$task_id]);

        // Rediriger vers la page de statut avec le task_id
        wp_redirect(admin_url('admin.php?page=' . $this->mapping_slug . '&file=' . urlencode($file_path) . '&delim=' . urlencode($delimiter) . '&task_id=' . $task_id));
        exit;
    }

    /**
     * Compte le nombre de lignes dans un fichier CSV
     */
    private function count_csv_rows($file_path, $delimiter) {
        $count = 0;
        if (($handle = fopen($file_path, 'r')) !== FALSE) {
            while (fgetcsv($handle, 0, $delimiter) !== FALSE) {
                $count++;
            }
            fclose($handle);
        }
        return $count;
    }

    /**
     * Vérifie l'état d'une tâche d'import CSV (appelée en AJAX depuis la page de statut)
     */
    public function check_csv_import_status()
    {
        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash($_POST['task_id'])) : '';
        if (!$task_id) {
            wp_send_json_error('ID de tâche manquant.');
        }

        $task_data = get_transient('ispag_csv_import_' . $task_id);
        if ($task_data === false) {
            wp_send_json_error('Task not found or expired.');
        }

        wp_send_json_success([
            'status' => $task_data['status'],
            'progress' => [
                'total_rows' => $task_data['total_rows'],
                'processed_rows' => $task_data['processed_rows'],
                'insert_count' => $task_data['insert_count'],
                'update_count' => $task_data['update_count'],
            ],
            'start_time' => $task_data['start_time'],
            'end_time' => $task_data['end_time'] ?? null,
            'error' => $task_data['error'] ?? null,
        ]);
    }

    /**
     * Affiche l'état d'un import en cours
     */
    private function display_import_status($task_id) {
        $task_data = get_transient('ispag_csv_import_' . $task_id);
        if (!$task_data) {
            echo '<div class="error"><p>Task not found or expired.</p></div>';
            return;
        }

        $status = $task_data['status'];
        $progress = [
            'total_rows' => $task_data['total_rows'],
            'processed_rows' => $task_data['processed_rows'],
            'insert_count' => $task_data['insert_count'],
            'update_count' => $task_data['update_count'],
        ];

        ?>
        <div class="wrap">
            <h1>Statut de l'Import CSV</h1>
            <div class="card" style="background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2>Task: <?php echo esc_html($task_id); ?></h2>
                <p><strong>Statut :</strong> <?php echo esc_html(ucfirst($status)); ?></p>
                <p><strong>Fichier :</strong> <?php echo esc_html(basename($task_data['file_path'])); ?></p>
                <p><strong>Start:</strong> <?php echo esc_html($task_data['start_time']); ?></p>
                <?php if (isset($task_data['end_time'])) : ?>
                    <p><strong>Fin :</strong> <?php echo esc_html($task_data['end_time']); ?></p>
                <?php endif; ?>

                <?php if ($status === 'processing' || $status === 'pending') : ?>
                    <div style="margin: 20px 0; background: #f1f1f1; padding: 15px; border-radius: 5px;">
                        <div style="width: 100%; background: #e1e1e1; border-radius: 3px; height: 20px; margin-bottom: 5px;">
                            <div
                                id="ispag-progress-bar"
                                style="width: <?php echo esc_attr(min(100, ($progress['processed_rows'] / max(1, $progress['total_rows'])) * 100)); ?>%;
                                       height: 100%;
                                       background: #2271b1;
                                       border-radius: 3px;
                                       transition: width 0.3s ease;">
                            </div>
                        </div>
                        <p style="margin: 0;">
                            Progress: <?php echo esc_html($progress['processed_rows']); ?> / <?php echo esc_html($progress['total_rows']); ?> lignes
                            (<?php echo esc_html(min(100, ($progress['processed_rows'] / max(1, $progress['total_rows'])) * 100)); ?>%)
                        </p>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 20px;">
                    <p><strong>Insertions :</strong> <?php echo esc_html($progress['insert_count']); ?></p>
                    <p><strong>Updates:</strong> <?php echo esc_html($progress['update_count']); ?></p>
                </div>

                <?php if ($status === 'failed' && isset($task_data['error'])) : ?>
                    <div class="notice notice-error" style="margin-top: 20px;">
                        <p><strong>Erreur :</strong> <?php echo esc_html($task_data['error']); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($status === 'pending' || $status === 'processing') : ?>
                    <p style="margin-top: 20px;">
                        <button
                            id="ispag-refresh-status"
                            class="button button-secondary"
                            data-task-id="<?php echo esc_attr($task_id); ?>">
                            <?php _e('Refresh status', 'creation-reservoir'); ?>
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
                        action: 'ispag_check_csv_import_status',
                        task_id: taskId,
                        nonce: '<?php echo wp_create_nonce("ispag_csv_upload"); ?>'
                    },
                    beforeSend: function() {
                        $('#ispag-refresh-status').prop('disabled', true).text('Refreshing...');
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            const progress = data.progress;
                            const percentage = Math.min(100, (progress.processed_rows / Math.max(1, progress.total_rows)) * 100);

                            // Mettre à jour la barre de progression
                            progressBar.css('width', percentage + '%');

                            // Mettre à jour le texte de progression
                            progressText.html(
                                'Progress: ' + progress.processed_rows + ' / ' + progress.total_rows + ' lignes (' + percentage.toFixed(1) + '%)'
                            );

                            // Mettre à jour les compteurs
                            $('p:contains("Insertions")').html('<strong>Insertions :</strong> ' + progress.insert_count);
                            $('p:contains("Updates")').html('<strong>Updates:</strong> ' + progress.update_count);

                            // Mettre à jour le statut
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
                        } else {
                            alert('Erreur : ' + response.data);
                        }
                    },
                    error: function(xhr) {
                        alert('Network error : ' + xhr.responseText);
                    },
                    complete: function() {
                        $('#ispag-refresh-status').prop('disabled', false).text('Refresh status');
                    }
                });
            });

            // Rafraîchir automatiquement toutes les 5 secondes si l'import est en cours
            <?php if ($status === 'pending' || $status === 'processing') : ?>
            const refreshInterval = setInterval(function() {
                $('#ispag-refresh-status').trigger('click');
            }, 5000);

            // Arrêter le rafraîchissement automatique si la page est fermée ou si l'import est terminé
            $(window).on('beforeunload', function() {
                clearInterval(refreshInterval);
            });
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Vérifie si une tâche existe déjà pour ce fichier
     */
    private function get_existing_task_for_file($file_path) {
        global $wpdb;
        $transients = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM $wpdb->options
             WHERE option_name LIKE 'ispag_csv_import_%%'
             AND option_value LIKE %s",
            '%' . $wpdb->esc_like($file_path) . '%'
        ));

        foreach ($transients as $transient) {
            $task_data = maybe_unserialize($transient->option_value);
            if ($task_data && isset($task_data['file_path']) && $task_data['file_path'] === $file_path) {
                $task_data['task_id'] = str_replace('ispag_csv_import_', '', $transient->option_name);
                return $task_data;
            }
        }
        return false;
    }

    /**
     * Récupère l'ordre d'une étape à partir de sa clé.
     */
    private function get_stage_order($stage_key) {
        $stage = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT stage_order FROM wor9711_ispag_deal_stages WHERE stage_key = %s",
                $stage_key
            )
        );
        return $stage ? (int) $stage->stage_order : 0;
    }

    /**
     * Traite une tâche d'import CSV en arrière-plan (appelée par WP Cron)
     */
    public function process_csv_import_task($task_id) {
        $task_data = get_transient('ispag_csv_import_' . $task_id);
        if ($task_data === false || $task_data['status'] !== 'pending') {
            return;
        }

        // Mettre à jour le statut
        $task_data['status'] = 'processing';
        set_transient('ispag_csv_import_' . $task_id, $task_data, DAY_IN_SECONDS);

        $file_path = $task_data['file_path'];
        $delimiter = $task_data['delimiter'];
        $mapping = $task_data['mapping'];
        $user_id = $task_data['user_id'];

        $insert_count = 0;
        $update_count = 0;
        $row_count = 0;

        $deal_repo = new ISPAG_Crm_Deals_Repository();

        try {
            if (($handle = fopen($file_path, 'r')) !== FALSE) {
                // Sauter l'en-tête
                fgetcsv($handle, 0, $delimiter);

                while (($raw_data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                    if (empty($raw_data) || !isset($raw_data[0])) {
                        continue;
                    }

                    // Convertir en UTF-8 si nécessaire
                    $raw_data = array_map(function($f) {
                        return (mb_check_encoding($f, 'UTF-8')) ? $f : @iconv('Windows-1252', 'UTF-8//IGNORE', $f);
                    }, $raw_data);

                    $db_data = $this->prepare_data_for_db($raw_data, $mapping);

                    if (empty($db_data[$this->lookup_column])) {
                        $task_data['processed_rows']++;
                        set_transient('ispag_csv_import_' . $task_id, $task_data, DAY_IN_SECONDS);
                        continue;
                    }

                    $db_data['record_source'] = 'viag_crm';

                    // Le CSV Viag contient des numéros d'entreprise (viag_id) : les deals sont liés par Id
                    if (!empty($db_data['associated_company_id'])) {
                        $db_data['associated_company_id'] = $this->viag_ids_to_company_ids($db_data['associated_company_id']);
                    }

                    $existing_row = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT id, associated_contact_ids, associated_company_id, current_stage_key FROM {$this->target_table} WHERE {$this->lookup_column} = %s",
                        $db_data[$this->lookup_column]
                    ));

                    if ($existing_row) {
                        // Récupérer l'ordre de l'étape actuelle et de la nouvelle étape
                        $current_stage_order = $this->get_stage_order($existing_row->current_stage_key);
                        $new_stage_order = $this->get_stage_order($db_data['current_stage_key']);

                        // Ne pas mettre à jour si la nouvelle étape est en arrière
                        if ($new_stage_order < $current_stage_order) {
                            unset($db_data['current_stage_key']);
                        }

                        // Fusionner les IDs existants avec les nouveaux
                        if (!empty($db_data['associated_contact_ids'])) {
                            $db_data['associated_contact_ids'] = $this->merge_ids($existing_row->associated_contact_ids, $db_data['associated_contact_ids']);
                        } else {
                            $db_data['associated_contact_ids'] = $existing_row->associated_contact_ids;
                        }

                        if (!empty($db_data['associated_company_id'])) {
                            $db_data['associated_company_id'] = $this->merge_ids($existing_row->associated_company_id, $db_data['associated_company_id']);
                        } else {
                            $db_data['associated_company_id'] = $existing_row->associated_company_id;
                        }

                        
                        $this->wpdb->update($this->target_table, $db_data, array('id' => $existing_row->id));
                        $update_count++;

                        // $deal_repo->ispag_handle_deal_stage_update($existing_row->id, $db_data['current_stage_key'], $db_data['current_stage_key']);
                        
                    } else {

                        $insert_result = $this->wpdb->insert($this->target_table, $db_data);
                        $insert_count++;

                        if ($insert_result !== false) {
                            // Récupération de l'ID auto-incrémenté venant d'être inséré
                            $inserted_id = $this->wpdb->insert_id; 
                            $insert_count++;

                            // Passage de l'ID inséré ($inserted_id) à la méthode
                            $deal_repo->ispag_handle_deal_stage_update(
                                $inserted_id, 
                                $db_data['current_stage_key'], 
                                $db_data['current_stage_key']
                            );
                        } else {
                            ISPAG_Workflow_Logger::error(
                                "Insert failed in table {$this->target_table}",
                                ['db_data' => $db_data, 'error' => $this->wpdb->last_error]
                            );
                        }
                                                
                    }

                    $row_count++;
                    $task_data['processed_rows'] = $row_count;
                    $task_data['insert_count'] = $insert_count;
                    $task_data['update_count'] = $update_count;
                    set_transient('ispag_csv_import_' . $task_id, $task_data, DAY_IN_SECONDS);
                }
                fclose($handle);
            }

            // Nettoyer le fichier temporaire
            @unlink($file_path);

            // Marquer la tâche comme terminée
            $task_data['status'] = 'completed';
            $task_data['end_time'] = current_time('mysql');
            set_transient('ispag_csv_import_' . $task_id, $task_data, DAY_IN_SECONDS);

            // Envoyer une notification à l'utilisateur
            $this->send_import_notification($user_id, $task_id, $row_count, $insert_count, $update_count, basename($file_path), true);

        } catch (Exception $e) {
            $task_data['status'] = 'failed';
            $task_data['error'] = $e->getMessage();
            $task_data['end_time'] = current_time('mysql');
            set_transient('ispag_csv_import_' . $task_id, $task_data, DAY_IN_SECONDS);

            $this->send_import_notification($user_id, $task_id, $row_count, $insert_count, $update_count, basename($file_path), false, $e->getMessage());
        }
    }

    /**
     * Envoie une notification à l'utilisateur après l'import
     */
    private function send_import_notification($user_id, $task_id, $row_count, $insert_count, $update_count, $filename, $success, $error = '') {
        if (!class_exists('ISPAG_Notifications_Manager')) {
            return;
        }

        if ($success) {
            $title = esc_html(__('✅ CSV Import Completed', 'creation-reservoir'));
            $message = sprintf(
                esc_html(__(
                    'Your CSV import has been completed successfully.<br>
                    - Total rows processed: %1$d<br>
                    - Insertions: %2$d<br>
                    - Updates: %3$d<br>
                    - File: %4$s<br>
                    - Task ID: %5$s',
                    'creation-reservoir'
                )),
                esc_html($row_count),
                esc_html($insert_count),
                esc_html($update_count),
                esc_html($filename),
                esc_html($task_id)
            );
        } else {
            $title = esc_html(__('❌ CSV Import Failed', 'creation-reservoir'));
            $message = sprintf(
                esc_html(__(
                    'Your CSV import has failed.<br>
                    - Processed rows: %1$d<br>
                    - Insertions: %2$d<br>
                    - Updates: %3$d<br>
                    - File: %4$s<br>
                    - Task ID: %5$s<br>
                    - Error: %6$s',
                    'creation-reservoir'
                )),
                esc_html($row_count),
                esc_html($insert_count),
                esc_html($update_count),
                esc_html($filename),
                esc_html($task_id),
                esc_html($error)
            );
        }

        ISPAG_Notifications_Manager::send(
            [$user_id],
            'datas_export', 
            $title,
            $message,
            '',
            null
        );
    }

    /**
     * Nettoie les anciens transients d'import CSV
     */
    public function cleanup_old_csv_imports() {
        global $wpdb;
        $one_week_ago = time() - (7 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->options
             WHERE option_name LIKE 'ispag_csv_import_%%'
             AND option_value LIKE %s",
            '%"start_time":"' . date('Y-m-d H:i:s', $one_week_ago) . '%'
        ));
    }

    /**
     * Fusionne deux chaînes d'IDs séparés par des virgules
     */
    private function merge_ids($existing, $new) {
        $existing_str = trim((string)$existing);
        $new_str = trim((string)$new);
        $existing_arr = !empty($existing_str) ? explode(',', $existing_str) : array();
        $new_arr = !empty($new_str) ? explode(',', $new_str) : array();
        $merged = array_unique(array_merge($existing_arr, $new_arr));
        $merged = array_filter(array_map('trim', $merged));
        return implode(',', $merged);
    }

    /**
     * Mappe les statuts CSV vers les clés d'étape CRM
     */
    private function map_csv_to_stage_key($db_status, $project_status) {
        // Convertir les valeurs en entiers pour éviter les comparaisons de chaînes
        $db_status = (int) trim($db_status);
        $project_status = (int) trim($project_status);

        // Cas où Etat = 0 (Ouvert)
        if ($project_status === 0) {
            if ($db_status === 1) {
                return 'submission_received';
            } elseif ($db_status === 2 || $db_status === 3) {
                return 'proposal_sent';
            }
            // Par défaut pour Etat = 0 et Etat de la base non géré
            return 'submission_received';
        }
        // Cas où Etat = 1 (Gagné)
        elseif ($project_status === 1) {
            if ($db_status === 8) {
                return 'closed_won';
            } else {
                return 'open_won';
            }
        }
        // Cas où Etat = 2 (Perdu)
        elseif ($project_status === 2) {
            return 'closed_lost';
        }

        // Valeur par défaut si aucune condition n'est remplie
        return 'submission_received';
    }

    /**
     * Prépare les données du CSV pour l'insertion en base de données
     */
    /** Convertit une liste de viag_id (« 123,456 ») en liste d'Id de ispag_companies (les inconnus sont ignorés). */
    private function viag_ids_to_company_ids($csv_value) {
        $viag_ids = array_filter(array_map('absint', preg_split('/[,;\s]+/', (string) $csv_value)));
        if (!$viag_ids) return '';
        $table = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $ph    = implode(',', array_fill(0, count($viag_ids), '%d'));
        $ids   = $this->wpdb->get_col($this->wpdb->prepare("SELECT Id FROM {$table} WHERE viag_id IN ($ph) ORDER BY Id", ...$viag_ids));
        return implode(',', array_map('intval', $ids));
    }

    private function prepare_data_for_db($raw_data, $mapping) {
        $db_data = array();
        $owner_fn = ''; $contact_ln = ''; $contact_fn = '';
        $tmp_db_status = ''; $tmp_project_status = '';

        foreach ($this->db_columns as $db_key => $db_label) {
            if (!isset($mapping[$db_key]) || $mapping[$db_key] === "") continue;

            $csv_index = intval($mapping[$db_key]);
            if (!isset($raw_data[$csv_index])) continue;

            $value = trim($raw_data[$csv_index]);

            // Stockage pour calcul de l'étape
            if ($db_key === 'database_status') $tmp_db_status = $value;
            if ($db_key === 'project_db_status') $tmp_project_status = $value;

            if ($value === '') continue;

            if (strpos($db_key, 'csv_') === 0) {
                if ($db_key === 'csv_owner_full_name') $owner_fn = $value;
                elseif ($db_key === 'csv_contact_lastname') $contact_ln = $value;
                elseif ($db_key === 'csv_contact_firstname') $contact_fn = $value;
                continue;
            }

            switch ($db_key) {
                case 'date_creation':
                case 'closing_date':
                    $date = $this->parse_date_to_mysql($value);
                    if ($date) $db_data[$db_key] = $date;
                    break;
                case 'total_excl_vat':
                case 'sales_coef':
                    $db_data[$db_key] = $this->parse_amount_to_float($value);
                    break;
                case 'is_copie':
                    $db_data[$db_key] = (strtoupper(trim($value)) === 'VRAI' || strtoupper(trim($value)) === 'TRUE' || $value === '1') ? 1 : 0;
                    break;
                default:
                    $db_data[$db_key] = $value;
                    break;
            }
        }

        // Mapping automatique de l'étape Kanban
        $db_data['current_stage_key'] = $this->map_csv_to_stage_key($tmp_db_status, $tmp_project_status);

        if (!empty($db_data['offer_num'])) {
            $parts = explode('.', $db_data['offer_num']);
            $db_data['deal_group_ref'] = trim($parts[0]);
        }

        $this->process_users_mapping($db_data, $owner_fn, $contact_fn, $contact_ln);

        return $db_data;
    }

    /**
     * Parse une date dans différents formats vers le format MySQL (YYYY-MM-DD)
     */
    private function parse_date_to_mysql($date_str) {
        $formats = array('d.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.y', 'd-m-Y', 'm/d/Y', 'Y/m/d');
        foreach ($formats as $f) {
            $d = DateTime::createFromFormat($f, trim($date_str));
            if ($d && $d->format($f) === trim($date_str)) return $d->format('Y-m-d');
        }
        return null;
    }

    /**
     * Traite le mappage des utilisateurs (propriétaire et contacts)
     */
    private function process_users_mapping(&$db_data, $owner_full, $contact_fn, $contact_ln) {
        if (!empty($owner_full)) {
            $parts = explode(' ', $owner_full);
            $ln = (count($parts) >= 2) ? array_pop($parts) : '';
            $fn = implode(' ', $parts) ?: $owner_full;
            $uid = $this->lookup_user_id_by_name($fn, $ln, 'OWNER');
            if ($uid) $db_data['deal_owner'] = $uid;
        }

        if (!empty($contact_ln)) {
            $cid = $this->lookup_user_id_by_name($contact_fn, $contact_ln, 'CONTACT');
            if ($cid) {
                $db_data['associated_contact_ids'] = (string)$cid;
            }
        }
    }

    private function parse_amount_to_float($value) {
        $value = trim($value);
        if ($value === '') return 0.0;

        // On retire tout ce qui n'est pas chiffre, point, virgule ou signe moins
        // (apostrophes, espaces normaux, espaces insécables, NBSP, etc.)
        $value = preg_replace('/[^\d,.\-]/u', '', $value);

        $lastComma = strrpos($value, ',');
        $lastDot   = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Les deux présents : le dernier des deux est le séparateur décimal
            if ($lastComma > $lastDot) {
                // format "1.234,56" -> virgule = décimale
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                // format "1,234.56" -> point = décimale
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            // Uniquement une virgule : c'est la décimale
            $value = str_replace(',', '.', $value);
        }
        // Si uniquement un point, ou rien : déjà bon

        return (float) $value;
    }

    /**
     * Recherche l'ID d'un utilisateur par son nom
     */
    private function lookup_user_id_by_name($first, $last, $context = '') {
        if (empty($last)) return null;

        $meta_key = 'ispag_account_status';
        $meta_value = 'disabled';

        // Recherche exacte
        $user = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT u.ID FROM {$this->wpdb->users} u
             LEFT JOIN {$this->wpdb->usermeta} m ON u.ID = m.user_id AND m.meta_key = %s
             WHERE (u.display_name = %s OR u.user_login = %s)
             AND (m.meta_value IS NULL OR m.meta_value != %s)
             LIMIT 1",
            $meta_key, "$first $last", strtolower($last), $meta_value
        ));

        if ($user) return $user->ID;

        // Recherche partielle (nom de famille seul)
        $user = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT u.ID FROM {$this->wpdb->users} u
             LEFT JOIN {$this->wpdb->usermeta} m ON u.ID = m.user_id AND m.meta_key = %s
             WHERE (u.display_name LIKE %s OR u.display_name LIKE %s)
             AND (m.meta_value IS NULL OR m.meta_value != %s)
             LIMIT 1",
            $meta_key,
            '% ' . $this->wpdb->esc_like($last),
            $this->wpdb->esc_like($last) . ' %',
            $meta_value
        ));

        return $user ? $user->ID : null;
    }
}

endif;