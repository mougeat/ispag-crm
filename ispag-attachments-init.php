<?php
defined('ABSPATH') || exit;


const ISPAG_ATTACHMENTS_NONCE_ACTION = 'ispag_attachments_nonce';

// 1. Enregistrement des scripts (avec passage de l'ajaxurl + nonce)
add_action('wp_enqueue_scripts', 'ispag_enqueue_attachments_assets');
function ispag_enqueue_attachments_assets() {
    // wp_enqueue_style('ispag-attachments-modal-css', plugins_url('/assets/css/ispag-attachments-modal.css', __FILE__), [], '1.0');

    wp_enqueue_script('ispag-attachments-js', plugins_url('/assets/js/ispag-attachments.js', __FILE__), ['jquery'], '1.1', true);
    wp_enqueue_script('ispag-attachments-upload-js', plugins_url('/assets/js/ispag-attachments-upload.js', __FILE__), ['jquery'], '1.0', true);
    wp_enqueue_script('ispag-attachment-dropzone-js', plugins_url('/assets/js/ispag-attachment-dropzone.js', __FILE__), ['jquery'], '1.0', true);

    // NB : avant cette version, 'nonce' n'était pas passé ici du tout,
    // alors que le JS l'utilisait déjà (ispagAttachmentsAjax.nonce) —
    // toute requête sécurisée par check_ajax_referer() aurait échoué.
    wp_localize_script('ispag-attachments-js', 'ispagAttachmentsAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce(ISPAG_ATTACHMENTS_NONCE_ACTION),
        'texts'   => [
            'loading'           => __('Loading...', 'ispag-crm'),
            'uploadSuccess'     => __('Document added.', 'ispag-crm'),
            'uploadError'       => __('Error while loading.', 'ispag-crm'),
            'confirmDeleteDoc'  => __('Are you sure you want to delete this document ?', 'ispag-crm'),
            'Confirm'           => __('Confirm', 'ispag-crm'),
            'Cancel'            => __('Cancel', 'ispag-crm'),
        ],
    ]);
}


/**
 * Un utilisateur peut supprimer un document s'il gère les commandes, s'il l'a chargé lui-même,
 * ou s'il est propriétaire (contact associé) du projet auquel le document est lié.
 */
function ispag_user_can_delete_attachment($mediaId) {
    static $cache = [];
    $mediaId = (int) $mediaId;
    if (!$mediaId || !is_user_logged_in()) return false;
    if (current_user_can('manage_order')) return true;
    if (isset($cache[$mediaId])) return $cache[$mediaId];

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT IdUser, hubspot_deal_id FROM {$wpdb->prefix}achats_historique WHERE IdMedia = %d LIMIT 1",
        $mediaId
    ));
    $ok = false;
    if ($row) {
        $ok = ((int) $row->IdUser === get_current_user_id())
            || ($row->hubspot_deal_id && class_exists('ISPAG_Projet_Repository') && ISPAG_Projet_Repository::is_user_project_owner((int) $row->hubspot_deal_id));
    }
    return $cache[$mediaId] = $ok;
}

// 2. Handler AJAX : tableau complet des pièces jointes (sidebar)
add_action('wp_ajax_ispag_get_all_attachments', 'ispag_handle_get_all_attachments');
function ispag_handle_get_all_attachments() {
    // check_ajax_referer(ISPAG_ATTACHMENTS_NONCE_ACTION);

    global $wpdb;
    // Vérification de sécurité optionnelle (nonce)
    $entityType = sanitize_text_field($_POST['entity_type'] ?? '');
    $entityId   = intval($_POST['entity_id'] ?? 0);

    if (!$entityType || !$entityId) {
        wp_send_json_error(['message' => 'Invalid parameters']);
    }

    $repository = new ISPAG_Attachments_Repository($wpdb);
    $table_renderer = new ISPAG_Attachments_Table_Renderer($repository);
    
    $html = $table_renderer->render_table($entityType, $entityId);
    $header = '<h2 id="task-title-main">' . __('Attachments', 'ispag-crm') . '</h2>';

    wp_send_json_success(['html' => $html, 'header' => $header]);
}

// 3. Handler AJAX : rendu de la modal d'upload (dropzone + sélecteur de type)
add_action('wp_ajax_ispag_get_upload_modal', 'ispag_handle_get_upload_modal');
function ispag_handle_get_upload_modal() {
    // check_ajax_referer(ISPAG_ATTACHMENTS_NONCE_ACTION, 'security');
    // error_log('IN wp_ajax_ispag_get_upload_modal');
    global $wpdb;
    $entityType = sanitize_text_field($_POST['entity_type'] ?? '');
    $entityId   = intval($_POST['entity_id'] ?? 0);


    if (!$entityType || !$entityId) {
        wp_send_json_error(['message' => __('Invalid parameters.', 'ispag-crm')]);
    }

    $docTypesRepo   = new ISPAG_Attachments_Doc_Types_Repository($wpdb);
    $modal_renderer = new ISPAG_Attachments_Modal_Renderer($docTypesRepo);

    wp_send_json_success(['html' => $modal_renderer->render_modal($entityType, $entityId)]);
}

// 4. Handler AJAX : traitement effectif de l'upload multi-fichiers
add_action('wp_ajax_ispag_upload_attachment', 'ispag_handle_upload_attachment');
function ispag_handle_upload_attachment() {
    check_ajax_referer(ISPAG_ATTACHMENTS_NONCE_ACTION, 'security');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Not authorized.', 'ispag-crm')]);
    }

    global $wpdb;
    $entityType  = sanitize_text_field($_POST['entity_type'] ?? '');
    $entityId    = intval($_POST['entity_id'] ?? 0);
    $docTypeSlug = sanitize_text_field($_POST['doc_type'] ?? '');
    $articleId   = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

    if (!$entityType || !$entityId || !$docTypeSlug) {
        wp_send_json_error(['message' => __('Missing parameters.', 'ispag-crm')]);
    }

    // Vérification de la clé multi-fichiers 'files' (ou fallback sur 'file' au cas où)
    $rawFiles = $_FILES['files'] ?? $_FILES['file'] ?? null;

    if (empty($rawFiles) || (is_array($rawFiles['name']) && empty($rawFiles['name'][0]))) {
        wp_send_json_error(['message' => __('No file received.', 'ispag-crm')]);
    }

    if (!class_exists('ISPAG_Attachments_Doc_Types_Repository') || !class_exists('ISPAG_Attachments_Uploader')) {
        wp_send_json_error(['message' => 'ISPAG_Attachments_Doc_Types_Repository ou ISPAG_Attachments_Uploader introuvable.']);
    }

    $docTypesRepo = new ISPAG_Attachments_Doc_Types_Repository($wpdb);
    $uploader     = new ISPAG_Attachments_Uploader($wpdb, $docTypesRepo);

    // Normalisation du tableau $_FILES pour gérer un ou plusieurs fichiers
    $uploadedResults = [];
    $errors          = [];

    if (is_array($rawFiles['name'])) {
        $fileCount = count($rawFiles['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            if ($rawFiles['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $singleFile = [
                'name'     => $rawFiles['name'][$i],
                'type'     => $rawFiles['type'][$i],
                'tmp_name' => $rawFiles['tmp_name'][$i],
                'error'    => $rawFiles['error'][$i],
                'size'     => $rawFiles['size'][$i],
            ];

            $result = $uploader->handleUpload($singleFile, $entityType, $entityId, $docTypeSlug, $articleId);

            if (!empty($result['success'])) {
                $uploadedResults[] = $result;
            } else {
                $errors[] = $rawFiles['name'][$i] . ' : ' . ($result['message'] ?? __('Upload error', 'ispag-crm'));
            }
        }
    } else {
        // Rétrocompatibilité : un seul fichier transmis sous forme d'objet direct
        $result = $uploader->handleUpload($rawFiles, $entityType, $entityId, $docTypeSlug, $articleId);
        if (!empty($result['success'])) {
            $uploadedResults[] = $result;
        } else {
            $errors[] = $result['message'] ?? __('Upload error', 'ispag-crm');
        }
    }

    if (!empty($uploadedResults)) {
        // Garde ajoutée : ISPAG_Notifications_Manager peut ne pas être chargée
        // selon le contexte d'exécution (cf. les fatal errors d'autoload de
        // cette semaine) — mieux vaut sauter la notif que planter l'upload.
        if (class_exists('ISPAG_Notifications_Manager')) {
            ISPAG_Notifications_Manager::send(
                [get_current_user_id()],
                'document_upload',
                "✅ " . __('Upload successful', 'creation-reservoir'),
                __('Your documents have been uploaded successfully!', 'creation-reservoir'),
                "projectdetail/" . $entityId,
                $entityId
            );
        }

        wp_send_json_success([
            'message' => __('Files uploaded successfully.', 'ispag-crm'),
            'uploads' => $uploadedResults,
            'errors'  => $errors,
        ]);
    } else {
        wp_send_json_error([
            'message' => !empty($errors) ? implode(' | ', $errors) : __('Upload failed.', 'ispag-crm'),
        ]);
    }
}


// 5. Handler AJAX : rafraîchissement d'une carte ou d'une liste après upload/suppression.
add_action('wp_ajax_ispag_refresh_attachments', 'ispag_handle_refresh_attachments');
function ispag_handle_refresh_attachments() {
    // check_ajax_referer(ISPAG_ATTACHMENTS_NONCE_ACTION, 'security');
 
    global $wpdb;
    $entityType = sanitize_text_field($_POST['entity_type'] ?? '');
    $entityId   = intval($_POST['entity_id'] ?? 0);
    $view       = sanitize_text_field($_POST['view'] ?? 'card');
 
    if (!$entityType || !$entityId) {
        wp_send_json_error(['message' => __('Invalid parameters.', 'ispag-crm')]);
    }
 
    if (!class_exists('ISPAG_Attachments_Repository') || !class_exists('ISPAG_Attachments_Card_Renderer')) {
        wp_send_json_error(['message' => 'ISPAG_Attachments_Repository or ISPAG_Attachments_Card_Renderer not found.']);
    }
 
    $repository = new ISPAG_Attachments_Repository($wpdb);
    $renderer   = new ISPAG_Attachments_Card_Renderer($repository);
 
    if ($view === 'list') {
        // bare=true : le wrapper .ispag-docu-card existe déjà côté DOM,
        // on ne régénère que son contenu interne (voir upload.js: $el.html(...)).
        $html = $renderer->render_doc_list($entityType, $entityId, -1, true);
    } else {
        // 'card' : on régénère le wrapper complet, remplacé en outerHTML côté JS.
        $html = $renderer->render($entityType, $entityId);
    }
 
    wp_send_json_success(['html' => $html, 'view' => $view]);
}
 

// 6. Handler AJAX : suppression d'une pièce jointe.
add_action('wp_ajax_ispag_delete_attachment', 'ispag_handle_delete_attachment');
function ispag_handle_delete_attachment() {
    check_ajax_referer(ISPAG_ATTACHMENTS_NONCE_ACTION, 'security');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Not authorized.', 'ispag-crm')]);
    }

    global $wpdb;
    $mediaId = isset($_POST['media_id']) ? intval($_POST['media_id']) : 0;
    // $entityType = sanitize_text_field($_POST['entity_type'] ?? '');
    // $entityId = intval($_POST['entity_id'] ?? 0);

    if (!$mediaId) {
        wp_send_json_error(['message' => __('Missing parameters.', 'ispag-crm')]);
    }

    if (!ispag_user_can_delete_attachment($mediaId)) {
        wp_send_json_error(['message' => __('Not authorized.', 'ispag-crm')]);
    }

    // Supprimer le fichier physique
    $attachment = get_post($mediaId);
    if ($attachment) {
        wp_delete_attachment($mediaId, true); // true pour supprimer le fichier physique
    }

    // Supprimer l'entrée dans la base de données (achats_historique ou ispag_contact_notes)
    $table = $wpdb->prefix . 'achats_historique';
    $wpdb->delete($table, ['IdMedia' => $mediaId]);

    wp_send_json_success(['message' => __('Document deleted.', 'ispag-crm')]);
}