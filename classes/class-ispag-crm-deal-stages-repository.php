<?php

if ( ! class_exists( 'ISPAG_Crm_Deal_Stages_Repository' ) ) :

class ISPAG_Crm_Deal_Stages_Repository {

    private $db;
    // Nom de la table basée sur la structure fournie
    private $table_name             = ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES; 
    private $deal_stages_table_name = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
    
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        
    }
    
    // ==========================================================
    // MÉTHODES DE RÉCUPÉRATION
    // ==========================================================

    /**
     * Récupère toutes les étapes de deals (colonnes Kanban), triées par stage_order.
     *
     * @param bool $as_models Si VRAI, retourne un tableau d'objets ISPAG_Crm_Deal_Stage_Model.
     * @return array Tableau d'objets ou de résultats bruts.
     */
    public function get_all_stages( $as_models = true ) {
        
        $sql = "SELECT * FROM {$this->table_name}
                ORDER BY stage_order ASC";
        
        $results = $this->db->get_results( $sql );
        
        if ( ! $results || ! $as_models ) {
            return $results ?: [];
        }

        // Convertir les résultats bruts en Modèles
        $stages = [];
        if ( class_exists( 'ISPAG_Crm_Deal_Stage_Model' ) ) {
            foreach ( $results as $row ) {
                $stages[] = new ISPAG_Crm_Deal_Stage_Model( $row );
            }
        }
        return $stages;
    }
    
    /**
     * Récupère toutes les étapes sous forme de tableau associatif, indexé par 'stage_key'.
     * C'est utile pour l'hydratation des Deals dans le Deals Repository.
     *
     * @return array Tableau associatif [stage_key => ISPAG_Crm_Deal_Stage_Model].
     */
    public function get_stages_keyed_by_stage_key() {
        $stages_list = $this->get_all_stages( true ); // Assurez-vous que c'est en modèles
        $keyed_stages = [];
        
        foreach ( $stages_list as $stage_model ) {
            $keyed_stages[ $stage_model->stage_key ] = $stage_model;
        }
        
        return $keyed_stages;
    }

    /**
     * Récupère le libellé d'une étape à partir de sa clé.
     * @param string $stage_key La clé de l'étape.
     * @return string Le libellé de l'étape, ou la clé par défaut.
     */
    public function get_stage_label_by_key( $stage_key ) {
        $stages = $this->get_stages_keyed_by_stage_key();
        
        if ( isset( $stages[ $stage_key ] ) ) {
            return $stages[ $stage_key ]->stage_label;
        }
        
        return esc_html( $stage_key ); // Retourne la clé si non trouvée
    }
    /**
     * Récupère les données brutes d'un stage par sa clé unique.
     * C'est utile pour l'enrichissement d'un seul Deal.
     * @param string $stage_key La clé unique du stage (ex: 'proposal_sent').
     * @return object|null L'objet de données brutes du stage, ou null.
     */
    public function get_stage_data_by_key( $stage_key ) {
        if ( empty( $stage_key ) ) {
            return null;
        }

        $sql = $this->db->prepare(
            "SELECT stage_label, stage_color, probability, is_closed 
            FROM {$this->table_name} 
            WHERE stage_key = %s",
            $stage_key
        );

        // Retourne l'objet stdClass directement, pas un modèle, pour l'enrichissement rapide
        return $this->db->get_row( $sql );
    }

    public function get_stage_by_deal_group_ref($deal_group_ref){
        if ( empty( $deal_group_ref ) ) {
            return null;
        }

        $sql = $this->db->prepare(
            "SELECT stage.stage_key, stage.stage_label, stage.stage_color, stage.probability, stage.is_closed 
            FROM {$this->deal_stages_table_name} AS dst
            LEFT JOIN {$this->table_name} AS stage
                ON stage.stage_key = dst.current_stage_key COLLATE utf8mb4_unicode_ci
            WHERE dst.deal_group_ref COLLATE utf8mb4_unicode_ci = %s
            ORDER BY dst.last_updated DESC
            LIMIT 1",
            $deal_group_ref
        );

        return $this->db->get_row( $sql );
    }

    /**
     * Récupère la clé du tout premier stage (ordre minimal).
     * Utile pour définir un point de départ si aucun historique n'existe.
     * * @return string La clé du stage (ex: 'submission_received')
     */
    public function get_first_stage_key() {
        $sql = "SELECT stage_key FROM {$this->table_name} 
                ORDER BY stage_order ASC 
                LIMIT 1";
                
        $first_stage = $this->db->get_var( $sql );
        
        // Par sécurité, si la table des stages est vide, on retourne une valeur par défaut
        return $first_stage ?: 'submission_received';
    }

}

endif;