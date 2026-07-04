<?php

/**
 * Gère l'affichage du détail d'une entreprise via un shortcode, 
 * en reprenant la structure de l'affichage de contact.
 */
class ISPAG_Company_Detail_Shortcode {

    // Définitions des méta-clés de l'entreprise (à adapter si vous utilisez des métas)
    // const META_OWNER_ID = 'ispag_company_owner';
    // const META_TYPE     = 'ispag_company_type';
    const META_TIER                 = 'ispag_company_tier';
    const META_COMPANY_ID           = 'ispag_company_id';
    const META_COMPANY_LEAD_STATUS  = 'ispag_company_lead_status';
    const META_COMPANY_OWNER        = 'ispag_company_owner';
    const META_COMPANY_TYPE         = 'ispag_company_type';
    const META_LAST_CONTACT_DATE    = 'ispag_last_contact_date';
    const META_COMPANY_CITY         = 'ispag_company_city';
    const META_COMPANY_ADRESS       = 'ispag_company_adress';
    const META_COMPANY_POSTAL_CODE  = 'ispag_company_postal_code';
    const META_COMPANY_REGION       = 'ispag_company_region';
    const META_COMPANY_COUNTRY      = 'ispag_company_country';
    const META_COMPANY_INDUSTRY     = 'ispag_company_industry';
    const META_COMPANY_PHONE        = 'ispag_company_phone';
    const META_HEALTH_CHECK_IGNORE  = 'ispag_ignore_health_check';

    const META_LEAD_FUNCTION        = 'ispag_lead_function';


    private static $log_file = WP_CONTENT_DIR . '/ispag_company_details_shortcode.log';

    public function __construct() {
        // Enregistrement du shortcode principal
        add_shortcode( 'ispag_company_detail', array( $this, 'render_company_detail_shortcode' ) );
        
        // Enqueue les scripts et styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ispag_assets' ) );

        // Gestion des contacts associés
        // add_action( 'wp_ajax_ispag_render_add_contact_modal', array( $this, 'ajax_render_add_contact_modal' ) );
        // add_action( 'wp_ajax_ispag_search_contacts', array( $this, 'ajax_search_contacts' ) );
        add_action( 'wp_ajax_ispag_associate_contacts', array( $this, 'ajax_associate_contacts' ) );

        // add_action( 'wp_ajax_save_company_field', array( $this, 'handle_ajax_save_company_field' ) );


        
    }

    // -------------------------------------------------------------------------
    // --- Configuration & Enqueue ---
    // -------------------------------------------------------------------------

    /**
     * Enqueue les styles et scripts nécessaires, incluant le script d'édition en ligne.
     */
    public function enqueue_ispag_assets() {
        
        $post = get_post( get_the_ID() );
        
        if ( ! $post ) {
            return;
        }
        
        // Vérification de la présence des shortcodes sur la page
        $contact_detail_needed = has_shortcode( $post->post_content, 'ispag_company_detail' );
        $contact_list_needed = has_shortcode( $post->post_content, 'ispag_company_detail' );

        if ( ! $contact_detail_needed && ! $contact_list_needed ) {
            return;
        }
        
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        // -----------------------------------------------------------
        // --- 1. CSS ---
        // -----------------------------------------------------------
        if ($contact_list_needed) {
            wp_enqueue_style( 'ispag-crm-styles', $plugin_url . 'assets/css/ispag-crm-styles.css', array(), '1.0.0' );
        }

        if ($contact_detail_needed) {
            wp_enqueue_style( 'ispag-contact-detail-styles', $plugin_url . 'assets/css/ispag-contact-detail-styles.css', array(), '1.0.0' );
        }
        
        // -----------------------------------------------------------
        // --- 2. JAVASCRIPT ---
        // -----------------------------------------------------------
        
        // JS pour la liste de contacts 
        if ($contact_list_needed) {
            wp_enqueue_script( 'ispag-crm-bulk-edit-js', $plugin_url . 'assets/js/ispag-bulk-edit.js', array( 'jquery' ), '1.0.0', true );
        }
        
        // JS pour la page de détail et l'édition en ligne
        if ($contact_detail_needed) {
            wp_enqueue_script( 
                'ispag-contact-detail-edit-js', 
                $plugin_url . 'assets/js/ispag-contact-detail-edit.js', 
                array( 'jquery' ), 
                '1.1.0', 
                true 
            );
            
            // // Passage de la variable AJAX pour que le JS sache où envoyer les requêtes
            // wp_localize_script( 
            //     'ispag-contact-detail-edit-js', 
            //     'ispag_ajax', 
            //     array( 
            //         'ajax_url' => admin_url( 'admin-ajax.php' ),
            //         // L'action 'ispag_nonce' DOIT correspondre à l'action utilisée dans check_ajax_referer()
            //         'nonce'    => wp_create_nonce( 'ispag_crm_nonce' ) 
            //     )
            // );
        }
    }



    // -------------------------------------------------------------------------
    // --- Fonctions AJAX ---
    // -------------------------------------------------------------------------

    /**
     * Helper pour écrire des messages dans un fichier de log dédié.
     * Le fichier sera créé dans wp-content/ispag_debug.log
     *
     * @param string $message Le message à enregistrer.
     * @param string $prefix Le préfixe du log (par défaut 'ISPAG-AJAX').
     */
    private static function log_message( $message, $prefix = 'ISPAG-AJAX' ) {
        // Détermine le chemin de base (utilise WP_CONTENT_DIR ou tente une alternative)
        $log_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

        $log_file = trailingslashit( $log_dir ) . 'ispag_debug.log';
        $timestamp = date( 'Y-m-d H:i:s' );
        $content = "[{$timestamp}] [{$prefix}] " . $message . "\n";
        
        // Utilise @ pour masquer les erreurs PHP si le fichier n'est pas inscriptible, 
        // et ajoute le contenu à la fin du fichier.
        @file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX );
    }

    /**
     * Fonction d'aide sécurisée pour lire un champ de la table Fournisseur 
     * (utilisée pour vérifier si la valeur a réellement changé).
     */
    private function get_company_field_value( $company_id, $field_name ) {
        global $wpdb;
        $table_name_fournisseur = $wpdb->prefix . 'achats_fournisseurs';

        // NOTE SUR LA SÉCURITÉ : Le nom de colonne ($field_name) est inséré directement 
        // dans la requête car il a été nettoyé par sanitize_key() avant l'appel.
        $sql = $wpdb->prepare( 
            "SELECT {$field_name} FROM {$table_name_fournisseur} WHERE Id = %d", 
            $company_id 
        );

        self::log_message("READ QUERY: " . $sql);

        return $wpdb->get_var( $sql );
    }

    // /**
    //  * Gère la sauvegarde AJAX des champs éditables de la page de détail d'une entreprise.
    //  * (Avec logs vers wp-content/ispag_debug.log)
    //  */
    // public function handle_ajax_save_company_field() {

    //     global $wpdb;
    //     $table_name_fournisseur = $wpdb->prefix . 'achats_fournisseurs';

    //     self::log_message('Démarrage de la fonction de sauvegarde.');

    //     // 2. Récupération et Nettoyage des données
    //     $company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
    //     $field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( $_POST['field_name'] ) : '';
    //     $new_value 	= isset( $_POST['new_value'] ) ? sanitize_text_field( wp_unslash( $_POST['new_value'] ) ) : '';

    //     if ( $company_id === 0 || empty( $field_name ) ) {
    //         self::log_message('ERROR: ID manquant ou nom de champ vide.', 'ERROR');
    //         wp_send_json_error( array( 'message' => __( 'Missing ID or field name.', 'ispag-crm' ) ) );
    //         wp_die();
    //     }
    //     self::log_message("RAW Champ=".$_POST['field_name'].", Nouvelle valeur=".$_POST['new_value']);
    //     self::log_message("ID={$company_id}, Champ={$field_name}, Nouvelle valeur={$new_value}");

    //     $updated_successfully = false;
    //     $db_value_to_return = $new_value; 

    //     // --- LOGIQUE DE MISE À JOUR ---

    //     // A. Cas des champs de la table principale (wp_achats_fournisseurs)
    //     if ( in_array( $field_name, array( 'Fournisseur', 'Email', 'viag_id' ) ) ) {
            
    //         $db_value = ( $field_name === 'Email' ) ? sanitize_email( $new_value ) : $new_value;
    //         $db_value_to_return = $db_value; 

    //         // Mise à jour de la table personnalisée
    //         $updated = $wpdb->update( 
    //             $table_name_fournisseur, 
    //             array( $field_name => $db_value ), 
    //             array( 'Id' => $company_id ),      
    //             array( '%s' ),                     
    //             array( '%d' )                      
    //         );
            
    //         // 🚨 LOG CRITIQUE 🚨
    //         self::log_message("TABLE UPDATE. Requête: " . $wpdb->last_query);
    //         self::log_message("TABLE UPDATE. Résultat wpdb->update: " . (string)$updated);

    //         // Si $updated est false, c'est une erreur SQL réelle
    //         if ( $updated === false ) {
    //             self::log_message("ERROR: Échec SQL pour {$field_name} (Erreur WPDB: {$wpdb->last_error})", 'ERROR');
    //             wp_send_json_error( array( 'message' => __( 'Failed to update company table in database.', 'ispag-crm' ) ) );
    //             wp_die();
    //         } 
            
    //         // Si $updated est 0, on vérifie si la valeur était déjà la même
    //         $current_value = $this->get_company_field_value( $company_id, $field_name );
            
    //         if ( $updated === 0 && $current_value == $db_value ) {
    //             self::log_message("SUCCESS (TABLE): Valeur inchangée mais correcte.");
    //             $updated_successfully = true;
    //         } elseif ( $updated > 0 ) {
    //             self::log_message("SUCCESS (TABLE): Mise à jour de {$updated} ligne(s).");
    //             $updated_successfully = true;
    //         }

    //     } 
    //     // B. Cas des champs de méta-données (wp_postmeta)
    //     else {
            
    //         if ( $field_name === self::META_COMPANY_OWNER ) {
    //             $db_value_to_return = empty( $new_value ) ? '' : absint( $new_value );
    //         } 

    //         $updated = update_post_meta( $company_id, $field_name, $db_value_to_return );
            
    //         self::log_message("META UPDATE. Clé={$field_name}. Résultat de update_post_meta: " . (string)$updated);

    //         if ( $updated === false && get_post_meta( $company_id, $field_name, true ) == $db_value_to_return ) {
    //             self::log_message("SUCCESS (META): Valeur inchangée mais correcte.");
    //             $updated_successfully = true;
    //         } elseif ( $updated !== false ) {
    //             self::log_message("SUCCESS (META): Mise à jour réussie.");
    //             $updated_successfully = true;
    //         }
    //     }


    //     // --- 4. RETOUR ET GESTION DU SUCCÈS ---

    //     if ( $updated_successfully ) {
    //         $display_html = $this->get_display_html( $field_name, $db_value_to_return );

    //         self::log_message("FIN DE SAUVEGARDE: Succès renvoyé à AJAX.");
    //         wp_send_json_success( array(
    //             'display_value' => $display_html,
    //             'new_value' 	=> $db_value_to_return, 
    //         ) );
    //     } else {
    //         self::log_message("ERROR: Échec de la mise à jour non géré pour {$field_name}.", 'ERROR');
    //         wp_send_json_error( array( 'message' => __( 'Update failed for unknown reason.', 'ispag-crm' ) ) );
    //     }

    //     wp_die();
    // }
    // -------------------------------------------------------------------------
    // --- Fonctions Placeholders pour la cohérence des données ---
    // -------------------------------------------------------------------------

    /**
     * Tente de trouver l'URL du favicon d'un domaine donné.
     *
     * @param string $domain Le domaine cible (ex: "ispag.ch").
     * @param int $timeout Le délai d'expiration pour les requêtes cURL.
     * @return string|false L'URL du favicon ou false si non trouvé.
     */
    function get_favicon_url($domain, $timeout = 5) {
        // 1. Nettoyage et normalisation du domaine
        $domain = trim($domain);
        $domain = preg_replace('/^https?:\/\//', '', $domain); // Supprimer http/https
        $domain = rtrim($domain, '/'); // Supprimer le slash final

        if (empty($domain)) {
            return false;
        }
        
        // Définition de l'URL de base pour l'analyse
        $base_url = 'https://' . $domain; 
        
        // --- Fonction utilitaire pour effectuer les requêtes cURL ---
        $make_request = function($url) use ($timeout) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // À désactiver en production si possible
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (FaviconFinder)');
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            return [
                'code' => $http_code,
                'content_type' => $content_type,
                'body' => $response
            ];
        };

        // -----------------------------------------------------------------
        // ÉTAPE 1 : Vérification des emplacements standards (Convention)
        // -----------------------------------------------------------------

        $standard_paths = [
            $base_url . '/favicon.ico',
            $base_url . '/apple-touch-icon.png',
            $base_url . '/apple-touch-icon-precomposed.png',
        ];

        foreach ($standard_paths as $path) {
            $response = $make_request($path);

            // Si la requête est un succès (200) et qu'il s'agit d'une image
            if ($response['code'] === 200 && str_contains($response['content_type'], 'image')) {
                return $path;
            }
        }

        // -----------------------------------------------------------------
        // ÉTAPE 2 : Analyse de la page d'accueil (Méthode robuste)
        // -----------------------------------------------------------------
        
        // Récupérer le code HTML de la page d'accueil
        $html_response = $make_request($base_url);

        if ($html_response['code'] === 200) {
            // Utiliser une expression régulière pour trouver les liens de favicon dans le <head>
            // Recherche des rel="icon", rel="shortcut icon", rel="apple-touch-icon"
            $pattern = '/<link[^>]+rel=["\'](icon|shortcut icon|apple-touch-icon)["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i';
            
            if (preg_match_all($pattern, $html_response['body'], $matches)) {
                // $matches[2] contient tous les chemins href trouvés
                $potential_paths = array_unique($matches[2]);

                // Tenter de valider le chemin trouvé (en privilégiant les chemins absolus ou en testant l'accès)
                foreach ($potential_paths as $path) {
                    // Si le chemin est absolu (commence par http/https)
                    if (preg_match('/^https?:\/\//i', $path)) {
                        $favicon_url = $path;
                    } 
                    // Si le chemin est relatif (commence par / ou non)
                    else {
                        $favicon_url = $base_url . '/' . ltrim($path, '/');
                    }

                    // Vérification finale si le lien est valide (facultatif mais recommandé)
                    $check = $make_request($favicon_url);
                    if ($check['code'] === 200 && str_contains($check['content_type'], 'image')) {
                        return $favicon_url;
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // ÉTAPE 3 : Solution de secours (Service tiers)
        // -----------------------------------------------------------------
        
        // Utilisation du service Google S2 pour la fiabilité maximale
        // Attention: ceci dépend d'un service externe et peut changer
        $fallback_url = 'https://t0.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=1&url=' . urlencode($base_url) . '&size=64';
        
        // Tenter de valider le fallback (pour éviter une URL S2 cassée)
        $fallback_check = $make_request($fallback_url);
        if ($fallback_check['code'] === 200 && str_contains($fallback_check['content_type'], 'image')) {
            return $fallback_url;
        }
        
        // Aucune icône trouvée
        return false;
    }


    /**
     * Formate un tableau clé => valeur/objet en chaîne pour l'attribut data-options.
     * Gère les tableaux de chaînes simples (Company Type, Owner) et les tableaux d'objets (Lead Status).
     * * @param array $data Map clé => valeur (string) ou objet.
     * @param string $label_property Le nom de la propriété contenant le label à afficher (si $data contient des objets).
     * @return string Chaîne au format "key1:Label 1;key2:Label 2"
     */
    private function format_options_for_data_attr( $data, $label_property ) {
        $options = [];
        foreach ( $data as $key => $value_or_object ) {
            $label = '';

            // Cas 1 : La valeur est un simple string (comme dans get_company_type_options)
            if ( is_scalar( $value_or_object ) ) {
                $label = $value_or_object;
            } 
            // Cas 2 : La valeur est un objet et la propriété demandée existe (comme dans get_company_lead_status_options)
            elseif ( is_object( $value_or_object ) && isset( $value_or_object->{$label_property} ) ) {
                $label = $value_or_object->{$label_property};
            }
            
            if ( $label ) {
                // Utilise sanitize_title pour la clé et esc_html pour le label
                $options[] = sanitize_title($key) . ':' . esc_html( $label );
            }
        }
        return implode( ';', $options );
    }

    private function get_display_html( $field_name, $value ) {
        $default_style = 'background-color: #cccccc; color: #333333;';
        $display_label = esc_html($value) ?: 'Non défini';

        switch ($field_name) {
            case self::META_COMPANY_TYPE:
                $options_map = self::get_company_type_options(); // ['prospect' => 'Prospect', ...]
                $display_label = $options_map[$value] ?? 'Non défini';
                // On peut définir une couleur spécifique si on le souhaite
                break;
            case self::META_COMPANY_OWNER:
                if ( ! empty($value) ) {
                    $user = get_user_by( 'id', $value );

                    if ( $user && ! is_wp_error($user) ) {
                        $display_label = esc_html($user->display_name);
                    } else {
                        $display_label = 'Unknown user';
                    }
                } else {
                    $display_label = 'Not assigned';
                }
                break;
            
            case self::META_COMPANY_LEAD_STATUS:
                // Ici, on utilise la logique qui gère les objets pour récupérer les couleurs (bg_color, text_color)
                $status_obj = $this->get_company_lead_status_options()[$value] ?? null;
                if ($status_obj) {
                    $display_label = esc_html($status_obj->label);
                    $default_style = 'background-color: ' . esc_attr($status_obj->bg_color) . '; color: ' . esc_attr($status_obj->text_color) . ';';
                }
                break;
            
            // ... autres champs
        }
        
        // Ajout du badge et de l'icône d'édition
        $html = '<span class="ispag-status-badge" style="' . $default_style . '">' . $display_label . '</span>';
        $html .= ' <span class="edit-icon">✏️</span>';

        return $html;
    }

    /**
     * Récupère la map complète des statuts de lead.
     * @return array Map (status_key => status_object)
     */
    // --- 1. LEAD STATUS (Identique aux contacts) ---
    private function get_company_lead_status_options() {
        global $wpdb;
        $return = array();
        
        $table_name = ISPAG_Crm_Company_Constants::LEAD_STATUS_TABLE_NAME; 

        $full_statuses = $wpdb->get_results( 
            "SELECT status_key, status_label AS label, bg_color, text_color, status_order 
             FROM {$table_name} 
             ORDER BY status_order ASC, status_label ASC" 
        );
        
        if ( ! empty( $full_statuses ) ) {
            foreach ( $full_statuses as $status ) {
                if ( ! empty( $status->status_key ) ) {
                    $return[ $status->status_key ] = $status;
                }
            }
        }

        return $return;
    }

    // --- 2. COMPANY TYPE ---
    public static function get_company_type_options() {
        return [
            'prospect'  => 'Prospect',
            'customer'  => 'Customer',
            'reseller'  => 'Reseeler',
            'engineer'  => 'Engineer',
            'vendor'    => 'Vendor'
        ];
    }

    // --- 3. COMPANY OWNER (Contacts ISPAG) ---

    public static function get_ispag_owners_options() {
        // Récupère les utilisateurs qui peuvent être des owners (ex: admins, editors, custom role)
        $owners = get_users( array(
            'role__in' => array('administrator', 'vente_ispag', 'ispag_commercial'), // Adaptez les rôles selon votre besoin
            'fields'   => array( 'ID', 'display_name' )
        ) );

        $options = ['' => 'Not assigned']; // Option par défaut
        foreach ($owners as $owner) {
            // La clé est l'ID de l'utilisateur, la valeur est son nom affiché
            $options[$owner->ID] = $owner->display_name;
        }
        return $options;
    }

    /**
     * Placeholder pour récupérer les propriétaires (comme dans la classe contact).
     * @return array Tableau des utilisateurs (ID => user object).
     */
    protected function get_all_owners() {
        // Logique réelle ici, mais pour l'affichage initial, on simule l'existence
        $owners = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
        $owners_lookup = [];
        foreach ($owners as $owner) {
            $owners_lookup[$owner->ID] = $owner;
        }
        return $owners_lookup;
    }

    // Les méthodes correspondantes :

    public function ajax_render_add_contact_modal() {
        // Vérification de sécurité (Nonce, permissions)
        // ...

        $company_id = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );
        
        if ( $company_id === 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing company ID.', 'ispag-crm' ) ) );
        }

        echo $this->render_add_contact_modal( $company_id );
        wp_die();
    }

    public function ajax_search_contacts() {
        // Vérification de sécurité (Nonce, permissions)
        // ...

        $search_term = sanitize_text_field( filter_input( INPUT_POST, 'search_term' ) );
        $company_id  = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );

        // Utilisation de WP_User_Query pour rechercher des utilisateurs
        $args = array(
            'search'         => '*' . $search_term . '*', // Permet la recherche partielle
            'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
            'number'         => 10, // Limite les résultats
            'exclude'        => get_users( [ // Exclut les contacts déjà associés
                'meta_key'   => self::META_COMPANY_ID,
                'meta_value' => $company_id,
                'fields'     => 'ID'
            ] ),
        );

        $user_query = new WP_User_Query( $args );
        
        // Renvoyer le HTML ou JSON des contacts trouvés (similaire à l'image jointe)
        $results = [];
        foreach ( $user_query->get_results() as $user ) {
            $results[] = [
                'ID'           => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ];
        }
        
        wp_send_json_success( array( 
            'count'   => $user_query->total_users, 
            'contacts' => $results 
        ) );
        wp_die();
    }

    public function ajax_associate_contacts() {
        // Vérification de sécurité (Nonce, permissions)
        // ...

        $company_id   = absint( filter_input( INPUT_POST, 'company_id', FILTER_VALIDATE_INT ) );
        $contact_ids  = array_map( 'absint', (array) filter_input( INPUT_POST, 'contact_ids', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) );

        if ( $company_id === 0 || empty( $contact_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing data.', 'ispag-crm' ) ) );
        }
        
        $updated_count = 0;
        foreach ( $contact_ids as $contact_id ) {
            // La clé utilisée est la constante META_COMPANY_ID de votre classe
            // update_user_meta( $contact_id, self::META_COMPANY_ID, $company_id );
            $updated_count++;
        }

        wp_send_json_success( array( 
            'message' => sprintf( __( '%d contacts successfully linked to the company.', 'ispag-crm' ), $updated_count ) 
        ) );
        wp_die();
    }

    /**
     * Récupère la date du dernier contact enregistré pour une entité (Contact ou Entreprise).
     * * @param int    $entity_id L'ID du contact ou de l'entreprise.
     * @param string $entity_type 'contact' ou 'company'.
     * @return string|null La date et l'heure du dernier contact au format 'Y-m-d H:i:s', ou null si aucun contact n'est trouvé.
     */
    function get_last_contact_date( $entity_id, $entity_type = 'company' ) {
        global $wpdb;
        
        $max_date = null;

        // --- 1. Préparation et Sécurité ---
        $entity_id = absint( $entity_id );
        $entity_type = strtolower( $entity_type );
        
        if ( $entity_id === 0 || ! in_array( $entity_type, array( 'contact', 'company' ) ) ) {
            return null;
        }

        
        $table_notes     = $wpdb->prefix . 'ispag_contact_notes';
        $table_commandes = $wpdb->prefix . 'achats_liste_commande';
        $table_phases    = $wpdb->prefix . 'achats_suivi_phase_commande';
        $table_slugs     = $wpdb->prefix . 'achats_slug_phase';
        
        // Types d'activités considérées comme un 'contact'
        $contact_types = array( 'EMAIL', 'CALL', 'MEETING' );
        // Création d'une chaîne SQL sécurisée pour la clause IN
        $contact_types_sql = "'" . implode("','", array_map('esc_sql', $contact_types)) . "'";

        // --- 2. Recherche dans les NOTES/ACTIVITÉS (Source 1) ---

        // Création conditionnelle de la clause WHERE pour les notes
        if ( $entity_type === 'contact' ) {
            // Recherche si $entity_id est dans la liste CSV de contact_id
            $notes_where = $wpdb->prepare( "FIND_IN_SET( %d, contact_id ) > 0", $entity_id );
        } else { // 'company'
            // Recherche sur la colonne company_id
            $notes_where = $wpdb->prepare( "company_id = %d", $entity_id );
        }
        
        $sql_notes = "
            SELECT MAX(created_at) 
            FROM {$table_notes}
            WHERE type IN ({$contact_types_sql})
            AND {$notes_where}
        ";
        
        $date_notes = $wpdb->get_var( $sql_notes );
        if ( $date_notes ) {
            $max_date = $date_notes;
        }

        // --- 3. Recherche dans les PHASES DE PROJET (Source 2) ---

        // Création conditionnelle de la clause WHERE pour les commandes (deals)
        if ( $entity_type === 'contact' ) {
            // Recherche si $entity_id est dans la liste CSV de AssociatedContactIDs
            $deals_where = $wpdb->prepare( "FIND_IN_SET( %d, lc.AssociatedContactIDs ) > 0", $entity_id );
        } else { // 'company'
            // Recherche sur la colonne AssociatedCompanyID
            $deals_where = $wpdb->prepare( "lc.AssociatedCompanyID = %d", $entity_id );
        }

        // Jointure complexe pour trouver la date max d'une phase de contact (Brevo_id > 0)
        $sql_phases = "
            SELECT MAX(spc.date_modification) 
            FROM {$table_commandes} lc
            INNER JOIN {$table_phases} spc
                ON lc.hubspot_deal_id = spc.hubspot_deal_id
            INNER JOIN {$table_slugs} sp
                ON spc.slug_phase = sp.SlugPhase 
            WHERE {$deals_where}
            AND sp.Brevo_id IS NOT NULL 
            AND sp.Brevo_id > 0
        ";

        $date_phases = $wpdb->get_var( $sql_phases );
        
        // Comparaison avec la date trouvée dans les notes et mise à jour de $max_date
        if ( $date_phases ) {
            if ( ! $max_date || strtotime( $date_phases ) > strtotime( $max_date ) ) {
                $max_date = $date_phases;
            }
        }

        // --- 4. Résultat Final ---
        return $max_date;
    }

    /**
     * Récupère les contacts (utilisateurs WordPress) associés à cette entreprise.
     * La recherche est basée sur la méta-clé utilisateur ispag_company_id = $company_id.
     * * @param int $company_id L'ID de l'entreprise dans la table achats_fournisseurs.
     * @param int $limit Le nombre maximum de contacts à retourner (pour l'affichage du panneau droit).
     * @return array Liste des objets utilisateur (WP_User).
     */
    protected function get_associated_contacts( $company_id, $limit = 5 ) {
        
        $contacts_query = new WP_User_Query( array(
            'meta_key'       => self::META_COMPANY_ID,
            'meta_value'     => $company_id,
            'meta_compare'   => '=',
            'number'         => $limit, // Limite le nombre de résultats
            'fields'         => array( 'ID', 'display_name', 'user_email' ), // Sélectionne uniquement les champs nécessaires
            'orderby'        => 'display_name',
            'order'          => 'ASC',
        ) );

        $contacts = [];

        // Traitement des résultats
        if ( ! empty( $contacts_query->get_results() ) ) {
            foreach ( $contacts_query->get_results() as $user ) {

                $contact_id = absint($user->ID);

                $last_contact_raw = get_user_meta( $contact_id, self::META_LAST_CONTACT_DATE, true );
                $lead_function = get_user_meta( $contact_id, self::META_LEAD_FUNCTION, true );
                $last_contact_display = !empty($last_contact_raw) 
                    ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_contact_raw) ) 
                    : 'N/A';

                $contacts[] = (object) [
                    'ID'                        => absint($user->ID),
                    'display_name'              => esc_html($user->display_name), 
                    'email'                     => esc_html($user->user_email),
                    'billing_phone'             => esc_html($user->billing_phone),
                    'last_contact_date_raw'     => $last_contact_raw,
                    'last_contact_date_display' => $last_contact_display,
                    'contact_function'          => $lead_function,

                ];
            }
        }

        return $contacts;
    }


    // NOTE: Assurez-vous d'avoir une méthode get_wpdb() si vous n'avez pas accès directement à $wpdb dans la classe.
    private function get_wpdb() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Placeholder pour afficher le contenu de l'onglet Activité (simulant la Note Manager).
     */
    protected function render_activity_tab_placeholder( $company_id ) {
        // Si la classe Note Manager est disponible globalement :
        if ( class_exists( 'ISPAG_Contact_Note_Manager' ) ) {
            // NOTE: Ceci suppose que vous instanciez Note_Manager de manière accessible
            $note_manager = new ISPAG_Contact_Note_Manager(); // Instanciation pour l'exemple
            return $note_manager->render_activity_tab( $company_id, 'company' );
        }
        return '<p>Erreur: ISPAG_Contact_Note_Manager n\'est pas accessible pour afficher les activités.</p>';
    }

    /**
     * [Hypothèse: Ajouté à la classe Company Detail]
     * Renvoie le HTML de la modale pour ajouter ou rechercher des contacts existants.
     * Cette méthode serait appelée via AJAX.
     * * @param int $company_id L'ID de l'entreprise actuelle.
     * @return string HTML de la modale.
     */
    public function render_add_contact_modal( $company_id ) {
        ob_start();
        ?>
        <div id="ispag-add-contact-modal" class="ispag-modal-overlay">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <h3><?php _e( 'Add existing Contact', 'ispag-crm' ); ?></h3>
                    <span class="ispag-modal-close">×</span>
                </div>

                <div class="ispag-modal-tabs">
                    <button class="ispag-tab-modal active" data-tab="create-new"><?php _e( 'Create new', 'ispag-crm' ); ?></button>
                    <button class="ispag-tab-modal" data-tab="add-existing"><?php _e( 'Add existing', 'ispag-crm' ); ?></button>
                </div>

                <div class="ispag-modal-body">
                    
                    <div id="tab-add-existing" class="ispag-tab-modal-pane active">
                        <input type="text" id="contact-search-input" placeholder="<?php _e( 'Search Contacts', 'ispag-crm' ); ?>" />
                        <button id="contact-search-btn"><span class="dashicons dashicons-search"></span></button>

                        <div id="contact-search-results">
                            <div class="ispag-already-associated-contacts">
                                <h5><?php _e( 'Currently associated contacts', 'ispag-crm' ); ?></h5>
                                <?php
                                // Vous devez récupérer ici $associated_contacts_list (comme sur la page)
                                
                                $associated_contacts_list = $this->get_associated_contacts( $company_id, 999 );
                                
                                if ( ! empty( $associated_contacts_list ) ) {
                                    echo '<p>' . count($associated_contacts_list). ' ' . __( 'Contacts', 'ispag-crm' ) . '</p>';
                                    foreach ( $associated_contacts_list as $contact ) {
                                        // Afficher chaque contact avec un bouton de suppression (par exemple)
                                        echo '<div class="contact-tag" data-id="' . absint( $contact->ID ) . '">' . esc_html( $contact->display_name ) . ' <span class="remove-contact">×</span></div>';
                                    }
                                } else {
                                    echo '<p>Aucun contact actuellement lié.</p>';
                                }
                                ?>
                            </div>
                            
                            <hr>
                            <p class="results-count">**X** <?php _e( 'Contacts', 'ispag-crm' ); ?></p>
                            
                            <div class="contact-list-container">
                            </div>
                            
                            <a href="#" class="load-more-btn"><?php _e( '10 items', 'ispag-crm' ); ?> <span class="dashicons dashicons-arrow-down-alt2"></span></a>
                        </div>
                    </div>

                    <div id="tab-create-new" class="ispag-tab-modal-pane">
                        <p><?php _e( 'Contact creation form...', 'ispag-crm' ); ?></p>
                    </div>
                </div>

                <div class="ispag-modal-footer">
                    <button class="ispag-btn ispag-btn-secondary ispag-modal-cancel"><?php _e( 'Cancel', 'ispag-crm' ); ?></button>
                    <button class="ispag-btn ispag-btn-primary ispag-modal-save" data-company-id="<?php echo absint($company_id); ?>"><?php _e( 'Save', 'ispag-crm' ); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // --- METHODE DE RENDU PRINCIPALE (Adaptée au modèle de contact) ---
    // -------------------------------------------------------------------------

    /**
     * Gère l'affichage du détail d'une entreprise pour le shortcode [ispag_company_detail].
     *
     * @param array $atts Attributs du shortcode.
     * @return string Le HTML du profil de l'entreprise.
     */
    public function render_company_detail_shortcode( $atts ) {
        
        // 1. Sécurité
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p class="ispag-access-denied">' . __( 'You do not have permission to view this content.', 'ispag-crm' ) . '</p>';
        }
 
        // 2. Définition des liens (similaire au contact)
        $transaction_list_page = get_page_by_path( 'liste-des-projets-new' );
        $contact_list_page = get_page_by_path( 'listes-des-contacts' );
        $new_project_page = get_page_by_path( 'nouvelle-selection' );
        $new_contact_page = get_page_by_path( 'nouveau-contact' ); // Nouveau lien
        $user_page = get_page_by_path( 'contact-detail' );

        $link_transaction_list = $transaction_list_page ? get_permalink( $transaction_list_page ) : '#';
        $link_contact_list = $contact_list_page ? get_permalink( $contact_list_page ) : '#';
        $link_new_project = $new_project_page ? get_permalink( $new_project_page ) : '#';
        $link_new_contact = $new_contact_page ? get_permalink( $new_contact_page ) : '#';
        $link_user_page = $user_page ? get_permalink( $user_page ) : '#';
        
        
        // 3. Détermination de l'ID de l'entreprise
        // $company_id = filter_input( INPUT_GET, 'company_id', FILTER_VALIDATE_INT ); --> OBsolete avec le permalien
        $company_viag_id = absint( get_query_var( 'company_id' ) );

        // 4. Récupération des données de l'entreprise
        global $wpdb;
        $table_name = $wpdb->prefix . 'ispag_companies';
        
        $company = $wpdb->get_row( 
            $wpdb->prepare( "SELECT * FROM {$table_name} WHERE viag_id = %d", $company_viag_id ) 
        );

        if ( ! $company ) {
            return '<p class="ispag-error">' . __( 'Company not found.', 'ispag-crm' ) . '</p>';
        }

        $company_id = $company->Id;

        
        $company_meta_lead_status = get_post_meta( $company_id, self::META_COMPANY_LEAD_STATUS, true );
        $company_meta_type        = get_post_meta( $company_id, self::META_COMPANY_TYPE, true );
        $company_meta_owner       = get_post_meta( $company_id, self::META_COMPANY_OWNER, true );

        // --- Champ : Type d'Entreprise ---
        $field_name = self::META_COMPANY_TYPE;
        $type_options = self::get_company_type_options(); // Retourne un tableau simple (string)
        // L'appel fonctionne maintenant car la fonction format_options_for_data_attr gère les strings
        $type_options_js = $this->format_options_for_data_attr($type_options, 'label');

        // --- Champ : Propriétaire (Owner) ---
        $field_name = self::META_COMPANY_OWNER;
        $owner_options = self::get_ispag_owners_options(); // Retourne un tableau simple (string)
        // L'appel fonctionne également, 'display_name' est ignoré pour les valeurs string
        $owner_options_js = $this->format_options_for_data_attr($owner_options, 'display_name');

        if ( !$company_id ) {
            $a = shortcode_atts( array( 'id' => 0 ), $atts );
            $company_id = absint( $a['id'] );
        }
        
        if ( $company_id === 0 ) {
            return '<p class="ispag-error">' . __( 'Company ID is missing.', 'ispag-crm' ) . '</p>';
        }

        

        // 5. Préparation des données et Placeholders
        
        $company_name = esc_html($company->company_name);
        $company_viag_id = esc_html($company->viag_id);
        $company_domain = esc_html(isset($company->compagnyDomain) ? $company->compagnyDomain : 'N/A');
        $company_phone = get_post_meta( $company_id, self::META_COMPANY_PHONE, true );
        // $company_city = esc_html(isset($company->Ville) ? $company->Ville : 'N/A');
        $company_city = get_post_meta( $company_id, self::META_COMPANY_CITY, true );
        $company_address = get_post_meta( $company_id, self::META_COMPANY_ADRESS, true );
        $company_postal_code = get_post_meta( $company_id, self::META_COMPANY_POSTAL_CODE, true );
        $company_region = get_post_meta( $company_id, self::META_COMPANY_REGION, true );
        $company_country = get_post_meta( $company_id, self::META_COMPANY_COUNTRY, true );
        $company_industry = get_post_meta( $company_id, self::META_COMPANY_INDUSTRY, true );

        $company_description = isset($company->Description) ? $company->Description : '';

        $company_owner_id = isset($company->OwnerID) ? absint($company->OwnerID) : 0;
        $owners_lookup = $this->get_all_owners();
        $owner_name = isset($owners_lookup[$company_owner_id]) ? esc_html($owners_lookup[$company_owner_id]->display_name) : 'Aucun propriétaire';
        
        $associated_contacts_list = $this->get_associated_contacts( $company_id, 5 ); 
        $associated_contacts_list_full = $this->get_associated_contacts( $company_id, 999 ); 
        
        $repo = new ISPAG_Crm_Deals_Repository(); 
        $transactions_list = $repo->get_projects_by_company( $company->viag_id, 5 );
        $transactions_list_full = $repo->get_projects_by_company( $company->viag_id, 999 );

        // $transactions_list = $this->get_company_transactions( $company_id, 5 ); 
        // $transactions_list_full = $this->get_company_transactions( $company_id, 999 ); 

        // Placeholder pour l'affichage des badges (Tier, Type)
        $company_type = esc_html(isset($company->Type) ? $company->Type : 'Client');
        $company_tier = 'Tier 1'; // Exemple statique
   
        
        $favicon = $this->get_favicon_url($company->compagnyDomain);
        
        // --- Début de la sortie HTML ---
        ob_start();
        ?>
        
        <div class="ispag-detail-container ispag-company-detail" data-company-id="<?php echo absint($company_id); ?>">
            
            <div class="ispag-left-panel">
                
                <div class="ispag-card ispag-header-card">
                    <div class="ispag-profile-pic <?php echo ($favicon) ? 'has-favicon' : ''; ?>">
                        <?php 
                        if ($favicon) {
                            // Afficher l'icône :
                            echo '<img src="' . $favicon . '" alt="Favicon" style="width:32px; height:32px;">';
                        } else {
                            // Afficher les deux premières lettres du nom de l'entreprise
                            $initials = strtoupper( substr( $company_name, 0, 1 ) . substr( $company_name, strpos($company_name, ' ') + 1, 1 ) );
                            echo esc_html( $initials ); 
                        }
                        
                        ?>
                    </div>

                            <div class="ispag-header-info">
                        <h4
                            class="ispag-editable-field" 
                            data-type="text" 
                            data-name="Fournisseur" 
                            data-value="<?php echo esc_attr( $company_name ); ?>"
                        >
                            <?php echo $company_name; ?>
                            <span class="edit-icon">✏️</span>
                        </h4>
                        <p 
                            class="ispag-editable-field" 
                            data-type="text" 
                            data-name="compagnyDomain" 
                            data-value="<?php echo esc_attr( $company_domain ); ?>"
                        >
                            <?php echo $company_domain; ?>
                            <span class="edit-icon">✏️</span>
                        </p>
                        <p 
                            class="ispag-editable-field" 
                            data-type="text" 
                            data-name="viag_id" 
                            data-value="<?php echo esc_attr( $company_viag_id ); ?>"
                        >
                            <?php echo $company_viag_id; ?>
                            <span class="edit-icon">✏️</span>
                        </p>
                    </div>

                </div>
                
                <div class="ispag-actions-bar">
                    <button class="ispag-action-btn" data-action="note" data-company-id="<?php echo $company_id; ?>" title="<?php esc_attr_e( 'Add Note', 'ispag-crm' ); ?>">
                        <span class="dashicons dashicons-text-page"></span>
                        <?php esc_html_e( 'Note', 'ispag-crm' ); ?>
                    </button>
                    <button class="ispag-action-btn" data-action="log_call" title="<?php esc_attr_e( 'Log a call', 'ispag-crm' ); ?>">
                        <span class="dashicons dashicons-phone"></span>
                        <?php esc_html_e( 'Call', 'ispag-crm' ); ?>
                    </button>
                    <button class="ispag-action-btn" data-action="create_task" title="<?php esc_attr_e( 'Create Task', 'ispag-crm' ); ?>">
                        <span class="dashicons dashicons-list-view"></span>
                        <?php esc_html_e( 'Task', 'ispag-crm' ); ?>
                    </button>
                    <button class="ispag-action-btn" data-action="new_contact" title="<?php esc_attr_e( 'Create New Contact', 'ispag-crm' ); ?>" onclick="window.open('<?php echo esc_url($link_new_contact); ?>?company_id=<?php echo $company_id; ?>', '_blank');">
                        <span class="dashicons dashicons-groups"></span>
                        <?php esc_html_e( 'Contact', 'ispag-crm' ); ?>
                    </button>
                </div>

                <div class="ispag-card ispag-key-info">
                    <h5><?php _e( 'Key information', 'ispag-crm' ); ?></h5>
                    <dl class="ispag-key-info-list">
                        
                        <dt><?php _e( 'Phone number', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="text" 
                            data-name="NumTel" 
                            data-value="<?php echo esc_attr( $company_phone ); ?>"
                        >
                            <?php echo $company_phone; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>
                        
                        <dt><?php _e( 'Address', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="textarea" 
                            data-name="Adresse" 
                            data-value="<?php echo esc_attr( $company_address ); ?>"
                        >
                            <?php echo $company_address; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>
                        
                        <dt><?php _e( 'City', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="text" 
                            data-name="Ville" 
                            data-value="<?php echo esc_attr( $company_city ); ?>"
                        >
                            <?php echo $company_city; ?>
                            <span class="edit-icon">✏️</span>
                        </dd>
                        
                        <dt><?php _e( 'Company Owner', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="select" 
                            data-name="<?php echo self::META_COMPANY_OWNER; ?>" 
                            data-value="<?php echo absint($company_meta_owner); ?>" 
                            data-options="<?php echo esc_attr($owner_options_js); ?>'"
                            data-original-content="<?php echo esc_attr($this->get_display_html(self::META_COMPANY_OWNER, $company_meta_owner)); ?>"
                        >
                            <?php echo $this->get_display_html($field_name, $company_meta_owner); ?>
                        </dd>

                        <dt><?php _e( 'Last contacted', 'ispag-crm' ); ?></dt>
                        <dd >
                            <?php echo $this->get_last_contact_date($company_id); ?>
                        </dd>
                        
                    </dl>
                </div>
                
                <div class="ispag-card" data-company-id="<?php echo $company_id; ?>">
                    <h5><?php _e( 'Company Status', 'ispag-crm' ); ?></h5>
                    <dl class="ispag-key-info-list">
                        <dt><?php _e( 'Company Type', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="select" 
                            data-name="<?php echo self::META_COMPANY_TYPE; ?>" 
                            data-value="<?php echo  esc_attr($company_meta_type); ?>" 
                            data-options="<?php echo esc_attr($type_options_js); ?>"
                            data-original-content="<?php echo esc_attr($this->get_display_html($field_name, $company_meta_type)); ?>"
                        >
                           <?php echo $this->get_display_html($field_name, $company_meta_type); ?>
                        </dd>
                        
                        <dt><?php _e( 'Client Tier', 'ispag-crm' ); ?></dt>
                        <dd 
                            class="ispag-editable-field" 
                            data-type="select" 
                            data-name="<?php echo self::META_TIER; ?>" 
                            data-value="<?php echo esc_attr($company_tier); ?>" 
                            data-options="{/* Options Tier ici */}"
                        >
                            <span class="ispag-status-badge" style="background-color: #f39c12; color: #fff;"><?php echo $company_tier; ?></span>
                            <span class="edit-icon">✏️</span>
                        </dd>
                    </dl>
                </div>
            </div> <div class="ispag-main-content">
        
                <div class="ispag-tabs-navigation">
                    <button class="ispag-tab-btn active" data-tab="about">
                        <?php esc_html_e( 'About', 'ispag-crm' ); ?>
                    </button>
                    <button class="ispag-tab-btn" data-tab="activity">
                        <?php esc_html_e( 'Activities', 'ispag-crm' ); ?>
                    </button>
                    <button class="ispag-tab-btn" data-tab="deal">
                        <?php esc_html_e( 'Transactions', 'ispag-crm' ); ?>
                    </button>
                    <button class="ispag-tab-btn" data-tab="intelligence">
                        <?php esc_html_e( 'Intelligence', 'ispag-crm' ); ?>
                    </button>
                </div>
                
                <div class="ispag-tabs-content">
                    
                    <div id="ispag-tab-about" class="ispag-tab-pane active">
                        
                        <div class="ispag-card">
                            <h5><?php _e( 'Company Profile', 'ispag-crm' ); ?></h5>
                            <div data-company-id="<?php echo $company_id; ?>" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 14px;">
                                
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'City', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_CITY; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_city); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_city); ?></span>
                                    </p>
                                </div>
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Street adress', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_ADRESS; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_address); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_address); ?></span>
                                    </p>
                                </div>
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Postal code', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_POSTAL_CODE; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_postal_code); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_postal_code); ?></span>
                                    </p>
                                </div>

                                <div class="ispag-field-container">
                                    <strong><?php _e( 'State/Region', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_REGION; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_region); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_region); ?></span>
                                    </p>
                                </div>
                                
                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Country/Region', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_COUNTRY; ?>" 
                                            data-type="text"
                                            data-value="<?php echo esc_html($company_country); ?>"
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_country); ?></span>
                                    </p>
                                </div>

                                <div class="ispag-field-container">
                                    <strong><?php _e( 'Industry', 'ispag-crm' ); ?> :</strong>
                                    <p>
                                        <span 
                                            class="ispag-editable-field" 
                                            data-id="<?php echo $company_id; ?>" 
                                            data-name="<?php echo self::META_COMPANY_INDUSTRY; ?>" 
                                            data-type="select"
                                            data-value="<?php echo esc_html($company_industry); ?>"
                                            data-options='["Installateur CVC", "Ingenieur CVC"]'
                                            title="<?php _e('Click to edit', 'ispag-crm'); ?>"
                                        ><?php echo esc_html($company_industry); ?></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="ispag-tab-activity" class="ispag-tab-pane">
                        <?php 
                        // Appel de la fonction placeholder pour l'activité
                        echo $this->render_activity_tab_placeholder($company_id); 
                        ?>
                    </div>
                    
                    <div id="ispag-tab-deal" class="ispag-tab-pane">
                        <h5><?php esc_html_e( 'Transaction information', 'ispag-crm' ); ?></h5>
                        <?php if ( ! empty( $transactions_list_full ) ): ?>
                            <div class="ispag-transactions-list">
                                <?php foreach ( $transactions_list_full as $transaction ): ?>
                                    <div class="ispag-transaction-item">
                                        <h6><a href="<?php echo esc_url($transaction->link); ?>" target="_blank"><?php echo $transaction->project_name; ?></a></h6>
                                        <p><?php _e( 'Amount', 'ispag-crm' ); ?>: <?php echo number_format($transaction->total_excl_vat, 2, ',', ' ') . ' CHF'; ?></p>
                                        <p><?php _e( 'Closing date', 'ispag-crm' ); ?>: <?php echo date_i18n( get_option('date_format'), strtotime( $transaction->closing_date ) ); ?></p>
                                        <p><?php _e( 'Transaction phase', 'ispag-crm' ); ?>: 
                                            <span class="ispag-status-badge" style="background-color: <?php echo esc_attr($transaction->stage_color); ?>; color: #fff;">
                                                <?php echo esc_html($transaction->stage_label); ?>
                                            </span>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($transactions_list_full) >= 999): ?>
                                <a href="<?php echo $link_transaction_list; ?>?company_id=<?php echo absint($company_id); ?>" class="ispag-button-link"><?php _e( 'Show all transactions', 'ispag-crm' ); ?></a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="font-size: 14px; color: #777;"><?php _e( 'No recent transactions.', 'ispag-crm' ); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div id="ispag-tab-intelligence" class="ispag-tab-pane">
                        <div 
                            id="gemini-ai-summary-<?php echo absint($company_id); ?>" 
                            class="ispag-ai-placeholder"
                            data-company-id="<?php echo absint($company_id); ?>"
                        >
                            <p style="text-align: center; color: #999; padding: 20px;">
                                <span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> 
                                <?php _e( 'Loading AI summary...', 'ispag-crm' ); ?>
                            </p>
                        </div>
                    </div>
                    
                </div>
            </div> <div class="ispag-right-panel">
                
                <div class="ispag-card ispag-company-card">
                    <h5>
                        <?php _e( 'Contacts', 'ispag-crm' ); ?> (<?php echo count($associated_contacts_list_full); ?>) 
                        <span id="open-add-contact-modal" 
                            style="font-size: 12px; color: #007bff; cursor: pointer;" 
                            data-company-id="<?php echo absint($company_id); ?>">
                            + <?php _e( 'Add', 'ispag-crm' ); ?>
                        </span>
                    </h5>
                    
                    <?php if ( ! empty( $associated_contacts_list ) ): ?>
                        
                        <?php foreach ( $associated_contacts_list as $contact ): 
                            $full_date_string = $contact->last_contact_date_display; 
                            $timestamp = strtotime( $full_date_string );
                            $date_format = date( 'd/m/Y', $timestamp );
                            ?>
                            <div class="ispag-card" style="font-size: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong style="color: #007bff;">
                                    <a href="<?php echo $link_user_page . '?user_id=' . absint($contact->ID); ?>" ><?php echo $contact->display_name; ?></a>
                                </strong>
                                
                                <span 
                                    class="ispag-remove-association" 
                                    data-contact-id="<?php echo absint($contact->ID); ?>"
                                    data-company-id="<?php echo absint($company->Id); ?>"
                                    title="<?php esc_attr_e( 'Remove association', 'ispag-crm' ); ?>"
                                    style="color: #e74c3c; cursor: pointer;"
                                >
                                    <span class="dashicons dashicons-trash"></span>
                                </span>
                            </div>
                            <p style="margin: 5px 0 0;"><?php _e( 'Last contact', 'ispag-crm' ); ?>: <?php echo $contact->last_contact_date_display; ?></p>
                            <p style="margin: 5px 0 0;"><?php _e( 'Phone number', 'ispag-crm' ); ?>: <?php echo $contact->billing_phone; ?></p>
                            <p style="margin: 5px 0 0;"><?php _e( 'Email', 'ispag-crm' ); ?>: <?php echo $contact->email; ?></p>
                                
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($associated_contacts_list_full) >= 5): ?>
                            <a href="<?php echo $link_contact_list; ?>?filter_company=<?php echo absint($company_id); ?>" class="ispag-button-link" target="_blank"><?php _e( 'View all associated Contacts', 'ispag-crm' ); ?></a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #777;"><?php _e( 'No contacts associated with this company.', 'ispag-crm' ); ?></p>
                    <?php endif; ?>
                </div>
                <div id="ispag-modal-container"></div>
                
                <div class="ispag-card ispag-transactions-card">
                    <h5>
                        <?php _e( 'Transactions', 'ispag-crm' ); ?> (<?php echo count($transactions_list_full); ?>)
                        <span style="font-size: 12px; color: #007bff; cursor: pointer;"><a href="<?php echo $link_new_project; ?>" target="_blank">+ <?php _e( 'Add', 'ispag-crm' ); ?></a></span>
                    </h5>
                    
                    <?php if ( ! empty( $transactions_list ) ): ?>
                        <div class="ispag-transactions-list">
                            <?php foreach ( $transactions_list as $transaction ): ?>
                                <div class="ispag-transaction-item">
                                    <h6><a href="<?php echo esc_url($transaction->link); ?>" target="_blank"><?php echo $transaction->project_name; ?></a></h6>
                                    <p><?php _e( 'Amount', 'ispag-crm' ); ?>: <?php echo number_format($transaction->total_excl_vat, 2, ',', ' ') . ' CHF'; ?></p>
                                    <p><?php _e( 'Closing date', 'ispag-crm' ); ?>: <?php echo date_i18n( get_option('date_format'), strtotime( $transaction->closing_date ) ); ?></p>
                                    <p><?php _e( 'Transaction phase', 'ispag-crm' ); ?>: 
                                        <span class="ispag-status-badge" style="background-color: <?php echo esc_attr($transaction->stage_color); ?>; color: #fff;">
                                            <?php echo esc_html($transaction->stage_label); ?>
                                        </span>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($transactions_list_full) >= 5): ?>
                            <a href="<?php echo $link_transaction_list; ?>?company_id=<?php echo absint($company_id); ?>" class="ispag-button-link"><?php _e( 'Show all transactions', 'ispag-crm' ); ?></a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #777;"><?php _e( 'No recent transactions.', 'ispag-crm' ); ?></p>
                    <?php endif; ?>
                </div>
            </div> </div>
        <?php
        return ob_get_clean();
    }
}