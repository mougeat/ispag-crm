window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
// ispag-creation-modal.js
// Responsabilité : Gestion complète de la modale de création/édition d'activité.

jQuery(document).ready(function($) {
    
    /* ==========================================================================
       1. VARIABLES ET CONFIGURATION
       ========================================================================== */
    const modal = $('#ispag-note-modal');
    const closeButton = $('.ispag-close-modal');
    const modalContent = $('.ispag-modal-content');
    const modalHeader = $('.ispag-modal-header');
    const createNoteForm = $('#create-note-form');
    const createNoteBtn = $('#ispag-create-note-btn');

    // Sélecteurs de champs
    const activityTypeSelect = $('#activity-type');
    const taskCheckbox       = $('#create-task-checkbox');
    const activityTitleLabel = $('#activity-title-label');
    const activityTitleInput = $('#activity-title-input');
    const noteTextArea       = $('#note-text-area');
    
    // Conteneurs de sections
    const taskFields    = $('.ispag-reminder-field');
    const meetingFields = $('.ispag-meeting-fields');
    const callFields    = $('.ispag-call-fields');
    const emailFields   = $('.ispag-email-fields');
    const noteFields    = $('.ispag-note-fields');

    // Select2
    const contactSelect = $('#meeting-attendees-select');
    const companySelect = $('#meeting-companies-select');
    const dealSelect    = $('#meeting-deals-select');

    // Hidden Inputs
    const modalActivityId = $('#modal-activity-id');
    const modalActionType = $('#modal-action-type');

    // Dates & Times
    const meetingOutcome = $('#meeting-outcome');
    const meetingDate    = $('#meeting-date');
    const meetingTime    = $('#meeting-time');
    const callOutcome    = $('#meeting-outcome-call');
    const callDate       = $('#meeting-date-call');
    const callTime       = $('#meeting-time-call');
    const taskDueOffsetSelect = $('#task-due-offset');
    const taskDueTime         = $('#task-due-time');
    const customDateContainer = $('#container-due-date-custom');
    const taskReminderOffset  = $('#task-reminder-offset');
    const taskDueDateCustom   = $('#task-due-date-custom');

    let dueDate = '';      
    let dueOffset = '';    
    let dueTime = '';      
    let reminderOffset = '';

    const errorBox   = $('#ispag-note-error');
    const typeIcons  = { note: 'edit', task: 'yes-alt', meeting: 'groups', call: 'phone', email: 'email-alt', mail: 'email-alt', log_email: 'email-alt', whatsapp: 'format-chat' };
    let isDirty = false;

    /** Titre de l'en-tête + icône du type d'activité. */
    function setModalTitle(text, type) {
        modalHeader.find('.ispag-modal-title-text').text(text);
        modalHeader.find('.ispag-modal-type-icon').attr('class', 'dashicons dashicons-' + (typeIcons[type] || 'edit') + ' ispag-modal-type-icon');
    }

    function showError(message, $field) {
        errorBox.text(message).prop('hidden', false);
        if ($field && $field.length) { $field.addClass('ispag-field-invalid').trigger('focus'); }
    }

    function clearError() {
        errorBox.prop('hidden', true).text('');
        $('.ispag-field-invalid').removeClass('ispag-field-invalid');
    }

    // Toute saisie marque le formulaire comme modifié (évite de perdre un texte par un clic à côté)
    modal.on('input change', 'input, textarea, select', function() { isDirty = true; clearError(); });

    function editorHasContent() {
        const ed = (window.tinymce && tinymce.get('note-text-area'));
        const html = ed ? ed.getContent() : noteTextArea.val();
        return !!html && html.trim() !== '' && html.trim() !== '<p></p>';
    }

    /** Fermeture demandée par l'utilisateur : confirmation si du texte risque d'être perdu. */
    function requestClose() {
        if (isDirty && (activityTitleInput.val().trim() !== '' || editorHasContent())) {
            if (!window.confirm(ispagNoteData.textConfirmDiscard || 'Discard this draft?')) return;
        }
        closeModal();
    }

    // Puces d'échéance : pilotent le select #task-due-offset (qui reste la source de vérité)
    function syncDueChips() {
        const v = taskDueOffsetSelect.val();
        $('.ispag-due-chips .ispag-chip').each(function() {
            $(this).toggleClass('is-active', $(this).data('due-offset') === v);
        });
    }
    $(document).on('click', '.ispag-due-chips .ispag-chip', function() {
        taskDueOffsetSelect.val($(this).data('due-offset')).trigger('change');
        isDirty = true;
    });
    taskDueOffsetSelect.on('change', syncDueChips);

    /* ==========================================================================
       2. FONCTIONS UTILITAIRES & RENDU
       ========================================================================== */

    // 1. On crée une fonction de mise à jour
    function toggleCustomDate() {
        const isCustom = taskDueOffsetSelect.val() === 'custom';
        customDateContainer.toggle(isCustom); 
    }

    // 2. On écoute les changements de valeur
    taskDueOffsetSelect.on('change', toggleCustomDate);

    // 3. On l'exécute une fois au chargement (au cas où la modale s'ouvre avec une valeur pré-remplie)
    toggleCustomDate();
    syncDueChips();

    /**
     * Ferme la modale et réinitialise le formulaire.
     */
    function closeModal() {
        modal.removeClass('is-open');
        isDirty = false;
        clearError();
        setTimeout(function() {
            if (createNoteForm.length) createNoteForm[0].reset();
            contactSelect.val(null).trigger('change');
            companySelect.val(null).trigger('change');
            dealSelect.val(null).trigger('change');

            taskCheckbox.prop('checked', false);
            taskFields.hide();
            meetingFields.hide();
            callFields.hide();
            emailFields.hide();
            noteFields.show();

            setModalTitle(ispagNoteData.modalTitleDefault || 'Note', 'note');
            createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
            createNoteBtn.removeAttr('data-action');
            createNoteBtn.removeData('action');

            $('#activity-id-edit').val('');
            modalActivityId.val('');
            toggleCustomDate();
            syncDueChips();
            isDirty = false;

            if (window.tinymce && tinymce.get('note-text-area')) {
                tinymce.get('note-text-area').setContent('');
            }
        }, 150); // même durée que la transition CSS (0.15s)
    }

    /**
     * Convertit les sauts de ligne simples (\n) en balises HTML pour TinyMCE.
     */
    function formatContentForTinyMCE(content) {
        if (!content) return "";
        let formatted = content.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br />');
        return '<p>' + formatted + '</p>';
    }

    /**
     * Traite les "pills" {{variable}} dans un texte.
     * Récupère le nom du 1er contact sélectionné dans Select2.
     */
    function processTemplatePills(content) {
        if (!content) return "";

        console.group("DEBUG : Traitement avancé des Pills");
        
        // 1. DONNÉES CONTACTS
        const contactData = $('#meeting-attendees-select').select2('data');
        let c = { full_name: "", first_name: "", last_name: "", email: "", phone: "" };

        if (contactData && contactData.length > 0) {
            const contact = contactData[0];
            c.full_name = contact.text || "";
            const nameParts = c.full_name.trim().split(' ');
            c.first_name = nameParts[0] || "";
            c.last_name = nameParts.length > 1 ? nameParts.slice(1).join(' ') : "";
            c.email = contact.email || ""; 
            c.phone = contact.phone || "";
        }

        // 2. DONNÉES PROJET / DEAL (Noms synchronisés avec ton PHP)
        const dealData = $('#meeting-deals-select').select2('data');
        let d = { name: "", offer: "", project: "", closing_date: "", total: "" };
        
        if (dealData && dealData.length > 0) {
            const deal = dealData[0];
            // console.log("Données brutes du deal sélectionné :", deal);
            
            d.name         = deal.project_name || deal.text || "";
            d.offer        = deal.offer_num || "";
            d.project      = deal.project_num || "";
            d.closing_date = deal.closing_date || "";
            d.total        = deal.total_excl_vat || ""; // <--- SYNCHRO AVEC PHP
        }

        // 3. MAPPING DES TAGS
        const mapObj = {
            // Tags Contact
            "{{contact_full_name}}":  c.full_name,
            "{{contact_first_name}}": c.first_name,
            "{{contact_last_name}}":  c.last_name,
            "{{contact_email}}":      c.email,
            "{{contact_phone}}":      c.phone,
            
            // Tags Projet (Deal)
            "{{deal_name}}":          d.name,
            "{{deal_offer_num}}":     d.offer,
            "{{deal_project_num}}":   d.project,
            "{{deal_closing_date}}":  d.closing_date,
            "{{deal_total}}":         d.total, // <--- SYNCHRO ICI AUSSI
            
            // Tags Système & Entreprise
            "{{user_name}}":          ispagNoteData.current_user_name || "",
            "{{date_today}}":         new Date().toLocaleDateString('fr-FR'),
            "{{company_name}}":       $('#meeting-companies-select').select2('data')[0]?.text || ""
        };

        // console.log("Table de remplacement finale :", mapObj);

        // 4. REMPLACEMENT (Regex)
        // On trie par longueur pour éviter les conflits de noms
        const sortedKeys = Object.keys(mapObj).sort((a, b) => b.length - a.length);
        const re = new RegExp(sortedKeys.map(k => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join("|"), "gi");
        
        const result = content.replace(re, (matched) => {
            const replacement = mapObj[matched.toLowerCase()];
            return (replacement !== undefined && replacement !== null) ? replacement : "";
        });

        console.groupEnd();
        return result;
    }

    /**
     * Gère l'affichage dynamique des champs selon le type.
     */
    window.toggleActivityFields = function(selectedType, originText = '', editMode = false) {
        if(! editMode){
            taskCheckbox.prop('checked', false);
            taskFields.hide();
        }
            [meetingFields, callFields, emailFields, noteFields].forEach(f => f.hide());
        
        
        const type = selectedType.toLowerCase();
        modalActionType.val(type);

        let finalBtnText = '';

        switch (type) {
            case 'task':
                activityTitleLabel.text(ispagNoteData.textTaskTitle);
                activityTitleInput.attr('placeholder', ispagNoteData.textNoteTitleInput + '...');
                
                if(! editMode){
                    taskCheckbox.prop('checked', true);
                    taskFields.show();
                }
                createNoteBtn.attr('data-action', 'log');
                finalBtnText = originText ? (ispagNoteData.textCreate + ' ' + originText) : (ispagNoteData.textCreate + ' ' + type);
                createNoteBtn.text(finalBtnText);
                break;
            case 'meeting':
                activityTitleLabel.text(ispagNoteData.textMeetingTitle);
                activityTitleInput.attr('placeholder', ispagNoteData.textNoteTitleInput + '...');
                meetingFields.show();
                
                createNoteBtn.attr('data-action', 'log');
                finalBtnText = originText ? (ispagNoteData.textCreate + ' ' + originText) : (ispagNoteData.textCreate + ' ' + type);
                createNoteBtn.text(finalBtnText);
                break;
            case 'call':
                activityTitleLabel.text(ispagNoteData.textCallTitle);
                activityTitleInput.attr('placeholder', ispagNoteData.textNoteTitleInput + '...');
                callFields.show();
                
                createNoteBtn.attr('data-action', 'log');
                finalBtnText = originText ? (ispagNoteData.textCreate + ' ' + originText) : (ispagNoteData.textCreate + ' ' + type);
                createNoteBtn.text(finalBtnText);
                break;
            case 'email':
            case 'mail':
                activityTitleLabel.text(ispagNoteData.textMailSubject);
                activityTitleInput.attr('placeholder', ispagNoteData.textMailSubjectInput + '...');
                emailFields.show();
                
                createNoteBtn.attr('data-action', 'send_mail');
                finalBtnText = ispagNoteData.textPrepareMail;
                createNoteBtn.text(finalBtnText);
                break;
            case 'log_email':
                activityTitleLabel.text(ispagNoteData.textMailSubject);
                activityTitleInput.attr('placeholder', ispagNoteData.textMailSubjectInput + '...');
                emailFields.show();
                
                createNoteBtn.attr('data-action', 'log');
                finalBtnText = originText ? (ispagNoteData.textCreate + ' ' + originText) : (ispagNoteData.textCreate + ' ' + type);
                createNoteBtn.text(finalBtnText);
                break;
            case 'whatsapp':
                activityTitleLabel.text(ispagNoteData.textMailSubject);
                activityTitleInput.attr('placeholder', ispagNoteData.textMailSubjectInput + '...');
                emailFields.show();
                
                createNoteBtn.attr('data-action', 'send');
                finalBtnText = originText ? (ispagNoteData.textSend + ' ' + originText) : (ispagNoteData.textSend + ' ' + type);
                createNoteBtn.text(finalBtnText);
                break;
            default:
                activityTitleLabel.text(ispagNoteData.textNoteTitle);
                activityTitleInput.attr('placeholder', ispagNoteData.textNoteTitleInput + '...');
                noteFields.show();
                createNoteBtn.text(ispagNoteData.textCreate + ' ' + type);
                createNoteBtn.attr('data-action', 'log');
                finalBtnText = originText ? (ispagNoteData.textCreate + ' ' + originText) : (ispagNoteData.textCreate + ' ' + type);
                createNoteBtn.text(finalBtnText);
                break;
        }
        if (!editMode) setModalTitle(finalBtnText || type, type);
        checkNoteTypeForTemplate();
    };

    function checkNoteTypeForTemplate() {
        const type = modalActionType.val();
        if (type === 'email' || type === 'mail') {
            $('#ispag-note-template-wrapper').slideDown(200);
            $('.ispag-eml-hint').slideDown(200);
        } else {
            $('#ispag-note-template-wrapper').slideUp(200);
            $('.ispag-eml-hint').slideUp(200);
        }
    }

    /* ==========================================================================
       3. INITIALISATION SELECT2
       ========================================================================== */
    const select2Config = (action, placeholder) => ({
        dropdownParent: modal,
        placeholder: placeholder,
        allowClear: true,
        ajax: {
            url: ispagNoteData.ajaxurl,
            dataType: 'json',
            delay: 250,
            data: params => ({ 
                action: action, 
                security: ispagNoteData.nonce, 
                search_term: params.term
            }),
            processResults: data => {
                if (!data.success) return { results: [] };

                // On récupère le tableau brut envoyé par le PHP
                const rawItems = data.data.contacts || data.data.companies || data.data.deals || [];
                // console.log('Select2 Raw items', rawItems);

                return {
                    results: rawItems.map(item => {
                        // C'EST ICI QUE LA MAGIE OPÈRE :
                        // On retourne un objet qui contient TOUTES les propriétés originales (...item)
                        // Select2 a impérativement besoin de 'id' et 'text'.
                        return {
                            ...item,
                            id: item.id || item.ID || item.deal_group_ref,
                            text: item.text || item.project_name
                        };
                    })
                };
            },
            cache: true
        }
    });

    contactSelect.select2(select2Config('ispag_search_contacts_select2', ispagNoteData.textSelectContacts));
    companySelect.select2(select2Config('ispag_search_company_select2', ispagNoteData.textSelectCompanies));
    dealSelect.select2(select2Config('ispag_search_deals_select2', ispagNoteData.textSelectDeals));

    /* ==========================================================================
       4. GESTIONNAIRES D'ÉVÉNEMENTS (MODALE & FORMULAIRE)
       ========================================================================== */

    // Ouverture Modale (Boutons d'action)
    $(document).on('click', '.ispag-action-btn[data-action]', function(e) { 
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);

        // On récupère le texte du bouton (en enlevant les espaces superflus)
        const buttonText = $btn.text().trim();
        
        // extraData peut être une fonction (index) => données propres à chaque option
        const populate = (sel, ids, names, extraData = {}) => {
            const s = $(sel).val(null);
            if (ids && names) {
                const idArr = ids.toString().split(','), nameArr = names.toString().split(',');
                idArr.forEach((id, i) => {
                    const newOpt = new Option(nameArr[i] || 'Inconnu', id.trim(), true, true);
                    
                    // --- LA MAGIE EST ICI ---
                    // On fusionne le texte et l'ID avec les données extra (ex: offer_num)
                    const fullData = { 
                        id: id.trim(), 
                        text: nameArr[i], 
                        ...(typeof extraData === 'function' ? extraData(i) : extraData)
                    };
                    
                    // On attache ces données à l'élément DOM de l'option
                    $(newOpt).data('data', fullData); 
                    s.append(newOpt);
                });
                s.trigger('change');
            }
        };

        // Pour les contacts (si tu as besoin de l'email/tel en direct)
        // Les listes e-mails / téléphones sont dans le même ordre que les ids : une valeur par contact
        const emailList = String($btn.data('contact-emails') || '').split(',');
        const phoneList = String($btn.data('contact-phones') || '').split(',');
        populate(contactSelect, $btn.data('contact-ids'), $btn.data('contact-names'), i => ({
            email: (emailList[i] || '').trim(),
            phone: (phoneList[i] || '').trim()
        }));

        populate(companySelect, $(this).data('company-ids'), $(this).data('company-names'));
        
        // Pour les deals (on récupère les infos depuis le bouton)
        populate(dealSelect, $btn.data('deal-ids'), $btn.data('deal-names'), {
            offer_num: $btn.data('deal-offer-num'),
            project_num: $btn.data('deal-project-num'),
            total_excl_vat: $btn.data('deal-total'),
            closing_date: $btn.data('deal-date')
        });

        if (typeof window.initializeTinyMCE === 'function') window.initializeTinyMCE();

        const type = $(this).data('action'); 
        if (type) {
            activityTypeSelect.val(type).trigger('change');
            window.toggleActivityFields(type, buttonText);
        }
        
        // modal.fadeIn(200);
        // modalContent.css('right', '0');

        modal.addClass('is-open');
        modalContent.css('right', '0');
        isDirty = false;
        setTimeout(() => activityTitleInput.trigger('focus'), 200);
    });

    // Fermeture
    closeButton.on('click', requestClose);
    $('#cancel-note-btn').on('click', requestClose);
    modal.on('mousedown', e => { if (e.target === modal[0]) requestClose(); });
    $(document).on('keydown', e => {
        if (!modal.hasClass('is-open')) return;
        if (e.key === 'Escape') { requestClose(); }
        // Ctrl/Cmd + Entrée : enregistrer sans quitter le clavier
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !createNoteBtn.prop('disabled')) {
            e.preventDefault();
            createNoteBtn.trigger('click');
        }
    });

    // Changements de type : On garde le type choisi (Call, Meeting, etc.)
    activityTypeSelect.on('change', function() { 
        // On passe le texte de l'option sélectionnée pour le bouton
        const selectedText = $(this).find('option:selected').text();
        window.toggleActivityFields($(this).val(), selectedText); 
    });

    // Case "Créer une tâche" : On affiche juste les champs de date
    taskCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            taskFields.fadeIn(200); // Affiche la date/heure
        } else {
            taskFields.fadeOut(200);
        }
    });

    /* ==========================================================================
       5. LOGIQUE DES TEMPLATES
       ========================================================================== */

    $(document).on('click', '#ispag-apply-template', function(e) {
        e.preventDefault();
        const templateId = $('#ispag-note-template-select').val();
        if (!templateId) return alert(ispagT("Select a template."));

        const editor = tinymce.get('note-text-area');
        let currentContent = editor ? editor.getContent() : noteTextArea.val();
        
        if (currentContent.trim() !== "" && currentContent.trim() !== "<p></p>") {
            if (!confirm(ispagNoteData.textReplaceContent || "Remplacer le contenu ?")) return;
        }

        $.ajax({
            url: ispagNoteData.ajaxurl,
            type: 'POST',
            data: { action: 'ispag_get_template_raw', security: ispagNoteData.nonce, template_id: templateId },
            beforeSend: () => $(this).prop('disabled', true).text('...'),
            success: function(response) {
                if (response.success) {
                    // Titre/Sujet
                    if (response.data.subject) {
                        activityTitleInput.val(processTemplatePills(response.data.subject));
                    }
                    // Corps
                    const processedBody = processTemplatePills(response.data.content);
                    if (editor) {
                        editor.setContent(formatContentForTinyMCE(processedBody));
                    } else {
                        noteTextArea.val(processedBody);
                    }
                }
            },
            complete: () => $(this).prop('disabled', false).text(ispagNoteData.textApply || 'Appliquer')
        });
    });

    /* ==========================================================================
       6. ENREGISTREMENT (SOUMISSION AJAX)
       ========================================================================== */

    createNoteBtn.on('click', function(e) {
        e.preventDefault();
        const editor = tinymce.get('note-text-area');

        // Récupération des contenus
        const isTask            = taskCheckbox.is(':checked');
        const noteContent       = editor ? editor.getContent() : noteTextArea.val();
        const actionType        = modalActionType.val();
        const noteContentHtml   = editor ? editor.getContent() : noteTextArea.val();
        const noteContentPlain  = editor ? editor.getContent({format: 'text'}) : noteTextArea.val();
        const activityTitle     = activityTitleInput.val();
        const submitMode        = createNoteBtn.attr('data-action');
        const activityId        = modalActivityId.val();

        clearError();
        if (noteContentHtml.trim() === "" || noteContentHtml.trim() === "<p></p>") {
            showError(ispagNoteData.textErrorContent || 'Please enter some content.', editor ? $() : noteTextArea);
            if (editor) editor.focus();
            return;
        }
        if (isTask && taskDueOffsetSelect.val() === 'custom' && !taskDueDateCustom.val()) {
            showError(ispagNoteData.textErrorDueDate || 'Please choose a due date.', taskDueDateCustom);
            return;
        }

        createNoteBtn.prop('disabled', true).text(submitMode === 'send_mail' ? ispagNoteData.textPreparingMail : ispagNoteData.textSaving);

        // --- CAS 1 : BROUILLON .EML (pas d'enregistrement DB : le CRM classe le mail à l'envoi via la copie cachée) ---
        if (submitMode === 'send_mail' && activityId == 0) {
            const contactData = contactSelect.select2('data');
            const companyData = companySelect.select2('data');
            const dealData    = dealSelect.select2('data');

            // Tous les destinataires sélectionnés (une adresse par contact)
            const recipients = contactData.map(c => c.email || '').filter(Boolean).join(',');

            let taskTs = 0;
            if (isTask) {
                let finalDate = new Date();
                const offset  = taskDueOffsetSelect.val();   // ex: "0d", "7d", "1m", "custom"
                const timeStr = taskDueTime.val() || "08:00";

                if (offset === 'custom') {
                    finalDate = new Date(taskDueDateCustom.val());
                } else if (/m$/.test(offset)) {
                    finalDate.setMonth(finalDate.getMonth() + (parseInt(offset, 10) || 0));
                } else {
                    finalDate.setDate(finalDate.getDate() + (parseInt(offset, 10) || 0));
                }
                const [hours, minutes] = timeStr.split(':');
                finalDate.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);
                taskTs = Math.floor(finalDate.getTime() / 1000);
            }

            $.post(ispagNoteData.ajaxurl, {
                action: 'ispag_build_eml',
                security: ispagNoteData.nonce,
                to: recipients,
                subject: activityTitle,
                body_html: noteContentHtml,
                deal_ref: dealData.length ? (dealData[0].offer_num || '') : '',
                user_id: contactData.length ? (contactData[0].id || '') : '',
                company_id: companyData.length ? (companyData[0].id || '') : '',
                task_ts: taskTs
            }).done(function(response) {
                if (!response.success) {
                    showError((response.data && response.data.message) || 'Error');
                    createNoteBtn.prop('disabled', false).text(ispagNoteData.textPrepareMail);
                    return;
                }
                // Téléchargement du .eml : le navigateur propose de l'ouvrir dans le client de messagerie
                const bytes = Uint8Array.from(atob(response.data.eml), ch => ch.charCodeAt(0));
                const url   = URL.createObjectURL(new Blob([bytes], { type: 'message/rfc822' }));
                const link  = document.createElement('a');
                link.href = url;
                link.download = response.data.filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 10000);
                closeModal();
            }).fail(function() {
                showError(ispagNoteData.textErrorNetwork || 'Network error, your text has been kept. Please try again.');
                createNoteBtn.prop('disabled', false).text(ispagNoteData.textPrepareMail);
            });
            return;
        }

        // --- CAS 2 : ENREGISTREMENT CLASSIQUE (AJAX) ---

        const data = {
            action: ispagNoteData.action, 
            security: ispagNoteData.nonce,
            activity_id: activityId,
            action_type: actionType,
            activity_title: activityTitle, // Ajout du titre
            note_content: noteContent,
            meeting_attendees: contactSelect.val() || [],
            meeting_companies: companySelect.val() || [],
            meeting_deals:     dealSelect.val() || [],
            is_task: taskCheckbox.is(':checked'),
            // ... (logique des dates de tâche/meeting identique à votre original)
        };

        // Gestion spécifique des dates Task/Meeting/Call
        if (actionType === 'meeting') {
            data.meeting_outcome = meetingOutcome.val();
            data.meeting_date = meetingDate.val();
            data.meeting_time = meetingTime.val();
        } else if (actionType === 'call') {
            data.meeting_outcome = callOutcome.val();
            data.meeting_date = callDate.val();
            data.meeting_time = callTime.val();
        }

        if (data.is_task) {
            data.due_offset = taskDueOffsetSelect.val();
            data.due_time = taskDueTime.val();
            data.reminder_offset = taskReminderOffset.val();
            data.due_date = (data.due_offset === 'custom') ? taskDueDateCustom.val() : data.due_offset;
        // console.log('IS TASK', data);
        }


        $.post(ispagNoteData.ajaxurl, data)
            .done(function(response) {
                if (response.success) {
                    closeModal();
                    if (response.data.whatsapp_url) window.open(response.data.whatsapp_url, '_blank');
                    
                    const newItemHtml = response.data.html;
                    const id = response.data.insert_id;
                    const existingItem = $(`.ispag-timeline-entry[data-activity-id="${id}"]`);
                    
                    if (existingItem.length) existingItem.replaceWith(newItemHtml);
                    else $('.ispag-activities-timeline').prepend(newItemHtml);

                    // --- MISE À JOUR TABLEAU DES TÂCHES (Task Table) --- 
                    if (response.data.task_html) {
                        const newTaskRowHtml = response.data.task_html;
                        const existingTaskRow = $(`#task-${id}`); // Ton ID de ligne est id="task-XXX"

                        if (existingTaskRow.length) {
                            // Si la tâche existe (Édition), on remplace la ligne
                            existingTaskRow.replaceWith(newTaskRowHtml);
                            $(document).trigger('ispag:tasks-changed');
                        } else {
                            // Si c'est une nouvelle tâche (Création)
                            // On vérifie si la ligne "No tasks found" est présente pour la supprimer
                            $('#the-list .empty-msg').closest('tr:not(.empty-state-row)').remove();
                            $('#the-list').prepend(newTaskRowHtml);
                            $(document).trigger('ispag:tasks-changed');
                        }
                    }
                } else {
                    showError((response.data && response.data.message) || 'Error');
                    createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
                }
            })
            .fail(function() {
                showError(ispagNoteData.textErrorNetwork || 'Network error, your text has been kept. Please try again.');
                createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
            });
    });

    /* ==========================================================================
    7. ÉDITION (FONCTION GLOBALE)
    ========================================================================== */
    window.openModalInEditMode = function(activityData) {
        // console.log("📦 Données reçues pour édition:", activityData);

        // 1. Initialiser TinyMCE si nécessaire AVANT d'afficher
        if (typeof window.initializeTinyMCE === 'function') {
            window.initializeTinyMCE();
        }

        // 2. Affichage immédiat de la modale (on utilise show pour éviter les bugs de fadeIn)
        // modal.show();
        modal.addClass('is-open');
        modalContent.css('right', '0');

        // 3. Remplissage des champs de base
        setModalTitle(ispagNoteData.modalTitleEdit.replace('%s', activityData.id), (activityData.type || 'note').toLowerCase());
        createNoteBtn.prop('disabled', false).text(ispagNoteData.textUpdate);
        modalActivityId.val(activityData.id);
        activityTitleInput.val(window.stripslashes_js(activityData.note_title));

        const type = (activityData.type || 'note').toLowerCase();
        modalActionType.val(type);
        activityTypeSelect.val(type).trigger('change');

        // 4. Gestion spécifique du contenu (TinyMCE ou Textarea)
        const rawContent = window.stripslashes_js(activityData.content || "");
        const editor = tinymce.get('note-text-area');

        if (editor) {
            // Sécurité : si l'éditeur n'est pas encore "ready", on attend l'événement init
            if (editor.initialized) {
                editor.setContent(rawContent);
            } else {
                editor.on('init', function() {
                    editor.setContent(rawContent);
                });
            }
        } else {
            noteTextArea.val(rawContent);
        }

        // 5. Gestion des Tâches
        if (activityData.is_task == 1) {
            taskCheckbox.prop('checked', true);
            taskFields.show();
            if (activityData.due_date_raw) {
                const parts = activityData.due_date_raw.split(' ');
                const datePart = parts[0];
                const timePart = parts[1] ? parts[1].substring(0, 5) : "08:00";

                taskDueOffsetSelect.val('custom').trigger('change');
                taskDueDateCustom.val(datePart).show();
                taskDueTime.val(timePart).show();
            }
        } else {
            taskCheckbox.prop('checked', false);
            taskFields.hide();
        }

        // 6. Gestion des Appels (Call)
        if (type === 'call') {
            callFields.show();
            const rawDate = activityData.created_date_raw || activityData.due_date_raw;
            if (rawDate) {
                const parts = rawDate.split(' ');
                callDate.val(parts[0]);
                if (parts[1]) callTime.val(parts[1].substring(0, 5));
            }
            const outcomeValue = activityData.outcome || activityData.meeting_outcome;
            if (outcomeValue) callOutcome.val(outcomeValue);
        }

        // 7. Mise à jour visuelle des champs selon le type
        window.toggleActivityFields(type, ispagNoteData.textUpdate, true);
        syncDueChips();
        isDirty = false;

        // 8. Remplissage des Select2 (Contacts, Entreprises, Deals)
        const forceS2 = ($s, ids, names, extraData = {}) => {
            $s.empty(); // On vide avant de remplir
            if (ids && names) {
                const idA = ids.toString().split(','), nameA = names.toString().split(',');
                idA.forEach((id, i) => {
                    const text = nameA[i] ? nameA[i].trim() : 'Inconnu';
                    const val = id.trim();
                    if (val) {
                        const newOpt = new Option(text, val, true, true);
                        $(newOpt).data('data', { id: val, text: text, ...extraData });
                        $s.append(newOpt);
                    }
                });
            }
            $s.trigger('change');
        };

        forceS2(contactSelect, activityData.contact_ids, activityData.contact_name_raw, {
            email: activityData.contact_emails,
            phone: activityData.contact_phones
        });
        
        forceS2(companySelect, activityData.company_ids, activityData.company_name);
        
        forceS2(dealSelect, activityData.deal_ids, activityData.deal_name, {
            offer_num: activityData.offer_num,
            project_num: activityData.project_num,
            total_excl_vat: activityData.total_excl_vat,
            closing_date: activityData.closing_date,
        });

        // Les remplissages ci-dessus déclenchent des 'change' : le formulaire n'est pas encore modifié
        isDirty = false;
    };
});


/* ==========================================================================
   GESTION DES TEMPLATES POUR LES ARTICLES
   ========================================================================== */
$(document).on('click', '#ispag-apply-article-template', function(e) {
    e.preventDefault();
    const templateId = $('#ispag-article-template-select').val();
    if (!templateId) return alert(ispagT("Please select a template."));

    // Ciblage direct de notre textarea pour le commentaire de la cuve
    const textArea = $('#tank-open-comment');
    if (!textArea.length) return; // Sécurité si le champ n'est pas présent sur la page

    let currentContent = textArea.val();
    
    if (currentContent && currentContent.trim() !== "") {
        if (!confirm(ispagNoteData.textReplaceContent || "Remplacer le contenu actuel ?")) return;
    }

    $.ajax({
        url: ispagNoteData.ajaxurl,
        type: 'POST',
        data: { 
            action: 'ispag_get_template_raw', 
            security: ispagNoteData.nonce, 
            template_id: templateId 
        },
        beforeSend: () => $(this).prop('disabled', true).text('...'),
        success: function(response) {
            if (response.success) {
                // Corps du message/commentaire du template
                const contentBody = response.data.content || "";
                textArea.val(contentBody);
            } else {
                alert(ispagT("Error while retrieving the template."));
            }
        },
        complete: () => $(this).prop('disabled', false).text(ispagNoteData.textApply || 'Appliquer')
    });
});