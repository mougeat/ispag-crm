<?php
/**
 * Phases du cycle de vie (export production 30.09.2026)
 * Appliqué seulement si la table est vide (voir seed() de l'installateur).
 */
defined('ABSPATH') || exit;

return [
    ['Id' => 1, 'phase_key' => 'subscriber', 'phase_label' => 'Subscriber', 'phase_description' => 'A contact who has opted in to receive content but hasn’t been qualified as a lead yet.', 'bg_color' => '#cccccc', 'text_color' => '#333333', 'phase_order' => 10],
    ['Id' => 2, 'phase_key' => 'lead', 'phase_label' => 'Lead', 'phase_description' => 'A contact who has shown interest in ISPAG products but has no project or offer yet.', 'bg_color' => '#dd3333', 'text_color' => '#ffffff', 'phase_order' => 20],
    ['Id' => 3, 'phase_key' => 'Offre', 'phase_label' => 'Qualified Lead', 'phase_description' => 'A lead with a validated interest for which a specific offer (Offre) has been made.', 'bg_color' => '#b71dad', 'text_color' => '#ffffff', 'phase_order' => 30],
    ['Id' => 4, 'phase_key' => 'Commande', 'phase_label' => 'Opportunity', 'phase_description' => 'A contact with a high probability of closing, linked to a formal order (Commande).', 'bg_color' => '#2a80ba', 'text_color' => '#ffffff', 'phase_order' => 40],
    ['Id' => 5, 'phase_key' => 'Facture', 'phase_label' => 'Customer', 'phase_description' => 'An active customer with at least one finalized invoice (Facture).', 'bg_color' => '#81d742', 'text_color' => '#000000', 'phase_order' => 50],
    ['Id' => 6, 'phase_key' => 'promoter', 'phase_label' => 'Promoter', 'phase_description' => 'A regular customer or partner who actively recommends ISPAG services.', 'bg_color' => '#dd9933', 'text_color' => '#333333', 'phase_order' => 60],
    ['Id' => 7, 'phase_key' => 'other', 'phase_label' => 'Other', 'phase_description' => 'Contacts that do not fit into standard business lifecycle categories.', 'bg_color' => '#cccccc', 'text_color' => '#333333', 'phase_order' => 90],
    ['Id' => 8, 'phase_key' => 'Situation', 'phase_label' => 'Customer', 'phase_description' => 'Specific status for interim invoicing or project-based billing situations.', 'bg_color' => '#81d742', 'text_color' => '#000000', 'phase_order' => 55],
];
