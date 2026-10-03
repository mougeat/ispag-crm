window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
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
     * Parsee les options données sous forme de chaîne (key:label;key2:label2) ou JSON.
     * @param {string} optionsString 
     * @returns {Object} Un objet au format { key: { label: 'Label' } }
     */
    function parseCustomOptions(optionsString) {
        let optionsMap = {};

        if (!optionsString) return optionsMap;

        // 1. Essai de parsing JSON (si data-options contient du JSON)
        try {
            const parsed = JSON.parse(optionsString);
            if (typeof parsed === 'object' && parsed !== null) {
                for (const [key, val] of Object.entries(parsed)) {
                    if (typeof val === 'object' && val !== null && val.label) {
                        optionsMap[key] = val;
                    } else {
                        optionsMap[key] = { label: String(val) };
                    }
                }
                return optionsMap;
            }
        } catch (e) {
            // Ce n'était pas du JSON, on continue vers le parsing personnalisé
        }

        // 2. Parsing du format custom "key:valeur;key2:valeur2"
        const pairs = optionsString.split(';');
        pairs.forEach(pair => {
            const trimmed = pair.trim();
            if (trimmed) {
                const parts = trimmed.split(':');
                const key = parts[0] ? parts[0].trim() : '';
                const label = parts[1] ? parts[1].trim() : key;
                if (key !== '') {
                    optionsMap[key] = { label: label };
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
        return function(...args) {
            const context = this;
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                func.apply(context, args);
            }, delay);
        };
    }
    
    // =========================================================================
    // --- 1. GESTION DE L'ÉDITION EN LIGNE (INLINE EDIT) ---
    // =========================================================================

    const editableFields = document.querySelectorAll('.ispag-editable-field');

    editableFields.forEach(field => {
        field.addEventListener('click', (event) => {
            if (field.classList.contains('editing') || event.target.tagName === 'INPUT' || event.target.tagName === 'SELECT' || event.target.tagName === 'TEXTAREA') {
                return;
            }
            if (event.target.closest('.ignore-inline-edit')) { 
                return; 
            }
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
            
            try {
                optionsMap = parseCustomOptions(optionsJsonString);
            } catch (err) {
                console.error('Error lors du parsing des options :', err);
            }
            
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Select...';
            inputElement.appendChild(defaultOption);

            for (const [value, dataObject] of Object.entries(optionsMap)) {
                const label = dataObject.label || value; 
                
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                
                if (value.toString() === currentValue.toString()) { 
                    option.selected = true;
                }
                inputElement.appendChild(option);
            }

        } else if (fieldType === 'textarea') {
            inputElement = document.createElement('textarea');
            inputElement.className = 'ispag-edit-input';
            inputElement.value = currentValue;
            inputElement.rows = 3; 
        } 
        else if (fieldType === 'checkbox') {
            inputElement = document.createElement('input');
            inputElement.type = 'checkbox'; 
            inputElement.name = fieldName;
            inputElement.value = '1'; 
            
            if (currentValue === '1') {
                inputElement.checked = true;
            }

            const labelText = document.createElement('span');
            labelText.textContent = ' ' + (field.dataset.title || '') + ' (Cocher = oui)';
            
            const wrapper = document.createElement('div');
            wrapper.className = 'ispag-checkbox-wrapper';
            wrapper.appendChild(inputElement);
            wrapper.appendChild(labelText);
            
            field.appendChild(wrapper);
            
            inputElement.addEventListener('change', () => {
                const newValue = inputElement.checked ? '1' : '0';
                saveAndExitEditMode(field, newValue);
            });
            
            return; 
        }
        else if (fieldType === 'date') {
            inputElement = document.createElement('input');
            inputElement.type = 'date';
            inputElement.className = 'ispag-edit-input';
            inputElement.value = currentValue; 
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
        
        inputElement.addEventListener('blur', () => {
            saveAndExitEditMode(field, inputElement.value.trim());
        });

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
        let fieldName = field.dataset.name; // Changé de const à let
        const currentValue = field.dataset.value;
        
        const companyContainer = field.closest('[data-company-id]');
        const contactContainer = field.closest('[data-contact-id]');
        const projectContainer = field.closest('[data-project-id]');
        const userDepartment = field.dataset.departmentId;

        let entityId = null;
        let ajaxAction = null;
        let idKey = null;
        let source = null;

        const formData = new FormData();

        if (companyContainer) {
            entityId = companyContainer.dataset.companyId;
            ajaxAction = 'save_company_field'; 
            idKey = 'company_id';
        } else if (contactContainer) { 
            entityId = contactContainer.dataset.contactId;
            ajaxAction = 'save_contact_field'; 
            idKey = 'contact_id';
        } else if (projectContainer) { 
            entityId = projectContainer.dataset.projectId;
            ajaxAction = 'ispag_inline_edit_field'; 
            idKey = 'deal_id';
            source = 'project';
        } else {
            console.error('Error: ID d\'entité non trouvé.');
            alert(ispagT('Error: The entity ID is missing.'));
            exitEditMode(field);
            return;
        }

        if (newValue === currentValue) {
            exitEditMode(field);
            return;
        }

        if (!entityId) {
            console.error('Error: Entity ID non trouvé.');
            alert(ispagT('Error: The entity ID is missing.'));
            exitEditMode(field);
            return;
        }

        field.classList.add('loading');
        field.innerHTML = '<span style="color: var(--ispag-color-primary, #007bff);">Saving...</span>'; 
        
        formData.append('action', ajaxAction); 
        formData.append(idKey, entityId); 
        if (userDepartment) formData.append('userDepartment', userDepartment); 
        if (source) formData.append('source', source); 
        formData.append('field_name', fieldName);
        formData.append('new_value', newValue);
        formData.append('field', fieldName);
        formData.append('value', newValue);
        
        if (field.dataset.departmentId) {
            formData.append('department_id', field.dataset.departmentId);
        }

        if (typeof ispag_ajax === 'undefined' || !ispag_ajax.ajax_url) {
            console.error('Error JS: ispag_ajax ou ajax_url est manquant.');
            alert(ispagT('AJAX configuration error.'));
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
                field.dataset.value = newValue;
                
                if (data.data && data.data.display_value) {
                    field.innerHTML = data.data.display_value;
                } else {
                    updateDisplayContent(field, newValue);
                }
                
                field.classList.remove('editing');
                field.dataset.originalContent = field.innerHTML; 
            } else {
                const errorMessage = data.data && data.data.message ? data.data.message : ispagT('Save failed.');
                console.error('Save error:', errorMessage);
                field.innerHTML = `<span style="color: #dc3545;">Error: ${errorMessage}</span>`;
                setTimeout(() => exitEditMode(field), 2000); 
            }
        })
        .catch(error => {
            field.classList.remove('loading');
            console.error('Network error ou Fetch:', error);
            field.innerHTML = '<span style="color: #dc3545;">Connection error.</span>';
            setTimeout(() => exitEditMode(field), 2000);
        });
    }

    function exitEditMode(field) {
        field.classList.remove('editing');
        field.classList.remove('loading');
        field.innerHTML = field.dataset.originalContent;
    }

    function updateDisplayContent(field, newValue) {
        const fieldType = field.dataset.type || 'text';
        field.innerHTML = ''; 

        if (fieldType === 'checkbox') { 
            field.textContent = (newValue === '1') ? 'Oui' : 'Non';
        } else {
            field.textContent = newValue;
        }

        const editIcon = document.createElement('span');
        editIcon.className = 'edit-icon';
        editIcon.innerHTML = ' ✏️'; 
        field.appendChild(editIcon);
    }

    // =========================================================================
    // --- 2. GESTION DES ONGLETS ET MODALES ---
    // =========================================================================

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

    $(document).on('click', '.ispag-modal-close, .ispag-modal-cancel', function(e) {
        e.preventDefault();
        closeAddContactModal();
    });
    
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape' && modalContainer.children().length > 0) {
            closeAddContactModal();
        }
    });

    $(document).on('click', '.ispag-modal-tabs .ispag-tab-modal', function() {
        const tabBtn = $(this);
        const tabName = tabBtn.data('tab');
        const modal = tabBtn.closest('.ispag-modal-content');
        
        modal.find('.ispag-tab-modal').removeClass('active');
        tabBtn.addClass('active');

        modal.find('.ispag-tab-modal-pane').removeClass('active');
        modal.find('#tab-' + tabName).addClass('active');
    });

    $(document).on('click', '.ispag-modal-save', function(e) {
        e.preventDefault();
        associateSelectedContacts($('#ispag-add-contact-modal'));
    }); 

    function closeAddContactModal() {
        const modal = $('#ispag-add-contact-modal');
        modal.fadeOut(200, function() {
            modalContainer.empty();
            $('body').removeClass('ispag-modal-open');
        });
    }

    function searchContacts(modal) {
        const companyId = modal.find('.ispag-modal-save').data('company-id');
        const searchTerm = modal.find('#contact-search-input').val() || '';
        const resultsContainer = modal.find(contactListContainerSelector);
        const countElement = modal.find('.results-count');

        resultsContainer.html('<div class="ispag-loader">Loading...</div>');
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
                    resultsContainer.html('<p>No contact found.</p>');
                    countElement.text('0 Contact');
                }
            },
            error: function() {
                resultsContainer.html('<p>Error while searching contacts.</p>');
            }
        });
    }

    function renderContactList(contacts, container) {
        let html = '';
        if (contacts.length === 0) {
            container.html('<p>No unassociated contact found.</p>');
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

    function associateSelectedContacts(modal) {
        const companyId = modal.find('.ispag-modal-save').data('company-id');
        const selectedIds = modal.find('.contact-item input:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert(ispagT('Please select at least one contact.'));
            return;
        }

        modal.find('.ispag-modal-save').prop('disabled', true).text('Saving...');

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
                    alert(ispagT('Save error: ') + response.data.message);
                }
            },
            error: function() {
                alert(ispagT('Error while linking contacts.'));
            },
            complete: function() {
                modal.find('.ispag-modal-save').prop('disabled', false).text(ispagT('Save'));
            }
        });
    }

    $(document).off('click', '.ispag-remove-association').on('click', '.ispag-remove-association', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!confirm(ispagT("Are you sure you want to remove this association?"))) {
            return;
        }

        const button = $(this);
        const action = button.data('action');
        const contactId = button.data('contact-id');
        const companyId = button.data('company-id');
        const dealId = button.data('deal-id');
        
        let ajax_action = '';
        const cardElement = button.closest('.ispag-card');
        
        if (action === 'remove-contact-from-company') {
            ajax_action = 'ispag_remove_company_association';
        } else if (action === 'remove-contact-from-deal') {
            ajax_action = 'ispag_remove_deal_contact_association';
        }
        
        button.attr('disabled', true).css('opacity', 0.5);

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: ajax_action,
                contact_id: contactId,
                company_id: companyId,
                deal_id: dealId,
                security: ispag_ajax.nonce
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
                        $(this).remove(); 
                    });
                } else {
                    alert(ispagT('Error: ') + (response.data.message || 'Unable to remove the association.'));
                }
            },
            error: function() {
                alert(ispagT('AJAX connection error.'));
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
        const dealId = $(this).data('deal-id');

        openAddContactModal(companyId, dealGroupRef, dealId); 
    });

    $(document).on('click', '.load-more-btn', function(e) {
        e.preventDefault();
        companyPage++;
        searchCompanies($(sidebarSelector), true);
    });

    $(document).on('click', '#company-search-btn', function() {
        searchCompanies($('#ispag-add-company-sidebar'), false);
    });

    $(document).on('keypress', '#company-search-input', function(e) {
        if (e.which === 13 || e.key === 'Enter') {
            e.preventDefault();
            searchCompanies($('#ispag-add-company-sidebar'), false);
        }
    });

    $(document).on('click', '.ispag-modal-save-company', function() {
        const button = $(this);
        const contactId = button.data('contact-id');
        if (contactId) {
            associateCompany(contactId, $('#ispag-add-company-sidebar'));
        }
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
                const sidebar = $(sidebarSelector); 
                sidebar.addClass('active'); 
                searchCompanies(sidebar);
                $('body').removeClass('loading-modal').addClass('ispag-sidebar-open');
            },
            error: function() {
                alert(ispagT('Error: Unable to load the company panel.'));
                $('body').removeClass('loading-modal');
            }
        });
    }

    $(document).on('click', sidebarSelector + ' .ispag-modal-close, ' + sidebarSelector + ' .ispag-modal-cancel', function(e) { 
        e.preventDefault();
        closeAddCompanySidebar();
    });

    $(document).on('click', sidebarSelector, function(e) {
        if (e.target === this) {
            closeAddCompanySidebar();
        }
    });

    const sidebarSelectorContact = '#ispag-add-contact-sidebar';

    function openAddContactModal(companyId = '', dealGroupRef = '', dealId = '') {
        const modalContainer = $('#ispag-modal-container');

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_render_add_contact_modal', 
                company_id: companyId,
                deal_group_ref: dealGroupRef,
                deal_id: dealId
            },
            beforeSend: function() {
                $('body').addClass('loading-modal');
            },
            success: function(response) {
                modalContainer.html(response);
                const sidebar = $(sidebarSelectorContact); 

                sidebar.addClass('active'); 
                $('body').removeClass('loading-modal').addClass('ispag-sidebar-open');
                
                searchSidebarContacts('', companyId);
            },
            error: function() {
                alert(ispagT('Error: Unable to load the panel.'));
                $('body').removeClass('loading-modal');
            }
        });
    }

    function searchSidebarContacts(term, companyId) {
        const container = $('.contact-list-container');
        container.html('<div class="ispag-loader">Searching...</div>');

        $.post(ispag_ajax.ajax_url, {
            action: 'ispag_search_contacts',
            search_term: term,
            company_id: companyId
        }, function(response) {
            if (response.success) {
                let html = '';
                if (response.data.contacts.length === 0) {
                    html = '<p>No contact found.</p>';
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
                $('.results-count').text(response.data.count + ' Contacts found');
            }
        });
    }

    $(document).on('click', '#contact-search-btn', function() {
        triggerContactSearch();
    });

    $(document).on('keypress', '#contact-search-input', function(e) {
        if (e.which === 13 || e.key === 'Enter') {
            e.preventDefault();
            triggerContactSearch();
        }
    });

    function triggerContactSearch() {
        const term = $('#contact-search-input').val();
        const rawCompanyId = $('#modal_company_id').val();
        const companyId = parseInt(rawCompanyId, 10);

        if (isNaN(companyId)) {
            console.error("ISPAG : ID Société invalide ou manquant dans la modal.");
            alert(ispagT("Error: Company ID not found."));
            return;
        }

        searchSidebarContacts(term, companyId);
    }

    $(document).on('click', '.ispag-modal-save-contact', function() {
        const btn = $(this);
        const companyId = btn.data('company-id');
        const dealGroupRef = btn.data('deal-group-ref');
        const hubspotDealId = btn.data('hubspot-deal-id');
        
        const selectedIds = $('.contact-list-container input:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert(ispagT('Please select at least one contact.'));
            return;
        }

        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: ispag_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_associate_contacts_to_company',
                company_id: companyId,
                contact_ids: selectedIds,
                deal_group_ref: dealGroupRef || '',
                deal_id: hubspotDealId,
            },
            success: function(response) {
                if (response.success) {
                    location.reload(); 
                } else {
                    alert(ispagT('Error: ') + response.data.message);
                    btn.prop('disabled', false).text(ispagT('Save'));
                }
            },
            error: function() {
                alert(ispagT('Network error lors de la liaison.'));
                btn.prop('disabled', false).text(ispagT('Save'));
            }
        });
    });

    $(document).on('click', sidebarSelectorContact + ' .ispag-modal-close, ' + sidebarSelectorContact + ' .ispag-modal-cancel', function() {
        $(sidebarSelectorContact).removeClass('active');
        setTimeout(() => { $('#ispag-modal-container').empty(); }, 300);
        $('body').removeClass('ispag-sidebar-open');
    });

    $(document).on('click', sidebarSelectorContact, function(e) {
        if (e.target === this) {
            $(sidebarSelectorContact).removeClass('active');
            setTimeout(() => { $('#ispag-modal-container').empty(); }, 300);
            $('body').removeClass('ispag-sidebar-open');
        }
    });

    function closeAddCompanySidebar() {
        const sidebar = $(sidebarSelector);
        const modalContainer = $('#ispag-modal-container');

        sidebar.removeClass('active');
        setTimeout(function() {
            modalContainer.empty();
        }, 300);

        $('body').removeClass('ispag-sidebar-open');
    }

    function searchCompanies(modal, append = false) {
        const contactId = $("#modal_contact_id").val();
        if (!contactId) return;

        const searchTerm = $('#company-search-input').val() || '';
        const resultsContainer = $(companyListContainerSelector);
        const countElement = modal.find('.results-count');
        const loadMoreBtn = modal.find('.load-more-btn');

        if (!append) {
            resultsContainer.html('<div class="ispag-loader">Loading...</div>');
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
                page: companyPage
            },
            success: function(response) {
                if (response.success && response.data.companies && response.data.companies.length > 0) {
                    let html = renderCompanyList(response.data.companies);
                    
                    if (append) {
                        resultsContainer.find('.ispag-loader').remove();
                        resultsContainer.append(html);
                    } else {
                        resultsContainer.html(html);
                    }

                    countElement.text(response.data.count + ' Entreprises');

                    if (response.data.has_more) {
                        loadMoreBtn.show();
                    } else {
                        loadMoreBtn.hide();
                    }

                } else {
                    if (!append) {
                        resultsContainer.html('<p>No company found.</p>');
                        loadMoreBtn.hide();
                    } else {
                        loadMoreBtn.hide();
                    }
                }
            }
        });
    }

    function associateCompany(contactId, modal) {
        const selectedIds = modal.find('.ispag-company-item input:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert(ispagT('Please select at least one company.'));
            return;
        }
        
        const saveBtn = modal.find('.ispag-modal-save-company');
        saveBtn.prop('disabled', true).text('Saving...');

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
                    closeAddCompanySidebar();
                    window.location.reload();
                } else {
                    alert(ispagT('Error while linking: ') + response.data.message);
                    saveBtn.prop('disabled', false).text(ispagT('Associer'));
                }
            },
            error: function() {
                alert(ispagT('Connection error while linking.'));
                saveBtn.prop('disabled', false).text(ispagT('Associer'));
            }
        });
    }

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

});