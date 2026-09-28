<?php
/**
 * Accès à la table achats_doc_types (types de documents disponibles au chargement).
 * Gère les types de documents généraux et ceux liés aux articles, avec regroupement par optgroup.
 */
class ISPAG_Attachments_Doc_Types_Repository {

    /** @var wpdb */
    private $wpdb;

    private string $table;
    private string $article_table;

    public function __construct($wpdb) {
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'achats_doc_types';
        $this->article_table = $wpdb->prefix . 'achats_details_commande';
    }

    public function getGeneralTypes(bool $includeRestricted = false, $entityType = null, $entityId = null): array {
        $restriction = $includeRestricted ? '' : ' AND restricted = 0';
        $deal_id = $entityId;

        // 1. Récupérer les types de documents généraux (for_article_type = 0)
        $sqlGeneral = "SELECT id, slug, label, ajax_action, badge_class, sort_order, restricted, for_article_type
                       FROM {$this->table}
                       WHERE for_article_type = 0 {$restriction}
                       ORDER BY sort_order ASC";

        $generalTypes = $this->wpdb->get_results($sqlGeneral);
        foreach ($generalTypes as $row) {
            $row->article_id = 0; // <-- Valeur par défaut pour le général
        }

        // 2. Récupérer les types de documents liés aux articles (for_article_type = 1)
        $articleTypes = $this->get_article_doc_list($includeRestricted, $deal_id);

        // 3. Fusionner les résultats
        $allTypes = array_merge($generalTypes ?: [], $articleTypes ?: []);

        return $allTypes ?: [];
    }

    private function get_project_main_article($deal_id = null) {
        if (empty($deal_id)) {
            return [];
        }

        $exclude_type = [2, 3, 13, 200, 500, 8];

        // Convertir le tableau en une chaîne de valeurs pour SQL
        $exclude_type_placeholders = implode(',', array_fill(0, count($exclude_type), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT Id, Groupe, Article, Type
            FROM {$this->article_table}
            WHERE hubspot_deal_id = %d
            AND Type NOT IN ({$exclude_type_placeholders})
            ORDER BY Groupe ASC",
            array_merge([$deal_id], $exclude_type)
        );

        return $this->wpdb->get_results($sql) ?: [];
    }

    private function get_article_doc_list(bool $includeRestricted = false, $deal_id = null): array {
        if (empty($deal_id)) {
            return [];
        }

        $restriction = $includeRestricted ? '' : ' AND restricted = 0';
        $articleList = $this->get_project_main_article($deal_id);
        $articleTypes = [];

        foreach ($articleList as $article) {
            // Générer dynamiquement le titre de l'article en fonction de son type
            switch ($article->Type) {
                case 1:
                    $articleTitle = (new ISPAG_Tank_Description())->generate_tank_title(null, $article->Id, null);
                    break;
                case 2:
                    $articleTitle = (new ISPAG_Tank_Insulation())->get_insulation_title(null, $article->Id);
                    break;
                case 3:
                    $articleTitle = (new ISPAG_Plate_Heat_exchanger_Designer())->generate_title_exchanger(null, $article->Id);
                    break;
                case 5:
                    $articleTitle = (new ISPAG_Tank_Welding())->get_welding_title(null, $article->Id);
                    break;
                default:
                    $articleTitle = $article->Article; // Cas par défaut si le type n'est pas reconnu
            }

            // Récupérer les types de documents pour cet article
            $sql = $this->wpdb->prepare(
                "SELECT id, slug, label, ajax_action, badge_class, sort_order, restricted, for_article_type
                FROM {$this->table}
                WHERE for_article_type = 1 {$restriction}
                ORDER BY sort_order ASC"
            );

            $rows = $this->wpdb->get_results($sql);

            // Ajouter un marqueur pour le regroupement (optgroup)
            foreach ($rows as $row) {
                // Créer une copie ou cloner l'objet pour éviter de partager le même article_id pour toutes les lignes
                $rowCopy = clone $row;
                $rowCopy->article_id = (int) $article->Id; // <-- Ajout de l'ID de l'article
                $rowCopy->optgroup = sprintf(
                    "%s - %s",
                    esc_html($article->Groupe),
                    esc_html(stripslashes($articleTitle))
                );
                $articleTypes[] = $rowCopy;
            }
        }

        return $articleTypes;
    }

    public function findBySlug(string $slug): ?object {
        

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM  {$this->table} WHERE slug = %s", $slug)
        );

        return $row ?: null;
    }
}