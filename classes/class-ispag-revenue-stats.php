<?php

class ISPAG_Revenue_Stats {
    private $wpdb;
    private $table_deals;
    private $table_stages;
    private $table_config_stages;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        // Noms exacts de tes tables
        $this->table_deals  = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $this->table_stages = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
        $this->table_config_stages = ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES;
    }

    public function get_revenue_data($id, $type = 'company') {
        if ($type === 'company') {
            $where_clause = $this->wpdb->prepare("d.associated_company_id = %d", $id);
        } else {
            $where_clause = $this->wpdb->prepare("FIND_IN_SET(%d, d.associated_contact_ids)", $id);
        }

        $query = "
            SELECT 
                COUNT(t.id) as total_deals,
                -- Deals Ouverts (En cours de négociation)
                SUM(CASE WHEN t.current_stage_key IN ('proposal_sent', 'follow_up_negotiation', 'awaiting_adjudication') THEN 1 ELSE 0 END) as open_deals_count,
                
                -- Commandes Actives (Gagnées)
                SUM(CASE WHEN t.current_stage_key = 'open_won' THEN 1 ELSE 0 END) as active_orders_count,
                
                -- Nouveaux compteurs basés sur process_type (Documents)
                SUM(CASE WHEN t.process_type LIKE '%Facture%' THEN 1 ELSE 0 END) as invoice_count,
                SUM(CASE WHEN t.process_type LIKE '%Commande%' THEN 1 ELSE 0 END) as order_document_count,
                SUM(CASE WHEN t.process_type LIKE '%Offre%' THEN 1 ELSE 0 END) as quotes_count,
                
                -- Calcul pondéré (Revenue prévisionnel)
                -- On ne calcule le revenu que pour les deals 'Ouverts' définis ci-dessus
                SUM(
                    CASE WHEN t.current_stage_key IN ('proposal_sent', 'follow_up_negotiation', 'awaiting_adjudication') THEN 
                        (CAST(NULLIF(t.total_excl_vat, '') AS DECIMAL(10,2)) * (COALESCE(t.probability, 0) / 100))
                    ELSE 0 END
                ) as weighted_revenue
            FROM (
                SELECT 
                    d.id, 
                    d.total_excl_vat, 
                    d.process_type,
                    s.current_stage_key,
                    conf.probability
                FROM {$this->table_deals} d
                LEFT JOIN {$this->table_stages} s 
                    ON d.deal_group_ref = s.deal_group_ref COLLATE utf8mb4_unicode_520_ci
                LEFT JOIN {$this->table_config_stages} conf 
                    ON s.current_stage_key = conf.stage_key COLLATE utf8mb4_unicode_520_ci
                WHERE {$where_clause}
                -- Groupement par référence de groupe pour éviter les doublons de lignes d'un même deal
                GROUP BY d.deal_group_ref
            ) as t
        ";

        // error_log('[DEBUG] get_revenue_data : ' . $query);

        return $this->wpdb->get_row($query);
    }

    public function render_perspective_cards($id, $type = 'company') {
        $stats = $this->get_revenue_data($id, $type);
        
        if (!$stats || $stats->total_deals == 0) {
            return '<div class="ispag-no-data">Aucune perspective financière.</div>';
        }

        ob_start(); ?>
        <div class="ispag-revenue-perspective">
            <?php if( $type == 'contact'){
            ?>
            <div class="ispag-stat-card">
                <span class="ispag-stat-label">
                    <?php _e( 'Health score', 'ispag-crm' ); ?>
                    <span class="ispag-info-icon" style="cursor:help; font-size: 0.8em; margin-left: 5px;" title="">ⓘ</span>
                </span>
                <span class="ispag-stat-value" id="health_score"></span>
                
            </div>
            <?php
            }
            ?>

            <div class="ispag-stat-card">
                <span class="ispag-stat-label"><?php _e( 'Total quotes', 'ispag-crm' ); ?></span>
                <span class="ispag-stat-value"><?php echo intval($stats->quotes_count); ?></span>
            </div>

            <div class="ispag-stat-card">
                <span class="ispag-stat-label"><?php _e( 'Open Quotes', 'ispag-crm' ); ?></span>
                <span class="ispag-stat-value"><?php echo intval($stats->open_deals_count); ?></span>
            </div>

            <div class="ispag-stat-card">
                <span class="ispag-stat-label"><?php _e( 'Open orders', 'ispag-crm' ); ?></span>
                <span class="ispag-stat-value"><?php echo intval($stats->order_document_count); ?></span>
            </div>

            <div class="ispag-stat-card highlight-blue">
                <span class="ispag-stat-label"><?php _e( 'Total invoices', 'ispag-crm' ); ?></span>
                <span class="ispag-stat-value"><?php echo intval($stats->invoice_count); ?></span>
            </div>

            <div class="ispag-stat-card highlight">
                <span class="ispag-stat-label"><?php _e( 'Weighted Pipeline', 'ispag-crm' ); ?></span>
                <span class="ispag-stat-value">
                    <?php echo number_format($stats->weighted_revenue, 0, '.', "'"); ?> 
                    <small>CHF</small>
                </span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}