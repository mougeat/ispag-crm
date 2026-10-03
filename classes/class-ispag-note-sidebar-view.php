<?php
defined('ABSPATH') || exit;
/**
 * Gère le rendu HTML et le JavaScript de la Sidebar de tache
 */
class ISPAG_Note_Sidebar_View {

    public function __construct() {
        
    }

    public function render_seiderbar_html(){
        if (!is_user_logged_in() || !current_user_can('manage_order')) {
            return;
        }

        ob_start();
        ?>
        <div id="ispag-task-sidebar-modal">
            <div id="ispag-task-modal-overlay"></div>

            <div id="ispag-task-modal-content">
                <div class="ispag-modal-header-modern">
                    <div class="header-main-info">
                        <h2 id="task-title-main"><?php _e('Task Details', 'ispag-crm'); ?></h2>
                        <div class="header-meta">
                            <span class="meta-item">
                                <i class="dashicons dashicons-admin-users"></i>
                                <?php _e('Assigned to', 'ispag-crm'); ?> : <strong id="assigned-value">-</strong>
                            </span>
                            <span class="meta-item" id="task-due-date-wrapper">
                                <i class="dashicons dashicons-calendar-alt"></i>
                                <?php _e('Due date', 'ispag-crm'); ?> : <strong id="due-date">-</strong>
                            </span>
                        </div>
                    </div>
                    <button class="close-sidebar-x" id="close-task-sidebar-btn">&times;</button>
                </div>

                <div class="ispag-task-modal-body">
                    <div class="ispag-section-card">
                        <h3 class="section-label"><?php _e('Associations', 'ispag-crm'); ?></h3>
                        <div class="association-grid">
                            <a href="#" id="task-contact-link" class="assoc-pill">
                                <i class="dashicons dashicons-admin-users"></i>
                                <span class="label"><?php _e('Contact', 'ispag-crm'); ?></span>
                                <span class="value" id="task-contact-name"><?php _e('Not defined', 'ispag-crm'); ?></span>
                            </a>
                            <a href="#" id="task-company-link" class="assoc-pill">
                                <i class="dashicons dashicons-bank"></i>
                                <span class="label"><?php _e('Company', 'ispag-crm'); ?></span>
                                <span class="value" id="task-company-name"><?php _e('Not defined', 'ispag-crm'); ?></span>
                            </a>
                            <a href="#" id="task-deal-link" class="assoc-pill">
                                <i class="dashicons dashicons-chart-bar"></i>
                                <span class="label"><?php _e('Deal', 'ispag-crm'); ?></span>
                                <span class="value" id="task-deal-name"><?php _e('Not defined', 'ispag-crm'); ?></span>
                            </a>
                        </div>
                    </div>

                    <div class="ispag-section-inline">
                        <div class="status-badge-wrapper">
                            <span class="small-label"><?php _e('Status', 'ispag-crm'); ?></span>
                            <div id="task-status-display" class="status-pill"><?php _e('To do', 'ispag-crm'); ?></div>
                        </div>
                        <div class="status-badge-wrapper">
                            <span class="small-label"><?php _e('Type', 'ispag-crm'); ?></span>
                            <div id="task-type-display" class="type-pill"><?php _e('Task', 'ispag-crm'); ?></div>
                        </div>
                    </div>

                    <div class="ispag-section-card content-section">
                        <h3 class="section-label"><?php _e('Notes & Description', 'ispag-crm'); ?></h3>
                        <div id="task-content" class="ispag-sidebar-content-view">
                        </div>
                    </div>

                    <div class="ispag-section-card meeting-details" id="meeting-info-section">
                        <h3 class="section-label"><?php _e('Meeting information', 'ispag-crm'); ?></h3>
                        <div class="meeting-info-grid">
                            <div class="m-item"><strong><?php _e('Outcome', 'ispag-crm'); ?> :</strong> <span id="meeting-outcome-display">-</span></div>
                            <div class="m-item"><strong><?php _e('Participants', 'ispag-crm'); ?> :</strong> <span id="meeting-attendees-display">-</span></div>
                            <div class="m-item"><strong><?php _e('Schedule', 'ispag-crm'); ?> :</strong> <span id="meeting-time-display">-</span></div>
                        </div>
                    </div>

                    <div class="ispag-section-card" id="sidebar-attachments-wrapper">
                        <h3 class="section-label"><i class="dashicons dashicons-paperclip"></i> <?php _e('Attachments', 'ispag-crm'); ?></h3>
                        <div id="task-attachments-container" class="attachments-compact">
                        </div>
                    </div>
                </div>

                <div class="ispag-task-modal-footer">
                    <button class="ispag-btn edit-activity" data-activity-id=""><?php _e('Edit', 'ispag-crm'); ?></button>
                    <button class="ispag-btn ispag-btn-grey" id="close-task-sidebar-footer-btn"><?php _e('Close', 'ispag-crm'); ?></button>
                </div>
            </div>
        </div>

        <?php
        echo ob_get_clean();
    }
}