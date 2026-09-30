<?php
/**
 * Statuts de lead (export production 30.09.2026)
 * Appliqué seulement si la table est vide (voir seed() de l'installateur).
 */
defined('ABSPATH') || exit;

return [
    ['Id' => 1, 'status_key' => 'new', 'status_label' => 'New', 'status_description' => 'Newly created lead with no recorded activity yet.', 'bg_color' => '#cccccc', 'text_color' => '#333333', 'status_order' => 10],
    ['Id' => 2, 'status_key' => 'in_progress', 'status_label' => 'In progress', 'status_description' => 'Active lead being handled but doesn’t fit into specific interaction categories.', 'bg_color' => '#81d742', 'text_color' => '#333333', 'status_order' => 30],
    ['Id' => 5, 'status_key' => 'unqualified', 'status_label' => 'Not qualified', 'status_description' => 'Lead does not meet the criteria or requirements for ISPAG services.', 'bg_color' => '#8224e3', 'text_color' => '#ffffff', 'status_order' => 110],
    ['Id' => 7, 'status_key' => 'open', 'status_label' => 'Open', 'status_description' => 'Lead is acknowledged and waiting for initial manual qualification.', 'bg_color' => '#cccccc', 'text_color' => '#333333', 'status_order' => 20],
    ['Id' => 8, 'status_key' => 'open_transaction', 'status_label' => 'Open transaction', 'status_description' => 'A formal offer or quote has been generated for this lead.', 'bg_color' => '#dd3333', 'text_color' => '#ffffff', 'status_order' => 40],
    ['Id' => 9, 'status_key' => 'attempted_contact', 'status_label' => 'Attempted contact', 'status_description' => 'Contact attempt made via Email or Phone, but no two-way conversation yet.', 'bg_color' => '#dd9933', 'text_color' => '#333333', 'status_order' => 60],
    ['Id' => 10, 'status_key' => 'connected', 'status_label' => 'Connected', 'status_description' => 'Successful engagement via Meeting, SMS, or WhatsApp.', 'bg_color' => '#81d742', 'text_color' => '#333333', 'status_order' => 70],
    ['Id' => 11, 'status_key' => 'bad_timing', 'status_label' => 'Bad timing', 'status_description' => 'Prospect is interested but requested to be contacted at a later date.', 'bg_color' => '#cccccc', 'text_color' => '#333333', 'status_order' => 90],
];
