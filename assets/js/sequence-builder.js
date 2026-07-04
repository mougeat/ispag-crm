jQuery(document).ready(function($) {
    console.log("=== ISPAG Sequence Builder Loaded ===");
    let stepCount = 0;

    // 1. Initialisation du Drag & Drop
    // Note : On utilise #steps-list car c'est l'ID dans ton template PHP
    $("#steps-list").sortable({
        handle: ".step-drag-handle", // La poignée ajoutée dans le template
        placeholder: "ui-state-highlight",
        start: function(e, ui) {
            // Désactiver TinyMCE avant le déplacement pour éviter qu'il ne fige
            const editorId = ui.item.find('.step-content-editor').attr('id');
            if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.execCommand('mceRemoveEditor', false, editorId);
            }
        },
        stop: function(e, ui) {
            // Réactiver TinyMCE après le dépôt
            const editorId = ui.item.find('.step-content-editor').attr('id');
            if (typeof tinymce !== 'undefined') {
                initTinyMCE(editorId);
            }
            reindexSteps();
        }
    });

    // 2. Fonction d'initialisation TinyMCE isolée
    function initTinyMCE(id) {
        if (typeof tinymce === 'undefined') return;
        tinymce.init({
            selector: '#' + id,
            height: 250,
            menubar: false,
            branding: false,
            plugins: 'lists link paste',
            toolbar: 'undo redo | bold italic | bullist numlist | link | removeformat',
            setup: function(editor) {
                editor.on('change', function() { editor.save(); });
            }
        });
    }

    // 3. Ajouter une étape
    function addStep(data = null) {
        const template = $('#step-template').html();
        if (!template) return;

        stepCount++;
        const uniqueId = 'step-editor-' + stepCount;
        const $newStep = $(template);

        const $textarea = $newStep.find('.step-content-editor');
        $textarea.attr('id', uniqueId);

        // Remplissage des données existantes (Édition)
        if (data) {
            $newStep.find('.step-type').val(data.action_type || 'TASK');
            $newStep.find('.step-delay').val(data.delay_days || 2);
            $newStep.find('.step-objective').val(data.objective || '');
            $newStep.find('.step-value-added').val(data.value_added || '');
            $newStep.find('.step-subject').val(data.subject || '');
            $newStep.find('.step-condition-type').val(data.condition_type || '');
            $newStep.find('.step-if-false').val(data.if_false_step_number || '');
            $textarea.val(data.content || '');

            if (data.action_type !== 'EMAIL') $newStep.find('.email-only-fields').hide();
            if (data.condition_type) $newStep.find('.condition-logic').show();
        }

        $('#steps-list').append($newStep);
        initTinyMCE(uniqueId);
        reindexSteps();
    }

    // 4. Reindexation (utilisée par le sortable et la suppression)
    function reindexSteps() {
        $('.sequence-step').each(function(index) {
            $(this).find('.step-index').text(index + 1);
        });
    }

    // --- EVENEMENTS ---

    // Chargement initial (Edition)
    if (typeof ispag_editing_sequence !== 'undefined' && ispag_editing_sequence !== null) {
        if (ispag_editing_sequence.steps && ispag_editing_sequence.steps.length > 0) {
            ispag_editing_sequence.steps.forEach(function(step) {
                addStep(step);
            });
        }
    }

    $('#add-step-btn').on('click', function(e) {
        e.preventDefault();
        addStep();
    });

    $(document).on('click', '.remove-step', function() {
        if (!confirm('Supprimer cette étape ?')) return;
        const editorId = $(this).closest('.sequence-step').find('.step-content-editor').attr('id');
        if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
            tinymce.get(editorId).remove();
        }
        $(this).closest('.sequence-step').remove();
        reindexSteps();
    });

    // Gestion dynamique Type Action
    $(document).on('change', '.step-type', function() {
        const $step = $(this).closest('.sequence-step');
        $(this).val() === 'EMAIL' ? $step.find('.email-only-fields').slideDown() : $step.find('.email-only-fields').slideUp();
    });

    // Gestion dynamique Condition
    $(document).on('change', '.step-condition-type', function() {
        const val = $(this).val();
        const $step = $(this).closest('.sequence-step');
        const $logicBox = $step.find('.condition-logic');
        const $unit = $step.find('.unit-label');
        const $operator = $step.find('.step-condition-operator');

        if (val !== "") {
            $logicBox.slideDown();
            
            // Personnalisation selon le type
            if (val === 'DEAL_AMOUNT') {
                $unit.text(' €');
                $operator.show();
            } else if (val === 'LAST_CONTACT') {
                $unit.text(' jours');
                $operator.val('>').show(); // Souvent on veut "plus de X jours"
            } else {
                $unit.text('');
                $operator.hide(); // Pour MAIL_OPENED, pas besoin d'opérateur
            }
        } else {
            $logicBox.slideUp();
        }
    });

    // Sauvegarde
    $('#save-full-sequence').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const steps = [];

        if (typeof tinymce !== 'undefined') tinymce.triggerSave();

        $('.sequence-step').each(function(index) {
            steps.push({
                step_number: index + 1,
                type:        $(this).find('.step-type').val(),
                content:     $(this).find('.step-content-editor').val(),
                delay:       $(this).find('.step-delay').val(),
                objective:   $(this).find('.step-objective').val(),
                value_added: $(this).find('.step-value-added').val(),
                subject:     $(this).find('.step-subject').val(),
                condition_type: $(this).find('.step-condition-type').val(),
                if_false_step:  $(this).find('.step-if-false').val()
            });
        });

        const data = {
            action: 'save_crm_sequence',
            security: ispag_ajax_sequence.nonce,
            sequence: {
                id: $('#seq-id').val(), 
                name: $('#seq-name').val(),
                description: $('#seq-desc').val(),
                steps: steps
            }
        };

        if(!data.sequence.name) {
            alert('Donne un nom à ta séquence !');
            return;
        }

        btn.attr('disabled', true).text('Enregistrement...');

        $.post(ispag_ajax_sequence.ajax_url, data, function(response) {
            if(response.success) {
                window.location.href = 'admin.php?page=ispag-sequences'; 
            } else {
                alert('Erreur: ' + (response.data.message || 'Inconnue'));
                btn.attr('disabled', false).text('Enregistrer');
            }
        });
    });

    // Gestion des templates
    $(document).on('change', '.step-template-selector', function() {
        const $option = $(this).find(':selected');
        const $step = $(this).closest('.sequence-step');
        const editorId = $step.find('.step-content-editor').attr('id');
        
        if ($(this).val() !== "") {
            $step.find('.step-subject').val($option.data('subject'));
            if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).setContent($option.data('content'));
            } else {
                $step.find('.step-content-editor').val($option.data('content'));
            }
            $step.find('.email-only-fields').show();
        }
    });
});