<?php
defined('ABSPATH') || exit;

/**
 * Déclare les tables de référence du CRM à l'éditeur ISPAG Settings → Reference tables
 * (ISPAG_Reference_Tables, plugin Project Manager). Sans ce plugin, le filtre n'est jamais appelé : rien ne change.
 *
 * Remplace l'ancien écran « Lead statuses » du menu CRM.
 */
class ISPAG_Crm_Reference_Tables {

    public function __construct() {
        add_filter('ispag_reference_tables', [$this, 'register']);
    }

    public function register($tables) {
        $tables['deal_stages'] = [
            'title' => 'Deal stages', 'table' => 'ispag_deal_stages', 'pk' => 'id', 'order' => 'stage_order',
            'description' => 'Columns of the deals pipeline. The key is stored on each deal and used by the program: it cannot be deleted while deals use it.',
            'columns' => [
                'stage_order' => ['label' => 'Order', 'type' => 'int', 'list' => true, 'default' => 100],
                'stage_key'   => ['label' => 'Key', 'type' => 'key', 'required' => true, 'unique' => true, 'list' => true],
                'stage_label' => ['label' => 'Label', 'type' => 'text', 'required' => true, 'list' => true],
                'probability' => ['label' => 'Probability (%)', 'type' => 'decimal', 'list' => true, 'default' => 0],
                'is_closed'   => ['label' => 'Closed stage', 'type' => 'bool', 'list' => true],
                'stage_type'  => ['label' => 'Type', 'type' => 'select', 'options' => ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost'], 'list' => true],
                'stage_color' => ['label' => 'Color', 'type' => 'color', 'list' => true, 'default' => '#cccccc'],
                'date_added'  => ['label' => 'Added on', 'type' => 'datetime'],
            ],
            'usage' => [['table' => 'ispag_deals_list', 'column' => 'current_stage_key', 'value' => 'stage_key']],
        ];

        $tables['lead_statuses'] = [
            'title' => 'Lead statuses', 'table' => 'ispag_lead_statuses', 'pk' => 'Id', 'order' => 'status_order',
            'description' => 'Statuses of a contact (new lead, contacted…). The key is stored on each contact.',
            'columns' => [
                'status_order'       => ['label' => 'Order', 'type' => 'int', 'list' => true],
                'status_key'         => ['label' => 'Key', 'type' => 'key', 'required' => true, 'unique' => true, 'list' => true],
                'status_label'       => ['label' => 'Label', 'type' => 'text', 'required' => true, 'list' => true],
                'status_description' => ['label' => 'Description', 'type' => 'textarea'],
                'bg_color'           => ['label' => 'Background color', 'type' => 'color', 'list' => true, 'default' => '#cccccc'],
                'text_color'         => ['label' => 'Text color', 'type' => 'color', 'list' => true, 'default' => '#333333'],
            ],
            'usage' => [['usermeta' => ISPAG_Crm_Contact_Constants::META_LEAD_STATUS, 'table' => '', 'column' => '', 'value' => 'status_key']],
        ];

        $tables['lifecycle_phases'] = [
            'title' => 'Lifecycle phases', 'table' => 'ispag_lifecycle_phases', 'pk' => 'Id', 'order' => 'phase_order',
            'description' => 'Lifecycle phases of a contact (lead, customer…). The key is stored on each contact.',
            'columns' => [
                'phase_order'       => ['label' => 'Order', 'type' => 'int', 'list' => true],
                'phase_key'         => ['label' => 'Key', 'type' => 'key', 'required' => true, 'unique' => true, 'list' => true],
                'phase_label'       => ['label' => 'Label', 'type' => 'text', 'required' => true, 'list' => true],
                'phase_description' => ['label' => 'Description', 'type' => 'textarea'],
                'bg_color'          => ['label' => 'Background color', 'type' => 'color', 'list' => true, 'default' => '#cccccc'],
                'text_color'        => ['label' => 'Text color', 'type' => 'color', 'list' => true, 'default' => '#333333'],
            ],
            'usage' => [['usermeta' => ISPAG_Crm_Contact_Constants::META_LIFECYCLE_PHASE, 'table' => '', 'column' => '', 'value' => 'phase_key']],
        ];

        $tables['rejection_reasons'] = [
            'title' => 'Rejection reasons', 'table' => 'ispag_rejection_reasons', 'pk' => 'id',
            'description' => 'Reasons offered when a deal is lost. The key is stored on the deal.',
            'columns' => [
                'reason_key' => ['label' => 'Key', 'type' => 'key', 'required' => true, 'unique' => true, 'list' => true],
                'label_en'   => ['label' => 'Label (English)', 'type' => 'text', 'required' => true, 'list' => true],
                'is_active'  => ['label' => 'Active', 'type' => 'bool', 'list' => true, 'default' => 1],
            ],
            'usage' => [['table' => 'ispag_deals_list', 'column' => 'reason_for_rejection', 'value' => 'reason_key']],
        ];

        return $tables;
    }
}
