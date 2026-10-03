<?php
defined('ABSPATH') || exit;
/**
 * Class ISPAG_Crm_Discount_Manager
 * Gère les remises et coefficients de vente pour les entreprises dans le CRM ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_crm_discount_manager.log.
 */
if (!class_exists('ISPAG_Crm_Discount_Manager')) :

class ISPAG_Crm_Discount_Manager
{
    private $logger;
    private $wpdb;
    private $table_company_discount;

    public function __construct()
    {
        // 1. TOUJOURS enregistrer les hooks AJAX tout au début du constructeur
        add_action('wp_ajax_update_company_discount_or_coef', [$this, 'handle_ajax_update_discount_or_coef']);

        // 2. Initialisation des dépendances
        global $wpdb;
        $this->wpdb = $wpdb;
        
        if (class_exists('ISPAG_Logger')) {
            $this->logger = ISPAG_Logger::get_instance();
        }

        // Sécurité si la constante n'est pas définie
        if (class_exists('ISPAG_Crm_Company_Constants') && defined('ISPAG_Crm_Company_Constants::TABLE_COMPANY_DISCOUNT')) {
            $this->table_company_discount = ISPAG_Crm_Company_Constants::TABLE_COMPANY_DISCOUNT;
        } else {
            $this->table_company_discount = $this->wpdb->prefix . 'ispag_company_discounts';
        }

        // 3. Script JS
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'ispag-discount-manager-js',
            plugin_dir_url(__FILE__) . '../assets/js/ispag-discount-manager.js',
            ['jquery'],
            '1.0.1',
            true
        );

        wp_localize_script(
            'ispag-discount-manager-js',
            'ispagDiscountManager',
            [
                'nonce'    => wp_create_nonce('ispag_crm_nonce'),
                'ajax_url' => admin_url('admin-ajax.php'),
            ]
        );
    }


    /**
     * Retourne le tableau des rabais standards (10% et de 42% à 50%).
     *
     * @param bool $formatted Si true, retourne [10 => '10%', 42 => '42%', ...], sinon [10 => 10, 42 => 42, ...]
     * @return array
     */
    public static function get_standard_discounts($formatted = false)
    {
        
        $discounts = [10, 0];
        for ($i = 42; $i <= 50; $i++) {
            $discounts[] = $i;
        }

        $result = [];
        foreach ($discounts as $val) {
            $result[$val] = $formatted ? $val . '%' : $val;
        }

        return $result;
    }

    /**
     * Récupère la liste des coefficients de vente configurés dans les options WordPress (wpcb_sales_coef*).
     *
     * @return array Tableau des coefficients uniques et triés.
     */
    public static function get_sales_coef_options()
    {
        $sales_coef_options = [];
        $all_options = wp_load_alloptions();

        foreach ($all_options as $option_name => $option_value) {
            if (strpos($option_name, 'wpcb_sales_coef') === 0) {
                $key = str_replace('wpcb_sales_coef', '', $option_name);
                $sales_coef_options[$key] = $option_value;
            }
        }

        $sales_coef_options = array_unique($sales_coef_options);
        sort($sales_coef_options);

        return $sales_coef_options;
    }

    /**
     * Récupère l'enregistrement de remise/coefficient valide pour une entreprise.
     *
     * @param int $company_id ID de l'entreprise.
     * @return object|null Objet de la ligne SQL ou null si aucune entrée valide.
     */
    public function get_current_discount_by_company_id($company_id, $type_value = 'coef_vente')
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('crm_discount_manager', 'get_current_discount_by_company_id_start', ['company_id' => $company_id, 'type_value' => $type_value], $user_id);

        $company_id = absint($company_id);
        if (!$company_id)
        {
            $this->logger->log('crm_discount_manager', 'ERROR: Invalid company_id', $user_id, ['company_id' => $company_id]);
            return null;
        }

        $this->logger->log_user_action('crm_discount_manager', 'company_id_validated', ['company_id' => $company_id], $user_id);

        $now = current_time('mysql');
        $this->logger->log_user_action('crm_discount_manager', 'current_time_retrieved', ['now' => $now], $user_id);

        $discount = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_company_discount}
                WHERE company_id = %d
                AND (valid_to IS NULL OR valid_to >= %s)
                AND discount_type = %s
                ORDER BY valid_from DESC, created_at DESC
                LIMIT 1",
                $company_id,
                $now,
                $type_value
            )
        );

        $this->logger->log_db_change('crm_discount_manager', $this->table_company_discount, 'FETCH_CURRENT_DISCOUNT', [
            'company_id' => $company_id,
            'type_value' => $type_value,
            'result' => !empty($discount)
        ], $user_id);

        if ($discount)
        {
            $this->logger->log_user_action('crm_discount_manager', 'discount_record_found', ['discount_id' => $discount->id], $user_id);
        }
        else
        {
            $this->logger->log_user_action('crm_discount_manager', 'no_discount_record_found', [], $user_id);
        }

        return $discount;
    }

    /**
     * Récupère le coefficient de vente / la valeur du rabais pour une entreprise.
     *
     * @param int $company_id ID de l'entreprise.
     * @param float $default Valeur par défaut si aucune entrée n'est trouvée (défaut : 1.00).
     * @return float Le coefficient ou la valeur de la remise.
     */
    public function get_coef_by_company_id($company_id, $default = 1.00)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('crm_discount_manager', 'get_coef_by_company_id_start', ['company_id' => $company_id, 'default' => $default], $user_id);

        $discount_record = $this->get_current_discount_by_company_id($company_id, 'coef_vente');
        $this->logger->log_user_action('crm_discount_manager', 'discount_record_retrieved', ['company_id' => $company_id], $user_id);

        if ($discount_record && isset($discount_record->discount_value))
        {
            $coef = (float) $discount_record->discount_value;
            $this->logger->log_user_action('crm_discount_manager', 'coef_retrieved_from_record', ['coef' => $coef], $user_id);
            return $coef;
        }

        $this->logger->log_user_action('crm_discount_manager', 'using_default_coef', ['default' => $default], $user_id);
        return (float) $default;
    }

    /**
     * Méthode publique réutilisable pour enregistrer ou mettre à jour le rabais / coefficient.
     * 
     * @param int    $company_id ID de l'entreprise
     * @param string $field_name Nom du champ (type de rabais ou coefficient)
     * @param mixed  $new_value  Nouvelle valeur
     * @return bool|WP_Error     True en cas de succès, false ou WP_Error en cas d'échec
     */
    public function save_company_discount_or_coef($company_id, $field_name, $new_value, $department_id = null)
    {
        $user_id = get_current_user_id();

        if (!$company_id || empty($field_name)) {
            $this->logger->log('crm_discount_manager', 'ERROR: Missing company ID or field name', $user_id, [
                'company_id' => $company_id,
                'field_name' => $field_name
            ]);
            return new \WP_Error('missing_parameters', __('Missing company ID or field name.', 'ispag-crm'));
        }

        $now = current_time('mysql');
        $this->logger->log_user_action('crm_discount_manager', 'current_time_retrieved', ['now' => $now], $user_id);

        // Récupérer l'enregistrement actuel
        $current_discount = $this->get_current_discount_by_company_id($company_id, $field_name);
        $current_value = $current_discount ? $current_discount->discount_value : null;

        if ($this->logger) {
            $this->logger->log_user_action('crm_discount_manager', 'current_discount_retrieved', [
                'current_value' => $current_value,
                'discount_id'   => $current_discount ? $current_discount->id : null,
            ], $user_id);
        }

        // Si aucune entrée valide n'existe, en créer une nouvelle
        if (!$current_discount) {
            $this->logger->log_user_action('crm_discount_manager', 'creating_new_discount_entry', [], $user_id);

            $result = $this->wpdb->insert(
                $this->table_company_discount,
                [
                    'company_id'     => $company_id,
                    'discount_type'  => $field_name,
                    'discount_value' => $new_value,
                    'department_id'  => $department_id,
                    'valid_from'     => $now,
                    'valid_to'       => null,
                    'created_by'     => $user_id ? $user_id : get_current_user_id(),
                    'modified_by'    => $user_id ? $user_id : get_current_user_id(),
                ],
                ['%d', '%s', '%f', '%s', '%s', '%d', '%d']
            );

            $this->logger->log_db_change('crm_discount_manager', $this->table_company_discount, 'INSERT_DISCOUNT', [
                'company_id'     => $company_id,
                'discount_type'  => $field_name,
                'discount_value' => $new_value,
                'result'         => $result
            ], $user_id);

            if ($result === false) {
                $this->logger->log('crm_discount_manager', 'ERROR: Failed to create discount entry', $user_id, [
                    'company_id' => $company_id,
                    'wpdb_error' => $this->wpdb->last_error
                ]);
                return false;
            }

            $this->logger->log_user_action('crm_discount_manager', 'discount_entry_created_successfully', ['insert_id' => $this->wpdb->insert_id], $user_id);
            return true;
        }

        // Si l'entrée existe, vérifier si la valeur est différente
        if ($current_discount->$field_name === $new_value) {
            $this->logger->log_user_action('crm_discount_manager', 'no_changes_detected', [], $user_id);
            return true; // Considéré comme un succès (pas de modification nécessaire)
        }

        $this->logger->log_user_action('crm_discount_manager', 'discount_type_change_detected', [
            'old_value' => $current_discount->$field_name,
            'new_value' => $new_value
        ], $user_id);

        // Expirer l'entrée actuelle
        $updated = $this->wpdb->update(
            $this->table_company_discount,
            [
                'valid_to'    => $now,
                'modified_by' => $user_id ? $user_id : get_current_user_id(),
                'updated_at'  => $now,
            ],
            ['id' => $current_discount->id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        $this->logger->log_db_change('crm_discount_manager', $this->table_company_discount, 'EXPIRE_CURRENT_DISCOUNT', [
            'id'       => $current_discount->id,
            'valid_to' => $now,
            'result'   => $updated
        ], $user_id);

        // Insérer la nouvelle entrée
        $result = $this->wpdb->insert(
            $this->table_company_discount,
            [
                'company_id'     => $company_id,
                'discount_type'  => $field_name,
                'discount_value' => $new_value,
                'valid_from'     => $now,
                'valid_to'       => null,
                'created_by'     => $user_id ? $user_id : get_current_user_id(),
                'modified_by'    => $user_id ? $user_id : get_current_user_id(),
            ],
            ['%d', '%s', '%f', '%s', '%s', '%d', '%d']
        );

        $this->logger->log_db_change('crm_discount_manager', $this->table_company_discount, 'INSERT_NEW_DISCOUNT', [
            'company_id'     => $company_id,
            'discount_type'  => $field_name,
            'discount_value' => $new_value,
            'result'         => $result
        ], $user_id);

        if ($result !== false) {
            $this->logger->log_user_action('crm_discount_manager', 'discount_updated_successfully', ['insert_id' => $this->wpdb->insert_id], $user_id);
            return $result;
        } else {
            $this->logger->log('crm_discount_manager', 'ERROR: Failed to update discount/coefficient', $user_id, [
                'wpdb_error' => $this->wpdb->last_error,
                'company_id' => $company_id,
                'field_name' => $field_name,
                'new_value'  => $new_value
            ]);
            return false;
        }
    }

    /**
     * Point d'entrée AJAX WordPress.
     */
    public function handle_ajax_update_discount_or_coef()
    {
        // error_log('*** AJAX ISPAG ATTEINT AVEC SUCCÈS ***');

        $user_id = get_current_user_id();
        $this->logger->log_user_action('crm_discount_manager', 'handle_ajax_update_discount_or_coef_start', [], $user_id);

        check_ajax_referer('ispag_crm_nonce', 'nonce');
        $this->logger->log_user_action('crm_discount_manager', 'nonce_verified', [], $user_id);

        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;
        $field_name = isset($_POST['field_name']) ? sanitize_text_field($_POST['field_name']) : '';
        $new_value  = isset($_POST['new_value']) ? sanitize_text_field($_POST['new_value']) : '';

        $this->logger->log_user_action('crm_discount_manager', 'post_data_retrieved', [
            'company_id' => $company_id,
            'field_name' => $field_name,
            'new_value'  => $new_value
        ], $user_id);

        // Appel de la méthode métier partagée
        $result = $this->save_company_discount_or_coef($company_id, $field_name, $new_value);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } elseif ($result === false) {
            wp_send_json_error(['message' => __('Failed to process discount/coefficient.', 'ispag-crm')]);
        } else {
            wp_send_json_success([
                'message'    => __('Discount/Coefficient saved successfully.', 'ispag-crm'),
                'field_name' => $field_name,
                'new_value'  => $new_value,
            ]);
        }
    }
}

endif;