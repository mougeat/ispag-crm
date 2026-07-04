<?php
/**
 * Class ISPAG_Workflow_CPT
 * Enregistre un Custom Post Type pour les workflows.
 */

if (!class_exists('ISPAG_Workflow_CPT')) {
    class ISPAG_Workflow_CPT {
        public function __construct() {
            add_action('init', [$this, 'register_workflow_cpt']);
        }

        /**
         * Enregistre le CPT pour les workflows.
         */
        public function register_workflow_cpt() {
            $labels = [
                'name'                  => _x('Workflows', 'Post Type General Name', 'ispag-crm'),
                'singular_name'         => _x('Workflow', 'Post Type Singular Name', 'ispag-crm'),
                'menu_name'             => __('Workflows', 'ispag-crm'),
                'name_admin_bar'        => __('Workflow', 'ispag-crm'),
                'add_new'               => _x('Add New', 'Workflow', 'ispag-crm'),
                'add_new_item'          => __('Add New Workflow', 'ispag-crm'),
                'new_item'              => __('New Workflow', 'ispag-crm'),
                'edit_item'             => __('Edit Workflow', 'ispag-crm'),
                'view_item'             => __('View Workflow', 'ispag-crm'),
                'all_items'             => __('All Workflows', 'ispag-crm'),
                'search_items'          => __('Search Workflows', 'ispag-crm'),
                'parent_item_colon'     => __('Parent Workflows:', 'ispag-crm'),
                'not_found'             => __('No workflows found.', 'ispag-crm'),
                'not_found_in_trash'    => __('No workflows found in Trash.', 'ispag-crm'),
                'featured_image'        => __('Workflow Image', 'ispag-crm'),
                'set_featured_image'    => __('Set workflow image', 'ispag-crm'),
                'remove_featured_image' => __('Remove workflow image', 'ispag-crm'),
                'use_featured_image'    => __('Use as workflow image', 'ispag-crm'),
            ];

            $args = [
                'labels'             => $labels,
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'query_var'          => true,
                'rewrite'            => ['slug' => 'ispag-workflow'],
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'menu_position'      => 25,
                'menu_icon'          => 'dashicons-networking',
                'supports'           => ['title'],
                'show_in_rest'       => true,
            ];

            register_post_type('ispag_workflow', $args);
        }
    }
}