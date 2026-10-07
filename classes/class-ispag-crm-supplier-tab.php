<?php
defined('ABSPATH') || exit;

/**
 * Onglet « Supplier » de la fiche entreprise : informations d'achat modifiables (devise, TVA, langue, délais, contacts
 * commande / plan / facturation / livraison) et liste des articles du fournisseur avec leur prix d'achat.
 *
 * Les champs propres aux fournisseurs sont stockés dans wor9711_ispag_companies_meta (mêmes clés que le plugin achats,
 * voir ISPAG_Achat_Supplier_Repository::SUPPLIER_META_KEYS). L'onglet n'apparaît que pour les entreprises marquées fournisseur.
 * Droits : voir l'onglet = edit_supplier_order ou manage_suppliers ; modifier l'onglet et marquer une entreprise comme fournisseur = manage_suppliers.
 */
class ISPAG_Crm_Supplier_Tab {

    const NONCE = 'ispag_crm_supplier_tab';

    /** champ => [clé meta, libellé, type] */
    public static function fields() {
        return [
            'currency'       => ['ispag_supplier_currency',      __('Currency', 'ispag-crm'),                'text'],
            'tva'            => ['ispag_supplier_tva',           __('VAT number', 'ispag-crm'),              'text'],
            'lang'           => ['ispag_supplier_lang',          __('Language', 'ispag-crm'),                'language'],
            'delivery_days'  => ['ispag_supplier_delivery_days', __('Delivery time (days)', 'ispag-crm'),    'number'],
            'transport_time' => ['ispag_supplier_transport_time', __('Transport time (days)', 'ispag-crm'),  'number'],
            'prepay'         => ['ispag_supplier_prepay',        __('Payment before delivery', 'ispag-crm'), 'checkbox'],
        ];
    }

    /** Langues proposées pour les e-mails au fournisseur (code de locale => libellé) ; ce code choisit le modèle d'e-mail. */
    public static function language_choices() {
        $langs = class_exists('ISPAG_Achat_Mail_Templates') ? ISPAG_Achat_Mail_Templates::languages()
               : ['en_US' => 'English', 'fr_FR' => 'Français', 'de_DE' => 'Deutsch', 'it_IT' => 'Italiano'];
        return $langs;
    }

    /** Ancienne saisie libre (« fr », « FR », « Français », « Deutsch »…) ramenée à un code de locale connu, sinon telle quelle. */
    public static function normalize_language($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $choices = self::language_choices();
        if (isset($choices[$value])) return $value;
        $v = strtolower($value);
        foreach ($choices as $code => $label) {
            if ($v === strtolower($code) || $v === strtolower(substr($code, 0, 2)) || $v === strtolower($label)) return $code;
        }
        return $value;
    }

    /** rôle => [clé meta, libellé] : chaque valeur est l'ID d'un utilisateur WordPress lié à l'entreprise. */
    public static function contact_roles() {
        return [
            'contact_order'    => ['ispag_supplier_contact_order',    __('Order / quotation contact', 'ispag-crm')],
            'contact_plan'     => ['ispag_supplier_contact_plan',     __('Drawing contact', 'ispag-crm')],
            'contact_billing'  => ['ispag_supplier_contact_billing',  __('Billing contact', 'ispag-crm')],
            'contact_delivery' => ['ispag_supplier_contact_delivery', __('Delivery contact', 'ispag-crm')],
        ];
    }

    public function __construct() {
        add_action('wp_ajax_ispag_crm_save_supplier_field', [$this, 'ajax_save_field']);
        add_action('wp_ajax_ispag_crm_set_supplier', [$this, 'ajax_set_supplier']);
    }

    /** Voir l'onglet Fournisseur. */
    public static function can_access() {
        return current_user_can('edit_supplier_order') || self::can_manage();
    }

    /** Marquer une entreprise comme fournisseur et modifier son onglet Fournisseur. */
    public static function can_manage() {
        return current_user_can('manage_suppliers');
    }

    private static function meta_table() {
        return ISPAG_Crm_Company_Constants::TABLE_COMPANY_META;
    }

    public static function get_meta($company_id, $meta_key) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM ' . self::meta_table() . ' WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
            (int) $company_id, $meta_key
        ));
    }

    public static function set_meta($company_id, $meta_key, $value) {
        global $wpdb;
        $wpdb->delete(self::meta_table(), ['company_id' => (int) $company_id, 'meta_key' => $meta_key], ['%d', '%s']);
        if ($value === '' || $value === null) {
            return true;
        }
        return $wpdb->insert(self::meta_table(), ['company_id' => (int) $company_id, 'meta_key' => $meta_key, 'meta_value' => (string) $value], ['%d', '%s', '%s']) !== false;
    }

    // ------------------------------------------------------------------ Affichage

    /**
     * Interrupteur « Fournisseur » de la fiche entreprise : modifiable avec le droit manage_suppliers, sinon simple indication si l'entreprise est fournisseur.
     * À l'enregistrement la page est rechargée (l'onglet Fournisseur apparaît ou disparaît).
     */
    public static function supplier_switch($company) {
        $is = self::is_supplier($company);
        if (!self::can_manage()) {
            return $is ? '<p class="ispag-supplier-flag"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__('Supplier', 'ispag-crm') . '</p>' : '';
        }
        $nonce = wp_create_nonce(self::NONCE);
        ob_start();
        ?>
        <p class="ispag-supplier-flag">
            <label style="cursor:pointer;">
                <input type="checkbox" id="ispag-supplier-switch" data-company="<?php echo (int) $company->Id; ?>" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($is); ?>>
                <?php esc_html_e('This company is a supplier', 'ispag-crm'); ?>
            </label>
            <span id="ispag-supplier-switch-msg" class="ispag-supplier-msg" aria-live="polite"></span>
        </p>
        <script>
        (function () {
            var box = document.getElementById('ispag-supplier-switch');
            if (!box) return;
            box.addEventListener('change', function () {
                var msg = document.getElementById('ispag-supplier-switch-msg');
                var body = new URLSearchParams({ action: 'ispag_crm_set_supplier', nonce: box.dataset.nonce, company_id: box.dataset.company, value: box.checked ? '1' : '0' });
                box.disabled = true;
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error((res.data && res.data.message) || 'Error');
                        window.location.reload();
                    })
                    .catch(function (err) { box.disabled = false; box.checked = !box.checked; msg.textContent = err.message; msg.classList.add('is-error'); });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /** Bouton d'onglet (à placer dans .ispag-tabs-navigation) ; vide si l'entreprise n'est pas fournisseur. */
    public static function tab_button($company) {
        if (!self::is_supplier($company) || !self::can_access()) {
            return '';
        }
        return '<button class="ispag-tab-btn" data-tab="supplier">' . esc_html__('Supplier', 'ispag-crm') . '</button>';
    }

    /** Contenu de l'onglet (à placer dans .ispag-tabs-content) ; vide si l'entreprise n'est pas fournisseur. */
    public static function tab_pane($company) {
        if (!self::is_supplier($company) || !self::can_access()) {
            return '';
        }
        $company_id = (int) $company->Id;
        $users = get_users([
            'meta_key'   => ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
            'meta_value' => $company_id,
            'fields'     => ['ID', 'display_name', 'user_email'],
            'orderby'    => 'display_name',
        ]);
        $articles = class_exists('ISPAG_Standard_Article_Service') ? ISPAG_Standard_Article_Service::articles_of_supplier($company_id) : null;
        $types    = class_exists('ISPAG_Standard_Article_Service') ? ISPAG_Standard_Article_Service::type_names() : [];
        $nonce    = wp_create_nonce(self::NONCE);
        $ro       = !self::can_manage();   // sans manage_suppliers : consultation seule

        ob_start();
        ?>
        <div id="ispag-tab-supplier" class="ispag-tab-pane" data-supplier-company="<?php echo $company_id; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="ispag-card">
                <h5><?php esc_html_e('Purchasing information', 'ispag-crm'); ?> <span class="ispag-supplier-msg" aria-live="polite"></span></h5>
                <?php if ($ro): ?><p class="description"><?php esc_html_e('Read only: you need the right to manage suppliers to edit this tab.', 'ispag-crm'); ?></p><?php endif; ?>
                <div class="ispag-supplier-grid">
                    <?php foreach (self::fields() as $key => $def): ?>
                        <?php if ($def[2] === 'language'):
                            $lang_now = self::normalize_language(self::get_meta($company_id, $def[0]));
                            $choices  = self::language_choices();
                            if ($lang_now !== '' && !isset($choices[$lang_now])) $choices[$lang_now] = $lang_now; // valeur ancienne non reconnue : conservée ?>
                        <label><span><?php echo esc_html($def[1]); ?></span>
                            <select class="ispag-supplier-field" <?php disabled($ro); ?> data-field="<?php echo esc_attr($key); ?>">
                                <option value="">— <?php echo esc_html(sprintf(__('Default (%s)', 'ispag-crm'), $choices['fr_FR'] ?? 'Français')); ?></option>
                                <?php foreach ($choices as $code => $label): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($lang_now, $code); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="description" style="display:block;color:#6b7480;"><?php esc_html_e('Language of the e-mails sent to this supplier (order, quotation…).', 'ispag-crm'); ?></small></label>
                        <?php continue; endif; ?>
                        <?php if ($def[2] === 'checkbox'): ?>
                        <label style="flex-direction:row;align-items:center;gap:.5rem;"><input type="checkbox" class="ispag-supplier-field" <?php disabled($ro); ?> data-field="<?php echo esc_attr($key); ?>" <?php checked(self::get_meta($company_id, $def[0]) === '1'); ?>>
                            <span><?php echo esc_html($def[1]); ?></span>
                            <small class="description" style="color:#6b7480;"><?php esc_html_e('This supplier must be paid before it delivers: its orders get a payment follow-up.', 'ispag-crm'); ?></small></label>
                        <?php continue; endif; ?>
                        <label><span><?php echo esc_html($def[1]); ?></span>
                            <input type="<?php echo esc_attr($def[2]); ?>" <?php echo $def[2] === 'number' ? 'min="0" step="1"' : ''; ?>
                                   class="ispag-supplier-field" <?php disabled($ro); ?> data-field="<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr(self::get_meta($company_id, $def[0])); ?>"></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ispag-card">
                <h5><?php esc_html_e('Contacts', 'ispag-crm'); ?></h5>
                <?php if (!$users): ?>
                    <p><?php esc_html_e('No contact is linked to this company yet.', 'ispag-crm'); ?></p>
                <?php endif; ?>
                <div class="ispag-supplier-grid">
                    <?php foreach (self::contact_roles() as $key => $def):
                        $current = (int) self::get_meta($company_id, $def[0]); ?>
                        <label><span><?php echo esc_html($def[1]); ?></span>
                            <select class="ispag-supplier-field" <?php disabled($ro); ?> data-field="<?php echo esc_attr($key); ?>">
                                <option value="0">—</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo (int) $u->ID; ?>" <?php selected($current, (int) $u->ID); ?>><?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ispag-card">
                <h5><?php esc_html_e('Articles supplied', 'ispag-crm'); ?><?php echo is_array($articles) ? ' (' . count($articles) . ')' : ''; ?></h5>
                <?php if ($articles === null): ?>
                    <p><?php esc_html_e('The article catalogue is not available (Project Manager plugin inactive).', 'ispag-crm'); ?></p>
                <?php elseif (!$articles): ?>
                    <p><?php esc_html_e('No article is linked to this supplier yet. Add it from an article (Purchasing tab).', 'ispag-crm'); ?></p>
                <?php else: ?>
                    <table class="ispag-project-table" style="width:100%;">
                        <thead><tr>
                            <th><?php esc_html_e('Type', 'ispag-crm'); ?></th>
                            <th><?php esc_html_e('Article', 'ispag-crm'); ?></th>
                            <th><?php esc_html_e('Supplier reference', 'ispag-crm'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Purchase price', 'ispag-crm'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Discount', 'ispag-crm'); ?> %</th>
                            <th><?php esc_html_e('Delivery time (days)', 'ispag-crm'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($articles as $a): ?>
                            <tr>
                                <td><?php echo esc_html($types[(int) $a->TypeArticle] ?? '—'); ?></td>
                                <td><a href="<?php echo esc_url(ISPAG_Standard_Article_Service::article_url($a->article_id) . '#purchase'); ?>"><?php echo esc_html($a->TitreArticle); ?></a>
                                    <?php echo $a->ref_article_ispag ? '<small>(' . esc_html($a->ref_article_ispag) . ')</small>' : ''; ?></td>
                                <td><?php echo esc_html($a->supplier_reference); ?></td>
                                <td style="text-align:right;"><?php echo esc_html(number_format((float) $a->purchase_price, 2, '.', "'") . ' ' . $a->currency); ?></td>
                                <td style="text-align:right;"><?php echo esc_html(rtrim(rtrim(number_format((float) $a->discount, 2, '.', ''), '0'), '.') ?: '0'); ?></td>
                                <td><?php echo (int) $a->delivery_days; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .ispag-supplier-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem 1rem}
            .ispag-supplier-grid label{display:flex;flex-direction:column;gap:.2rem;font-size:.85rem;color:#6b7280}
            .ispag-supplier-grid input,.ispag-supplier-grid select{font-size:1rem;color:#111}
            .ispag-supplier-field.is-saved{box-shadow:0 0 0 2px #46b450}
            .ispag-supplier-field.is-error{box-shadow:0 0 0 2px #dc3232}
            .ispag-supplier-msg{font-size:.8rem;font-weight:400;color:#1b5e20}
            .ispag-supplier-msg.is-error{color:#b32d2e}
        </style>
        <script>
        (function () {
            var pane = document.getElementById('ispag-tab-supplier');
            if (!pane) return;
            pane.addEventListener('change', function (e) {
                var f = e.target.closest('.ispag-supplier-field');
                if (!f) return;
                var msg = pane.querySelector('.ispag-supplier-msg');
                var body = new URLSearchParams({ action: 'ispag_crm_save_supplier_field', nonce: pane.dataset.nonce,
                    company_id: pane.dataset.supplierCompany, field: f.dataset.field, value: f.type === 'checkbox' ? (f.checked ? '1' : '') : f.value });
                f.classList.remove('is-saved', 'is-error');
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error((res.data && res.data.message) || 'Error');
                        f.classList.add('is-saved'); msg.classList.remove('is-error'); msg.textContent = <?php echo wp_json_encode(__('Saved', 'ispag-crm')); ?>;
                        setTimeout(function () { msg.textContent = ''; }, 2000);
                    })
                    .catch(function (err) { f.classList.add('is-error'); msg.classList.add('is-error'); msg.textContent = err.message; });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function is_supplier($company) {
        return $company && !empty($company->isSupplier);
    }

    // ------------------------------------------------------------------ AJAX

    public function ajax_save_field() {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'ispag-crm')], 403);
        }
        if (!self::can_manage()) {
            wp_send_json_error(['message' => __('Unauthorized', 'ispag-crm')], 403);
        }
        global $wpdb;
        $company_id = absint($_POST['company_id'] ?? 0);
        $field      = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        $raw        = wp_unslash($_POST['value'] ?? '');

        $exists = $company_id ? $wpdb->get_var($wpdb->prepare('SELECT isSupplier FROM ' . ISPAG_Crm_Company_Constants::TABLE_NAME . ' WHERE Id = %d', $company_id)) : null;
        if ($exists === null) {
            wp_send_json_error(['message' => __('Company not found.', 'ispag-crm')]);
        }

        $fields = self::fields();
        $roles  = self::contact_roles();
        if (isset($fields[$field])) {
            $meta_key = $fields[$field][0];
            $value    = $fields[$field][2] === 'number' ? (string) absint($raw) : ($fields[$field][2] === 'checkbox' ? ($raw === '1' ? '1' : '') : sanitize_text_field($raw));
            if ($fields[$field][2] === 'language') { $value = self::normalize_language($value); }
            if ($value === '0') { $value = ''; }
        } elseif (isset($roles[$field])) {
            $meta_key = $roles[$field][0];
            $user_id  = absint($raw);
            // Le contact doit être rattaché à cette entreprise
            if ($user_id) {
                $linked = in_array($company_id, array_map('intval', get_user_meta($user_id, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, false)), true);
                if (!$linked) {
                    wp_send_json_error(['message' => __('This contact does not belong to this company.', 'ispag-crm')]);
                }
            }
            $value = $user_id ? (string) $user_id : '';
        } else {
            wp_send_json_error(['message' => __('Invalid data', 'ispag-crm')]);
        }

        self::set_meta($company_id, $meta_key, $value) ? wp_send_json_success(['value' => $value]) : wp_send_json_error(['message' => __('Database update failed or no changes made.', 'ispag-crm')]);
    }

    /** Marque ou démarque une entreprise comme fournisseur (droit manage_suppliers). */
    public function ajax_set_supplier() {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'ispag-crm')], 403);
        }
        if (!self::can_manage()) {
            wp_send_json_error(['message' => __('Unauthorized', 'ispag-crm')], 403);
        }
        global $wpdb;
        $company_id = absint($_POST['company_id'] ?? 0);
        $value      = !empty($_POST['value']) && $_POST['value'] !== '0' ? 1 : 0;
        $table      = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $current    = $company_id ? $wpdb->get_var($wpdb->prepare("SELECT isSupplier FROM {$table} WHERE Id = %d", $company_id)) : null;
        if ($current === null) {
            wp_send_json_error(['message' => __('Company not found.', 'ispag-crm')]);
        }
        if ((int) $current !== $value && $wpdb->update($table, ['isSupplier' => $value], ['Id' => $company_id], ['%d'], ['%d']) === false) {
            wp_send_json_error(['message' => __('Database update failed or no changes made.', 'ispag-crm')]);
        }
        wp_send_json_success(['isSupplier' => $value]);
    }
}
