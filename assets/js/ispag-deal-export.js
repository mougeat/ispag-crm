jQuery(document).ready(function($) {
    const $modal = $('#ispag-export-modal');

    // Ouvrir la modal
    $('#ispag-open-export-modal').on('click', function() {
        $modal.show();
    });

    // Fermer la modal
    $('.ispag-modal-close').on('click', function() {
        $modal.hide();
    });

    // Gérer l'export
    $('#ispag-export-form').on('submit', function(e) {
        e.preventDefault();
        
        // On récupère soit tous les IDs du tableau, soit seulement ceux cochés
        let selectedIds = [];
        const $checked = $('.ispag-project-checkbox:checked');
        
        if ($checked.length > 0) {
            $checked.each(function() { selectedIds.push($(this).val()); });
        } else {
            $('.ispag-project-checkbox').each(function() { selectedIds.push($(this).val()); });
        }

        const data = {
            action: 'ispag_export_deals',
            filename: $('#export_filename').val() || $('#export_filename').attr('placeholder'),
            format: $('#export_format').val(),
            ids: selectedIds
            // _ajax_nonce: ispag_ajax.nonce
        };

        // On ouvre l'export dans une nouvelle fenêtre/onglet pour forcer le téléchargement
        const baseUrl = ispag_ajax.ajax_url;
        const params = $.param(data);
        window.location.href = baseUrl + '?' + params;

        $modal.hide();
    });
});