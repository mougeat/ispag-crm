<?php

if ( ! class_exists( 'ISPAG_Crm_Deal_Stage_Model' ) ) :

class ISPAG_Crm_Deal_Stage_Model {

    // --- PROPRIÉTÉS DU MODÈLE (Correspondant aux colonnes de la DB) ---
    public $id;
    public $stage_key;       // Clé unique interne (e.g., 'proposal_sent')
    public $stage_label;     // Nom affiché de l'étape (e.g., 'Proposition envoyée')
    public $stage_order;     // Ordre d'affichage dans le pipeline
    public $probability;     // Pourcentage de probabilité (e.g., 50.00)
    public $is_closed;       // 1 si l'étape est finale (Won/Lost), 0 sinon
    public $stage_type;      // Type de résultat : 'open', 'won', ou 'lost'
    public $stage_color;     // Code couleur hexadécimal (e.g., '#2ecc71')
    public $date_added;
    
    /**
     * Constructeur : Hydrate l'objet avec les données de la DB.
     * @param object|array $data Les données brutes de la base de données.
     */
    public function __construct( $data = null ) {
        if ( $data ) {
            $this->hydrate( $data );
        }
    }
    
    /**
     * Remplir l'objet avec les données brutes de la base de données.
     * @param object|array $data Les données brutes (stdClass ou array) de la base de données.
     */
    public function hydrate( $data ) {
        if ( is_array( $data ) ) {
            $data = (object) $data;
        }

        foreach ( get_object_vars( $this ) as $key => $default ) {
            // Assigne la valeur si elle existe dans les données brutes
            if ( isset( $data->$key ) ) {
                $this->$key = $data->$key;
            }
        }
    }
    
    // ==========================================================
    // MÉTHODES UTILITAIRES CLÉS
    // ==========================================================

    /**
     * Calcule le montant pondéré pour un deal, en utilisant la probabilité de cette étape.
     * Montant pondéré = Montant * (Probabilité / 100)
     *
     * @param float $amount Le montant total du deal (total_excl_vat).
     * @return float Le montant pondéré.
     */
    public function get_weighted_amount( $amount ) {
        // La propriété $this->probability est un pourcentage (e.g., 50.00)
        $prob = floatval( $this->probability ) / 100.0;
        return floatval( $amount ) * $prob;
    }

    /**
     * Retourne le HTML d'un badge de statut pour un affichage visuel.
     *
     * @return string Le code HTML du badge.
     */
    public function render_stage_badge() {
        if ( empty( $this->stage_label ) ) {
            return '';
        }

        // Assainissement de la couleur pour l'attribut style
        $style = sprintf( 'background-color:%s; color:white;', esc_attr( $this->stage_color ) );
        
        return sprintf(
            '<span class="ispag-stage-badge ispag-stage-%s" style="%s">%s</span>',
            esc_attr( $this->stage_key ),
            $style,
            esc_html( $this->stage_label )
        );
    }
    
    /**
     * Vérifie si l'étape est le statut 'Gagné' (won).
     *
     * @return bool VRAI si c'est le statut gagné.
     */
    public function is_won_stage() {
        return ( $this->is_closed == 1 && $this->stage_type === 'won' );
    }

    /**
     * Vérifie si l'étape est le statut 'Perdu' (lost).
     *
     * @return bool VRAI si c'est le statut perdu.
     */
    public function is_lost_stage() {
        return ( $this->is_closed == 1 && $this->stage_type === 'lost' );
    }
}

endif;