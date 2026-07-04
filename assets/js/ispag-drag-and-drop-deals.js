jQuery(document).ready(function($) {

    if (typeof ispag_ajax === 'undefined' || ispag_ajax === null) {
        console.error('ISPAG Kanban variables are not loaded.');
        return;
    }

    

    // --- LOGIQUE DRAG AND DROP ---
    $('.kanban-deal-card').on('dragstart', function(event) {
        const dealId = $(this).data('deal-id');
        event.originalEvent.dataTransfer.setData('text/plain', dealId.toString());
        setTimeout(() => $(this).addClass('is-dragging'), 0);
    });

    $('.kanban-deal-card').on('dragend', function() {
        $(this).removeClass('is-dragging');
    });

    $('.ispag-deals-dropzone').on('dragover', function(event) {
        event.preventDefault(); 
        $(this).addClass('is-drag-over'); 
    });
    
    $('.ispag-deals-dropzone').on('dragleave', function() {
        $(this).removeClass('is-drag-over');
    });

    $('.ispag-deals-dropzone').on('drop', function(event) {
        event.preventDefault(); 
        $(this).removeClass('is-drag-over');
        const dealId = event.originalEvent.dataTransfer.getData('text/plain');
        const newStage = $(this).data('stage-key');
        
        if (dealId && newStage) {
            // Si c'est un drop vers "Closed Lost", on pourrait aussi ouvrir la modal ici
            // Mais pour l'instant, on reste sur le comportement par défaut
            const draggedElement = $('.kanban-deal-card[data-deal-id="' + dealId + '"]');
            $(this).append(draggedElement); 
            sendUpdateStage(dealId, newStage);
        }
    });

});