<?php
/**
 * Raisons de rejet (export production 30.09.2026)
 * Appliqué seulement si la table est vide (voir seed() de l'installateur).
 */
defined('ABSPATH') || exit;

return [
    ['id' => 1, 'reason_key' => 'price', 'label_en' => 'Price too high', 'is_active' => 1],
    ['id' => 2, 'reason_key' => 'delay', 'label_en' => 'Delivery lead time too long', 'is_active' => 1],
    ['id' => 3, 'reason_key' => 'technical', 'label_en' => 'Non-compliant technical specs', 'is_active' => 1],
    ['id' => 4, 'reason_key' => 'competitor', 'label_en' => 'Local competitor chosen', 'is_active' => 1],
    ['id' => 5, 'reason_key' => 'canceled', 'label_en' => 'Project canceled by client', 'is_active' => 1],
    ['id' => 6, 'reason_key' => 'no_answer', 'label_en' => 'No response from client', 'is_active' => 1],
    ['id' => 7, 'reason_key' => 'engineer_proposal', 'label_en' => 'Engineering proposal', 'is_active' => 1],
    ['id' => 8, 'reason_key' => 'client_lost_bid', 'label_en' => 'Client lost the bid', 'is_active' => 1],
];
