<?php

class ISPAG_Entreprise_Manager {
    // Utilisation de vos constantes pour la liaison Meta
    const META_COMPANY_ID   = 'ispag_company_id';
    const META_COMPANY_CITY = 'ispag_company_city';

    private $table_name;
    private $menu_slug = 'ispag-entreprises';
    private $add_slug  = 'ispag-entreprise-add';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ispag_companies';

        add_action('admin_menu', array($this, 'add_admin_menu_page'));
        add_action('admin_post_ispag_save_entreprise', array($this, 'handle_form_submissions'));
        add_action('init', array($this, 'add_shortcodes'));
    }

    public function add_admin_menu_page() {
        add_menu_page('CRM ISPAG', 'CRM ISPAG', 'manage_options', $this->menu_slug, array($this, 'render_entreprises_page'), 'dashicons-store', 6);
        add_submenu_page($this->menu_slug, __('Companies', 'ispag-crm'), __('Companies', 'ispag-crm'), 'manage_options', $this->menu_slug, array($this, 'render_entreprises_page'));
        add_submenu_page($this->menu_slug, __('Add Company', 'ispag-crm'), __('Add Company', 'ispag-crm'), 'manage_options', $this->add_slug, array($this, 'render_entreprise_form'));
    }

    public function render_entreprises_page() {
        $id     = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

        if ('edit' === $action && $id > 0) {
            $this->render_entreprise_form($id);
        } elseif ('delete' === $action && $id > 0) {
            $this->handle_delete($id);
        } else {
            $this->render_list_view();
        }
    }

    private function render_list_view() {
        global $wpdb;

        // Paramètres de tri et recherche
        $search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby     = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'company_name';
        $order       = isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC' ? 'DESC' : 'ASC';

        // Mapping pour le tri (Inclusion de la ville via Meta)
        $sortable_columns = array(
            'company_name'    => 'E.company_name',
            'ville'           => 'city_meta.meta_value',
            'isSupplier'      => 'E.isSupplier',
            'isIngenieur'     => 'E.isIngenieur',
            'is_active'       => 'E.is_active'
        );

        $sql_orderby = isset($sortable_columns[$orderby]) ? $sortable_columns[$orderby] : 'E.company_name';

        // Requête complexe : 
        // 1. On prend les données de votre table
        // 2. On joint les postmeta pour récupérer la VILLE (basé sur l'Id de la company)
        $sql = "SELECT E.*, city_meta.meta_value as ville_name
                FROM {$this->table_name} E 
                LEFT JOIN {$wpdb->postmeta} city_meta ON (E.Id = city_meta.post_id AND city_meta.meta_key = '" . self::META_COMPANY_CITY . "')";
        
        $where = array();
        $params = array();

        if (!empty($search_term)) {
            $like = '%' . $wpdb->esc_like($search_term) . '%';
            $where[] = "(E.company_name LIKE %s OR E.compagny_domain LIKE %s OR city_meta.meta_value LIKE %s)";
            array_push($params, $like, $like, $like);
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY {$sql_orderby} {$order}";

        $companies = $wpdb->get_results($wpdb->prepare($sql, $params));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Gestion des Entreprises</h1>
            <a href="<?php echo admin_url('admin.php?page=' . $this->add_slug); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_slug); ?>" />
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search_term); ?>" />
                    <?php submit_button('Rechercher', 'button', false, false); ?>
                </p>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <?php 
                        $this->render_sortable_column_header('company_name', 'Entreprise', $orderby, $order, $search_term);
                        $this->render_sortable_column_header('ville', 'Ville (Meta)', $orderby, $order, $search_term);
                        $this->render_sortable_column_header('isSupplier', 'Fournisseur', $orderby, $order, $search_term);
                        $this->render_sortable_column_header('isIngenieur', 'Ingénieur', $orderby, $order, $search_term);
                        $this->render_sortable_column_header('is_active', 'Actif', $orderby, $order, $search_term);
                        ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($companies) : foreach ($companies as $company) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($company->company_name); ?></strong><br>
                                <small>ID: <?php echo $company->Id; ?> | Viag: <?php echo $company->viag_id; ?></small>
                            </td>
                            <td><?php echo esc_html($company->ville_name ? $company->ville_name : '—'); ?></td>
                            <td><?php echo $company->isSupplier ? '✅' : '❌'; ?></td>
                            <td><?php echo $company->isIngenieur ? '✅' : '❌'; ?></td>
                            <td><?php echo $company->is_active ? '<span style="color:green">Oui</span>' : '<span style="color:red">Non</span>'; ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page='.$this->menu_slug.'&action=edit&id='.$company->Id); ?>">Modifier</a> | 
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page='.$this->menu_slug.'&action=delete&id='.$company->Id), 'delete_entreprise_'.$company->Id); ?>" 
                                   class="submitdelete" style="color:red;" onclick="return confirm('Supprimer définitivement ?');">Supprimer</a>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="6">Aucune entreprise trouvée.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_sortable_column_header($key, $label, $current_orderby, $current_order, $search) {
        $active = ($key === $current_orderby);
        $next_order = ($active && $current_order === 'ASC') ? 'DESC' : 'ASC';
        $url = add_query_arg(['orderby' => $key, 'order' => $next_order, 's' => $search], admin_url('admin.php?page='.$this->menu_slug));
        $class = "manage-column column-{$key} sortable " . ($active ? "sorted ".strtolower($current_order) : "desc");
        echo "<th class='{$class}'><a href='".esc_url($url)."'><span>{$label}</span><span class='sorting-indicator'></span></a></th>";
    }

    public function render_entreprise_form($id = 0) {
        global $wpdb;
        $company = null;
        $city = '';

        if ($id > 0) {
            $company = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE Id = %d", $id));
            // Récupération de la ville dans les metas
            $city = get_post_meta($id, self::META_COMPANY_CITY, true);
        }
        ?>
        <div class="wrap">
            <h1><?php echo $id > 0 ? 'Modifier : ' . esc_html($company->company_name) : 'Ajouter une entreprise'; ?></h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('ispag_entreprise_nonce'); ?>
                <input type="hidden" name="action" value="ispag_save_entreprise">
                <input type="hidden" name="id" value="<?php echo $id; ?>">

                <table class="form-table">
                    <tr>
                        <th><label>Nom de l'entreprise</label></th>
                        <td><input name="company_name" type="text" value="<?php echo $company ? esc_attr($company->company_name) : ''; ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label>Ville (Post Meta)</label></th>
                        <td><input name="ville_meta" type="text" value="<?php echo esc_attr($city); ?>" class="regular-text" placeholder="Saisir la ville"></td>
                    </tr>
                    <tr>
                        <th><label>Viag ID</label></th>
                        <td><input name="viag_id" type="number" value="<?php echo $company ? esc_attr($company->viag_id) : ''; ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label>Type</label></th>
                        <td>
                            <label><input name="isSupplier" type="checkbox" value="1" <?php checked($company ? $company->isSupplier : 0, 1); ?>> Fournisseur</label><br>
                            <label><input name="isIngenieur" type="checkbox" value="1" <?php checked($company ? $company->isIngenieur : 0, 1); ?>> Ingénieur</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Domaine</label></th>
                        <td><input name="compagny_domain" type="text" value="<?php echo $company ? esc_attr($company->compagny_domain) : ''; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Statut</label></th>
                        <td>
                            <select name="is_active">
                                <option value="1" <?php selected($company ? $company->is_active : 1, 1); ?>>Actif</option>
                                <option value="0" <?php selected($company ? $company->is_active : 1, 0); ?>>Inactif</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function handle_form_submissions() {
        check_admin_referer('ispag_entreprise_nonce');
        if (!current_user_can('manage_options')) wp_die('Accès refusé');

        global $wpdb;
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        
        $data = array(
            'company_name'    => sanitize_text_field($_POST['company_name']),
            'compagny_domain' => sanitize_text_field($_POST['compagny_domain']),
            'viag_id'         => absint($_POST['viag_id']),
            'isSupplier'      => isset($_POST['isSupplier']) ? 1 : 0,
            'isIngenieur'     => isset($_POST['isIngenieur']) ? 1 : 0,
            'is_active'       => isset($_POST['is_active']) ? absint($_POST['is_active']) : 1,
        );

        if ($id > 0) {
            $wpdb->update($this->table_name, $data, array('Id' => $id));
            $msg = 'updated';
        } else {
            $wpdb->insert($this->table_name, $data);
            $id = $wpdb->insert_id; // On récupère l'ID pour le meta
            $msg = 'added';
        }

        // Sauvegarde de la ville dans les postmeta (lié à l'ID de la company)
        if (isset($_POST['ville_meta'])) {
            update_post_meta($id, self::META_COMPANY_CITY, sanitize_text_field($_POST['ville_meta']));
        }

        wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '&message=' . $msg));
        exit;
    }

    public function handle_delete($id) {
        check_admin_referer('delete_entreprise_' . $id);
        global $wpdb;
        
        // Suppression dans la table
        $wpdb->delete($this->table_name, array('Id' => $id));
        // Optionnel : Suppression du meta ville
        delete_post_meta($id, self::META_COMPANY_CITY);

        wp_safe_redirect(admin_url('admin.php?page=' . $this->menu_slug . '&message=deleted'));
        exit;
    }

    public function add_shortcodes() {}
}