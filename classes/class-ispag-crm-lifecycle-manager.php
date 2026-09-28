<?php
/**
 * Class ISPAG_Crm_Lifecycle_Manager
 * Gère les remises et coefficients de vente pour les entreprises dans le CRM ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_crm_discount_manager.log.
 */
if (!class_exists('ISPAG_Crm_Lifecycle_Manager')) :

class ISPAG_Crm_Lifecycle_Manager
{
    private $logger;
    private $wpdb;
    private $table_company_lifecycle;

    public function __construct()
    {
        // // 1. TOUJOURS enregistrer les hooks AJAX tout au début du constructeur
        // add_action('wp_ajax_update_company_lifecycle', [$this, 'handle_ajax_update_lifecycle']);
        // add_action('wp_ajax_nopriv_update_company_lifecycle', [$this, 'handle_ajax_update_lifecycle']);

        // 2. Initialisation des dépendances
        global $wpdb;
        $this->wpdb = $wpdb;
        
        if (class_exists('ISPAG_Logger')) {
            $this->logger = ISPAG_Logger::get_instance();
        }

        // Sécurité si la constante n'est pas définie
        if (class_exists('ISPAG_Crm_Company_Constants') && defined('ISPAG_Crm_Company_Constants::TABLE_COMPANY_LIFECYCLE')) {
            $this->table_company_lifecycle = ISPAG_Crm_Company_Constants::TABLE_COMPANY_LIFECYCLE;
        } else {
            $this->table_company_lifecycle = $this->wpdb->prefix . 'ispag_companies_lifecycle';
        }

        // // 3. Script JS
        // add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function get_standard_lifecycle() {
        $table_name = $this->wpdb->prefix . 'ispag_lifecycle_phases';

        // Récupération des phases triées par ordre
        $results = $this->wpdb->get_results(
            "SELECT phase_key, phase_label FROM {$table_name} ORDER BY phase_order ASC",
            ARRAY_A
        );

        if (empty($results)) {
            // Fallback si la table est vide
            return [
                'Prospect' => __('Prospect', 'ispag-crm'),
                'Partner'  => __('Partner', 'ispag-crm'),
                'Reseller' => __('Reseller', 'ispag-crm'),
                'Vendor'   => __('Vendor', 'ispag-crm'),
                'Engineer' => __('Engineer', 'ispag-crm'),
                'Other'    => __('Other', 'ispag-crm'),
            ];
        }

        // Transformation sous forme de tableau associatif [ 'phase_key' => 'phase_label' ]
        $options = [];
        foreach ($results as $row) {
            // Vous pouvez aussi utiliser __($row['phase_label'], 'ispag-crm') si vos libellés sont traduisibles
            $options[$row['phase_key']] = __($row['phase_label'], 'ispag-crm');
        }

        return $options;
    }

    /**
     * Récupère le lifecycle actif (ou le plus récent) d'une entreprise avec son libellé lisible.
     * Si aucun lifecycle n'existe, en crée un par défaut ('subscriber') avant de le retourner.
     * 
     * @param int $company_id L'ID de l'entreprise.
     * @return object|null Un objet contenant les informations de lifecycle et le 'phase_label'.
     */
    public function get_company_lifecycle($company_id) {
        $table_companies_lifecycle = $this->table_company_lifecycle;
        $table_phases = $this->wpdb->prefix . 'ispag_lifecycle_phases';

        // Requête principale
        $query = $this->wpdb->prepare(
            "SELECT cl.*, p.phase_label, p.bg_color, p.text_color, p.phase_description 
             FROM {$table_companies_lifecycle} cl
             LEFT JOIN {$table_phases} p ON cl.lifecycle_type COLLATE utf8mb4_unicode_ci = p.phase_key COLLATE utf8mb4_unicode_ci
             WHERE cl.company_id = %d 
             ORDER BY cl.valid_from DESC 
             LIMIT 1",
            $company_id
        );

        $lifecycle = $this->wpdb->get_row($query);

        // Si aucun lifecycle n'existe, on initialise le défaut ('subscriber')
        if (!$lifecycle) {
            $this->ensure_default_lifecycle($company_id);
            
            // On relance la requête pour récupérer l'objet complet avec les jointures
            $lifecycle = $this->wpdb->get_row($query);
        }

        return $lifecycle;
    }

    /**
     * Vérifie si une entreprise possède un lifecycle (directement en BDD pour éviter la boucle).
     * Si aucun n'est trouvé, lui assigne le lifecycle par défaut ('subscriber').
     * 
     * @param int      $company_id L'ID de l'entreprise.
     * @param int|null $user_id    L'ID de l'utilisateur WordPress.
     * @return bool True si un lifecycle existe ou a été créé avec succès.
     */
    public function ensure_default_lifecycle($company_id, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id() ? get_current_user_id() : 0;
        }

        $table = $this->table_company_lifecycle;

        // Vérification directe par comptage (évite l'appel récursif à get_company_lifecycle)
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE company_id = %d",
            $company_id
        ));

        if (!$count) {
            return $this->update_company_lifecycle($company_id, 'subscriber', $user_id);
        }

        return true;
    }

    /**
     * Met à jour ou ajoute un nouveau lifecycle pour une entreprise et un département donnés.
     * Clôture automatiquement le lifecycle précédent actif correspondant.
     * 
     * @param int         $company_id         L'ID de l'entreprise.
     * @param string      $new_lifecycle_type La nouvelle clé de phase (ex: 'lead', 'Offre', etc.).
     * @param int         $user_id            L'ID de l'utilisateur WordPress qui effectue l'action.
     * @param string|null $department_id      ID du département concerné (optionnel).
     * @return bool Vrai en cas de succès, faux sinon.
     */
    public function update_company_lifecycle($company_id, $new_lifecycle_type, $department_id = null) {
        $table = $this->table_company_lifecycle;
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        // 1. Préparation des critères de recherche pour clôturer l'ancien lifecycle actif
        $where = [
            'company_id' => $company_id,
            'valid_to'   => null
        ];
        $where_format = ['%d', '%s'];

        // Gestion du département dans la condition de fermeture
        if (!empty($department_id)) {
            $where['department_id'] = $department_id;
            $where_format[] = '%s';
        } else {
            $where['department_id'] = null;
            $where_format[] = '%s';
        }

        // Clôture de l'ancien enregistrement actif
        $this->wpdb->update(
            $table,
            [
                'valid_to'    => $now,
                'modified_by' => $user_id,
                'updated_at'  => $now
            ],
            $where,
            ['%s', '%d', '%s'],
            $where_format
        );

        // 2. Insertion du nouveau lifecycle avec le department_id reçu
        $inserted = $this->wpdb->insert(
            $table,
            [
                'company_id'     => $company_id,
                'lifecycle_type' => $new_lifecycle_type,
                'department_id'  => $department_id,
                'valid_from'     => $now,
                'valid_to'       => null,
                'created_by'     => $user_id,
                'created_at'     => $now
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        // 3. Traçabilité (Logs)
        if ($this->logger && method_exists($this->logger, 'info')) {
            $dept_info = !empty($department_id) ? " (Département ID: {$department_id})" : " (Global)";
            $this->logger->info("Lifecycle mis à jour pour la société ID {$company_id}{$dept_info} : passage à '{$new_lifecycle_type}' par l'utilisateur ID {$user_id}");
        }

        return $inserted !== false;
    }

}

endif;