<?php
class ISPAG_Attachment {

    public $id;
    public $title;
    public $url;
    public $mime;
    public $timestamp;
    public $source;
    public $classCss;
    public ?int $article_id;
    public ?int $hubspot_deal_id;
    public ?int $purchase_order;
    public string $label;
    public string $ajax_action;
    public $fileSize;

    /**
     * @var string Date lisible.
     * Provient de achats_historique.dateReadable (DATETIME, ex: "2025-01-03 14:22:00")
     * ou, à défaut (documents issus des notes), dérivée du timestamp/created_at.
     */
    public $dateReadable;

    public function __construct(
        int $id,
        string $title,
        string $url,
        string $mime,
        int $timestamp,
        string $source = '',
        string $classCss = '',
        string $fileSize = '',
        ?int $article_id = null,
        ?int $hubspot_deal_id = null,
        ?int $purchase_order = null,
        string $label = '',
        string $ajax_action = '',
        string $dateReadable = ''
    ) {
        $this->id        = $id;
        $this->title     = $title ?: 'Document sans nom';
        $this->url       = $url;
        $this->mime      = $mime;
        $this->timestamp = $timestamp;
        $this->source    = $source;
        $this->classCss  = $classCss;
        $this->fileSize  = $fileSize;
        $this->article_id = $article_id;
        $this->hubspot_deal_id = $hubspot_deal_id;
        $this->purchase_order = $purchase_order;
        $this->label = $label;
        $this->ajax_action = $ajax_action;

        // Si la source ne fournit pas dateReadable (ex: contact_notes), on dérive du timestamp
        $this->dateReadable = $dateReadable !== ''
            ? $dateReadable
            : date_i18n('Y-m-d H:i:s', $timestamp);
    }

    public function iconType(): string {
        if (strpos($this->mime, 'pdf') !== false) return 'pdf';
        if (strpos($this->mime, 'image') !== false) return 'image';
        if (strpos($this->mime, 'word') !== false || strpos($this->mime, 'msword') !== false) return 'doc';
        if (strpos($this->mime, 'sheet') !== false || strpos($this->mime, 'excel') !== false) return 'xls';
        return 'file';
    }

    public function dateFormatted(string $format = 'd/m/Y'): string {
        return date_i18n($format, $this->timestamp);
    }

    /** Formate dateReadable (DATETIME) pour l'affichage, ex: '12/03/2025 14:22' */
    public function dateReadableFormatted(string $format = 'd/m/Y H:i'): string {
        $ts = strtotime($this->dateReadable) ?: $this->timestamp;
        return date_i18n($format, $ts);
    }
}