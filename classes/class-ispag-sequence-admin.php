<?php

class ISPAG_Sequence_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Liste des actions AJAX à enregistrer
        $actions = [
            'save_crm_sequence',
            'get_active_sequences',
            'enroll_in_sequence',
            'crm_enroll_now'
        ];

        foreach ($actions as $action) {
            $callback = [$this, "ajax_" . str_replace('crm_', '', $action)];
            add_action("wp_ajax_{$action}", $callback);
            
        }
    }

    public function add_menu() {
        add_submenu_page(
            'ispag-entreprises',       // Slug du menu parent
            'Séquences CRM',           // Titre de la page
            'Séquences',               // Titre du menu
            'manage_options',          // Capacité requise
            'ispag-sequences',         // Slug de ce sous-menu
            [$this, 'render_page_router'] // Le routeur décide quoi afficher
        );
    }

    /**
     * ROUTEUR : Gère l'affichage selon l'action dans l'URL
     */
    public function render_page_router() {
        $action = $_GET['action'] ?? 'list';
        $id     = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($action === 'edit' || $action === 'new') {
            // On utilise le builder pour la création ET l'édition
            $this->render_builder_page($id); 
        } else {
            $this->render_list_page();
        }
    }

    public function enqueue_admin_scripts($hook) {
        // On vérifie qu'on est sur la page des séquences (le slug est dans le hook)
        if (strpos($hook, 'ispag-sequences') === false) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');

        $js_url = plugin_dir_url( __FILE__ ) . '../assets/js/sequence-builder.js';
        wp_enqueue_editor();
        wp_enqueue_script(
            'ispag-sequence-builder-js', 
            $js_url, 
            ['jquery', 'jquery-ui-sortable'], 
            '1.0.5', 
            true
        );

        wp_localize_script('ispag-sequence-builder-js', 'ispag_ajax_sequence', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ispag_crm_nonce')
        ]);
    }

    /**
     * VUE : LISTE DES SÉQUENCES
     */
    private function render_list_page() {
        global $wpdb;
        $table_seq = $wpdb->prefix . 'ispag_sequences';
        $table_enroll = $wpdb->prefix . 'ispag_sequence_enrollments';

        $sequences = $wpdb->get_results("
            SELECT s.*, 
            (SELECT COUNT(*) FROM $table_enroll WHERE sequence_id = s.id AND status = 'ACTIVE') as active_contacts
            FROM $table_seq s 
            ORDER BY s.created_at DESC
        ");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Séquences de vente</h1>
            <a href="?page=ispag-sequences&action=new" class="page-title-action">Ajouter une séquence</a>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th class="manage-column column-primary">Nom de la séquence</th>
                        <th class="manage-column">Description</th>
                        <th class="manage-column">Contacts Actifs</th>
                        <th class="manage-column">Statut</th>
                        <th class="manage-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sequences) : foreach ($sequences as $seq) : 
                        $edit_url = admin_url('admin.php?page=ispag-sequences&action=edit&id=' . $seq->id);
                        ?>
                        <tr>
                            <td class="column-primary">
                                <strong><a href="<?php echo $edit_url; ?>"><?php echo esc_html($seq->name); ?></a></strong>
                            </td>
                            <td><?php echo wp_trim_words(esc_html($seq->description), 10); ?></td>
                            <td><span class="ispag-badge"><?php echo intval($seq->active_contacts); ?> actifs</span></td>
                            <td>
                                <?php echo $seq->is_active ? 
                                    '<span style="color:green;">● Active</span>' : 
                                    '<span style="color:red;">○ Inactive</span>'; 
                                ?>
                            </td>
                            <td>
                                <a href="<?php echo $edit_url; ?>" class="button button-small">Modifier</a>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="5">Aucune séquence trouvée. <a href="?page=ispag-sequences&action=new">Créez-en une !</a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <style>
            .ispag-badge { background: #d7eeff; color: #0073aa; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        </style>
        <?php
    }

    

    /**
     * VUE : BUILDER (POUR NOUVELLE SÉQUENCE)
     */
    public function render_builder_page($id = 0) {
        $template_repo = new ISPAG_Template_Repository();
        $all_templates = $template_repo->get_templates_for_user(get_current_user_id());
        
        $sequence_to_edit = null;
        if ($id > 0) {
            $repo = new ISPAG_Sequence_Repository();
            $sequence_to_edit = $repo->get_sequence($id);
        }

        // On injecte les données dans le JS pour que le builder se pré-remplisse
        ?>
        <script>
            const ispag_editing_sequence = <?php echo $sequence_to_edit ? json_encode($sequence_to_edit) : 'null'; ?>;
        </script>
        <?php

        include plugin_dir_path(__FILE__) . '/admin/sequence-builder.php';
    }

    /**
     * AJAX : ENREGISTRER UNE NOUVELLE SÉQUENCE (DEPUIS LE BUILDER JS)
     */
    public function ajax_save_sequence() {
        check_ajax_referer('ispag_crm_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        $sequence_data = $_POST['sequence'] ?? null;

        if (!$sequence_data || empty($sequence_data['name'])) {
            wp_send_json_error(['message' => 'Données de séquence invalides']);
        }

        $repository = new ISPAG_Sequence_Repository();
        $sequence_id = $repository->save_full_sequence($sequence_data);

        if ($sequence_id) {
            wp_send_json_success([
                'message' => 'Séquence enregistrée !',
                'id'      => $sequence_id
            ]);
        } else {
            wp_send_json_error(['message' => 'Erreur SQL lors de l\'enregistrement']);
        }
    }

    /**
     * AJAX : ENRÔLEMENT IMMÉDIAT
     */
    public function ajax_enroll_now() {
        check_ajax_referer('ispag_crm_nonce', 'security');
        
        $contact_id = intval($_POST['contact_id']);
        $sequence_id = intval($_POST['sequence_id']);
        
        $repo = new ISPAG_Sequence_Repository();
        if ($repo->enroll($contact_id, $sequence_id)) {
            wp_send_json_success('Contact inscrit avec succès !');
        } else {
            wp_send_json_error('Erreur lors de l\'inscription.');
        }
    }

    /**
     * AJAX : LISTE DES SÉQUENCES ACTIVES (POUR SELECTS)
     */
    public function ajax_get_active_sequences() {
        global $wpdb;
        $table = $wpdb->prefix . 'ispag_sequences';
        $results = $wpdb->get_results("SELECT id, name FROM $table WHERE is_active = 1 ORDER BY name ASC");
        
        if ($results) {
            wp_send_json_success($results);
        } else {
            wp_send_json_error('Aucune séquence trouvée.');
        }
    }

    /**
     * AJAX : ENRÔLEMENT DEPUIS FICHE CONTACT
     */
    public function ajax_enroll_in_sequence() {
        $contact_id  = intval($_POST['contact_id']);
        $sequence_id = intval($_POST['sequence_id']);
        $deal_id     = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : null; // Récupération du Deal

        if (!$contact_id || !$sequence_id) {
            wp_send_json_error('Missing data.');
        }

        $repository = new ISPAG_Sequence_Repository();
        
        // On passe le 3ème argument ici
        if ($repository->enroll($contact_id, $sequence_id, $deal_id)) {
            wp_send_json_success('Successfully enrolled!');
        } else {
            wp_send_json_error('Error during enrollment.');
        }
    }
}