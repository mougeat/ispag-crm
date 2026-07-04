/**
 * ispag-contact-detail-edit.js
 * Gère l'édition en ligne des champs (Inline Edit) sur les pages de détail.
 * Inclut la CORRECTION CRITIQUE pour le parsing du format custom "key:Label;...".
 * Gère également la navigation par onglets et la modale de contact (jQuery).
 */

document.addEventListener('DOMContentLoaded', () => {

    let companyPage = 1;
    let contactPage = 1;

    // On s'assure que jQuery est disponible pour les fonctions de la modale
    if (typeof jQuery === 'undefined' || typeof ispag_ajax === 'undefined' || !ispag_ajax.ajax_url) {
        console.error('jQuery ou l\'objet ispag_ajax est manquant. Les fonctionnalités AJAX ne seront pas chargées.');
        return;
    }
    const $ = jQuery;
    const ajaxUrl = ispag_ajax.ajax_url;
    const modalContainer = $('#ispag-modal-container');
    const contactListContainerSelector = '#contact-search-results .contact-list-container';
    const companyListContainerSelector = '#companies-search-results .companies-list-container';

    // =========================================================================
    // --- 0. Fonctions Utilitaires (Vanilla JS) ---
    // =========================================================================

    /**
     * Parse le format custom "key:Label;key2:Label2;..." en un objet
     * compatible avec la boucle existante: { key: { label: 'Label' } }
     */
    function parseCustomOptions(optionsString) {
        const optionsMap = {};
        if (!optionsString) {
            return optionsMap;
        }
        
        // Sépare les différentes options (paires "clé:Label")
        const pairs = optionsString.split(';');

        pairs.forEach(pair => {
            // Sépare la clé de la valeur (ne splite que sur le premier ':')
            const parts = pair.split(':');
            
            if (parts.length >= 2) {
                const value = parts[0].trim();
                // Reconstruit le label au cas où il y aurait d'autres ':' dans le texte du label
                const label = parts.slice(1).join(':').trim(); 
                
                if (value) {
                    // Crée l'objet { label: 'Label' } que le code d'itération attend
                    optionsMap[value] = { label: label };
                }
            }
        });
        
        return optionsMap;
    }

    /**
     * Fonction utilitaire pour éviter de déclencher la recherche trop souvent.
     */
    function debounce(func, delay) {
        let timeoutId;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                func.apply(context, args);
            }, delay);
        };
    }
    
    // =========================================================================
    // --- 1. GESTION DE L'ÉDITION EN LIGNE (INLINE EDIT) ---
    // =========================================================================

    // Sélectionne tous les conteneurs de champs éditables
    const editableFields = document.querySelectorAll('.ispag-editable-field');

    editableFields.forEach(field => {
        field.addEventListener('click', (event) => {
            if (field.classList.contains('editing') || event.target.tagName === 'INPUT' || event.target.tagName === 'SELECT' || event.target.tagName === 'TEXTAREA') {
                return;
            }
            // --- AJOUT DE LA VÉRIFICATION CRITIQUE ---
            if (event.target.closest('.ignore-inline-edit')) { 
                return; 
            }
            // --- FIN DE LA VÉRIFICATION ---
            switchToEditMode(field);
        });
    });

    /**
     * Passe un champ du mode affichage au mode édition (input, select ou textarea).
     * @param {HTMLElement} field - L'élément .ispag-editable-field
     */
    function switchToEditMode(field) {
        field.classList.add('editing');

        const currentValue = field.dataset.value || ''; 
        const fieldType = field.dataset.type || 'text'; 
        const fieldName = field.dataset.name;
        const departmentId = field.dataset.departmentId;
        
        field.dataset.originalContent = field.innerHTML;
        field.innerHTML = ''; 

        let inputElement;

        if (fieldType === 'select') {
            inputElement = document.createElement('select');
            inputElement.className = 'ispag-edit-select';

            const optionsJsonString = field.dataset.options || '';
            let optionsMap = {};
            
            // On utilise la nouvelle fonction de parsing pour le format custom
            // NOTE: La fonction parseCustomOptions doit être définie ailleurs
            optionsMap = parseCustomOptions(optionsJsonString); 
            
            // Crée l'option par défaut (vide)
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Sélectionner...';
            inputElement.appendChild(defaultOption);

            // BOUCLE qui utilise optionsMap (maintenant correctement remplie)
            // NOTE: Remplacer le contenu de cette boucle si vous n'utilisez pas optionsMap
            for (const [value, dataObject] of Object.entries(optionsMap)) {
                const label = dataObject.label; 
                
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                
                if (value.toString() === currentValue.toString()) { 
                    option.selected = true;
                }
                inputElement.appendChild(option);
            }
            // FIN DE BOUCLE

        } else if (fieldType === 'textarea') {
            inputElement = document.createElement('textarea');
            inputElement.className = 'ispag-edit-input';
            inputElement.value = currentValue;
            inputElement.rows = 3; 
        } 
        
        // --- NOUVEAU: GESTION DE LA CASE À COCHER ---
        else if (fieldType === 'checkbox') {
            // Créer l'input checkbox
            inputElement = document.createElement('input');
            inputElement.type = 'checkbox'; 
            inputElement.name = fieldName;
            inputElement.value = '1'; 
            
            // Définir l'état initial
            if (currentValue === '1') {
                inputElement.checked = true;
            }

            // Créer un label/texte de contexte
            const labelText = document.createElement('span');
            labelText.textContent = ' ' + field.dataset.title + ' (Cocher = yes)';
            
            // Ajouter un wrapper div pour mieux gérer l'affichage
            const wrapper = document.createElement('div');
            wrapper.className = 'ispag-checkbox-wrapper';
            wrapper.appendChild(inputElement);
            wrapper.appendChild(labelText);
            
            field.appendChild(wrapper);
            
            // Pour une checkbox, on sauvegarde immédiatement au changement (click)
            inputElement.addEventListener('change', () => {
                // Déterminer la nouvelle valeur: '1' (coché) ou '0' (décoché)
                const newValue = inputElement.checked ? '1' : '0';
                saveAndExitEditMode(field, newValue);
            });
            
            // Retourner ici pour éviter l'ajout standard du listener 'blur' et 'keydown'
            return; 
        }
        else if (fieldType === 'date') {
            inputElement = document.createElement('input');
            inputElement.type = 'date';
            inputElement.className = 'ispag-edit-input';
            // S'assurer que la valeur est au format YYYY-MM-DD pour l'input HTML5
            inputElement.value = currentValue; 
            
            // Optionnel: On peut forcer l'ouverture du calendrier sur certains navigateurs
            // inputElement.click(); 
        }

        else {
            inputElement = document.createElement('input');
            inputElement.type = fieldType; 
            inputElement.className = 'ispag-edit-input';
            inputElement.value = currentValue;
        }

        inputElement.name = fieldName;
        field.appendChild(inputElement);
        inputElement.focus();
        
        // Gérer la sauvegarde au "blur" (clic en dehors)
        inputElement.addEventListener('blur', () => {
            saveAndExitEditMode(field, inputElement.value.trim());
        });

        // Gérer la touche Entrée pour valider (sauf pour textarea)
        if (fieldType !== 'textarea') {
            inputElement.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault(); 
                    inputElement.blur(); 
                }
            });
        }
    }

    /**
     * Sauvegarde la nouvelle valeur et quitte le mode édition.
     */
    function saveAndExitEditMode(field, newValue) {
        const fieldName = field.dataset.name;
        const currentValue = field.dataset.value;
        
        // --- NOUVELLE LOGIQUE : DÉTERMINATION DE L'ENTITÉ ET DE L'ID ---
        const companyContainer = field.closest('[data-company-id]');
        const contactContainer = field.closest('[data-contact-id]');
        
        let entityId = null;
        let ajaxAction = null;
        let idKey = null;
 
        if (companyContainer) {
            entityId = companyContainer.dataset.companyId;
            ajaxAction = 'save_company_field'; 
            idKey = 'company_id';

            // 🎯 CORRECTION CRITIQUE POUR LA CASSE DE LA TABLE PRINCIPALE 🎯
            if (fieldName === 'fournisseur' || fieldName === 'email') {
                // NOTE: fieldName doit être redéclaré avec let/var pour être réassigné
                // Sinon, il faudrait changer l'usage direct dans formData.append('field_name', fieldName)
                // Pour l'exemple, on suppose que fieldName est réassignable
                // fieldName = fieldName.charAt(0).toUpperCase() + fieldName.slice(1);
            }
        } else if (contactContainer) { 
            entityId = contactContainer.dataset.contactId;
            ajaxAction = 'save_contact_field'; 
            idKey = 'contact_id';
        } else {
            console.error('Erreur: ID d\'entité (Company ou Contact) non trouvé.');
            alert('Erreur: L\'ID de l\'entité est manquant.');
            exitEditMode(field);
            return;
        }
        // --- FIN NOUVELLE LOGIQUE ---


        // 1. Vérification si la valeur a changé ou si l'ID est manquant
        if (newValue === currentValue) {
            exitEditMode(field);
            return;
        }

        if (!entityId) {
            console.error('Erreur: Entity ID non trouvé.');
            alert('Erreur: L\'ID de l\'entité est manquant.');
            exitEditMode(field);
            return;
        }

        // 2. Afficher l'état de chargement
        field.classList.add('loading');
        field.innerHTML = '<span style="color: var(--ispag-color-primary, #007bff);">Sauvegarde...</span>'; 
        
        // --- LOGIQUE D'APPEL AJAX/FETCH RÉEL VERS WORDPRESS ---
        
        const formData = new FormData();
        formData.append('action', ajaxAction); 
        formData.append(idKey, entityId); 
        formData.append('field_name', fieldName);
        formData.append('new_value', newValue);
        
        // --- AJOUT POUR LE DÉPARTEMENT ---
        if (field.dataset.departmentId) {
            formData.append('department_id', field.dataset.departmentId);
        }

        if (typeof ispag_ajax === 'undefined' || !ispag_ajax.ajax_url) {
            console.error('Erreur JS: ispag_ajax ou ajax_url est manquant.');
            alert('Erreur de configuration AJAX. La sauvegarde ne peut pas être effectuée.');
            field.classList.remove('loading');
            exitEditMode(field);
            return;
        }
 
        fetch(ispag_ajax.ajax_url, {
            method: 'POST',
            body: formData,
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            field.classList.remove('loading');

            if (data.success) {
                // Succès : La BDD a été mise à jour.
                field.dataset.value = newValue;
                
                // Mettre à jour l'affichage en utilisant la valeur formatée envoyée par PHP (méthode préférée)
                if (data.data && data.data.display_value) {
                    field.innerHTML = data.data.display_value;
                } else {
                    // Fallback si la valeur d'affichage n'est pas fournie par le serveur
                    updateDisplayContent(field, newValue);
                }
                
                field.classList.remove('editing');
                // Mettre à jour le contenu original (pour la prochaine édition)
                field.dataset.originalContent = field.innerHTML; 
                
            } else {
                // Échec : Erreur de validation ou échec DB
                const errorMessage = data.data && data.data.message ? data.data.message : 'Échec de la sauvegarde côté serveur.';
                console.error('Save error:', errorMessage);
                
                field.innerHTML = `<span style="color: #dc3545;">Erreur: ${errorMessage}</span>`;
                
                // Réinitialiser après un court délai
                setTimeout(() => {
                    exitEditMode(field); 
                }, 2000); 
            }
        })
        .catch(error => {
            // Erreur réseau ou JSON invalide
            field.classList.remove('loading');
            console.error('Erreur réseau ou Fetch:', error);
            field.innerHTML = '<span style="color: #dc3545;">Erreur de connexion.</span>';

            // Réinitialiser après un court délai
            setTimeout(() => {
                exitEditMode(field); 
            }, 2000);
        });
    }

    /**
     * Restaure le contenu d'affichage d'origine du champ.
     */
    function exitEditMode(field) {
        field.classList.remove('editing');
        field.classList.remove('loading');
        // Restaure le contenu initial 
        field.innerHTML = field.dataset.originalContent;
    }

    /**
     * Fallback : Met à jour le contenu HTML affiché après une sauvegarde réussie 
     * si PHP n'a pas renvoyé de display_value.
     */
    function updateDisplayContent(field, newValue) {
        const fieldType = field.dataset.type || 'text';
        field.innerHTML = ''; 

        if (fieldType === 'select') {
            // Récupérer la map d'options pour trouver le label
            const optionsJsonString = field.dataset.options || '';
            let optionsMap = {};
            
            // optionsMap = parseCustomOptions(optionsJsonString); // NOTE: Fonction à définir
            
            // Trouver le label
            // const dataObject = optionsMap[newValue] || { label: newValue };
            // const label = dataObject.label || newValue; 
            
            // // Recréer le badge
            // const badge = document.createElement('span');
            // badge.className = 'ispag-status-badge';
            // badge.textContent = label;
            
            // // Appliquer le style
            // badge.style.backgroundColor = dataObject.bg_color || '#007bff'; 
            // badge.style.color = dataObject.text_color || '#fff';
            
            // field.appendChild(badge);
        } 
        // --- NOUVEAU: CAS CHECKBOX ---
        else if (fieldType === 'checkbox') { 
            // Déterminer le texte à afficher
            const status_text = (newValue === '1') ? 'Oui (Ignoré)' : 'Non (Suivi Actif)';
            
            field.textContent = status_text;
        }
        // --- FIN NOUVEAU ---
        
        else {
            // Gérer le cas du texte simple/textarea/email
            field.textContent = newValue;
        }

        // Ajoute l'icône de crayon
        const editIcon = document.createElement('span');
        editIcon.className = 'edit-icon';
        editIcon.innerHTML = '✏️'; 
        field.appendChild(editIcon);
    }
    // -------------------------------------------------------------------------
    // --- 2. GESTION DES ONGLETS (VANILLA JS) et MODALE (JQUERY) ---
    // -------------------------------------------------------------------------

    const tabButtons = document.querySelectorAll('.ispag-tabs-navigation .ispag-tab-btn');
    const tabPanes = document.querySelectorAll('.ispag-tabs-content .ispag-tab-pane');

    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            
            tabButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            tabPanes.forEach(pane => pane.classList.remove('active'));
            
            const targetPane = document.getElementById(`ispag-tab-${targetTab}`);
            if (targetPane) {
                targetPane.classList.add('active');
            }
        });
    });



    // --- 2.2 Événements: Fermeture de la modale ---
    $(document).on('click', '.ispag-modal-close, .ispag-modal-cancel', function(e) {
        e.preventDefault();
        closeAddContactModal();
    });
    
    // Fermeture par la touche Échap
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape' && modalContainer.children().length > 0) {
            closeAddContactModal();
        }
    });

    // --- 2.3 Événement: Changement d'onglet ---
    $(document).on('click', '.ispag-modal-tabs .ispag-tab-modal', function() {
        const tabBtn = $(this);
        const tabName = tabBtn.data('tab');
        const modal = tabBtn.closest('.ispag-modal-content');
        
        modal.find('.ispag-tab-modal').removeClass('active');
        tabBtn.addClass('active');

        modal.find('.ispag-tab-modal-pane').removeClass('active');
        modal.find('#tab-' + tabName).addClass('active');
    });

    // --- 2.4 Événement: Recherche de contact (saisie et bouton) ---
    // $(document).on('input', '#contact-search-input', debounce(function() {
    //     searchContacts($('#ispag-add-contact-modal'));
    // }, 300));

    // $(document).on('click', '#contact-search-btn', function() {
    //     searchContacts($('#ispag-add-contact-modal'));
    // });
    
    // --- 2.5 Événement: Sauvegarde des contacts ---
    $(document).on('click', '.ispag-modal-save', function(e) {
        e.preventDefault();
        associateSelectedContacts($('#ispag-add-contact-modal'));
    }); 

    /**
     * Ferme et vide la modale.
     */
    function closeAddContactModal() {
        const modal = $('#ispag-add-contact-modal');
        modal.fadeOut(200, function() {
            modalContainer.empty();
            $('body').removeClass('ispag-modal-open');
        });
    }

    /**
     * Effectue la recherche de contacts via AJAX et met à jour la liste.
     */
    function searchContacts(modal) {
        const companyId = modal.find('.ispag-modal-save').data('company-id');
        const searchTerm = modal.find('#contact-search-input').val() || '';
        const resultsContainer = modal.find(contactListContainerSelector);
        const countElement = modal.find('.results-count');

        resultsContainer.html('<div class="ispag-loader">Chargement...</div>');
        countElement.text('...');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ispag_search_contacts',
                company_id: companyId,
                search_term: searchTerm
            },
            success: function(response) {
                if (response.success && response.data.contacts) {
                    renderContactList(response.data.contacts, resultsContainer);
                    countElement.text(response.data.count + ' Contacts');
                } else {
                    resultsContainer.html('<p>Aucun contact trouvé.</p>');
                    countElement.text('0 Contact');
                }
            },
            error: function() {
                resultsContainer.html('<p>Erreur lors de la recherche des contacts.</p>');
            }
        });
    }

    /**
     * Rend la liste des contacts trouvés dans le conteneur.
     */
    function renderContactList(contacts, container) {
        let html = '';
        if (contacts.length === 0) {
            container.html('<p>Aucun contact non associé trouvé.</p>');
            return;
        }

        $.each(contacts, function(index, contact) {
            html += `
                <div class="contact-item">
                    <input type="checkbox" id="contact-${contact.ID}" name="contact_id[]" value="${contact.ID}">
                    <label for="contact-${contact.ID}">
                        <strong>${contact.display_name}</strong> (${contact.email})
                    </label>
                    <span class="contact-info-icon" title="Plus d'info">ⓘ</span>
                </div>
            `;
        });
        container.html(html);
    }
    
    /**
     * Sauvegarde les contacts sélectionnés à l'entreprise via AJAX.
     */
    function associateSelectedContacts(modal) {
        const companyId = modal.find('.ispag-modal-save').data('company-id');
        const selectedIds = modal.find('.contact-item input:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert('Veuillez sélectionner au moins un contact.');
            return;
        }

        modal.find('.ispag-modal-save').prop('disabled', true).text('Sauvegarde...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ispag_associate_contacts',
                company_id: companyId,
                contact_ids: selectedIds
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message); 
                    closeAddContactModal();
                    window.location.reload(); 
                } else {
                    alert('Erreur de sauvegarde: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erreur lors de l\'association des contacts.');
            },
            complete: function() {
                modal.find('.ispag-modal-save').prop('disabled', false).text('Sauvegarder');
            }
        });
    }
    // Événement de clic sur l'icône de suppression
    $(document).off('click', '.ispag-remove-association').on('click', '.ispag-remove-association', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!confirm("Êtes-vous sûr de vouloir retirer cette association ?")) {
            return;
        }

        const button = $(this);
        const action = button.data('action');
        const contactId = button.data('contact-id');
        const companyId = button.data('company-id');
        const dealId = button.data('deal-id');
        
        let ajax_action = '';

        const cardElement = button.closest('.ispag-card');
        
        if(action == 'remove-contact-from-company'){
            ajax_action = 'ispag_remove_company_association';
        }
        else if(action == 'remove-contact-from-deal'){
            ajax_action = 'ispag_remove_deal_contact_association';
        }
        
        // Empêcher les clics multiples pendant le traitement
        button.attr('disabled', true).css('opacity', 0.5);

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: ajax_action, // Nouvelle action AJAX
                contact_id: contactId,
                company_id: companyId,
                deal_id: dealId,
                security: ispag_ajax.nonce // Assurez-vous d'avoir un nonce
            },
            success: function(response) {
                if (response.success) {
                    const sectionContainer = button.closest('.ispag-contacts-card');
                    const countElement = sectionContainer.find('.ispag-contact-count');

                    if (countElement.length > 0) {
                        let currentCount = parseInt(countElement.text());
                        if (!isNaN(currentCount) && currentCount > 0) {
                            countElement.text(currentCount - 1);
                        }
                    }

                    cardElement.fadeOut(400, function() {
                        // 2. Supprimer l'élément du DOM une fois l'animation terminée
                        $(this).remove(); 
                    });
                } else {
                    alert('Erreur: ' + (response.data.message || 'Impossible de retirer l\'association.'));
                }
            },
            error: function() {
                alert('Erreur de connexion AJAX.');
            },
            complete: function() {
                button.attr('disabled', false).css('opacity', 1);
            }
        });
    });


    $(document).on('click', '#open-add-company-modal', function(e) { 
        e.preventDefault();
        const contactId = $(this).data('contact-id');
        openAddCompanyModal(contactId);
    });

    $(document).on('click', '#open-add-contact-modal', function(e) { 
        e.stopPropagation();
        e.preventDefault();

        const companyId = $(this).data('company-id');
        const dealGroupRef = $(this).data('deal-group-ref');
        console.log('click open-add-contact-modal', companyId, dealGroupRef);

        openAddContactModal(companyId, dealGroupRef); 
    });

    // Événement pour le bouton Load More
    $(document).on('click', '.load-more-btn', function(e) {
        e.preventDefault();
        companyPage++; // On incrémente la page
        searchCompanies($(sidebarSelector), true); // true = mode append
    });

    // 2. Gestion de la recherche en temps réel dans la modale (avec debounce)
    $(document).on('click', '#company-search-btn', function() {
        searchCompanies($('#ispag-add-company-sidebar'), false);
    });

    // 3. Gestion de l'association d'une entreprise (Bouton Associer)
    $(document).on('click', '.ispag-modal-save-company', function() {
//        console.log('.ispag-modal-save-company', 'Start');
        const button = $(this);
        const contactId = button.data('contact-id');
        // // Récupérer l'ID du contact depuis un élément de la modale (ex: depuis les tags associés)
        // const companyId = $('#ispag-add-company-sidebar').find('.ispag-remove-association-in-modal').first().data('contact-id'); 
        // const checkedIds = $('.companies-list-container input[type="checkbox"]:checked')
        // .map(function() {
        //     // La valeur de l'attribut 'value' du checkbox
        //     return $(this).val(); 
        // })
        // .get();
//        console.log('.ispag-modal-save-company contactId', contactId);
        if (contactId) {
            associateCompany(contactId, $('#ispag-add-company-sidebar'));
        }
    });

    // 4. Événement pour fermer la modale
    $(document).on('click', '.ispag-modal-close', function() {
        $('#ispag-add-company-sidebar').fadeOut(200, function() {
            $(this).remove();
            // $('body').removeClass('ispag-modal-open');
            requestCloseModal();
            location.reload(); // Recharger la page principale pour mettre à jour la carte des entreprises
        });
    });

        
    const sidebarSelector = '#ispag-add-company-sidebar';

    function openAddCompanyModal(contactId) {
        const modalContainer = $('#ispag-modal-container');
        const ajaxUrl = ispag_ajax.ajax_url;
 
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'ispag_render_add_company_modal', 
                contact_id: contactId
            },
            beforeSend: function() {
                $('body').addClass('loading-modal');
            },
            success: function(response) {
                modalContainer.html(response);
                
                // --- CHANGEMENT CLÉ ICI ---
                // On cible l'overlay de la sidebar
                const sidebar = $(sidebarSelector); 
                
                // 1. Ajouter la classe 'active' pour afficher l'overlay et faire coulisser le contenu
                sidebar.addClass('active'); 

                searchCompanies(sidebar); // Appelle la recherche initiale
                
                // Gérer les classes de body
                $('body').removeClass('loading-modal').addClass('ispag-sidebar-open');
                // --- FIN CHANGEMENT CLÉ ---
            },
            error: function() {
                alert('Erreur: Impossible de charger le panneau d\'entreprise.');
                $('body').removeClass('loading-modal');
            }
        });
    }

    // 1. Fermeture via le bouton X ou Cancel
    $(document).on('click', sidebarSelector + ' .ispag-modal-close, ' + sidebarSelector + ' .ispag-modal-cancel', function(e) { 
        e.preventDefault();
        closeAddCompanySidebar();
    });

    // 2. Fermeture en cliquant sur l'overlay (sauf sur le contenu lui-même)
    $(document).on('click', sidebarSelector, function(e) {
        if (e.target === this) {
            closeAddCompanySidebar();
        }
    });


    // --- GESTION SIDEBAR CONTACTS ---
    const sidebarSelectorContact = '#ispag-add-contact-sidebar';

    /**
     * Ouvre la sidebar de contact
     */
    // On ajoute le paramètre dealGroupRef (optionnel)
    function openAddContactModal(companyId, dealGroupRef = '') {
        const modalContainer = $('#ispag-modal-container');

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_render_add_contact_modal', 
                company_id: companyId,
                deal_group_ref: dealGroupRef // On transmet la ref ici
            },
            beforeSend: function() {
                $('body').addClass('loading-modal');
            },
            success: function(response) {
                modalContainer.html(response);
                const sidebar = $(sidebarSelectorContact); 

                sidebar.addClass('active'); 
                $('body').removeClass('loading-modal').addClass('ispag-sidebar-open');
                
                searchContacts('', companyId);
            },
            error: function() {
                alert('Erreur: Impossible de charger le panneau.');
                $('body').removeClass('loading-modal');
            }
        });
    }
    /**
     * Recherche de contacts
     */
    function searchContacts(term, companyId) {
        
        const container = $('.contact-list-container');
        container.html('<div class="ispag-loader">Recherche...</div>');

        $.post(ispag_ajax.ajax_url, {
            action: 'ispag_search_contacts',
            search_term: term,
            company_id: companyId
        }, function(response) {
            if(response.success) {
                // console.log(response.data);
                let html = '';
                if (response.data.contacts.length === 0) {
                    html = '<p>Aucun contact trouvé.</p>';
                } else {
                    response.data.contacts.forEach(function(contact) {
                        html += `
                        <div class="ispag-contact-item">
                            <input type="checkbox" id="ct-${contact.id}" value="${contact.id}">
                            <label for="ct-${contact.id}">
                                <strong>${contact.name}</strong> (${contact.email})
                            </label>
                        </div>`;
                    });
                }
                container.html(html);
                $('.results-count').text(response.data.count + ' Contacts trouvés');
            }
        });
    }

    // --- EVENTS DELEGATION ---

    // Bouton recherche
    $(document).on('click', '#contact-search-btn', function() {
        const term = $('#contact-search-input').val();
        const rawCompanyId = $('#modal_company_id').val();

        const companyId = parseInt(rawCompanyId, 10);

        if (isNaN(companyId)) {
            console.error("ISPAG : ID Société invalide ou manquant dans la modal.");
            alert("Erreur : ID de société introuvable.");
            return;
        }

        // console.log("Lancement de la recherche pour la société :", companyId, "avec le terme :", term);
        
        searchContacts(term, companyId);
    });

    // Bouton Sauvegarder
    $(document).on('click', '.ispag-modal-save-contact', function() {
        const btn = $(this);
        const companyId = btn.data('company-id');
        const dealGroupRef = btn.data('deal-group-ref'); // Peut être undefined si on est sur une fiche société seule
        
        // On récupère les IDs cochés
        const selectedIds = $('.contact-list-container input:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert('Veuillez sélectionner au moins un contact.');
            return;
        }

        btn.prop('disabled', true).text('Enregistrement...');

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_associate_contacts_to_company',
                company_id: companyId,
                contact_ids: selectedIds,
                deal_group_ref: dealGroupRef || '' // Envoi d'une chaîne vide si pas de deal
            },
            success: function(response) {
                if(response.success) {
                    // Succès : on recharge ou on ferme la sidebar
                    location.reload(); 
                } else {
                    alert('Erreur: ' + response.data.message);
                    btn.prop('disabled', false).text('Sauvegarder');
                }
            },
            error: function() {
                alert('Erreur réseau lors de la liaison.');
                btn.prop('disabled', false).text('Sauvegarder');
            }
        });
    });

    // Fermeture
    $(document).on('click', sidebarSelectorContact + ' .ispag-modal-close, ' + sidebarSelectorContact + ' .ispag-modal-cancel', function() {
        $(sidebarSelectorContact).removeClass('active');
        setTimeout(() => { $('#ispag-modal-container').empty(); }, 300);
        $('body').removeClass('ispag-sidebar-open');
    });

    // 1. Fermeture via le bouton X ou Cancel
    $(document).on('click', sidebarSelectorContact + ' .ispag-modal-close, ' + sidebarSelectorContact + ' .ispag-modal-cancel', function(e) { 
        e.preventDefault();
        closeAddCompanySidebar();
    });

    // 2. Fermeture en cliquant sur l'overlay (sauf sur le contenu lui-même)
    $(document).on('click', sidebarSelectorContact, function(e) {
        if (e.target === this) {
            closeAddCompanySidebar();
        }
    });


    function closeAddCompanySidebar() {
        const sidebar = $(sidebarSelector);
        const modalContainer = $('#ispag-modal-container');

        // 1. Retirer la classe 'active' pour déclencher l'animation de sortie
        sidebar.removeClass('active');
        
        // 2. Attendre la fin de l'animation CSS (300ms) avant de vider le contenu
        setTimeout(function() {
            modalContainer.empty();
        }, 300);

        $('body').removeClass('ispag-sidebar-open');
    }

    /**
     * Effectue la recherche d'entreprises non associées via AJAX et met à jour la liste.
     */
    function searchCompanies(modal, append = false) {
        const contactId = $("#modal_contact_id").val();
        if (!contactId) return;

        const searchTerm = $('#company-search-input').val() || '';
        const resultsContainer = $(companyListContainerSelector);
        const countElement = modal.find('.results-count');
        const loadMoreBtn = modal.find('.load-more-btn');

        if (!append) {
            resultsContainer.html('<div class="ispag-loader">Chargement...</div>');
            companyPage = 1; 
        }

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ispag_search_companies', 
                contact_id: contactId,
                search_term: searchTerm,
                page: companyPage // On envoie le numéro de page au PHP
            },
            success: function(response) {
                if (response.success && response.data.companies && response.data.companies.length > 0) {
                    
                    // On génère le HTML
                    let html = renderCompanyList(response.data.companies);
                    
                    if (append) {
                        resultsContainer.find('.ispag-loader').remove(); // Nettoyage au cas où
                        resultsContainer.append(html);
                    } else {
                        resultsContainer.html(html);
                    }

                    countElement.text(response.data.count + ' Entreprises');

                    // Cacher le bouton s'il n'y a plus rien à charger
                    // (Supposons que tu renvoies 'has_more' dans ton JSON PHP)
                    if (response.data.has_more) {
                        loadMoreBtn.show();
                    } else {
                        loadMoreBtn.hide();
                    }

                } else {
                    if (!append) {
                        resultsContainer.html('<p>Aucune entreprise trouvée.</p>');
                        loadMoreBtn.hide();
                    } else {
                        loadMoreBtn.hide();
                    }
                }
            }
        });
    }

    /**
     * Gère l'association de l'entreprise au contact.
     */
    function associateCompany(contactId, modal) {
        // const companyName = button.closest('.ispag-company-item').find('strong').text();
//        console.log(modal);
        const selectedIds = modal.find('.ispag-company-item input:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert('Veuillez sélectionner au moins une entreprise.');
            return;
        }
        
        modal.find('.ispag-modal-save-company').prop('disabled', true).text('Sauvegarde...');

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ispag_associate_company_to_contact', 
                contact_id: contactId,
                company_ids: selectedIds
            },
            success: function(response) {
                if (response.success) {
                    // ... code pour le succès (non modifié)
                    
                    // --- CHANGEMENT CLÉ ICI ---
                    // Remplacer l'ancienne fermeture par la nouvelle fonction 
                    closeAddCompanySidebar();
                    // NOTE: Vous pourriez aussi vouloir recharger la liste des entreprises associées 
                    // sur la page principale après le succès.

                } else {
                    alert('Erreur lors de l\'association: ' + response.data.message);
                    button.attr('disabled', false).text('Associer');
                }
            },
            error: function() {
                alert('Erreur de connexion lors de l\'association.');
                button.attr('disabled', false).text('Associer');
            }
        });
    }


    /**
     * Fonction de rendu des résultats de la recherche 
     */
    // Modifier renderCompanyList pour qu'elle RETOURNE le HTML au lieu de l'injecter
    function renderCompanyList(companies) {
        let html = '';
        companies.forEach(company => {
            html += `
                <div class="ispag-company-item">
                    <input type="checkbox" id="company-${company.Id}" name="company_id[]" value="${company.Id}">
                    <label for="company-${company.Id}">
                        <strong>${company.Fournisseur}</strong> (${company.Ville} - ${company.Id})
                    </label>
                </div>`;
        });
        return html;
    }

    // Fonction debounce (nécessaire si non déjà définie)
    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }


});


