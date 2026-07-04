document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('ispag-select-all');
    const checkboxes = document.querySelectorAll('input[name="ispag_contact_ids[]"]');
    const bulkForm = document.getElementById('ispag-bulk-edit-form');
    const bulkActionSelect = document.getElementById('ispag-bulk-action-select');

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
        });
    }

    // Gérer la soumission du formulaire d'édition groupée
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const selectedAction = bulkActionSelect.value;
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            // 1. Vérifier si des contacts sont sélectionnés
            if (checkedCount === 0) {
                alert("<?php esc_attr_e( 'Please select at least one contact for bulk editing.', 'ispag-crm' ); ?>");
                e.preventDefault();
                return;
            }
            
            // 2. Vérifier si une action est sélectionnée
            if (selectedAction === '0') {
                alert("<?php esc_attr_e( 'Please select a bulk action to perform.', 'ispag-crm' ); ?>");
                e.preventDefault();
                return;
            }
            
            // Confirmation (facultatif mais recommandé)
            if (!confirm("<?php esc_attr_e( 'Are you sure you want to apply the selected action to ' . '" + checkedCount + " contacts?', 'ispag-crm' ); ?>")) {
                e.preventDefault();
            }
        });
    }
});