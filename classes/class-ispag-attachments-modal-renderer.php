<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-ispag-attachments-doc-types-repository.php';

/**
 * Rend la dropzone de chargement de document + son sélecteur de type.
 *
 * render_dropzone() est conçu pour être appelé à plusieurs endroits de la
 * page (pas seulement dans une modal) : chaque appel génère un id unique,
 * et le JS (ispag-attachments-upload.js) initialise automatiquement toute
 * .ispag-dropzone trouvée dans le DOM, y compris injectée en AJAX.
 */
class ISPAG_Attachments_Modal_Renderer {

    private ISPAG_Attachments_Doc_Types_Repository $docTypesRepo;

    public function __construct(ISPAG_Attachments_Doc_Types_Repository $docTypesRepo) {
        $this->docTypesRepo = $docTypesRepo;
    }

    /**
     * Fragment autonome : dropzone (multi-fichiers) + sélecteur de type + bouton "Charger".
     * Utilisable directement inline n'importe où sur une page.
     */
    public function render_dropzone(string $entityType, $entityId, string $instanceId = ''): string {
        $instanceId = $instanceId ?: 'ispag-dz-' . uniqid();
        $docTypes = $this->docTypesRepo->getGeneralTypes(current_user_can('manage_order'), $entityType, $entityId);

        // Regrouper les types de documents par optgroup
        $groupedTypes = [];
        foreach ($docTypes as $type) {
            if (isset($type->optgroup)) {
                $groupedTypes[$type->optgroup][] = $type;
            } else {
                $groupedTypes[__('General', 'ispag-crm')][] = $type; // Groupe par défaut pour les types généraux
            }
        }

        ob_start();
        ?>
        <div class="ispag-dropzone"
            id="<?php echo esc_attr($instanceId); ?>"
            data-entity-type="<?php echo esc_attr($entityType); ?>"
            data-entity-id="<?php echo esc_attr($entityId); ?>">

            <div class="ispag-dropzone__field" tabindex="0" role="button"
                aria-label="<?php esc_attr_e('Drop a file or click to browse', 'ispag-crm'); ?>">
                <input type="file" name="files[]" class="ispag-dropzone__input" multiple hidden>
                <div class="ispag-dropzone__icon" aria-hidden="true">&#8593;</div>
                <p class="ispag-dropzone__label">
                    <?php _e('Drag a file here or', 'ispag-crm'); ?>
                    <span><?php _e('browse', 'ispag-crm'); ?></span>
                </p>
                <p class="ispag-dropzone__hint"><?php _e('PDF, images, Word, Excel, Mail — 20 Mo max', 'ispag-crm'); ?></p>
            </div>

            <div class="ispag-dropzone__file-preview" hidden>
                <span class="ispag-dropzone__file-icon" aria-hidden="true">&#128196;</span>
                <span class="ispag-dropzone__file-name"></span>
                <button type="button" class="ispag-dropzone__file-remove" aria-label="<?php esc_attr_e('Revoke', 'ispag-crm'); ?>">&times;</button>
            </div>

            <div class="ispag-dropzone__doctype">
                <label for="<?php echo esc_attr($instanceId); ?>-doctype"><?php _e('Document type', 'ispag-crm'); ?></label>
                <select id="<?php echo esc_attr($instanceId); ?>-doctype" class="ispag-dropzone__doctype-select">
                    <option value=""><?php _e('— Select —', 'ispag-crm'); ?></option>
                    <?php foreach ($groupedTypes as $optgroupLabel => $types) : ?>
                        <optgroup label="<?php echo esc_attr($optgroupLabel); ?>">
                            <?php foreach ($types as $type) :
                                $articleIdAttr = !empty($type->article_id) ? ' data-article-id="' . esc_attr($type->article_id) . '"' : '';
                                ?>
                                <option value="<?php echo esc_attr($type->slug); ?>" <?php echo $articleIdAttr; ?>>
                                    <?php
                                    // $type->label vient de la base (achats_doc_types), c'est une
                                    // valeur DYNAMIQUE — jamais esc_html__() ici, qui est réservé
                                    // aux chaînes LITTÉRALES à extraire pour la traduction.
                                    echo esc_html__( $type->label, 'ispag-crm' );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ispag-dropzone__actions">
                <button type="button" class="ispag-btn ispag-dropzone__submit" disabled><?php _e('Load', 'ispag-crm'); ?></button>
            </div>

            <div class="ispag-dropzone__progress" hidden>
                <div class="ispag-dropzone__progress-bar" style="width:0%"></div>
            </div>

            <p class="ispag-dropzone__status" aria-live="polite"></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Wrappe la dropzone dans un shell de modal générique, prêt à être
     * injecté dans le <body> et ouvert par le JS.
     */
    public function render_modal(string $entityType, $entityId): string {
        ob_start();
        ?>
        <div class="ispag-modal-overlay" id="ispag-upload-modal" hidden>
            <div class="ispag-modal-upload" role="dialog" aria-modal="true" aria-labelledby="ispag-upload-modal-title">
                <div class="ispag-modal-upload__header">
                    <h3 id="ispag-upload-modal-title"><?php _e('Add a document', 'ispag-crm'); ?></h3>
                    <button type="button" class="ispag-btn ispag-btn-red-outlined ispag-modal-close ispag-close-croix ispag-modal-upload__close" aria-label="<?php esc_attr_e('Close', 'ispag-crm'); ?>">&times;</button>
                </div>
                <div class="ispag-modal-upload__body">
                    <?php echo $this->render_dropzone($entityType, $entityId, 'ispag-upload-modal-dropzone'); ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}