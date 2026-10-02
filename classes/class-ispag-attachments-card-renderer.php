<?php
require_once __DIR__ . '/class-ispag-attachments-repository.php';
require_once __DIR__ . '/class-ispag-attachments-modal-renderer.php';

/**
 * Rend les pièces jointes sous deux formes :
 *  - render()          : carte compacte (3 docs + lien "voir tout" qui ouvre la sidebar)
 *  - render_doc_list()  : liste complète inline (utilisée dans l'onglet "Documents")
 *
 * Les deux wrappers portent data-view="card"|"list" pour que le JS de
 * rafraîchissement (ispag:refresh-attachments) sache quel rendu redemander
 * après un upload, même quand plusieurs instances existent sur la même page
 * pour la même entité (cf. page de détail projet : carte à droite + liste
 * dans l'onglet Documents, toutes deux avec le même data-entity-id).
 */
class ISPAG_Attachments_Card_Renderer {

    private ISPAG_Attachments_Repository $repository;

    public function __construct(ISPAG_Attachments_Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Carte « Pièces jointes » sans liste : la dropzone (type de document + chargement) est directement affichée,
     * sans passer par la modal. La liste complète reste disponible dans l'onglet Documents.
     * NB : pas de classe .ispag-docu-card ici, sinon le rafraîchissement d'après upload remplacerait cette carte par la liste.
     */
    public function render_upload_card(string $entityType, $entityId): string {
        global $wpdb;
        $modal = new ISPAG_Attachments_Modal_Renderer(new ISPAG_Attachments_Doc_Types_Repository($wpdb));

        ob_start();
        ?>
        <div class="ispag-card ispag-upload-card"
             data-entity-type="<?php echo esc_attr($entityType); ?>"
             data-entity-id="<?php echo esc_attr($entityId); ?>">
            <h5><?php _e('Attachments', 'ispag-crm'); ?></h5>
            <?php echo $modal->render_dropzone($entityType, $entityId); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render(string $entityType, $entityId, int $limit = 3): string {
        // $attachments = $this->repository->forEntity($entityType, $entityId, $limit);

        
        ob_start();
        ?>
        <div class="ispag-card ispag-docu-card"
             data-entity-type="<?php echo esc_attr($entityType); ?>"
             data-entity-id="<?php echo esc_attr($entityId); ?>">

            <div class="ispag-docu-card__header">
                <span type="button" class="ispag-docu-card__toggle" aria-expanded="true">
                    
                    <span class="ispag-docu-card__title"><?php _e('Attachments', 'ispag-crm'); ?></span>
                </span>

                <div class="ispag-docu-card__add">
                    <span
                            class="ispag-docu-card__add-btn" 
                            data-entity-type="<?php echo esc_attr($entityType); ?>"
                            data-entity-id="<?php echo esc_attr($entityId); ?>">
                            <?php _e('Add', 'ispag-crm'); ?>
                             <!-- <span class="ispag-docu-card__caret" aria-hidden="true">&#9662;</span> -->
                    </span>
                    <!-- menu déroulant (upload / lier un fichier existant) injecté en JS -->
                </div>
            </div>
            <?php echo $this->render_doc_list($entityType, $entityId, $limit); ?>
            
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Liste complète (sans limite par défaut), pour l'onglet "Documents".
     *
     * @param bool $bare Si true, ne génère PAS le wrapper .ispag-card.ispag-docu-card
     *                   (utilisé quand le template appelant fournit déjà ce wrapper,
     *                   comme dans l'onglet Documents de la page projet — dans ce cas,
     *                   ajoute toi-même data-view="list" sur CE wrapper pour que le
     *                   rafraîchissement AJAX cible le bon rendu).
     */
    public function render_doc_list(string $entityType, $entityId, int $limit = 3, bool $bare = false) {


        $attachments = $this->repository->forEntity($entityType, $entityId, $limit);

        // error_log('::::::::::::::::::::::: render_doc_list :::::::::::::::::::::::::::::::::::::: ' . print_r($attachments, true));


        ob_start();
        ?>
        <div class="ispag-docu-card__body">
            <?php if (empty($attachments)) : ?>
                <p class="ispag-docu-card__empty ispag-dropzone__field"><?php _e('No attachments to display', 'ispag-crm'); ?></p>
            <?php else : ?>
                <?php if ($bare) : ?>
                    <!-- ===== MODE TRI PAR ARTICLE ===== -->
                    <?php
                    // 1. Regrouper les pièces jointes par catégorie (Général ou Article)
                    $groupedAttachments = [
                        'general' => [],
                        'articles' => [],
                    ];

                    foreach ($attachments as $att) {
                        // Si l'attachement a un article_id (différent de 0 ou null), c'est un article
                        if (!empty($att->article_id) && $att->article_id > 0) {
                            $groupedAttachments['articles'][$att->article_id][] = $att;
                        } else {
                            $groupedAttachments['general'][] = $att;
                        }
                    }

                    // 2. Trier les articles par nom (si nécessaire)
                    $articleNames = [];
                    if (!empty($groupedAttachments['articles'])) {
                        foreach ($groupedAttachments['articles'] as $articleId => $articleAttachments) {
                            // $articleNames[$articleId] = html_entity_decode($this->get_article_name($articleId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $articleNames[$articleId] = html_entity_decode($this->get_article_name($articleId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            
                        }
                        // Trier les articles par nom
                        
                        uksort($groupedAttachments['articles'], function($a, $b) use ($articleNames) {
                            $nameA = $articleNames[$a] ?? '';
                            $nameB = $articleNames[$b] ?? '';

                            // Si l'extension intl (Collator) est disponible, on l'utilise pour un tri naturel parfait
                            if (class_exists('Collator')) {
                                $collator = new Collator('fr_FR');
                                return $collator->compare($nameA, $nameB);
                            }

                            // Solution de repli native pour les chaînes UTF-8
                            return mb_stricmp($nameA, $nameB);
                        });
                    }
                    ?>

                    <!-- Section pour les fichiers généraux -->
                    <?php if (!empty($groupedAttachments['general'])) : ?>
                        <p class="ispag-docu-card__subtitle"><?php _e('General files', 'ispag-crm'); ?></p>
                        <ul class="ispag-docu-card__list">
                            <?php foreach ($groupedAttachments['general'] as $att) : ?>
                                <?php echo $this->render_attachments($att, $bare); ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <!-- Section pour les fichiers par article -->
                    <?php if (!empty($groupedAttachments['articles'])) : ?>
                        <?php foreach ($groupedAttachments['articles'] as $articleId => $articleAttachments) : ?>
                            <h3 style="margin-top:0; font-size:1.8rem; border-bottom: 1px dashed transparent; cursor: pointer;">
                                <?php echo esc_html($articleNames[$articleId] ?? __('Unknown article', 'ispag-crm')); ?>
                            </h3>
                            
                            <ul class="ispag-docu-card__list">
                                <?php foreach ($articleAttachments as $att) : ?>
                                    <?php echo $this->render_attachments($att, $bare); ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php else : ?>
                    <!-- ===== MODE SANS TRI (ORDRE INVERSE DES DATES) ===== -->
                    <ul class="ispag-docu-card__list">
                        <?php foreach ($attachments as $att) : ?>
                            <?php echo $this->render_attachments($att, $bare); ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <a href="#"
                    class="ispag-docu-card__view-all"
                    data-entity-type="<?php echo esc_attr($entityType); ?>"
                    data-entity-id="<?php echo esc_attr($entityId); ?>">
                    <?php _e('See all attachments', 'ispag-crm'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_attachments($att = null, bool $bare = false){
        if(empty($att)) return;
        ob_start();
        ?>
        <li class="ispag-docu-card__item" data-media-id="<?php echo esc_attr($att->id); ?>">

            <?php
            /**
             * Aperçu réel du fichier plutôt qu'un pictogramme générique.
             * wp_get_attachment_image() :
             *  - renvoie une vraie vignette pour les images
             *  - renvoie une vignette de la 1ère page pour les PDF SI le
             *    serveur dispose d'Imagick + Ghostscript (sinon repli auto)
             *  - repli automatique ($icon = true) sur l'icône générique
             *    WordPress du mime-type pour tout le reste (doc, xls, ...)
             * Donc pas de cas à gérer nous-mêmes : la fonction gère déjà
             * le fallback proprement.
             *
             * IMPORTANT : c'est le conteneur lui-même (.ispag-docu-card__icon)
             * qui devient le lien, avec ses propres styles de mise en page
             * (taille fixe 38px, flex). Ne PAS réutiliser .ispag-docu-card__filename
             * pour envelopper l'image : cette classe est pensée pour du texte
             * (white-space:nowrap, pas de width/height propre), ce qui crée une
             * dépendance de taille circulaire avec l'img en width/height:100%
             * et fait s'effondrer la vignette à 0×0 (invisible).
             */
            ?>
            <a href="<?php echo esc_url($att->url); ?>"
                target="_blank"
                rel="noopener"
                tabindex="-1"
                aria-hidden="true"
                class="ispag-docu-card__icon ispag-docu-card__icon--<?php echo esc_attr($att->iconType()); ?>">
                <?php
                echo wp_get_attachment_image(
                    $att->id,
                    [40, 40],
                    true, // $icon : autorise le repli sur l'icône générique du mime-type
                    [
                        'class' => 'ispag-docu-card__thumb',
                        'alt'   => '',
                    ]
                );
                ?>
            </a>

            <div class="ispag-docu-card__meta">
                <a href="<?php echo esc_url($att->url); ?>"
                    target="_blank"
                    rel="noopener"
                    class="ispag-docu-card__filename">
                    <?php echo esc_html($att->title); ?>
                </a>
                <span class="ispag-docu-card__date"><?php echo esc_html($att->dateFormatted()); ?></span>
            </div>

            <?php
            if(!empty($att->classCss) && $bare):
            ?>
            <div
                class="ispag-status-badge badge-<?php echo esc_attr($att->classCss); ?>">
                    <?php echo esc_html__($att->label, 'ispag-crm'); ?>
            </div>
            
            <?php if (current_user_can('manage_order')) : // extraction de données : réservée aux gestionnaires ?>
            <span 
                    class="ispag-btn ispag-btn-grey-outlined extract-doc-btn"
                    data-doc-id="<?php echo esc_attr($att->id); ?>"
                    data-deal-id="<?php echo esc_attr($att->hubspot_deal_id); ?>"
                    data-purchase-id="<?php echo esc_attr($att->purchase_order); ?>"
                    data-doc-type="<?php echo esc_attr($att->classCss); ?>"
                    data-tank-id="<?php echo esc_attr($att->article_id); ?>"
                    data-ajax-action="<?php echo esc_attr($att->ajax_action); ?>">
                <span class="dashicons dashicons-analytics"></span>
            </span>
            <?php endif; ?>
            <?php
            endif;
            ?>
            <?php if (function_exists('ispag_user_can_delete_attachment') ? ispag_user_can_delete_attachment($att->id) : current_user_can('manage_order')) : ?>
            <span
                    class="ispag-btn ispag-btn-grey-outlined ispag-docu-card__remove"
                    data-media-id="<?php echo esc_attr($att->id); ?>"
                    title="Retirer">
                &times;
            </span>
            <?php endif; ?>
        </li>
        <?php
        return ob_get_clean();
    }

    /**
     * Récupère le nom d'un article depuis son ID.
     */
    private function get_article_name(int $articleId): string {
        
        global $wpdb;
        $table = $wpdb->prefix . 'achats_details_commande';

        $sql = $wpdb->prepare(
            "SELECT Id, Article, Type
            FROM {$table}
            WHERE Id = %d
            LIMIT 1",
            $articleId
        );
        

        $article = $wpdb->get_row($sql);

        if (!$article) {
            return __('Unknown article', 'ispag-crm');
        }

        // Générer le nom de l'article en fonction de son type
        switch ($article->Type) {
            case 1:
                return (new ISPAG_Tank_Description())->generate_tank_title(null, $article->Id, null);
            case 2:
                return (new ISPAG_Tank_Insulation())->get_insulation_title(null, $article->Id);
            case 3:
                return (new ISPAG_Tank_Welding())->get_welding_title(null, $article->Id);
            case 5:
                return (new ISPAG_Plate_Heat_exchanger_Designer())->generate_doc_title_exchanger(null, $article->Id);
                
            default:
                return $article->Article;
        }
    }
}