(function($) {
    "use strict";

    // --- SÉLECTEURS ---
    const $sidebarModal = $('#ispag-task-sidebar-modal'); 
    const $sidebarContent = $('#ispag-task-modal-content'); 
    const SIDEBAR_WIDTH = '650px'; 

    const $assignedValue = $('#assigned-value');
    const $dueDate = $('#due-date');
    const $taskContactLink = $('#task-contact-link');
    const $taskCompanyLink = $('#task-company-link');
    const $taskDealLink = $('#task-deal-link');
    const $taskStatusDisplay = $('#task-status-display');
    const $taskTypeDisplay = $('#task-type-display');
    const $meetingOutcomeDisplay = $('#meeting-outcome-display');
    const $meetingAttendeesDisplay = $('#meeting-attendees-display');
    const $meetingDateDisplay = $('#meeting-date-display');
    const $meetingTimeDisplay = $('#meeting-time-display');
    const $taskIdInput = $('#task-id');
    const $taskContent = $('#task-content');
    const $taskAttachmentsContainer = $('#task-attachments-container');
    const $attachmentsWrapper = $('#sidebar-attachments-wrapper');

    // --- FERMETURE ---
    window.closeSidebar = function() {
        $sidebarContent.animate({ right: '-' + SIDEBAR_WIDTH }, 300, function() {
            $sidebarModal.fadeOut(200, function() {
                $('body').removeClass('sidebar-open');
                // Reset complet pour éviter les flashs d'anciennes données
                $taskContent.empty();
                $assignedValue.text('-');
                $taskAttachmentsContainer.empty();
                $sidebarContent.removeClass('is-loading');
            });
        });
    };

    // --- OUVERTURE (La fonction appelée par l'autre JS) ---
    window.openTaskSidebar = function(taskId) {
        if (!taskId) return;
        $('body').addClass('sidebar-open'); 
        $sidebarModal.fadeIn(200, function() {
            $sidebarContent.animate({ right: '0' }, 300); 
        });
        loadTaskDetails(taskId);
    };

    // --- CHARGEMENT AJAX COMPLET ---
    function loadTaskDetails(taskId) {
        $sidebarContent.addClass('is-loading'); 
        
        $.ajax({
            url: ispagNoteData.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_load_task_details', 
                task_id: taskId,
                security: ispagNoteData.nonce,
            },
            success: function(response) {
                $sidebarContent.removeClass('is-loading');  
                if (response.success) {
                    const data = response.data;

                    // Mise à jour des IDs pour les boutons d'action de la sidebar
                    $('.complete-task-btn, .edit-activity, .delete-activity').attr('data-activity-id', data.id);
                    
                    // Remplissage des champs de base
                    $taskIdInput.val(data.id);
                    $assignedValue.text(data.assigned_to || '-');
                    $dueDate.text(data.due_date || '-');
                    $taskContent.html(data.content); 
                    $taskStatusDisplay.html(data.status_html);
                    $taskTypeDisplay.text(data.type);

                    // Liens de contexte
                    $taskContactLink.html(data.contact_name || '-');
                    $taskCompanyLink.html(data.company_name || '-');
                    $taskDealLink.html(data.deal_name || '-');

                    // Bouton "Fait"
                    if (data.is_task == 1 && data.is_completed == 0) {
                        $('.complete-task-btn').show();
                    } else {
                        $('.complete-task-btn').hide();
                    }

                    // Pièces jointes (logique complète)
                    $taskAttachmentsContainer.empty();
                    if (data.attachments && data.attachments.length > 0) {
                        $attachmentsWrapper.show();
                        data.attachments.forEach(file => {
                            const $link = $('<a href="' + file.url + '" target="_blank" class="ispag-sidebar-attachment-item"></a>');
                            $link.html('<span class="dashicons dashicons-media-default"></span> ' + file.name);
                            $taskAttachmentsContainer.append($link);
                        });
                    } else {
                        $attachmentsWrapper.hide();
                    }

                    // Section Meeting / Call (RESTAURÉE)
                    const type = data.type ? data.type.toLowerCase() : '';
                    if(type === 'meeting' || type === 'call'){
                         $('.meeting-section, .meeting-section-hr').show();
                         $meetingOutcomeDisplay.html(data.outcome || '-');
                         $meetingAttendeesDisplay.html(data.attendees || '-');
                         $meetingDateDisplay.text(data.meeting_date || '-'); 
                         $meetingTimeDisplay.text(data.meeting_time || '-');
                    } else {
                        $('.meeting-section, .meeting-section-hr').hide();
                    }
                }
            }
        });
    }

    // Event listeners de fermeture
    $(document).on('click', '#close-task-sidebar-btn, #close-task-sidebar-footer-btn', function(e) {
        e.preventDefault(); window.closeSidebar();
    });
    $sidebarModal.on('click', function(e) { if (e.target === this) window.closeSidebar(); });

})(jQuery);