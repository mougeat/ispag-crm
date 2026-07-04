jQuery(document).ready(function($) {

    // --- 1. DROPDOWNS ---
    $(document).on('click', '.action-menu-toggle', function(e) {
        e.preventDefault(); e.stopPropagation();
        const $dropdown = $(this).closest('.ispag-dropdown-actions');
        $('.ispag-dropdown-actions').not($dropdown).removeClass('active');
        $dropdown.toggleClass('active');
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.ispag-dropdown-actions').length) {
            $('.ispag-dropdown-actions').removeClass('active');
        }
    });

    // --- 2. OUVERTURE SIDEBAR (Zones sécurisées) ---
    $(document).on('click', '.open-task-sidebar', function(e) {
        if ($(e.target).closest('.ispag-quick-complete, .ispag-dropdown-actions, .ispag-activity-attachments, .ispag-action-btn, input, label').length) {
            return;
        }
        const activityId = $(this).closest('.ispag-activity-item').data('activity-id') || $(this).data('task-id');
        if (activityId && typeof window.openTaskSidebar === 'function') {
            window.openTaskSidebar(activityId);
        }
    });

    // --- 3. ÉDITION (FONCTION ÉDIT RÉPARÉE) ---
    $(document).on('click', '.edit-activity', function(e) {
        e.preventDefault(); e.stopPropagation();
        const activityId = $(this).attr('data-activity-id') || $(this).data('activity-id');
        const $btn = $(this);

        if (!activityId) return;
        $btn.addClass('is-loading').css('opacity', 0.5);

        $.post(ispagNoteData.ajaxurl, {
            action: 'ispag_load_task_details', 
            task_id: activityId,
            security: ispagNoteData.nonce,
        }).done(function(response) {
            $btn.removeClass('is-loading').css('opacity', 1);
            if (response.success && typeof window.openModalInEditMode === 'function') {
                if (typeof window.closeSidebar === 'function') window.closeSidebar();
                window.openModalInEditMode(response.data);
            }
        });
    });

    // --- 4. ACTIONS AJAX (Delete / Complete) ---
    function executeActivityAction(actionType, activityId, activityElement) {
        const actionName = (actionType === 'delete') ? 'ispag_delete_activity' : 'ispag_complete_task';
        const labels = window.ispagNoteData || {};
        const confirmText = (actionType === 'delete') ? labels.textConfirmDeleteLog : labels.textConformCompleteTask;

        // if (!confirm(confirmText)) {
        //     if(actionType === 'complete') activityElement.find('input[type="checkbox"]').prop('checked', false);
        //     return;
        // }

        activityElement.css('opacity', 0.5);

        $.post(labels.ajaxurl, {
            action: actionName,
            security: labels.nonce,
            activity_id: activityId
        }).done(function(response) {
            if (response.success) {
                if (actionType === 'delete') {
                    activityElement.fadeOut(300, function() { $(this).remove(); });
                    // Supprimer aussi la ligne du tableau si elle existe
                    $('#task-' + activityId).fadeOut();
                } else {
                    activityElement.addClass('is-completed').css('opacity', 1);
                    activityElement.find('.ispag-quick-complete').html('<span class="dashicons dashicons-yes-alt is-completed-check"></span>');
                    // Gérer aussi la ligne de tableau
                    const $row = $('#task-' + activityId);
                    if($row.length) $row.addClass('is-completed').css('background-color', '#d4edda').fadeOut();
                }
                if (typeof window.closeSidebar === 'function') window.closeSidebar();
            } else {
                alert('Erreur: ' + response.data.message);
                activityElement.css('opacity', 1);
            }
        });
    }

    // --- 5. ÉCOUTEURS D'ACTIONS ---
    $(document).on('click', '.delete-activity', function(e) {
        e.preventDefault(); e.stopPropagation();
        executeActivityAction('delete', $(this).attr('data-activity-id'), $(this).closest('.ispag-timeline-entry'));
    });

    $(document).on('change', '.ispag-task-quick-check', function() {
        if ($(this).is(':checked')) {
            executeActivityAction('complete', $(this).data('id'), $(this).closest('.ispag-timeline-entry'));
        }
    });

    $(document).on('click', '.complete-task-btn', function(e) {
        e.preventDefault(); e.stopPropagation();
        const id = $(this).attr('data-activity-id');
        const $el = $(`.ispag-activity-item[data-activity-id="${id}"]`).closest('.ispag-timeline-entry');
        executeActivityAction('complete', id, $el);
    });
});