jQuery(document).ready(function($) {
    // --- SÉLECTEURS ---
    const $modal = $('#ispag-template-modal');
    const $modalContent = $(".ispag-modal-content");
    const $folderModal = $('#ispag-folder-modal');
    const $form = $('#ispag-template-form');
    const $editable = $('#tpl-content-editable');
    const $hiddenInput = $('#tpl-content');
    const $status = $('#tpl-status-msg');

    let savedRange = null; // Pour l'insertion précise du badge
    let lastFocusedInput = $editable; 

    // --- 1. INITIALISATION (DRAGGABLE & CENTRAGE) ---
    if ($modalContent.length) {
        $modalContent.draggable({
            handle: ".ispag-modal-header",
            containment: "window",
            start: function(event, ui) {
                $(this).css({
                    transform: 'none',
                    top: ui.position.top + 'px',
                    left: ui.position.left + 'px'
                });
            }
        });
    }

    function openModal() {
        $modalContent.css({
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)'
        });
        $modal.fadeIn(200).addClass('active');
        $editable.focus();
    }

    function closeModal() {
        $modal.fadeOut(200).removeClass('active');
    }

    // --- 2. GESTION DU CURSEUR ET DU CONTENU ---

    function saveCaretPosition() {
        const sel = window.getSelection();
        if (sel.rangeCount > 0) {
            savedRange = sel.getRangeAt(0);
        }
    }

    $editable.on('mouseup keyup focus input', function() {
        saveCaretPosition();
        updateHiddenInput();
    });

    $('#tpl-subject, #tpl-content-editable').on('focus', function() {
        lastFocusedInput = $(this);
    });

    function updateHiddenInput() {
        if (!$editable.length) return;
        let content = $editable.html();
        content = content.replace(/<div>/gi, '\n').replace(/<\/div>/gi, '').replace(/<br\s*[\/]?>/gi, '\n');
        
        const tempDiv = $('<div>').html(content);
        tempDiv.find('[data-variable]').each(function() {
            $(this).replaceWith($(this).data('variable'));
        });
        
        $hiddenInput.val(tempDiv.text().trim());
    }

    function loadContentToVisualEditor(textContent) {
        if (!textContent) { $editable.empty(); return; }
        let visualContent = textContent.replace(/\{\{(.*?)\}\}/g, function(match) {
            let label = match.replace('{{', '').replace('}}', '').replace(/_/g, ' ');
            return `<span data-variable="${match}" contenteditable="false">${label}</span>`;
        });
        visualContent = visualContent.replace(/\n/g, '<br>');
        $editable.html(visualContent);
        $hiddenInput.val(textContent);
    }

    // --- 3. INSERTION DES VARIABLES (PILLS) ---

    $('.ispag-variable-badge').on('mousedown', function(e) {
        e.preventDefault(); 
    });

    $('.ispag-variable-badge').on('click', function(e) {
        e.preventDefault();
        const tag = $(this).data('tag');
        const label = $(this).text();

        if (lastFocusedInput.attr('id') === 'tpl-content-editable') {
            $editable.focus();
            const sel = window.getSelection();
            if (savedRange) {
                sel.removeAllRanges();
                sel.addRange(savedRange);
            }
            const badgeHtml = `<span data-variable="${tag}" contenteditable="false">${label}</span>&nbsp;`;
            document.execCommand('insertHTML', false, badgeHtml);
            saveCaretPosition();
            updateHiddenInput();
        } else {
            const input = lastFocusedInput[0];
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const text = $(input).val();
            $(input).val(text.substring(0, start) + tag + text.substring(end));
            const newPos = start + tag.length;
            input.setSelectionRange(newPos, newPos);
            input.focus();
        }
    });

    // --- 4. GESTION DES DOSSIERS (FOLDERS) ---

    $('#ispag-add-new-folder').on('click', function() {
        $folderModal.fadeIn(200).css('display', 'flex');
        $('#new-folder-name').val('').focus();
    });

    $('.ispag-close-folder-modal, #cancel-folder').on('click', function() {
        $folderModal.fadeOut(200);
    });

    $('#save-new-folder').on('click', function() {
        const folderName = $('#new-folder-name').val();
        const isPersonal = $('#folder-is-personal').is(':checked') ? 1 : 0;

        if(!folderName) return;

        $.ajax({
            url: ispag_crm_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_save_folder',
                security: ispag_crm_obj.nonce,
                folder_name: folderName,
                is_personal: isPersonal
            },
            beforeSend: function() {
                $('#folder-status-msg').text('Enregistrement...').css('color', '#666');
            },
            success: function(response) {
                if(response.success) {
                    $('#folder-status-msg').text('Dossier créé !').css('color', 'green');
                    setTimeout(() => { location.reload(); }, 600);
                } else {
                    $('#folder-status-msg').text(response.data).css('color', 'red');
                }
            }
        });
    });

    // --- 5. ACTIONS TEMPLATES (OUVERTURE & ÉDITION) ---

    $('#ispag-add-new-tpl').on('click', function(e) {
        e.preventDefault();
        $form[0].reset();
        $('#tpl-id').val('');
        $editable.empty();
        $hiddenInput.val('');
        if ($('#tpl-personal').is(':disabled')) $('#tpl-personal').prop('checked', true);
        openModal();
    });

    $(document).on('click', '.ispag-edit-template', function(e) {
        e.preventDefault();
        const templateId = $(this).data('id');
        const $btn = $(this);
        $.ajax({
            url: ispag_crm_obj.ajax_url,
            type: 'POST',
            data: { action: 'ispag_get_template_raw', security: ispag_crm_obj.nonce, template_id: templateId },
            beforeSend: function() { $btn.addClass('loading'); },
            success: function(response) {
                if (response.success) {
                    const tpl = response.data;
                    $('#tpl-id').val(tpl.id);
                    $('#tpl-name').val(tpl.name);
                    $('#tpl-folder').val(tpl.folder_id);
                    $('#tpl-lang').val(tpl.language);
                    $('#tpl-subject').val(tpl.subject);
                    
                    if (!$('#tpl-personal').is(':disabled')) {
                        $('#tpl-personal').prop('checked', parseInt(tpl.is_personal) === 1);
                    } else {
                        $('#tpl-personal').prop('checked', true);
                    }
                    
                    loadContentToVisualEditor(tpl.content);
                    openModal();
                }
            },
            complete: function() { $btn.removeClass('loading'); }
        });
    });

    $('.ispag-close-modal, #ispag-template-modal').on('click', function(e) {
        if (e.target !== this && !$(e.target).hasClass('ispag-close-modal')) return;
        closeModal();
    });

    // --- 6. SAUVEGARDE TEMPLATE ---

    $form.on('submit', function(e) {
        e.preventDefault();
        updateHiddenInput();
        let formData = $form.serializeArray();
        
        if ($('#tpl-personal').is(':disabled') && $('#tpl-personal').is(':checked')) {
            formData.push({ name: 'is_personal', value: 'on' });
        }

        $.ajax({
            url: ispag_crm_obj.ajax_url,
            type: 'POST',
            data: $.param(formData) + '&action=ispag_save_template&security=' + ispag_crm_obj.nonce,
            beforeSend: function() { $status.text('Enregistrement...').css('color', '#666'); },
            success: function(response) {
                if (response.success) { location.reload(); }
                else { $status.text(response.data).css('color', 'red'); }
            }
        });
    });

    // --- 7. RECHERCHE & FILTRES ---

    $('#tpl-search').on('keyup', function() {
        const value = $(this).val().toLowerCase();
        $("#the-list tr").each(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });

    $('#tpl-filter-owner').on('change', function() {
        const filter = $(this).val();
        $("#the-list tr").each(function() {
            if (filter === 'all') $(this).show();
            else if (filter === 'common') $(this).hasClass('ispag-tpl-common') ? $(this).show() : $(this).hide();
            else if (filter === 'mine') $(this).hasClass('ispag-tpl-personal') ? $(this).show() : $(this).hide();
        });
    });

});