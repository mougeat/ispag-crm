<?php

if ( ! class_exists( 'ISPAG_Crm_Deal_Stage_Model' ) ) :

class ISPAG_Crm_Deal_Stage_Model {

    // --- PROPRIÉTÉS DU MODÈLE (Colonnes de la DB) ---
    public $id;
    public $stage_key;      // Clé unique pour l'étape (ex: 'qualification', 'negotiation')
    public $stage_label;    // Libellé affiché (ex: 'Qualification')
    public $stage_order;    // Ordre d'affichage dans le Kanban
    public $probability;    // Probabilité de succès (ex: 20.00, 50.00)
    public $is_closed;      // 1 si étape de clôture (Gagné/Perdu), 0 sinon
    public $stage_type;     // Type de l'étape (ex: 'open', 'won', 'lost')
    public $stage_color;    // Couleur CSS (ex: #cccccc)
    public $date_added;


    /**
     * Constructeur : Hydrate l'objet.
     * @param object|array $data Les données brutes de l'étape de la base de données.
     */
    public function __construct( $data = null ) {
        if ( $data ) {
            $this->hydrate( $data );
        }
    }

    /**
     * Remplir l'objet avec les données.
     */
    public function hydrate( $data ) {
        if ( is_array( $data ) ) {
            $data = (object) $data;
        }

        foreach ( get_object_vars( $this ) as $key => $default ) {
            if ( isset( $data->$key ) ) {
                $this->$key = $data->$key;
            }
        }
    }

    // ==========================================================
    // MÉTHODES UTILITAIRES KANBAN
    // ==========================================================

    /**
     * Calcule le montant total pondéré pour cette étape.
     * Montant pondéré = Montant total * (Probabilité / 100).
     *
     * @param float $total_amount Le montant total non pondéré de tous les deals dans cette étape.
     * @return float Le montant pondéré.
     */
    public function get_weighted_amount( $total_amount ) {
        $total_amount = floatval( $total_amount );
        $probability  = floatval( $this->probability );
        
        if ( $total_amount <= 0 || $probability <= 0 ) {
            return 0.00;
        }
        
        // Formule : Total * (Probabilité / 100)
        return ( $total_amount * ( $probability / 100 ) );
    }
}

endif;