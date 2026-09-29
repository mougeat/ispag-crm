<?php
require_once __DIR__ . '/class-ispag-attachments-doc-types-repository.php';

/**
 * Traite l'upload d'un document : déplace le fichier, crée l'attachment
 * WordPress, puis le rattache à l'entité (deal/project/purchase → ligne
 * dans achats_historique ; contact/company → note dans ispag_contact_notes).
 */
class ISPAG_Attachments_Uploader {

    private const ENTITY_TYPES_ACHATS = ['deal', 'project', 'purchase'];
    private const ENTITY_TYPES_NOTES  = ['contact', 'company'];

    /** @var wpdb */
    private $wpdb;

    private ISPAG_Attachments_Doc_Types_Repository $docTypesRepo;

    public function __construct($wpdb, ISPAG_Attachments_Doc_Types_Repository $docTypesRepo) {
        $this->wpdb         = $wpdb;
        $this->docTypesRepo = $docTypesRepo;
    }

    /**
     * @param array  $file       Une entrée de $_FILES (ex: $_FILES['file'])
     * @param string $entityType 'deal' | 'project' | 'purchase' | 'contact' | 'company'
     * @param mixed  $entityId
     * @param string $docTypeSlug
     * @return array{success:bool, message?:string, media_id?:int, url?:string}
     */
    public function handleUpload(array $file, string $entityType, $entityId, string $docTypeSlug, $article_id = null): array {
        // error_log('handleUpload ARTICLE ID : ' . $article_id);

        if (!in_array($entityType, array_merge(self::ENTITY_TYPES_ACHATS, self::ENTITY_TYPES_NOTES), true)) {
            return ['success' => false, 'message' => __('Invalid entity type.', 'ispag-crm')];
        }

        $docType = $this->docTypesRepo->findBySlug($docTypeSlug);
        if (!$docType) {
            return ['success' => false, 'message' => __('Invalid document type.', 'ispag-crm')];
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $movefile = wp_handle_upload($file, ['test_form' => false]);

        if (isset($movefile['error'])) {
            return ['success' => false, 'message' => $movefile['error']];
        }

        $attachmentData = [
            'post_mime_type' => $movefile['type'],
            'post_title'     => sanitize_file_name(pathinfo($movefile['file'], PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $mediaId = wp_insert_attachment($attachmentData, $movefile['file']);

        if (is_wp_error($mediaId) || !$mediaId) {
            return ['success' => false, 'message' => __('Failed to create the media.', 'ispag-crm')];
        }

        $metadata = wp_generate_attachment_metadata($mediaId, $movefile['file']);
        wp_update_attachment_metadata($mediaId, $metadata);

        $this->linkMediaToEntity($mediaId, $entityType, $entityId, $docType, $article_id);

        // Point d'extension : chaque type de document peut déclencher un
        // traitement spécifique après upload (ex: notification, génération
        // d'une vignette, etc.) via son ajax_action.
        if (!empty($docType->ajax_action)) {
            do_action($docType->ajax_action, $mediaId, $entityType, $entityId, $docType);
        }

        return [
            'success'  => true,
            'media_id' => $mediaId,
            'url'      => wp_get_attachment_url($mediaId),
        ];
    }

    private function linkMediaToEntity(int $mediaId, string $entityType, $entityId, object $docType, $article_id = null): void {
        // error_log('linkMediaToEntity ARTICLE ID : ' . $article_id);
        if (in_array($entityType, self::ENTITY_TYPES_ACHATS, true)) {
            $this->linkToAchatsHistorique($mediaId, $entityType, $entityId, $docType, $article_id);
        } elseif (in_array($entityType, self::ENTITY_TYPES_NOTES, true)) {
            $this->linkToContactNotes($mediaId, $entityType, $entityId, $docType);
        }
    }

    private function linkToAchatsHistorique(int $mediaId, string $entityType, $entityId, object $docType, $article_id = null): void {
        $table = $this->wpdb->prefix . 'achats_historique';

        // error_log('linkToAchatsHistorique ARTICLE ID : ' . $article_id);

        $data = [
            'hubspot_deal_id' => 0,
            'purchase_order'  => 0, 
            'Date'            => time(),
            'dateReadable'    => current_time('mysql'),
            'IdUser'          => get_current_user_id(),
            'Historique'      => !empty($article_id) ? $article_id : $docType->label,
            'IdMedia'         => $mediaId,
            'is_task'         => 0,
            'is_done'         => 0,
            'ClassCss'        => $docType->slug ?? '',
        ];

        if ($entityType === 'purchase') {
            $data['purchase_order'] = (int) $entityId;
        } else { // 'deal' ou 'project'
            $data['hubspot_deal_id'] = (int) $entityId;
        }

        $this->wpdb->insert(
            $table,
            $data,
            ['%d', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s']
        );
    }

    private function linkToContactNotes(int $mediaId, string $entityType, $entityId, object $docType): void {
        $table = $this->wpdb->prefix . 'ispag_contact_notes';

        $data = [
            'user_id'    => get_current_user_id(),
            'type'       => 'NOTE',
            'title'      => $docType->label,
            'content'    => '',
            'media_ids'  => (string) $mediaId,
            'created_at' => current_time('mysql'),
        ];

        // 'contact_id' ou 'company_id' selon $entityType
        $data[$entityType . '_id'] = (string) $entityId;

        $this->wpdb->insert($table, $data);
    }
}
