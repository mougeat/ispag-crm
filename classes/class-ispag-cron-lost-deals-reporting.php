<?php

/**
 * Gère le reporting hebdomadaire des opportunités perdues (Closed Lost).
 */
class ISPAG_Cron_Lost_Deals_Reporting {

    const CRON_HOOK_LOST_REPORT = 'ispag_weekly_lost_deals_report';
    const LOG_FILE_NAME         = 'ispag_lost_deals_cron.log';
    const BREVO_TEMPLATE_ID     = 90; 

    public function __construct() {
        add_action( self::CRON_HOOK_LOST_REPORT, array( $this, 'process_lost_deals_report' ) );
    }

    public function process_lost_deals_report() {
        self::log("--- DEBUT DU RAPPORT HEBDOMADAIRE (DEALS PERDUS) ---");

        global $wpdb;
        $table_deal   = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $table_company = $wpdb->prefix . 'ispag_companies';
        $table_stages  = $wpdb->prefix . 'ispag_deals_stages'; 

        // 1. Définition de la période
        $start_date = date('Y-m-d H:i:s', strtotime('monday this week 00:00:00', current_time('timestamp')));
        $end_date   = date('Y-m-d H:i:s', strtotime('sunday this week 23:59:59', current_time('timestamp')));

        // 2. Requête SQL améliorée
        $sql = $wpdb->prepare( "
            SELECT 
                t1.Id AS deal_id, 
                t1.project_name, 
                t1.closing_date,
                t1.offer_num,
                t1.deal_owner AS owner_id,
                t1.reason_for_rejection,
                ts.current_stage_key, 
                ts.deal_group_ref,
                t2.display_name AS owner_name,
                t2.user_email AS owner_email,
                t3.company_name
            FROM {$table_deal} AS t1
            INNER JOIN {$table_stages} AS ts 
                ON t1.deal_group_ref COLLATE utf8mb4_general_ci = ts.deal_group_ref COLLATE utf8mb4_general_ci
            LEFT JOIN {$wpdb->users} AS t2 
                ON t1.deal_owner = t2.ID
            LEFT JOIN {$table_company} AS t3 
                ON t1.associated_company_id = t3.viag_id
            WHERE 
                ts.current_stage_key = 'closed_lost'
                AND t1.project_db_status != 2
                AND ts.last_updated BETWEEN %s AND %s
                AND t1.deal_owner != 0
            GROUP BY t1.deal_group_ref
            ORDER BY t1.deal_owner ASC
        ", $start_date, $end_date );

        self::log("SQL Query : " . $sql);

        $lost_deals = $wpdb->get_results( $sql );

        if ( empty( $lost_deals ) ) {
            self::log("Aucun deal perdu trouvé.");
            return;
        }

        // 3. Groupement des deals par responsable
        $grouped_data = [];
        foreach ( $lost_deals as $deal ) {
            $grouped_data[ $deal->owner_id ]['info'] = [
                'name'  => $deal->owner_name,
                'email' => $deal->owner_email
            ];
            $grouped_data[ $deal->owner_id ]['deals'][] = $deal;
        }

        // 4. Envoi des emails via Brevo
        $brevo_mailer = new ISPAG_Brevo_Mailer();
        $count_sent = 0;

        foreach ( $grouped_data as $owner_id => $data ) {
            $owner_email = $data['info']['email'];
            
            if ( ! is_email( $owner_email ) ) continue;

            $projects_list = [];
            foreach ( $data['deals'] as $d ) {
                $projects_list[] = [
                    'PROJECT_NAME'         => esc_html( $d->project_name ),
                    'PROJECT_COMPANY'      => esc_html( $d->company_name ),
                    'PROJECT_CLOSING_DATE' => date_i18n( get_option( 'date_format' ), strtotime( $d->closing_date ) ),
                    'PROJECT_OFFER_NUM'    => esc_html( $d->offer_num ),
                    'PROJECT_STATE'        => esc_html( $d->current_stage_key ),
                    'PROJECT_REASON'       => !empty($d->reason_for_rejection) ? esc_html( $d->reason_for_rejection ) : 'Non spécifiée', // La nouvelle variable
                    'PROJECT_LINK'         => trailingslashit( get_home_url() . '/deal/' . $d->deal_id )
                ];
            }

            $params = [
                'OWNER_NAME'   => $data['info']['name'],
                'NB_DEALS'     => count( $projects_list ),
                'PROJECTS'     => $projects_list,
                'PERIOD_START' => date_i18n( get_option( 'date_format' ), strtotime( $start_date ) ),
                'PERIOD_END'   => date_i18n( get_option( 'date_format' ), strtotime( $end_date ) )
            ];

            $sent = $brevo_mailer->send_template( $owner_email, self::BREVO_TEMPLATE_ID, $params );

            if ( $sent ) {
                $count_sent++;
                self::log("Rapport envoyé à $owner_email.");
            }
        }

        self::log("--- FIN D'EXECUTION : $count_sent rapports envoyés ---");
    }

    private static function log( $message ) {
        if ( ! defined( 'WP_CONTENT_DIR' ) ) return;
        $file = WP_CONTENT_DIR . '/' . self::LOG_FILE_NAME;
        $log_msg = "[" . date( 'Y-m-d H:i:s' ) . "] " . $message . "\n";
        // file_put_contents( $file, $log_msg, FILE_APPEND );
    }
}