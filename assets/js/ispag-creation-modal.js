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

    /**
     * Ferme la modale et réinitialise le formulaire.
     */
    function closeModal() {
        modal.fadeOut(200, function() {
            createNoteForm.trigger('reset');
            contactSelect.val(null).trigger('change');
            companySelect.val(null).trigger('change');
            dealSelect.val(null).trigger('change');
            
            taskCheckbox.prop('checked', false);
            taskFields.hide();
            meetingFields.hide();
            callFields.hide();
            emailFields.hide();
            noteFields.show(); 
            
            modalHeader.find('h4').text(ispagNoteData.modalTitleDefault);
            createNoteBtn.text(ispagNoteData.textCreateNote); 
            createNoteBtn.removeAttr('data-action');
            createNoteBtn.removeData('action');
            
            $('#activity-id-edit').val(''); 
            modalActivityId.val('');

            if (tinymce.get('note-text-area')) {
                tinymce.get('note-text-area').setContent('');
            }
        });
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
                finalBtnText = originText ? (ispagNoteData.textSend + ' ' + originText) : (ispagNoteData.textSend + ' ' + type);
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
        checkNoteTypeForTemplate();
    };

    function checkNoteTypeForTemplate() {
        const type = modalActionType.val();
        if (type === 'email' || type === 'mail') {
            $('#ispag-note-template-wrapper').slideDown(200);
        } else {
            $('#ispag-note-template-wrapper').slideUp(200);
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
    $(document).on('click', '[data-action]', function(e) { 
        e.preventDefault();

        const $btn = $(this);

        // On récupère le texte du bouton (en enlevant les espaces superflus)
        const buttonText = $btn.text().trim();
        
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
                        ...extraData 
                    };
                    
                    // On attache ces données à l'élément DOM de l'option
                    $(newOpt).data('data', fullData); 
                    s.append(newOpt);
                });
                s.trigger('change');
            }
        };

        // Pour les contacts (si tu as besoin de l'email/tel en direct)
        populate(contactSelect, $btn.data('contact-ids'), $btn.data('contact-names'), {
            email: $btn.data('contact-emails'),
            phone: $btn.data('contact-phones')
        });

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
        
        modal.fadeIn(200);
        modalContent.css('right', '0');
    });

    // Fermeture
    closeButton.on('click', closeModal);
    $('#cancel-note-btn').on('click', closeModal);
    modal.on('click', e => { if (e.target === modal[0]) closeModal(); });
    $(document).on('keydown', e => { if (e.key === 'Escape' && modal.is(':visible')) closeModal(); });

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
        if (!templateId) return alert("Sélectionnez un template.");

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

        if (noteContentHtml.trim() === "" || noteContentHtml.trim() === "<p></p>") {
            return alert("Veuillez saisir un contenu.");
        }

        createNoteBtn.prop('disabled', true).text(ispagNoteData.textSaving);

        // --- CAS 1 : ENVOI VIA OUTLOOK (Pas d'enregistrement DB) ---
        if (submitMode === 'send_mail' && activityId == 0) {
            // 1. Récupérer l'email du premier contact
            const contactData       = contactSelect.select2('data');
            const companyData       = companySelect.select2('data');
            const dealData          = dealSelect.select2('data');
            const recipientEmail    = (contactData.length > 0) ? (contactData[0].email || "") : "";
            const offerNum          = (dealData.length > 0) ? (dealData[0].offer_num || "") : "";
            const dealId            = (dealData.length > 0) ? (dealData[0].id || "") : "";
            const companyId         = (companyData.length > 0) ? (companyData[0].id || "") : "";
            const userId            = (contactData.length > 0) ? (contactData[0].id || "") : "";

            // 2. Construire le lien mailto
            const subject = encodeURIComponent(activityTitle);

            let taskTag = ""; 
            if (isTask) {
                let finalDate = new Date();
                const offset = taskDueOffsetSelect.val(); // ex: "0d", "7d", "custom"
                const timeStr = taskDueTime.val() || "08:00"; // HH:mm

                if (offset === 'custom') {
                    finalDate = new Date(taskDueDateCustom.val());
                } else {
                    // Extraction du nombre de jours depuis l'offset (ex: "14d" -> 14)
                    const daysToAdd = parseInt(offset.replace('d', '')) || 0;
                    finalDate.setDate(finalDate.getDate() + daysToAdd);
                }

                // Appliquer l'heure choisie
                const [hours, minutes] = timeStr.split(':');
                finalDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);

                // Conversion en timestamp (secondes)
                const timestamp = Math.floor(finalDate.getTime() / 1000);
                taskTag = ` [T-${timestamp}]`;
            }

            const invisibleGap = "\n".repeat(5);
            const trackingTag = `Ref: [D-${offerNum}] [U-${userId}] [C-${companyId}] ${taskTag}`;


            const body = encodeURIComponent(noteContentPlain + invisibleGap + trackingTag);
            const mailtoUrl = `mailto:${recipientEmail}?subject=${subject}&body=${body}`;

            // 3. Ouvrir Outlook
            window.location.href = mailtoUrl;

            // 4. Fermer simplement la modale
            closeModal();
            return; // On s'arrête ici, pas d'AJAX
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
                    const existingItem = $(`.ispag-activity-item[data-activity-id="${id}"]`);
                    
                    if (existingItem.length) existingItem.replaceWith(newItemHtml);
                    else $('.ispag-activities-timeline').prepend(newItemHtml);

                    // --- MISE À JOUR TABLEAU DES TÂCHES (Task Table) --- 
                    if (response.data.task_html) {
                        const newTaskRowHtml = response.data.task_html;
                        const existingTaskRow = $(`#task-${id}`); // Ton ID de ligne est id="task-XXX"

                        if (existingTaskRow.length) {
                            // Si la tâche existe (Édition), on remplace la ligne
                            existingTaskRow.replaceWith(newTaskRowHtml);
                        } else {
                            // Si c'est une nouvelle tâche (Création)
                            // On vérifie si la ligne "No tasks found" est présente pour la supprimer
                            if ($('#the-list tr').length === 1 && $('#the-list td').attr('colspan') == "8") {
                                $('#the-list').empty();
                            }
                            $('#the-list').prepend(newTaskRowHtml);
                        }
                    }
                } else {
                    alert('Erreur: ' + response.data.message);
                    createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
                }
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
        modal.show();
        modalContent.css('right', '0');

        // 3. Remplissage des champs de base
        modalHeader.find('h4').text(ispagNoteData.modalTitleEdit.replace('%s', activityData.id));   
        createNoteBtn.text(ispagNoteData.textUpdate);
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
    };
});