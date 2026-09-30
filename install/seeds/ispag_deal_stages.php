<?php
/**
 * Étapes des deals (export production 30.09.2026)
 * Appliqué seulement si la table est vide (voir seed() de l'installateur).
 */
defined('ABSPATH') || exit;

return [
    ['id' => 1, 'stage_key' => 'submission_received', 'stage_label' => 'Submission Received', 'stage_order' => 5, 'probability' => '3.00', 'is_closed' => 0, 'stage_type' => 'open', 'stage_color' => '#BDC3C7', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 3, 'stage_key' => 'proposal_sent', 'stage_label' => 'Proposal Sent', 'stage_order' => 20, 'probability' => '3.00', 'is_closed' => 0, 'stage_type' => 'open', 'stage_color' => '#2ECC71', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 4, 'stage_key' => 'follow_up_negotiation', 'stage_label' => 'Follow-up & Negotiation', 'stage_order' => 35, 'probability' => '50.00', 'is_closed' => 0, 'stage_type' => 'open', 'stage_color' => '#3498DB', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 5, 'stage_key' => 'awaiting_adjudication', 'stage_label' => 'Awaiting order', 'stage_order' => 40, 'probability' => '85.00', 'is_closed' => 0, 'stage_type' => 'open', 'stage_color' => '#E67E22', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 6, 'stage_key' => 'closed_won', 'stage_label' => 'Closed Won', 'stage_order' => 55, 'probability' => '100.00', 'is_closed' => 1, 'stage_type' => 'won', 'stage_color' => '#9dafa5', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 7, 'stage_key' => 'closed_lost', 'stage_label' => 'Closed Lost', 'stage_order' => 60, 'probability' => '0.00', 'is_closed' => 1, 'stage_type' => 'lost', 'stage_color' => '#E74C3C', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 8, 'stage_key' => 'open_won', 'stage_label' => 'In accomplishement', 'stage_order' => 50, 'probability' => '100.00', 'is_closed' => 0, 'stage_type' => 'open', 'stage_color' => '#9dafa5', 'date_added' => '2025-12-08 15:23:06'],
    ['id' => 9, 'stage_key' => 'awarder_no_contact', 'stage_label' => 'Awarded / No Contact', 'stage_order' => 30, 'probability' => '25.00', 'is_closed' => 0, 'stage_type' => 'open', 'stage_color' => '#FF8F00', 'date_added' => '2026-01-16 05:23:49'],
];
