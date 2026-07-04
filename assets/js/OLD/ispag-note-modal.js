jQuery(document).ready(function($) {
    const modal = $('#ispag-note-modal');
    

    // 1. Définir le chemin de base AVANT toute autre action (Méthode la plus robuste de WP)
    if (typeof tinymce !== 'undefined' && typeof ispagNoteData !== 'undefined' && ispagNoteData.tinymceUrl) {
        
        // Forcer la définition de la propriété globale tinymce.baseURL
        tinymce.baseURL = ispagNoteData.tinymceUrl;
        
        // 2. Assurer que l'objet de pré-initialisation de WP connaît le chemin
        if (typeof window.tinyMCEPreInit !== 'undefined') {
            
            // Assurer que l'URL de base est mise dans l'objet de pré-initialisation
            window.tinyMCEPreInit.baseURL = ispagNoteData.tinymceUrl;
            
            // Créer une configuration TinyMCE pour l'ID de votre éditeur
            const editorID = 'note-text-area';
            
            if (typeof window.tinyMCEPreInit.mceInit === 'undefined') {
                window.tinyMCEPreInit.mceInit = {};
            }
            
            // Si la configuration n'existe pas, nous la créons
            if (typeof window.tinyMCEPreInit.mceInit[editorID] === 'undefined') {
                 
                 // Création d'une configuration minimale complète
                 window.tinyMCEPreInit.mceInit[editorID] = {
                     base_url: ispagNoteData.tinymceUrl, 
                     suffix: '.min',
                     // La liste des plugins doit être une chaîne séparée par des espaces ou des virgules !
                     plugins: 'advlist autolink lists link image charmap preview anchor searchreplace code fullscreen insertdatetime media table paste help wordcount',
                     toolbar1: 'undo redo | formatselect | bold italic underline backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | outdent indent | removeformat ',
                     // Options de base
                     height: 200, 
                     menubar: false,
                     selector: '#' + editorID,
                     // Ces paramètres sont essentiels pour le chargement correct des plugins
                     skin: 'lightgray', 
                     theme: 'modern'
                 };
            }
        }
    }

    // Sélecteur mis à jour pour inclure tous les types d'actions
    // const actionButton = $(
    //     '[data-action="note"], ' +
    //     '[data-action="call"], ' +
    //     '[data-action="email"], ' +
    //     '[data-action="task"], ' +
    //     '[data-action="meeting"]' +
    //     '[data-action="whatsapp"]' +
    //     '[data-action="sms"]'
    // );
    const actionButtonSelector = [
        '[data-action="note"]',
        '[data-action="call"]',
        '[data-action="email"]',
        '[data-action="task"]',
        '[data-action="meeting"]',
        '[data-action="whatsapp"]',
        '[data-action="sms"]'
    ].join(', ');
    const actionButton = $(actionButtonSelector);
    const closeButton = $('.ispag-close-modal');
    const modalContent = $('.ispag-modal-content');
    const modalHeader = $('.ispag-modal-header');
    const taskCheckbox = $('#create-task-checkbox');
    const taskDueDate = $('#task-due-date');
    const taskDueTime = $('#task-due-time');
    const actionTitle = $('#ispag-action-type');
    const createNoteBtn = $('#ispag-create-note-btn');
    const noteTextArea = $('#note-text-area');
    const taskReminderField = $('.ispag-reminder-field');
    const taskReminderOffset = $('#task-reminder-offset');  
    const taskDueOffsetSelect = $('#task-due-offset');
    const taskDueDateCustom = $('#task-due-date-custom');

    // NOUVELLES RÉFÉRENCES AUX CHAMPS DE MEETING
    const meetingFields = $('.ispag-meeting-fields');
    const callFields = $('.ispag-call-fields');
    const meetingAttendeesSelect = $('#meeting-attendees-select');
    const meetingCompanySelect = $('#meeting-companies-select');
    const meetingDealsSelect = $('#meeting-deals-select');
    const meetingOutcome = $('#meeting-outcome');
    const meetingDate = $('#meeting-date');
    const meetingTime = $('#meeting-time');
    
    // Références aux champs de la modale pour l'injection de données
    const modalContactId = $('#modal-contact-id');
    const modalcompanyId = $('#modal-company-id');
    const modaldealIds = $('#modal-deal-id');
    const modalActivityId = $('#modal-activity-id');
    const modalActionType = $('#modal-action-type');
    const linkedContactName = $('#linked-contact-name');
    const linkedContactAttendees = $('#meeting-attendees-select');
    const linkedCompanyName = $('.ispag-company-name');
    const linkedCompanyAttendees = $('#meeting-companies-select');
    const meetingDealSelect = $('#meeting-deals-select');

    // Assurer que le sélecteur de date est désactivé au début.
    taskDueOffsetSelect.prop('disabled', true); 
    taskReminderField.prop('disabled', true); 
    taskDueTime.prop('disabled', true); 
    taskReminderOffset.prop('disabled', true);

        // Données de Tâche (Conditionnel)
    let dueDate = '';      // Stockera la date absolue (AAAA-MM-JJ)
    let dueOffset = '';    // Stockera l'offset (0d, 1d, custom)
    let dueTime = '';      // Stockera l'heure (HH:MM)
    let reminderOffset = '';

//     meetingAttendeesSelect.select2({
//         dropdownParent: modal, 
//         placeholder: ispagNoteData.textSelectContacts,
//         allowClear: true,
//         ajax: {
//             url: ispagNoteData.ajaxurl, 
//             dataType: 'json',
//             delay: 250, 
//             data: function (params) {
// //                console.log('--- REQUÊTE SELECT2 ---');
// //                console.log('Terme de recherche envoyé:', params.term);
//                 return {
//                     action: 'ispag_search_contacts_select2', // NOM DE L'ACTION PHP
//                     security: ispagNoteData.nonce,
//                     search_term: params.term 
//                 };
//             },
//             processResults: function (data) {
//                 // 1. Log de la réponse brute du serveur
// //                console.log('--- RÉPONSE BRUTE AJAX ---');
// //                console.log(data); // C'est ici que vous voyez si PHP a renvoyé 'success: true'
                
//                 if (data.success) {
//                     // 2. Log des contacts extraits (qui seront affichés)
// //                    console.log('Résultats formatés pour Select2:', data.data.contacts); 
                    
//                     return {
//                         results: data.data.contacts 
//                     };
//                 } else {
//                     console.error('Erreur serveur Select2:', data.data.message || 'Échec sans message');
//                     return {
//                         results: []
//                     };
//                 }
//             },
//             cache: true
//         }
//     });

//     meetingCompanySelect.select2({
//         dropdownParent: modal, 
//         placeholder: ispagNoteData.textSelectCompanies,
//         allowClear: true,
//         ajax: {
//             url: ispagNoteData.ajaxurl, 
//             dataType: 'json',
//             delay: 250, 
//             data: function (params) {
// //                console.log('--- REQUÊTE SELECT2 company ---');
// //                console.log('Terme de recherche envoyé:', params.term);
//                 return {
//                     action: 'ispag_search_company_select2', // NOM DE L'ACTION PHP
//                     // security: ispagNoteData.nonce,
//                     search_term: params.term 
//                 };
//             },
//             processResults: function (data) {
//                 // 1. Log de la réponse brute du serveur
// //                console.log('--- RÉPONSE BRUTE AJAX ---');
// //                console.log(data); // C'est ici que vous voyez si PHP a renvoyé 'success: true'
                
//                 if (data.success) {
//                     // 2. Log des contacts extraits (qui seront affichés)
// //                    console.log('Résultats formatés pour Select2:', data.data.companies); 
                    
//                     return {
//                         results: data.data.companies 
//                     };
//                 } else {
//                     console.error('Erreur serveur Select2:', data.data.message || 'Échec sans message');
//                     return {
//                         results: []
//                     };
//                 }
//             },
//             cache: true
//         }
//     });
//     meetingDealsSelect.select2({
//         dropdownParent: modal, 
//         placeholder: ispagNoteData.textSelectDeals,
//         allowClear: true,
//         ajax: {
//             url: ispagNoteData.ajaxurl, 
//             dataType: 'json',
//             delay: 250, 
//             data: function (params) {
// //                console.log('--- REQUÊTE SELECT2 deals ---');
// //                console.log('Terme de recherche envoyé:', params.term);
//                 return {
//                     action: 'ispag_search_deals_select2', // NOM DE L'ACTION PHP
//                     // security: ispagNoteData.nonce,
//                     search_term: params.term 
//                 };
//             },
//             processResults: function (data) {
//                 // 1. Log de la réponse brute du serveur
// //                console.log('--- RÉPONSE BRUTE AJAX ---');
// //                console.log(data); // C'est ici que vous voyez si PHP a renvoyé 'success: true'
                
//                 if (data.success) {
//                     // 2. Log des contacts extraits (qui seront affichés)
// //                    console.log('Résultats formatés pour Select2:', data.data.deals); 
                    
//                     return {
//                         results: data.data.deals 
//                     };
//                 } else {
//                     console.error('Erreur serveur Select2:', data.data.message || 'Échec sans message');
//                     return {
//                         results: []
//                     };
//                 }
//             },
//             cache: true
//         }
//     });

    // --- Gestion de la Modale et des Interactions ---

    // 1. Ouvrir la modale
    // actionButton.on('click', function(e) {
    $(document).on('click', '[data-action]', function(e) {
        
        // alert('yesss');
        // 1.1. Récupération des données du bouton cliqué (this)

        if ($(e.target).closest('.delete-activity, .edit-activity').length) return;

        const $this = $(this);
        const dataDontactId = $this.data('userId') || 0;
        const dataCompanyId = $this.data('companyId') || 0;
        const dataDealIds = $this.data('dealIds') || 0;
        const logType = $this.data('action');
        
        const contactNameText = $('.ispag-header-info h4').text().trim() || 'Contact inconnu';
        const companyLabel = $('#hidden_company_name').val() || 'Aucune entreprise liée';

        // // L'accès au nom de l'entreprise peut être délicat si elle est dans une DL/DT
        // const companyName = $('dd.company_name').contents().filter(function() {
        //     return this.nodeType === 3;
        // }).text().trim();


        // 1.2. Injection des données dans la modale (champs cachés et affichage)
        modalContactId.val(dataDontactId);
        modalcompanyId.val(dataCompanyId);
        modaldealIds.val(dataDealIds);
        
        modalActionType.val(logType);
        actionTitle.text(logType);
        
        linkedContactName.text(contactNameText);
        linkedContactAttendees.text(contactNameText);
        linkedCompanyName.text(companyLabel);
        linkedCompanyAttendees.text(linkedCompanyAttendees);

        // S'assurer que Select2 est vide lors de la création et vider les options d'édition précédentes
        meetingAttendeesSelect.empty().val(null).trigger('change'); 
        
        // Tableau pour stocker les IDs à sélectionner
        const attendeesToSelect = [];
        // =========================================================
        // 1. AJOUT DES UTILISATEUR
        // =========================================================
        const currentUserId = ispagNoteData.current_user_id;
        const currentUserName = ispagNoteData.current_user_name;

        const contactId = modalContactId.val(); // ID du contact principal de la page
        const contactName = contactNameText; // Nom du contact principal de la page

        // On s'assure que le contact existe, n'est pas déjà l'utilisateur connecté et que ce n'est pas le mode édition.
        if (contactId && contactId !== '0' && contactId !== currentUserId) {
            
            const newOption = new Option(contactName, contactId, true, true); 
            meetingAttendeesSelect.append(newOption); 
            attendeesToSelect.push(contactId);
        }
        
        // 3. Appliquer la sélection sur tous les participants ajoutés
        if (attendeesToSelect.length > 0) {
            meetingAttendeesSelect.val(attendeesToSelect).trigger('change');
        }

        // =========================================================
        // 1. AJOUT DES ENTREPRISES
        // =========================================================
        const companiesToSelect = [];
        const companyId = modalcompanyId.val(); // ID du contact principal de la page
        const companyName = companyLabel; // Nom du contact principal de la page
        
        // On s'assure que l'entreprise existe, 
        if (companyId && companyId !== '0') {
            
            const newOption = new Option(companyName, companyId, true, true); 
            meetingCompanySelect.append(newOption); 
            companiesToSelect.push(companyId);
        }
        // 3. Appliquer la sélection sur tous les participants ajoutés
        if (companiesToSelect.length > 0) {
            meetingCompanySelect.val(companiesToSelect).trigger('change');
        }

        // =========================================================
        // 1. AJOUT DES DEALS
        // =========================================================
        
        // 2. Récupérer la chaîne des Deal IDs du champ hidden
        const dealIdsString = modaldealIds.val();

        if (dealIdsString) {
            const dealsToSelect = [];
            // 1. Séparer les paires ID:Nom (séparateur ";")
            const pairsArray = dealIdsString.split(';');
            pairsArray.forEach(pair => {
                // 2. Séparer l'ID du Nom (séparateur ":")
                const parts = pair.split(':');
                
                if (parts.length === 2) {
                    const dealId = parts[0].trim();
                    // 3. Utiliser decodeURIComponent pour retrouver les espaces et accents originaux
                    const dealName = decodeURIComponent(parts[1].trim()); 
                    
                    if (dealId && dealName) {
                        // Créer l'option : (Texte du Nom, Valeur de l'ID, Est sélectionné, Est appliqué)
                        const newOption = new Option(dealName, dealId, true, true); 
                        
                        // Ajouter l'option au Select2
                        meetingDealSelect.append(newOption); 
                        dealsToSelect.push(dealId);
                    }
                }
            });

            // 4. Appliquer la sélection
            if (dealsToSelect.length > 0) {
                meetingDealSelect.val(dealsToSelect).trigger('change');
            }
        }
        
        // GESTION CONDITIONNELLE des champs de meeting
        if (logType === 'meeting') {
            meetingFields.slideDown(200);
            callFields.slideUp(200);
            createNoteBtn.text(ispagNoteData.textLogMeeting); 

            // Initialisation des champs de date/heure/résultat pour un ispagNoteData.textLogMeeting
            meetingDate.val(new Date().toISOString().split('T')[0]);
            meetingTime.val(new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', hour12: false }));
            meetingOutcome.val('completed'); 

            if (currentUserId && currentUserId !== '0') {
                const newOption = new Option(currentUserName + ' (moi)', currentUserId, true, true); 
                meetingAttendeesSelect.append(newOption); 
                attendeesToSelect.push(currentUserId);
            }
         
            
        } else if (logType === 'task') {
            meetingFields.slideUp(200);
            callFields.slideUp(200);
            createNoteBtn.text(ispagNoteData.textLogTask); 
        } else if (logType === 'call') {
            meetingFields.slideUp(200);
            callFields.slideDown(200);
            createNoteBtn.text(ispagNoteData.textLogCall); 
        } else if (logType === 'email') {
            meetingFields.slideUp(200);
            callFields.slideUp(200);
            createNoteBtn.text(ispagNoteData.textLogMail); 
        } else {
            meetingFields.slideUp(200);
            callFields.slideUp(200);
            createNoteBtn.text(ispagNoteData.textCreateNote); // Texte par défaut pour Note/Call/Email/Task
        }

        // 1.3. Affichage de la modale
        modal.fadeIn(200);
        initializeTinyMCE();
    });
    

    // 2. Fermer la modale
    function closeModal() {
        modal.fadeOut(200, function() {
            // Réinitialiser les champs après la fermeture
            noteTextArea.val('');
            taskCheckbox.prop('checked', false).trigger('change');
            createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
            modalHeader.css('cursor', 'grab');
            modalActivityId.val('0');
            
            // Réinitialisation des champs de meeting
            meetingAttendeesSelect.val(null).trigger('change'); 
            meetingOutcome.val('scheduled'); 
            meetingFields.hide(); // Masquer les champs de meeting par défaut
        });
    }

    closeButton.on('click', closeModal);
    modal.on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    // 4. Fermeture avec la touche ESC
    $(document).on('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
    
    // 3. Logique de création de tâche (Active/Désactive la sélection de date)
    taskCheckbox.on('change', function() {
        const isChecked = $(this).is(':checked');
        taskDueDate.prop('disabled', !$(this).is(':checked'));
        taskDueTime.prop('disabled', !$(this).is(':checked'));

        // NOUVEAU TOGGLE POUR LE RAPPEL OFFSET
        if (isChecked) {
            taskReminderField.slideDown(200);
            taskDueOffsetSelect.prop('disabled', false); 
            taskDueDate.prop('disabled', false); 
            taskDueTime.prop('disabled', false); 
            taskReminderOffset.prop('disabled', false); 
        } else {

            taskReminderField.slideUp(200);
            taskDueOffsetSelect.prop('disabled', true); 
            taskDueDate.prop('disabled', true); 
            taskDueTime.prop('disabled', true); 
            taskReminderOffset.prop('disabled', true);
        }
    });

    // 4. Glisser-Déposer (Draggable Logic)
    let isDragging = false;
    let offsetX, offsetY;

    modalHeader.on('mousedown', function(e) {
        isDragging = true;
        offsetX = e.clientX - modalContent.offset().left;
        offsetY = e.clientY - modalContent.offset().top;

        modalContent.css({
            'transform': 'none',
            'top': modalContent.offset().top,
            'left': modalContent.offset().left
        });

        modalHeader.css('cursor', 'grabbing');
        e.preventDefault(); 
    });

    $(document).on('mousemove', function(e) {
        if (!isDragging) return;
        
        const newX = e.clientX - offsetX;
        const newY = e.clientY - offsetY;

        modalContent.offset({ top: newY, left: newX });
    });

    $(document).on('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            modalHeader.css('cursor', 'grab');
        }
    });

    // 5. Envoi AJAX pour la sauvegarde de la note/tâche/meeting
    createNoteBtn.on('click', function(e) {
        e.preventDefault();
        
        // const noteContent = noteTextArea.val().trim();
        let noteContent = '';
        const editorInstance = tinymce.get('note-text-area');
        if (editorInstance) {
            // Récupère le contenu au format HTML
            noteContent = editorInstance.getContent(); 
        } else {
            // Cas de secours si TinyMCE n'a pas été initialisé (pourrait être une erreur)
            // On peut toujours essayer de récupérer la valeur du textarea brut
            noteContent = $('#note-text-area').val(); 
        }

//        console.log(noteContent);
        const contactId = modal.find('input[name="contact_id"]').val();
        const companyId = modal.find('input[name="company_id"]').val();
        const dealId = modal.find('input[name="deal_id"]').val();
        const activityId = modalActivityId.val();
        const actionType = modalActionType.val();
        const isTask = taskCheckbox.is(':checked');
        
        // Vérification du contenu
        if (noteContent === "") {
            alert(`Veuillez saisir le contenu du ${actionType === 'meeting' ? 'meeting' : 'la note'}.`);
            return;
        }

        createNoteBtn.prop('disabled', true).text(ispagNoteData.textSaving);

        // Données de Tâche (Conditionnel)
        // const dueDate = isTask ? taskDueDate.val() : '';
        // const dueTime = isTask ? taskDueTime.val() : '';
        // const reminderOffset = isTask ? taskReminderOffset.val() : '';

        // Données de Meeting (Conditionnel)
        let attendees = [];
        let outcome = '';
        let mDate = '';
        let mTime = '';
        attendees = meetingAttendeesSelect.val() || []; // Assurer que c'est un tableau vide si rien n'est sélectionné
        companies = meetingCompanySelect.val() || []; // Assurer que c'est un tableau vide si rien n'est sélectionné
        deals = meetingDealsSelect.val() || []; // Assurer que c'est un tableau vide si rien n'est sélectionné
        if (actionType === 'meeting') {
            
            outcome = meetingOutcome.val();
            mDate = meetingDate.val();
            mTime = meetingTime.val();
        }

        if (isTask) {
            const selectedOffset = taskDueOffsetSelect.val();
            dueTime = taskDueTime.val();
            reminderOffset = taskReminderOffset.val();

            if (selectedOffset === 'custom') {
                // Mode Date Personnalisée
                dueDate = taskDueDateCustom.val(); // Récupère la date AAAA-MM-JJ
                dueOffset = 'custom';              // Enregistre l'état 'custom'
            } else {
                // Mode Offset Standard
                dueDate = '';                      // Ne pas enregistrer de date absolue
                dueOffset = selectedOffset;        // Enregistre l'offset (ex: '1d')
            }
        }

        // Données à envoyer
        const data = {
            action: ispagNoteData.action, 
            security: ispagNoteData.nonce,
            contact_id: contactId,
            company_id: companyId,
            activity_id: activityId,
            action_type: actionType,
            dealId: dealId,
            note_content: noteContent,
            
            // Tâche
            is_task: isTask,
            due_date: dueDate,
            due_offset: dueOffset,
            due_time: dueTime,
            reminder_offset: reminderOffset,
            
            // Meeting (nouveaux champs)
            meeting_attendees: attendees,
            meeting_companies: companies,
            meeting_deals: deals,
            meeting_outcome: outcome,
            meeting_date: mDate,
            meeting_time: mTime
        };

//        console.log('Données envoyées:', data);

        $.post(ispagNoteData.ajaxurl, data)
            .done(function(response) {
                // console.log('Réponse AJAX complète (succès):', response); 
                if (response.success) {
                    closeModal(); // Fermer la modale
            
                    const newItemHtml = response.data.html;
                    const activityId = response.data.insert_id;
                    
                    const activityFeed = $('.ispag-activities-timeline'); 
                    const existingItem = $(`.ispag-activity-item[data-activity-id="${activityId}"]`);

                    if (existingItem.length) {
                        // MISE À JOUR : Remplacer l'ancien HTML par le nouveau
                        existingItem.replaceWith(newItemHtml);
                    } else {
                        // CRÉATION : Ajouter l'élément en haut de la liste
                        if (activityFeed.length) {
                            activityFeed.prepend(newItemHtml);
                        } else {
                            console.error("Conteneur d'activité (.ispag-activities-timeline) non trouvé.");
                        }
                    }

                    // 3. Afficher la notification
//                    console.log(response.data.message);
                } else {
                    alert('Erreur lors de la sauvegarde: ' + response.data.message);
                    createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
                }
            })
            .fail(function() {
                alert('Erreur de connexion au serveur.');
                createNoteBtn.prop('disabled', false).text(ispagNoteData.textCreateNote);
            });
    });

    // --------------------------------------------------------
    // --- GESTION DES ACTIONS D'ACTIVITÉ (TÂCHES/NOTES) ---
    // --------------------------------------------------------
    
    // Fermer tous les menus dropdown si on clique ailleurs
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.ispag-dropdown-actions')) {
            document.querySelectorAll('.ispag-dropdown-actions.active').forEach(dd => {
                dd.classList.remove('active');
            });
        }
    });

    // Événement pour basculer l'affichage du menu dropdown
    document.querySelectorAll('.ispag-dropdown-actions .action-menu-toggle').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation(); 
            const dropdown = this.closest('.ispag-dropdown-actions');
            
            // Fermer les autres menus ouverts
            document.querySelectorAll('.ispag-dropdown-actions.active').forEach(dd => {
                if (dd !== dropdown) {
                    dd.classList.remove('active');
                }
            });
            
            // Basculer l'état du menu actuel
            dropdown.classList.toggle('active');
        });
    });

    // --- Gestion des Actions AJAX ---
    
    // Fonction utilitaire pour effectuer les appels AJAX
    function executeActivityAction(activityId, actionType, confirmMessage = null) {
        // if (confirmMessage && !confirm(confirmMessage)) {
        //     return;
        // }

        const formData = new FormData();
        formData.append('action', 'ispag_activity_' + actionType); 
        formData.append('activity_id', activityId);

        if (typeof ispagNoteData !== 'undefined' && ispagNoteData.nonce) {
             formData.append('security', ispagNoteData.nonce); 
        }

        fetch(ispagNoteData.ajaxurl, {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const activityElement = document.querySelector(`.ispag-activity-item[data-activity-id="${activityId}"]`);
                if (activityElement) {
                    if (actionType === 'delete') {
                        activityElement.remove();
                        // alert('Activité supprimée avec succès.');
                    } else if (actionType === 'complete') {
                        activityElement.classList.add('completed');
                        activityElement.remove(); 
                        // alert('Tâche marquée comme traitée.');
                    }
                }
            } else {
                alert('Erreur lors de l\'opération: ' + (data.data && data.data.message ? data.data.message : 'Échec de la sauvegarde.'));
            }
        })
        .catch(error => {
            console.error('Erreur réseau ou Fetch:', error);
            alert('Erreur de connexion lors de l\'exécution de l\'action.');
        });
    }
// // Test : Temporaire et non délégué
// $('.complete-task-btn').on('click', function(e) { 
//     e.preventDefault();
//     handleCompleteTaskClick($(this));
// });
    // Gestion du clic sur le bouton "Marquer comme traité"
   $(document).on('click', '.complete-task-btn', function(e) {
        e.preventDefault();
//        console.log('Bouton Terminé cliqué ! ID:', $(this).data('activity-id'));
        const completeButton = $(this);
        const activityId = completeButton.data('activity-id');
        const activityItem = completeButton.closest('.ispag-activity-item');
        // On suppose que activityRow est l'identifiant de l'élément parent principal (ex: le <tr>)
        const activityRow = $('#task-' + activityId); 

        // if (!confirm(ispagNoteData.textConformCompleteTask)) {
        //     return;
        // }

        // On réduit l'opacité de la ligne principale pour l'état d'attente
        activityRow.css('opacity', 0.5); 

        const data = {
            action: 'ispag_complete_task', 
            security: ispagNoteData.nonce,
            activity_id: activityId
        };

        $.post(ispagNoteData.ajaxurl, data)
            .done(function(response) {
                if (response.success) {
                    
                    // ----------------------------------------------------
                    // DEBUT DU BLOC CORRIGÉ 
                    // Objectif : Faire disparaître la ligne principale (activityRow)
                    // ----------------------------------------------------
                    
                    // 1. Démarrer l'animation de disparition sur la ligne principale
                    activityRow.fadeOut(300, function() { 
                        
                        // 2. Supprimer l'élément du DOM après l'animation
                        $(this).remove();
                        
                        // 3. Vérifier s'il reste des tâches après suppression
                        if ($('.ispag-task-list tbody tr').length === 0) {
                            $('#ispag-no-tasks-message').show(); 
                        }
                        
                        // Note : Si response.data.html est un élément mis à jour (ex: "Terminé"),
                        // vous devriez le traiter ici si nécessaire. Sinon, il est ignoré.
                        
                    }); 
                    
                    // ----------------------------------------------------
                    // FIN DU BLOC CORRIGÉ 
                    // L'ancien code (activityItem.replaceWith et les deux fadeOut) est supprimé.
                    // ----------------------------------------------------
                    
                } else {
                    // En cas d'échec : rétablir l'opacité de la ligne principale
                    activityRow.css('opacity', 1);
                    alert('Erreur: ' + response.data.message);
                    console.error('Erreur: Impossible de marquer la tâche comme terminée.', response);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                // En cas d'échec réseau : rétablir l'opacité de la ligne principale
                activityRow.css('opacity', 1);
                console.error('Erreur réseau/serveur lors de la finalisation de la tâche :', textStatus, errorThrown);
                alert('Erreur de connexion : Impossible de finaliser la tâche.');
            });
    });

    // Gérer le clic "Supprimer"
    document.querySelectorAll('.delete-activity').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const activityId = this.dataset.activityId;
            executeActivityAction(activityId, 'delete', ispagNoteData.textConformDeleteLog);
        });
    });
    
    // Gérer le clic "Modifier"
    $(document).on('click', '.ispag-action-btn.edit-activity', function(e) {
        e.preventDefault();
        
        const activityId = $(this).data('activity-id');
        const logType = $(this).data('action');
        
        if (activityId) {
            loadActivityDetailPanel(activityId, logType); 
        } else {
            console.error("ID d'activité manquant pour l'édition.");
            alert("Erreur: ID de l'activité à éditer introuvable.");
        }
    });
    
    $(document).on('click', '.ispag-open-edit-mode', function(e) {
        e.preventDefault();
        const activityId = $(this).data('activity-id');
        $('#ispag-detail-viewer-panel').fadeOut(200); 
        loadActivityDetailPanel(activityId); 
    });

    // --- Définition de la fonction d'initialisation de TinyMCE ---
    function initializeTinyMCE() {
        const editorID = 'note-text-area';
        
        // 1. Tenter de supprimer toute instance existante pour éviter les conflits
        if (tinymce.get(editorID)) {
            tinymce.get(editorID).remove();
        }
        
        let config = {};

        // 2. Tenter d'utiliser la configuration définie dans tinyMCEPreInit
        if (typeof window.tinyMCEPreInit !== 'undefined' && window.tinyMCEPreInit.mceInit && window.tinyMCEPreInit.mceInit[editorID]) {
            config = window.tinyMCEPreInit.mceInit[editorID];
        }
        
        // 3. Compléter ou écraser les paramètres si nécessaire
        config = $.extend(true, {
            selector: '#' + editorID,
            height: 200,
            menubar: false,
            // Ces lignes assurent le chemin si tinyMCEPreInit.baseURL a été ignoré
            base_url: ispagNoteData.tinymceUrl, 
            suffix: '.min', 
            
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace code fullscreen insertdatetime media table paste help wordcount',
            toolbar: 'undo redo | formatselect | bold italic underline backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | outdent indent | removeformat ',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
            
            setup: function (editor) {
                editor.on('init', function () {
                    editor.focus(false);
                });
            }
        }, config); 
        
        // 4. Initialisation finale
        tinymce.init(config);
    }

    // Cible le conteneur des pièces jointes
    const attachmentContainers = document.querySelectorAll('.ispag-activity-attachments');

    attachmentContainers.forEach(container => {
        container.addEventListener('click', function(e) {
            // Empêche l'événement de "remonter" vers .ispag-activity-item
            e.stopPropagation();
            
            // Si c'est un bouton toggle pour le menu déroulant
            const toggle = e.target.closest('.attachment-toggle');
            if (toggle) {
                e.preventDefault(); // Évite tout comportement par défaut
                const menu = container.querySelector('.attachment-list');
                if (menu) {
                    // Ferme les autres menus ouverts si nécessaire
                    // menu.classList.toggle('is-open'); 
                    console.log('Ouverture du menu des pièces jointes');
                }
            }
        });
    });

    // Optionnel : Si vous avez des liens directs (1 seul fichier)
    const attachmentLinks = document.querySelectorAll('.ispag-attachment-link');
    attachmentLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.stopPropagation(); // Empêche l'ouverture de la sidebar
            // Le lien s'ouvrira normalement en _blank grâce à l'attribut HTML
        });
    });

});



/***************************************************************************************************** */

// Utilisation de la fonction d'enveloppement pour jQuery
(function($) {
    
    const $sidebarModal = $('#ispag-task-sidebar-modal');
    const $sidebarContent = $('#ispag-task-modal-content');
    const SIDEBAR_WIDTH = '650px'; // Doit correspondre à max-width en CSS

    /**
     * Ouvre la sidebar avec un effet de slide-in et charge les données.
     */
    function openSidebar(taskId) {
        if ($sidebarModal.length === 0) {
            console.error("Sidebar Modal non trouvée.");
            return;
        }

        // 1. Initialise la position et l'opacité du contenu pour le slide
        $sidebarContent.css('right', '-' + SIDEBAR_WIDTH); // Hors écran à droite

        // 2. Affiche l'overlay avec un fade-in
        $sidebarModal.fadeIn(200, function() {
            // 3. Une fois l'overlay affiché, fait glisser le contenu
            $sidebarContent.animate({ right: '0px' }, 300);
        });

        // 4. Bloque le scroll
        $('body').addClass('sidebar-open'); 

        loadTaskDetails(taskId);
    }

    /**
     * Ferme la sidebar avec un effet de slide-out et vide le formulaire.
     */
    function closeSidebar() {
        // 1. Fait glisser le contenu hors écran
        $sidebarContent.animate({ right: '-' + SIDEBAR_WIDTH }, 300, function() {
            // 2. Une fois le contenu sorti, masque l'overlay
            $sidebarModal.fadeOut(200, function() {
                // 3. Débloque le scroll
                $('body').removeClass('sidebar-open');
                // 4. Réinitialise le formulaire si besoin
                
            });
        });
    }

    // --- Gestion des événements de la page ---



    // 1. Clic sur le lien 'open-task-sidebar'
    $(document).on('click', '.open-task-sidebar', function(e) {
        e.preventDefault();
        const taskId = $(this).data('task-id');
        openSidebar(taskId);
    });

    // 2. Clic sur le bouton de fermeture
    $('#close-task-sidebar-btn, #cancel-task-btn').on('click', function(e) {
        e.preventDefault();
        closeSidebar();
    });
    
    // 3. Clic sur l'overlay pour fermer
    $('#ispag-task-modal-overlay').on('click', function() {
        closeSidebar();
    });
    $sidebarModal.on('click', function(e) {
        e.stopPropagation(); // Cette ligne stoppe l'événement à la barre latérale
    });
    // Tentez de cibler un conteneur parent s'il existe et gère l'état
    $('#ispag-task-sidebar-modal').on('click', function(e) {
        // S'assurer que l'élément cliqué est l'overlay ou le wrapper lui-même,
        // mais pas un enfant de la sidebar
        if (e.target.id === 'ispag-task-sidebar-modal' || e.target.id === 'ispag-task-modal-overlay') {
            closeSidebar();
        }
    });

    // 4. Fermeture avec la touche ESC
    $(document).on('keydown', function(event) {
        if (event.key === 'Escape' && $sidebarModal.is(':visible')) {
            closeSidebar();
        }
    });
    
    /**
     * Récupère les données de la tâche via AJAX et remplit la sidebar.
     */
    function loadTaskDetails(taskId) {
        // Affichage d'un loader pendant le chargement (optionnel)
        // $sidebarModal.addClass('is-loading'); 
        const completeButton    = $('.complete-task-btn');
        
        
        $.ajax({
            url: ispagNoteData.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_load_task_details', // Le nom de l'action PHP
                task_id: taskId,
                // (Si vous utilisez un nonce, ajoutez-le ici: _ajax_nonce: '...' )
            },
            success: function(response) {
                // $sidebarModal.removeClass('is-loading'); 
                
                if (response.success) {
                    const data = response.data;
//                    console.log('Données chargées :', data);
                    
                    // --- Remplissage du HTML/Formulaire ---
                    if(data.type != 'MEETING'){
                        $('.meeting-section').slideUp(0);
                        $('.meeting-section').prev('hr').slideUp(0);
                    }
                    if(data.is_completed == 1){
                        completeButton.hide();
                    }
                    else{
                        completeButton.show();
                    }
                    // 1. Mise à jour de l'ID et du titre du header
                    $('#task-id').val(data.id);
                    $('#task-id-display').text('#' + data.id);

                    completeButton.attr('data-activity-id', data.id);

                    // Header
                    $('#assigned-value').text(data.assigned_to);
                    $('#due-date').text(data.due_date);
                    
                    
                    // 2. Remplissage du formulaire principal
                    
                    $('#task-content').html(data.content);
                    $('#task-status-display').html(data.status);
                    $('#task-type-display').html(data.type);
                    // $('#task-new-status').val(data.status); // Mise à jour du select
                    
                    // 3. Remplissage des blocs d'information (Contexte)
                    $('#task-contact-link').html(data.contact_name);
                    $('#task-company-link').html(data.company_name);
                    $('#task-deal-link').html(data.deal_name);
                    
                    
                    $('#meeting-outcome-display').html(data.outcome);
                    $('#meeting-attendees-display').html(data.attendees);
                    
                    // Conversion de la date (si nécessaire) et remplissage
                    // Ici, on suppose que data.meeting_date est au format YYYY-MM-DD
                    $('#meeting-date-display').text(data.meeting_date); 
                    $('#meeting-time-display').text(data.meeting_time);
                    
                } else {
                    alert('Erreur de chargement : ' + response.data.message);
                    closeSidebar(); // Fermer si erreur critique
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // $sidebarModal.removeClass('is-loading');
                console.error('Erreur AJAX:', textStatus, errorThrown);
                alert('Une erreur de connexion est survenue. Veuillez réessayer.');
                closeSidebar(); 
            }
        });
    }

})(jQuery);


function handleCompleteTaskClick(completeButton) {
    // Votre code de traitement (que vous avez fourni)
    
    e.preventDefault();
//    console.log('Bouton Terminé cliqué ! ID:', completeButton.data('activity-id'));
    const activityId = completeButton.data('activity-id');
    
    // Si le bouton est dans la modale, on ne trouve pas d'activity-item parent, 
    // il faut donc cibler l'élément affiché qui contient la carte de tâche dans la timeline.
    // Pour simplifier, trouvons l'élément le plus proche à masquer temporairement.
    
    // NOTE: Si le bouton est dans le tableau, activityItem pourrait ne pas être la bonne classe.
    // Il faut adapter le sélecteur. Ex: 
    // const activityItem = completeButton.closest('.ispag-activity-item, .task-table-row');
    
    // Simplifions en ciblant la ligne du tableau ou la carte de la timeline
    const activitySelector = completeButton.closest('.ispag-activity-item').length ? '.ispag-activity-item' : '.task-table-row';
    const activityItem = completeButton.closest(activitySelector);
    
    
    // if (!confirm(ispagNoteData.textConformCompleteTask)) {
    //     return;
    // }

    activityItem.css('opacity', 0.5); 

    const data = {
        action: 'ispag_complete_task', 
        security: ispagNoteData.nonce,
        activity_id: activityId
    };

    $.post(ispagNoteData.ajaxurl, data)
        .done(function(response) {
            // ... (votre logique de success/failure) ...
            // Pensez à fermer la modale si le bouton a été cliqué dans la modale
            if (completeButton.closest('#ispag-task-sidebar-modal').length) {
                 closeSidebar();
            }
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            // ... (votre logique de fail) ...
        });

}