<?php

class ISPAG_Projects_List_Table {

    public function __construct() {
        // Hooks pour l'affichage dans le profil WordPress
        add_action( 'show_user_profile', array( $this, 'display_contact_projects_section' ), 20 ); 
        add_action( 'edit_user_profile', array( $this, 'display_contact_projects_section' ), 20 ); 
    }

    /**
     * Récupère les données via le Repository pour profiter de l'enrichissement
     */
    protected static function get_projects_data( $user_id ) {
        if ( ! class_exists( 'ISPAG_Crm_Deals_Repository' ) ) {
            return array();
        }

        $repo = new ISPAG_Crm_Deals_Repository();
        // On récupère les deals liés à ce contact (inclut l'enrichissement automatique)
        return $repo->get_projects_by_contact( $user_id, 0, true );
    }

    protected static function render_projects_table( $projects ) {
        if ( empty( $projects ) ) {
            echo '<div class="notice notice-info inline"><p>' . __( 'No projects found for this contact.', 'ispag-crm' ) . '</p></div>';
            return;
        }
        ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 12%;"><?php _e( 'Ref / ID', 'ispag-crm' ); ?></th>
                    <th scope="col" style="width: 15%;"><?php _e( 'Type', 'ispag-crm' ); ?></th>
                    <th scope="col" style="width: 30%;"><?php _e( 'Project / Customer', 'ispag-crm' ); ?></th>
                    <th scope="col" style="width: 15%;"><?php _e( 'Stage', 'ispag-crm' ); ?></th>
                    <th scope="col" style="width: 15%;"><?php _e( 'Date', 'ispag-crm' ); ?></th>
                    <th scope="col" style="width: 10%;"><?php _e( 'Actions', 'ispag-crm' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $projects as $deal ) : 
                    // $deal est maintenant un objet ISPAG_Crm_Deal_Model grâce au Repository
                    $link = home_url( '/deal/' . $deal->id . '/' );
                    $type_label = ($deal->process_type == 'Offre') ? '📄 Offre' : '📦 Commande';
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $deal->project_num ); ?></strong></td>
                        <td><?php echo esc_html( $type_label ); ?></td>
                        <td>
                            <strong><?php echo esc_html( $deal->project_name ); ?></strong><br>
                            <small style="color:#666;"><?php echo esc_html( $deal->associated_company_name ); ?></small>
                        </td>
                        <td>
                            <span class="ispag-badge" style="background:<?php echo esc_attr($deal->stage_color); ?>; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px;">
                                <?php echo esc_html( $deal->stage_label ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $deal->date_creation ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $link ); ?>" target="_blank" class="button button-small">
                                <?php _e( 'View', 'ispag-crm' ); ?> 🔗
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <style>
            .ispag-badge { display: inline-block; text-transform: uppercase; font-weight: 600; }
        </style>
        <?php
    }

    public function display_contact_projects_section( $user ) {
        // Seul l'admin ou le staff peut voir cette section
        if ( ! current_user_can( 'edit_others_posts' ) ) return;
        
        $projects = self::get_projects_data( $user->ID );
        ?>
        <div id="ispag-user-projects-list" style="margin-top: 30px;">
            <h3 class="heading"><?php _e( 'ISPAG CRM : Associated Deals', 'ispag-crm' ); ?></h3>
            <?php self::render_projects_table( $projects ); ?>
        </div>
        <hr />
        <?php
    }
}

// // Initialisation
// new ISPAG_Projects_List_Table();