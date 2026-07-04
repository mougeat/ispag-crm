<?php
// Fichier : includes/crm/class-ispag-cron-weekly-deal-report.php

if ( ! class_exists( 'ISPAG_Cron_Weekly_Deal_Report' ) ) :

class ISPAG_Cron_Weekly_Deal_Report {

    private $wpdb;
    private $table_deal;
    private $table_company;
    private $table_status_rel = 'wor9711_ispag_deals_stages';
    private $table_status_ref = 'wor9711_ispag_deal_stages';
    
    const CRON_HOOK = 'ispag_weekly_closing_deals_report';

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        $this->table_deal    = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $this->table_company = $wpdb->prefix . 'ispag_companies';

        add_action( self::CRON_HOOK, array( $this, 'send_weekly_closing_deals_report' ) );
    }

    public function send_weekly_closing_deals_report() {
        $this->log("--- DÉBUT RAPPORT HEBDOMADAIRE ---");
        
        $start_of_week = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end_of_week   = date('Y-m-d 23:59:59', strtotime('sunday this week'));

        // SQL avec correction du nom de colonne : stage_color
        $sql = $this->wpdb->prepare( "
            SELECT 
                t1.id AS internal_id,
                t1.project_name AS deal_name, 
                t1.closing_date AS close_date,
                t1.deal_owner AS owner_id,
                t2.display_name AS owner_name,
                t2.user_email AS owner_email,
                t3.company_name AS company_name,
                ref.stage_label AS status_label,
                ref.stage_color AS status_color
            FROM {$this->table_deal} AS t1
            LEFT JOIN {$this->wpdb->users} AS t2 ON t1.deal_owner = t2.ID
            LEFT JOIN {$this->table_company} AS t3 ON t1.associated_company_id = t3.viag_id
            INNER JOIN {$this->table_status_rel} AS rel ON (t1.deal_group_ref COLLATE utf8mb4_unicode_ci) = rel.deal_group_ref
            INNER JOIN {$this->table_status_ref} AS ref ON (rel.current_stage_key COLLATE utf8mb4_unicode_ci) = ref.stage_key
            WHERE 
                t1.closing_date BETWEEN %s AND %s
                AND t1.deal_owner != 0
                AND rel.current_stage_key NOT IN ('closed_won', 'closed_lost')
            ORDER BY t1.deal_owner, t1.closing_date ASC
        ", $start_of_week, $end_of_week );

        $deals = $this->wpdb->get_results( $sql );

        if ( empty( $deals ) ) {
            $this->log("Aucun deal trouvé.");
            return;
        }

        $reports = [];
        foreach ( $deals as $deal ) {
            $owner_id = $deal->owner_id;
            if ( ! isset( $reports[$owner_id] ) ) {
                $reports[$owner_id] = [
                    'info' => [
                        'name'  => (string)($deal->owner_name ?? 'Collaborateur'),
                        'email' => (string)($deal->owner_email ?? '')
                    ],
                    'projects' => []
                ];
            }

            $reports[$owner_id]['projects'][] = [
                'name'    => (string)($deal->deal_name ?? 'Sans nom'),
                'link'    => trailingslashit( (string)home_url( "/deal/{$deal->internal_id}/" ) ),
                'company' => (string)($deal->company_name ?: 'N/C'),
                'date'    => date_i18n('d/m/Y', strtotime($deal->close_date)),
                'status'  => (string)($deal->status_label ?: 'En cours'),
                'color'   => (string)($deal->status_color ?: '#BDC3C7')
            ];
        }

        $count_sent = 0;
        foreach ( $reports as $owner_id => $data ) {
            if ( empty($data['info']['email']) ) continue;

            $to      = $data['info']['email'];
            $subject = "ISPAG CRM : " . count($data['projects']) . " projets cette semaine";
            $message = $this->get_email_html_content($data);
            $headers = array('Content-Type: text/html; charset=UTF-8');

            if ( wp_mail($to, $subject, $message, $headers) ) {
                $count_sent++;
                $this->log("Email envoyé à {$data['info']['name']}");
            }
        }
        $this->log("--- FIN RAPPORT : $count_sent notifiés ---");
    }

    private function get_email_html_content($data) {
        $html = '<div style="font-family: Arial, sans-serif; color: #333; max-width: 750px; padding: 20px; border: 1px solid #eee;">';
        $html .= '<h2 style="color: #2271b1;">Bonjour ' . esc_html($data['info']['name']) . ',</h2>';
        $html .= '<p>Voici vos projets arrivant à échéance cette semaine :</p>';
        $html .= '<table border="0" cellpadding="10" cellspacing="0" style="width:100%; border-collapse: collapse; margin-top:15px;">';
        $html .= '<tr style="background:#f8f9fa; text-align:left; border-bottom:2px solid #eee;"><th>Projet</th><th>Client</th><th style="text-align:center;">Statut</th><th>Échéance</th></tr>';
        
        foreach ($data['projects'] as $p) {
            $html .= '<tr style="border-bottom: 1px solid #eee;">';
            $html .= '<td><a href="' . esc_url($p['link']) . '" style="color:#2271b1; text-decoration:none; font-weight:bold;">' . esc_html($p['name']) . '</a></td>';
            $html .= '<td>' . esc_html($p['company']) . '</td>';
            $html .= '<td style="text-align:center;"><span style="background-color:' . esc_attr($p['color']) . '; color: #ffffff; padding: 4px 10px; border-radius: 20px; font-size: 0.8em; font-weight: bold; text-shadow: 1px 1px 1px rgba(0,0,0,0.2); white-space: nowrap;">' . esc_html($p['status']) . '</span></td>';
            $html .= '<td style="white-space: nowrap;">' . esc_html($p['date']) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        $html .= '</div>';
        return $html;
    }

    private function log($message) {
        $log_file = WP_CONTENT_DIR . '/ispag_cron.log';
        // file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] [WeeklyReport] $message\n", FILE_APPEND);
    }
}
endif;