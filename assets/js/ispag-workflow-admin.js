jQuery(document).ready(function($) {
    // =============================================
    // GESTION DES ÉTAPES
    // =============================================

    // Ajouter une étape
    $('#ispag-add-step').on('click', function() {
        var index = $('#ispag-workflow-steps-container .ispag-workflow-step').length;
        var stepHtml = `
            <div class="ispag-workflow-step" data-index="${index}">
                <h4>${ispag_workflow_admin.step_label || 'Step'} ${index + 1}</h4>
                <div class="ispag-field-group">
                    <label>${ispag_workflow_admin.step_type_label || 'Step Type:'}</label>
                    <select name="ispag_workflow_steps[${index}][type]" class="widefat">
                        <option value="email">${ispag_workflow_admin.email_label || 'Email'}</option>
                        <option value="task">${ispag_workflow_admin.task_label || 'Task'}</option>
                        <option value="call">${ispag_workflow_admin.call_label || 'Call'}</option>
                    </select>
                </div>

                <div class="ispag-field-group">
                    <label>${ispag_workflow_admin.delay_label || 'Delay (e.g., 1 day, 2 hours):'}</label>
                    <input type="text" name="ispag_workflow_steps[${index}][delay]" class="widefat" placeholder="1 day">
                </div>

                <div class="ispag-field-group">
                    <label>${ispag_workflow_admin.title_label || 'Title/Subject:'}</label>
                    <input type="text" name="ispag_workflow_steps[${index}][title]" class="widefat">
                </div>

                <div class="ispag-field-group">
                    <label>${ispag_workflow_admin.content_label || 'Content/Description:'}</label>
                    <textarea name="ispag_workflow_steps[${index}][content]" class="widefat" rows="5"></textarea>
                </div>

                <button type="button" class="button ispag-remove-step">${ispag_workflow_admin.remove_step_label || 'Remove Step'}</button>
                <hr>
            </div>
        `;
        $('#ispag-workflow-steps-container').append(stepHtml);
    });

    // Supprimer une étape
    $(document).on('click', '.ispag-remove-step', function() {
        $(this).closest('.ispag-workflow-step').remove();
        // Réorganiser les indices si nécessaire
        reindexSteps();
    });

    // Réindexer les étapes après suppression
    function reindexSteps() {
        $('#ispag-workflow-steps-container .ispag-workflow-step').each(function(index) {
            $(this).attr('data-index', index);
            $(this).find('input, select, textarea').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/ispag_workflow_steps\[\d+\]/g, `ispag_workflow_steps[${index}]`));
                }
            });
            $(this).find('h4').text(`${ispag_workflow_admin.step_label || 'Step'} ${index + 1}`);
        });
    }

    // =============================================
    // GESTION DES DÉCLENCHEURS
    // =============================================

    // Charge les statuts des deals via AJAX
    function loadDealStages(selectElement, selectedValue = '') {
        $.ajax({
            url: ispag_workflow_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'get_deal_stages',
                nonce: ispag_workflow_admin.nonce
            },
            beforeSend: function() {
                selectElement.html('<option value="">' + (ispag_workflow_admin.loading_label || 'Loading...') + '</option>');
            },
            success: function(response) {
                if (response.success) {
                    var stages = response.data;
                    var options = '<option value="">' + ispag_workflow_admin.any_status + '</option>';
                    $.each(stages, function(index, stage) {
                        options += '<option value="' + stage.stage_key + '" ' +
                                  (stage.stage_key === selectedValue ? 'selected' : '') + '>' +
                                  stage.stage_label + '</option>';
                    });
                    selectElement.html(options);
                } else {
                    selectElement.html('<option value="">' + (ispag_workflow_admin.error_label || 'Error loading stages') + '</option>');
                }
            },
            error: function() {
                selectElement.html('<option value="">' + (ispag_workflow_admin.error_label || 'Error loading stages') + '</option>');
            }
        });
    }

    // Ajouter un déclencheur
    $('#ispag-add-trigger').on('click', function() {
        var index = $('#ispag-workflow-triggers-container .ispag-workflow-trigger').length;
        var triggerHtml = `
            <div class="ispag-workflow-trigger" data-index="${index}">
                <h4>${ispag_workflow_admin.trigger_label || 'Trigger'} ${index + 1}</h4>
                <div class="ispag-field-group">
                    <label>${ispag_workflow_admin.trigger_type_label || 'Trigger Type:'}</label>
                    <select name="ispag_workflow_triggers[${index}][type]" class="widefat ispag-trigger-type">
                        <option value="status_change">${ispag_workflow_admin.status_change_label || 'Status Change'}</option>
                        <option value="email_response">${ispag_workflow_admin.email_response_label || 'Email Response'}</option>
                        <option value="task_completed">${ispag_workflow_admin.task_completed_label || 'Task Completed'}</option>
                    </select>
                </div>

                <!-- Champs pour le déclencheur "status_change" -->
                <div class="ispag-status-change-fields" style="display: none;">
                    <div class="ispag-field-group">
                        <label>${ispag_workflow_admin.from_status_label || 'From Status:'}</label>
                        <select name="ispag_workflow_triggers[${index}][from_status]" class="widefat ispag-from-status"></select>
                    </div>
                    <div class="ispag-field-group">
                        <label>${ispag_workflow_admin.to_status_label || 'To Status:'}</label>
                        <select name="ispag_workflow_triggers[${index}][to_status]" class="widefat ispag-to-status"></select>
                    </div>
                </div>

                <!-- Champs pour les autres types de déclencheurs -->
                <div class="ispag-other-trigger-fields">
                    <p>${ispag_workflow_admin.no_settings_label || 'No additional settings for this trigger type.'}</p>
                </div>

                <button type="button" class="button ispag-remove-trigger">${ispag_workflow_admin.remove_trigger_label || 'Remove Trigger'}</button>
                <hr>
            </div>
        `;
        $('#ispag-workflow-triggers-container').append(triggerHtml);

        // Chargez les statuts pour les nouveaux sélecteurs
        var fromStatusSelect = $('#ispag-workflow-triggers-container .ispag-workflow-trigger:last .ispag-from-status');
        var toStatusSelect = $('#ispag-workflow-triggers-container .ispag-workflow-trigger:last .ispag-to-status');
        loadDealStages(fromStatusSelect);
        loadDealStages(toStatusSelect);
    });

    // Supprimer un déclencheur
    $(document).on('click', '.ispag-remove-trigger', function() {
        $(this).closest('.ispag-workflow-trigger').remove();
        // Réorganiser les indices si nécessaire
        reindexTriggers();
    });

    // Réindexer les déclencheurs après suppression
    function reindexTriggers() {
        $('#ispag-workflow-triggers-container .ispag-workflow-trigger').each(function(index) {
            $(this).attr('data-index', index);
            $(this).find('input, select').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/ispag_workflow_triggers\[\d+\]/g, `ispag_workflow_triggers[${index}]`));
                }
            });
            $(this).find('h4').text(`${ispag_workflow_admin.trigger_label || 'Trigger'} ${index + 1}`);
        });
    }

    // Afficher/masquer les champs en fonction du type de déclencheur
    $(document).on('change', '.ispag-trigger-type', function() {
        var triggerContainer = $(this).closest('.ispag-workflow-trigger');
        var selectedType = $(this).val();

        if (selectedType === 'status_change') {
            triggerContainer.find('.ispag-status-change-fields').show();
            triggerContainer.find('.ispag-other-trigger-fields').hide();

            // Chargez les statuts si ce n'est pas déjà fait
            var fromStatusSelect = triggerContainer.find('.ispag-from-status');
            var toStatusSelect = triggerContainer.find('.ispag-to-status');
            if (fromStatusSelect.find('option').length <= 1) {
                loadDealStages(fromStatusSelect);
                loadDealStages(toStatusSelect);
            }
        } else {
            triggerContainer.find('.ispag-status-change-fields').hide();
            triggerContainer.find('.ispag-other-trigger-fields').show();
        }
    });

    // Déclencher le change pour les déclencheurs existants
    $('.ispag-trigger-type').trigger('change');

    // =============================================
    // LOCALISATION DES TEXTES
    // =============================================
    // Si les textes ne sont pas définis dans wp_localize_script, on utilise des valeurs par défaut
    ispag_workflow_admin = ispag_workflow_admin || {};
    ispag_workflow_admin.step_label = ispag_workflow_admin.step_label || 'Step';
    ispag_workflow_admin.step_type_label = ispag_workflow_admin.step_type_label || 'Step Type:';
    ispag_workflow_admin.email_label = ispag_workflow_admin.email_label || 'Email';
    ispag_workflow_admin.task_label = ispag_workflow_admin.task_label || 'Task';
    ispag_workflow_admin.call_label = ispag_workflow_admin.call_label || 'Call';
    ispag_workflow_admin.delay_label = ispag_workflow_admin.delay_label || 'Delay (e.g., 1 day, 2 hours):';
    ispag_workflow_admin.title_label = ispag_workflow_admin.title_label || 'Title/Subject:';
    ispag_workflow_admin.content_label = ispag_workflow_admin.content_label || 'Content/Description:';
    ispag_workflow_admin.remove_step_label = ispag_workflow_admin.remove_step_label || 'Remove Step';
    ispag_workflow_admin.trigger_label = ispag_workflow_admin.trigger_label || 'Trigger';
    ispag_workflow_admin.trigger_type_label = ispag_workflow_admin.trigger_type_label || 'Trigger Type:';
    ispag_workflow_admin.status_change_label = ispag_workflow_admin.status_change_label || 'Status Change';
    ispag_workflow_admin.email_response_label = ispag_workflow_admin.email_response_label || 'Email Response';
    ispag_workflow_admin.task_completed_label = ispag_workflow_admin.task_completed_label || 'Task Completed';
    ispag_workflow_admin.from_status_label = ispag_workflow_admin.from_status_label || 'From Status:';
    ispag_workflow_admin.to_status_label = ispag_workflow_admin.to_status_label || 'To Status:';
    ispag_workflow_admin.no_settings_label = ispag_workflow_admin.no_settings_label || 'No additional settings for this trigger type.';
    ispag_workflow_admin.remove_trigger_label = ispag_workflow_admin.remove_trigger_label || 'Remove Trigger';
    ispag_workflow_admin.loading_label = ispag_workflow_admin.loading_label || 'Loading...';
    ispag_workflow_admin.error_label = ispag_workflow_admin.error_label || 'Error loading stages';
});