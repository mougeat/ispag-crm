<?php

/**
 * Classe ISPAG_Template_Modal
 * Gère l'affichage de l'interface de création et d'édition des templates.
 */
class ISPAG_Template_Modal {

    /**
     * Rendu de la modal d'édition
     */
    public function render() {
        $repo = new ISPAG_Template_Repository();
        $current_user_id = get_current_user_id();
        $folders = $repo->get_folders($current_user_id);
        
        ?>
        <div id="ispag-template-modal" class="ispag-modal">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <h2 id="template-modal-title"><?php _e('Template Editor', 'ispag-crm'); ?></h2>
                    <span class="ispag-close-modal">&times;</span>
                </div>

                <form id="ispag-template-form">
                    <input type="hidden" name="id" id="tpl-id">
                    
                    <div class="ispag-template-editor-layout">
                        <div class="ispag-editor-main">
                            <div class="ispag-form-group">
                                <label><?php _e('Template Name', 'ispag-crm'); ?></label>
                                <input type="text" name="name" id="tpl-name" required placeholder="ex: Relance facture impayée">
                            </div>

                            <div class="ispag-form-group">
                                <label><?php _e('Email Subject', 'ispag-crm'); ?></label>
                                <input type="text" name="subject" id="tpl-subject" required placeholder="<?php _e('Email Subject', 'ispag-crm'); ?>">
                            </div>

                            <div class="ispag-form-group">
                                <label><?php _e('Message Content', 'ispag-crm'); ?></label>
                                <?php $this->render_variable_pills(); ?>
                                
                                <div id="tpl-content-editable" class="ispag-editor-content" contenteditable="true"></div>
                                
                                <input type="hidden" name="content" id="tpl-content">
                            </div>
                        </div>

                        <div class="ispag-editor-sidebar">
                            <div class="ispag-form-group">
                                <label><?php _e('Folder / Category', 'ispag-crm'); ?></label>
                                <select name="folder_id" id="tpl-folder">
                                    <option value=""><?php _e('No folder', 'ispag-crm'); ?></option>
                                    <?php foreach ($folders as $folder) : ?>
                                        <option value="<?php echo $folder->id; ?>"><?php echo esc_html($folder->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ispag-form-group">
                                <label><?php _e('Language', 'ispag-crm'); ?></label>
                                <select name="language" id="tpl-lang">
                                    <option value="fr">Français</option>
                                    <option value="de">Deutsch</option>
                                    <option value="en">English</option>
                                </select>
                            </div>

                            <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">

                            <div class="ispag-form-group" style="flex-direction: row; align-items: center; gap: 10px;">
                                <?php 
                                $is_admin = current_user_can('administrator');
                                $checked = !$is_admin ? 'checked' : ''; 
                                $disabled = !$is_admin ? 'disabled' : '';
                                ?>
                                <input type="checkbox" name="is_personal" id="tpl-personal" <?php echo $checked; ?> <?php echo $disabled; ?>>
                                <label for="tpl-personal" style="margin:0; cursor:pointer;">
                                    <?php _e('Private Template', 'ispag-crm'); ?>
                                </label>
                            </div>
                            
                            <p class="description" style="margin-top:20px; font-size:12px; color:#666; line-height: 1.4;">
                                <span class="dashicons dashicons-info" style="font-size:16px; width:16px; height:16px;"></span>
                                <?php _e('Shared templates are visible to the whole team but can only be modified by admins.', 'ispag-crm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="ispag-modal-footer">
                        <div id="tpl-status-msg"></div>
                        <button type="button" class="button ispag-btn-grey-outlined ispag-close-modal"><?php _e('Cancel', 'ispag-crm'); ?></button>
                        <button type="submit" class="button button-primary" style="background:#800000; border-color:#600000;">
                            <?php _e('Save Template', 'ispag-crm'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Affiche le tableau de liste des templates
     */
    public function render_list_table($templates) {
        $current_user_id = get_current_user_id();
        $is_admin = current_user_can('administrator');
        ?>
        <div class="ispag-template-list-wrapper">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-primary"><?php _e('Name', 'ispag-crm'); ?></th>
                        <th><?php _e('Owner', 'ispag-crm'); ?></th>
                        <th><?php _e('Date created', 'ispag-crm'); ?></th>
                        <th><?php _e('Date modified', 'ispag-crm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)) : ?>
                        <tr><td colspan="4"><?php _e('No templates found.', 'ispag-crm'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($templates as $tpl) : 
                            // Logique de permission : Admin peut tout modifier, 
                            // l'utilisateur ne modifie que ses templates personnels (owner_id non NULL)
                            $can_edit = $is_admin || ($tpl->owner_id == $current_user_id);
                            $owner_name = $tpl->owner_id ? get_userdata($tpl->owner_id)->display_name : __('Shared (Admin)', 'ispag-crm');
                        ?>
                            <tr>
                                <td class="column-primary">
                                    <?php if ($can_edit) : ?>
                                        <strong><a href="#" class="ispag-edit-template" data-id="<?php echo $tpl->id; ?>">
                                            <?php echo esc_html($tpl->name); ?>
                                        </a></strong>
                                    <?php else : ?>
                                        <span class="ispag-template-readonly" title="<?php _e('Read-only (Common template)', 'ispag-crm'); ?>">
                                            <?php echo esc_html($tpl->name); ?> <span class="dashicons dashicons-lock"></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($owner_name); ?></td>
                                <td><?php echo human_time_diff(strtotime($tpl->created_at), current_time('timestamp')) . ' ' . __('ago', 'ispag-crm'); ?></td>
                                <td><?php echo human_time_diff(strtotime($tpl->updated_at), current_time('timestamp')) . ' ' . __('ago', 'ispag-crm'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Affiche la barre de recherche et les filtres
     */
    public function render_filter_bar() {
        ?>
        <div class="ispag-filter-bar">
            <div class="filter-left">
                <input type="text" id="tpl-search" placeholder="<?php esc_attr_e('Search templates...', 'ispag-crm'); ?>">
                
                <select id="tpl-filter-owner">
                    <option value="all"><?php _e('All Owners', 'ispag-crm'); ?></option>
                    <option value="common"><?php _e('Shared (Admin)', 'ispag-crm'); ?></option>
                    <option value="mine"><?php _e('My Templates', 'ispag-crm'); ?></option>
                </select>
            </div>
            
            <div class="filter-right">
                <button type="button" class="button button-primary" id="ispag-add-new-tpl">
                    <span class="dashicons dashicons-plus"></span> <?php _e('Add New', 'ispag-crm'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Affiche les badges de variables cliquables
     */
    private function render_variable_pills() {
        $variables = array(
            'contact_first_name' => __('First Name', 'ispag-crm'),
            'contact_last_name'  => __('Last Name', 'ispag-crm'),
            'contact_full_name'  => __('Name', 'ispag-crm'),
            'company_name'       => __('Company', 'ispag-crm'),
            'contact_email'      => __('Email', 'ispag-crm'),
            'contact_phone'      => __('Phone number', 'ispag-crm'),
            'deal_name'          => __('Offer Name', 'ispag-crm'),
            'deal_offer_num'     => __('Offer No.', 'ispag-crm'),
            'deal_project_num'   => __('Project No.', 'ispag-crm'),
            'deal_closing_date'  => __('Project No.', 'ispag-crm'),
            'deal_total'         => __('Deal total', 'ispag-crm'),
        );

        echo '<div class="ispag-variable-pills">';
        echo '<span style="font-size: 11px; color: #666; width: 100%; margin-bottom: 5px; display: block;">' . __('Click to insert at cursor:', 'ispag-crm') . '</span>';
        foreach ($variables as $tag => $label) {
            printf('<span class="ispag-variable-badge" data-tag="{{%s}}">%s</span>', esc_attr($tag), esc_html($label));
        }
        echo '</div>';
    }
}