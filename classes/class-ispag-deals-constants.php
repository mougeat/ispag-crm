<?php

if ( ! class_exists( 'ISPAG_Deals_Constants' ) ) :

/**
 * Définit les constantes et les mappings pour les transactions (Deals/Projets) ISPAG.
 * Cela inclut les statuts, les couleurs, les motifs de rejet, et le mapping vers les étapes Kanban.
 */
class ISPAG_Deals_Constants {

    const TABLE_NAME = ISPAG_Crm_Deal_Constants::TABLE_NAME;
    const STAGES_TABLE_NAME = 'wor9711_ispag_deals_stages';
    
    // ==========================================================
    // 1. STATUTS DE L'ÉTAT DU DEAL (project_db_status)
    //    Ces 3 statuts définissent la vie d'un deal dans le pipeline (0, 1, 2)
    // ==========================================================
    
    const STATUS_OPEN         = 0; // Correspond à toutes les étapes "ouvertes" du Kanban
    const STATUS_CLOSED_WON   = 1; // Correspond à l'étape "Closed Won" (Gagné)
    const STATUS_CLOSED_LOST  = 2; // Correspond à l'étape "Closed Lost" (Perdu)
    
    /**
     * Retourne les libellés des statuts du projet.
     * @return array [ID_STATUT => 'Libellé']
     */
    public static function get_project_db_status_labels() {
        return [
            self::STATUS_OPEN         => 'Open (Pipeline)',
            self::STATUS_CLOSED_WON   => 'Closed Won',
            self::STATUS_CLOSED_LOST  => 'Closed Lost',
        ];
    }
    
    /**
     * Retourne la cartographie entre les ID de statut (0, 1, 2) et les clés textuelles du Kanban.
     * @return array [ID_STATUT => 'stage_key']
     */
    public static function get_project_db_status_keys() {
        return [
            // Statuts de clôture (inchangés)
            self::STATUS_CLOSED_WON   => 'closed_won',   
            self::STATUS_CLOSED_LOST  => 'closed_lost',  
            
            // Les deals Ouverts (STATUS_OPEN = 0) pointent vers la première étape
            self::STATUS_OPEN         => 'submission_received', 
        ];
    }

    // ==========================================================
    // 2. STATUTS DE LA BASE DE DONNÉES (database_status)
    //    Ces IDs correspondent aux valeurs stockées dans la colonne `database_status` (Etat de la base)
    // ==========================================================
    
    const DB_STATUS_WORKING         = 1; // En travail
    const DB_STATUS_PRINTED         = 2; // Imprimé
    const DB_STATUS_SENT_EMAIL      = 3; // Envoyé par mail
    const DB_STATUS_INVOICED        = 4; // Facturé
    // const DB_STATUS_UNKNOWN       = 5; // À clarifier
    const DB_STATUS_PAID_PARTIAL    = 6; // Payé partiellement
    const DB_STATUS_PAID_FULL       = 7; // Payé en totalité
    const DB_STATUS_FINISHED        = 8; // Terminé
    const DB_STATUS_ADJOURNED       = 9; // Ajourné
    const DB_STATUS_CANCELLED       = 10; // Interrompue / Annulée
    // const DB_STATUS_IN_PROGRESS     = 11; // En accomplissement
    const DB_STATUS_ACCOMPLISHMENT  = 11;

    /**
     * Retourne les libellés pour le statut interne de la base de données.
     * @return array [ID_STATUT => 'Libellé']
     */
    public static function get_database_status_labels() {
        return [
            self::DB_STATUS_WORKING         => 'In Progress (Working)',
            self::DB_STATUS_PRINTED         => 'Printed',
            self::DB_STATUS_SENT_EMAIL      => 'Sent by Email',
            self::DB_STATUS_INVOICED        => 'Invoiced',
            // 5                             => 'Unknown (5)', 
            self::DB_STATUS_PAID_PARTIAL    => 'Partially Paid',
            self::DB_STATUS_PAID_FULL       => 'Fully Paid',
            self::DB_STATUS_FINISHED        => 'Finished',
            self::DB_STATUS_ADJOURNED       => 'Adjourned',
            self::DB_STATUS_CANCELLED       => 'Interrupted / Cancelled',
            self::DB_STATUS_ACCOMPLISHMENT  => 'In Accomplishment',
        ];
    }

    // ==========================================================
    // 3. MAPPING DB_STATUS vers ÉTAPE KANBAN (Uniformisation)
    // ==========================================================
    
    /**
     * Mappe le statut détaillé de la base de données (database_status) vers 
     * les clés d'étape du Kanban (stage_key).
     * @return array [DB_STATUS_ID => 'kanban_stage_key']
     */
    public static function get_db_status_to_kanban_stage_map() {
        return [
            // Statuts du pipeline (ouvert)
            self::DB_STATUS_WORKING         => 'submission_received', 
            
            // Assimilés à l'étape "Proposition Envoyée"
            self::DB_STATUS_PRINTED         => 'proposal_sent', 
            self::DB_STATUS_SENT_EMAIL      => 'proposal_sent', 
            
            // Statuts Fermés GAGNÉS (Doivent s'afficher dans la colonne Closed Won)
            self::DB_STATUS_INVOICED        => 'closed_won',
            self::DB_STATUS_PAID_PARTIAL    => 'closed_won',
            self::DB_STATUS_PAID_FULL       => 'closed_won',
            self::DB_STATUS_FINISHED        => 'closed_won',
            self::DB_STATUS_ACCOMPLISHMENT  => 'open_won',
            
            // Statuts de transition (Assimilés à Perdu/Archivé)
            self::DB_STATUS_ADJOURNED       => 'closed_lost', 
            self::DB_STATUS_CANCELLED       => 'closed_lost', 
        ];
    }
    
    // ==========================================================
    // 4. MOTIFS DE REJET (reason_for_rejection)
    // ==========================================================
    
    /**
     * Retourne la liste des motifs de rejet.
     * @return array [ID_MOTIF => 'Libellé du Motif']
     */
    public static function get_rejection_reason_labels() {
        return [
            1 => __( 'Price Too High', 'ispag-crm' ),         
            2 => __( 'Timeline Problem', 'ispag-crm' ),       
            3 => __( 'Dissatisfied with Last Order', 'ispag-crm' ), 
            4 => __( 'Competitor Chosen', 'ispag-crm' ),     
            5 => __( 'Project Not Realized', 'ispag-crm' ), 
            6 => __( 'Political Decision', 'ispag-crm' ),    
            7 => __( 'Replaced by Offer', 'ispag-crm' ),      
            8 => __( 'Client Did Not Receive Order', 'ispag-crm' ), 
            9 => __( 'Assigned to Internal Group', 'ispag-crm' ), 
        ];
    }
    
    // ==========================================================
    // 5. COULEURS (Basées sur l'état du deal)
    // ==========================================================
    
    /**
     * Retourne une couleur standard basée sur l'ID du statut du deal (project_db_status).
     * @param int $status_id L'ID du statut du deal (0, 1, 2).
     * @return string Code couleur hexadécimal.
     */
    public static function get_status_color( $status_id ) {
        switch ( (int) $status_id ) {
            case self::STATUS_CLOSED_WON:
                return '#2ECC71'; // Vert : Gagné
            case self::STATUS_CLOSED_LOST:
                return '#E74C3C'; // Rouge : Perdu
            case self::STATUS_OPEN:
            default:
                return '#3498DB'; // Bleu : Ouvert (Pipeline)
        }
    }
}

endif;