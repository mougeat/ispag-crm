<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-ispag-attachment.php';

/**
 * Récupère les documents (pièces jointes) liés à un projet/deal, un purchase (bon d'achat),
 * un contact ou une entreprise, depuis :
 *  - achats_historique.IdMedia   (projet / purchase) — inclut ClassCss
 *  - ispag_contact_notes.media_ids (tous types, listes CSV) — pas de ClassCss
 */
class ISPAG_Attachments_Repository {

    /** @var wpdb */
    private $wpdb;

    /** cache local pour éviter de refetch un même media_id dans la même requête HTTP */
    private $mediaCache = [];

    public function __construct($wpdb) {
        $this->wpdb = $wpdb;
    }

    public function forDeal($dealId, int $limit = 3): array {
        $fromAchats = $this->fromAchatsHistorique('hubspot_deal_id', $dealId);
        $fromNotes  = $this->fromContactNotes('deal_id', $dealId);
        return $this->mergeSortLimit([$fromAchats, $fromNotes], $limit);
    }

    /** Projet = même source que Deal dans ce schéma (hubspot_deal_id) */
    public function forProject($projectId, int $limit = 3): array {
        return $this->forDeal($projectId, $limit);
    }

    public function forPurchase($purchaseOrder, int $limit = 3): array {
        $fromAchats = $this->fromAchatsHistorique('purchase_order', $purchaseOrder);
        return $this->mergeSortLimit([$fromAchats], $limit);
    }

    public function forContact($contactId, int $limit = 3): array {
        $fromNotes = $this->fromContactNotes('contact_id', $contactId);
        return $this->mergeSortLimit([$fromNotes], $limit);
    }

    public function forCompany($companyId, int $limit = 3): array {
        $fromNotes = $this->fromContactNotes('company_id', $companyId);
        return $this->mergeSortLimit([$fromNotes], $limit);
    }

    /**
     * Dispatch générique, pratique pour le renderer.
     * $limit = -1 signifie "pas de limite" (utilisé par le rendu tableau complet).
     */
    public function forEntity(string $entityType, $entityId, int $limit = 3): array {
        switch ($entityType) {
            case 'deal':
            case 'project':
                return $this->forDeal($entityId, $limit);
            case 'purchase':
                return $this->forPurchase($entityId, $limit);
            case 'contact':
                return $this->forContact($entityId, $limit);
            case 'company':
                return $this->forCompany($entityId, $limit);
            default:
                return [];
        }
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    private function fromAchatsHistorique(string $column, $value): array {
        $allowed = ['hubspot_deal_id', 'purchase_order'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }

        $source = $column === 'hubspot_deal_id'
            ? __('Project', 'ispag-crm')
            : __('Purchase', 'ispag-crm');

        $table = $this->wpdb->prefix . 'achats_historique';
        $table_doc_type = $this->wpdb->prefix . 'achats_doc_types';

        $sql = $this->wpdb->prepare(
            "SELECT tm.IdMedia, tm.Date, tm.dateReadable, tm.Historique, tm.ClassCss, tm.hubspot_deal_id, tm.purchase_order, tdt.label, tdt.ajax_action
            FROM {$table} tm
            LEFT JOIN {$table_doc_type} tdt
                ON tdt.slug = tm.ClassCss
            WHERE {$column} = %d AND tm.IdMedia > 0
            ORDER BY tm.Date ASC",
            $value
        );

        $rows = $this->wpdb->get_results($sql);
        if (!$rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $article_id = is_numeric($row->Historique) ? (int) $row->Historique : null;

            $att = $this->buildFromMediaId(
                (int) $row->IdMedia,
                (int) $row->Date,
                $row->Historique,
                $source,
                (string) ($row->ClassCss ?? ''),
                $article_id,
                $row->hubspot_deal_id,
                $row->purchase_order,
                $row->label,
                $row->ajax_action,
                (string) ($row->dateReadable ?? '')
            );
            if ($att) {
                $out[] = $att;
            }
        }
        return $out;
    }

    private function fromContactNotes(string $column, $value): array {
        $allowed = ['contact_id', 'company_id', 'deal_id'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }

        $table = $this->wpdb->prefix . 'ispag_contact_notes';

        $sql = $this->wpdb->prepare(
            "SELECT media_ids, created_at, title
             FROM {$table}
             WHERE FIND_IN_SET(%d, {$column}) AND media_ids IS NOT NULL AND media_ids != ''
             ORDER BY created_at ASC",
            $value
        );

        $rows = $this->wpdb->get_results($sql);
        if (!$rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $timestamp = strtotime($row->created_at) ?: time();
            $mediaIds  = array_filter(array_map('intval', explode(',', $row->media_ids)));

            foreach ($mediaIds as $mediaId) {
                // Pas de ClassCss pour les docs issus des notes (le champ n'existe pas sur cette table)
                $att = $this->buildFromMediaId($mediaId, $timestamp, $row->title, __('Note', 'ispag-crm'), '');
                if ($att) {
                    $out[] = $att;
                }
            }
        }
        return $out;
    }

    private function buildFromMediaId(
        int $mediaId,
        int $timestamp,
        string $fallbackLabel,
        string $source,
        string $classCss = '',
        ?int $article_id = null,
        ?int $hubspot_deal_id = null,
        ?int $purchase_order = null,
        string $label = '',
        string $ajax_action = '',
        string $dateReadable = ''
    ): ?ISPAG_Attachment {
        if ($mediaId <= 0) {
            return null;
        }

        if (isset($this->mediaCache[$mediaId])) {
            $cached = $this->mediaCache[$mediaId];
            if ($cached === null) {
                return null;
            }
            return new ISPAG_Attachment(
                $cached->id,
                $cached->title,
                $cached->url,
                $cached->mime,
                $timestamp,
                $source,
                $classCss,
                $cached->fileSize,
                $article_id,
                $hubspot_deal_id,
                $purchase_order,
                $label,
                $ajax_action,
                $dateReadable
            );
        }

        $url = wp_get_attachment_url($mediaId);
        if (!$url) {
            $this->mediaCache[$mediaId] = null;
            return null;
        }

        $mime  = get_post_mime_type($mediaId) ?: 'application/octet-stream';
        $title = get_the_title($mediaId) ?: $fallbackLabel;

        $fileSize = '';
        $filePath = get_attached_file($mediaId);
        if ($filePath && file_exists($filePath)) {
            $fileSize = size_format(filesize($filePath));
        }

        $att = new ISPAG_Attachment(
            $mediaId, $title, $url, $mime, $timestamp, $source, $classCss, $fileSize,
            $article_id, $hubspot_deal_id, $purchase_order, $label, $ajax_action, $dateReadable
        );
        $this->mediaCache[$mediaId] = $att;

        return $att;
    }

    /**
     * Fusionne plusieurs listes, dédoublonne par media id (garde l'occurrence la plus récente),
     * trie par date décroissante et limite. $limit = -1 → pas de limite.
     */
    private function mergeSortLimit(array $lists, int $limit): array {
        $merged = array_merge(...$lists);

        $byId = [];
        foreach ($merged as $att) {
            /** @var ISPAG_Attachment $att */
            if (!isset($byId[$att->id]) || $att->timestamp > $byId[$att->id]->timestamp) {
                $byId[$att->id] = $att;
            }
        }

        $unique = array_values($byId);
        usort($unique, fn(ISPAG_Attachment $a, ISPAG_Attachment $b) => $a->timestamp <=> $b->timestamp);

        if ($limit < 0) {
            return $unique;
        }

        return array_slice($unique, 0, $limit);
    }
}
