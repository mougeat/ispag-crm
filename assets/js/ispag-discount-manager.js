window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
jQuery(document).ready(function($) {
    // Log initial pour vérifier que le script est chargé
// console.log('🔹 ISPAG Discount Manager: Script loaded');

    // Sélecteur pour les menus déroulants de rabais/coefficients
    const $discountSelects = $('.ispag-discount-select');
// console.log('🔹 Found discount selects:', $discountSelects.length);

    // Stocker les valeurs initiales pour chaque menu déroulant
    $discountSelects.each(function() {
        const currentValue = $(this).val();
        $(this).data('previous-value', currentValue);
        console.log('🔹 Stored previous value for select:', {
            field: $(this).data('field-name'),
            value: currentValue
        });
    });

    // Écouteur d'événement pour détecter les changements
    $discountSelects.on('change', function() {
        const $select = $(this);
        const companyId = $select.data('company-id');
        const fieldName = $select.data('field-name');
        const $editIcon = $select.siblings('.edit-icon');
        const previousValue = $select.data('previous-value');
        let newValue = $select.val();

        console.log('🔹 Change detected in select:', {
            companyId: companyId,
            fieldName: fieldName,
            previousValue: previousValue,
            newValue: newValue
        });

        // Convertir la valeur en nombre (float) avec 4 décimales pour éviter les erreurs
        if (fieldName === 'rabais' || fieldName === 'coef_vente') {
            newValue = parseFloat(newValue).toFixed(4);
// console.log('🔹 Converted newValue to float:', newValue);
        }

        // Désactiver le menu déroulant et afficher un indicateur de chargement
        $select.prop('disabled', true).css('opacity', '0.7');
        $editIcon.css('opacity', '0.5');
// console.log('🔹 Disabled select and showed loading indicator');

        // Envoyer la requête AJAX
        console.log('🔹 Sending AJAX request with data:', {
            action: 'update_company_discount_or_coef',
            company_id: companyId,
            field_name: fieldName,
            new_value: newValue,
            url: ispagDiscountManager.ajax_url,
            nonce: ispagDiscountManager.nonce
        });

        $.ajax({
            url: ispagDiscountManager.ajax_url,
            type: 'POST',
            data: {
                action: 'update_company_discount_or_coef',
                company_id: companyId,
                field_name: fieldName,
                new_value: newValue,
                nonce: ispagDiscountManager.nonce
            },
            beforeSend: function() {
// console.log('🔹 AJAX beforeSend: Request about to be sent');
            },
            success: function(response) {
// console.log('🔹 AJAX success: Response received:', response);

                if (response.success) {
// console.log('🔹 Success: Updating UI for success');

                    // Mettre à jour l'icône d'édition pour indiquer le succès
                    $editIcon.css('color', '#28a745').animate({ color: '#333' }, 1000);

                    // Afficher un toast de succès
                    if (typeof ispagShowToast === 'function') {
// console.log('🔹 Showing toast with message:', response.data?.message);
                        ispagShowToast('success', response.data?.message || 'Update successful');
                    } else {
// console.log('🔹 Toast function not available. Logging message:', response.data?.message);
                    }

                    // Mettre à jour les données du menu déroulant pour les prochaines modifications
                    $select.data('previous-value', newValue);
// console.log('🔹 Updated previous-value to:', newValue);
                } else {
                    console.error('🔴 AJAX error in response:', response);

                    // Réinitialiser la valeur en cas d'erreur
                    $select.val($select.data('previous-value'));
// console.log('🔹 Reverted to previous value:', $select.data('previous-value'));

                    const errorMessage = response?.data?.message || 'An error occurred while updating.';
                    console.error('🔴 Error message:', errorMessage);
                    alert(errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('🔴 AJAX request failed:', {
                    status: status,
                    error: error,
                    xhr: xhr
                });

                // Réinitialiser la valeur en cas d'erreur
                $select.val($select.data('previous-value'));
// console.log('🔹 Reverted to previous value after error:', $select.data('previous-value'));

                alert(ispagT('An error occurred. Please try again.'));
            },
            complete: function() {
// console.log('🔹 AJAX complete: Re-enabling select');
                // Réactiver le menu déroulant
                $select.prop('disabled', false).css('opacity', '1');
                $editIcon.css('opacity', '1');
            }
        });
    });

    // Fonction utilitaire pour afficher des notifications (toasts)
    window.ispagShowToast = function(type, message) {
// console.log('🔹 Showing toast:', { type, message });

        let toastContainer = $('#ispag-toast-container');
        if (toastContainer.length === 0) {
// console.log('🔹 Creating toast container');
            $('body').append('<div id="ispag-toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999;"></div>');
            toastContainer = $('#ispag-toast-container');
        }

        const toastId = 'toast-' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="ispag-toast ispag-toast-${type}" style="
                padding: 12px 20px;
                background: ${type === 'success' ? '#d4edda' : '#f8d7da'};
                color: ${type === 'success' ? '#155724' : '#721c24'};
                border: 1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'};
                border-radius: var(--ispag-btn-border-radius);
                margin-bottom: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                animation: slideIn 0.3s ease-out;
            ">
                ${message}
            </div>
        `;

        toastContainer.append(toastHtml);
// console.log('🔹 Toast added to container:', toastId);

        // Supprimer le toast après 3 secondes
        setTimeout(() => {
// console.log('🔹 Removing toast:', toastId);
            $(`#${toastId}`).fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    };

    // Ajouter une animation CSS pour les toasts (si elle n'existe pas)
    if ($('style#ispag-toast-animation').length === 0) {
// console.log('🔹 Adding toast animation CSS');
        $('head').append(`
            <style id="ispag-toast-animation">
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            </style>
        `);
    }

// console.log('🔹 ISPAG Discount Manager: Initialization complete');
});