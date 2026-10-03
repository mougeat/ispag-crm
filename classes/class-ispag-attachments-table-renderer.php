<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-ispag-attachments-repository.php';

class ISPAG_Attachments_Table_Renderer {

    private ISPAG_Attachments_Repository $repository;

    public function __construct(ISPAG_Attachments_Repository $repository) {
        $this->repository = $repository;
    }

    public function render_table(string $entityType, $entityId): string {
        // Récupère TOUTES les pièces jointes pour l'entité (sans limite)
        $attachments = $this->repository->forEntity($entityType, $entityId, -1);

        ob_start();
        ?>
        <div class="ispag-attachments-table-wrapper">
            <table class="ispag-data-table ispag-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="ispag-select-all"></th>
                        <th><?php _e('Name', 'ispag-crm'); ?></th>
                        <th><?php _e('Type', 'ispag-crm'); ?></th>
                        <th><?php _e('Download date', 'ispag-crm'); ?></th>
                        <th><?php _e('Source', 'ispag-crm'); ?></th>
                        <th><?php _e('Size', 'ispag-crm'); ?></th>
                        <th><?php _e('Actions', 'ispag-crm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attachments)) : ?>
                        <tr>
                            <td colspan="6" class="ispag-empty-row"><?php _e('No attachments found.', 'ispag-crm'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($attachments as $att) :
                            // ClassCss vient de achats_historique (projet/purchase) et est vide
                            // pour les docs issus des notes (contact/company) — cf. repository.
                            $rowClass = trim($att->classCss ?? '');
                        ?>
                            <tr<?php echo $rowClass ? ' class="' . esc_attr($rowClass) . '"' : ''; ?> data-media-id="<?php echo esc_attr($att->id); ?>">
                                <th class="check-column"><input type="checkbox" name="media_ids[]" value="<?php echo esc_attr($att->id); ?>"></th>
                                <td>
                                    <a href="<?php echo esc_url($att->url); ?>" target="_blank" rel="noopener" class="ispag-table-link">
                                        <?php echo esc_html($att->title); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html(strtolower($att->iconType())); ?></td>
                                <td><?php echo esc_html($att->dateFormatted()); ?></td>
                                <td><?php echo esc_html($att->source ?: __('Unknown', 'ispag-crm')); ?></td>
                                <td><?php echo esc_html($att->fileSize ?: '-'); ?></td>
                                <td>
                                    <?php if (current_user_can('manage_order')) : // extraction de données : réservée aux gestionnaires ?>
                                    <span 
                                            class="ispag-btn ispag-btn-grey-outlined extract-doc-btn"
                                            data-doc-id="<?php echo esc_attr($att->id); ?>"
                                            data-deal-id="<?php echo esc_attr($att->hubspot_deal_id); ?>"
                                            data-purchase-id="<?php echo esc_attr($att->purchase_order); ?>"
                                            data-doc-type="<?php echo esc_attr($att->classCss); ?>"
                                            data-tank-id="<?php echo esc_attr($att->Historique); ?>"
                                            data-ajax-action="<?php echo esc_attr($att->ajax_action); ?>">
                                        <span class="dashicons dashicons-analytics"></span>
                                    </span>
                                    <?php endif; ?>
                                    <?php if (function_exists('ispag_user_can_delete_attachment') ? ispag_user_can_delete_attachment($att->id) : current_user_can('manage_order')) : ?>
                                    <span
                                            class="ispag-btn ispag-btn-grey-outlined ispag-docu-card__remove"
                                            data-media-id="<?php echo esc_attr($att->id); ?>"
                                            title="Retirer">
                                        &times;
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
