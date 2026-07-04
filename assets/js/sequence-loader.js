jQuery(document).ready(function($) {
    console.log("=== ISPAG Sequence Loader Loaded ===");
    let currentContactId = null;
    let currentDealId = null;

    // 1. OUVERTURE DE LA MODAL & CHARGEMENT DES SEQUENCES
    $(document).on('click', '.open-sequence-modal', function(e) {
        e.preventDefault();
        
        currentContactId = $(this).data('contact-id');
        currentDealId    = $(this).data('deal-id');
        const contactName = $(this).data('contact-name');
        const $modal = $('#modal-enroll-sequence');
        const $select = $('#select-sequence-id');

        if ($modal.length === 0) return console.error("Modal introuvable");

        $('#display-contact-name').text(contactName);
        $select.html('<option value="">Chargement...</option>');
        $modal.fadeIn();

        // APPEL AJAX POUR LES SEQUENCES
        $.post(ispag_ajax.ajax_url, {
            action: 'get_active_sequences',
            security: ispag_ajax.nonce // Utilise l'objet global défini dans ispag-crm.php
        }, function(response) {
            if (response.success) {
                let options = '<option value="">-- Choisir une séquence --</option>';
                response.data.forEach(function(seq) {
                    options += `<option value="${seq.id}">${seq.name}</option>`;
                });
                $select.html(options);
            } else {
                $select.html('<option value="">Erreur de chargement</option>');
            }
        });
    });

    // 2. ACTION DU BOUTON START
    $('#confirm-enroll').on('click', function() {
        const sequenceId = $('#select-sequence-id').val();
        if (!sequenceId) return alert('Veuillez sélectionner une séquence.');

        const $btn = $(this);
        $btn.attr('disabled', true).text('Lancement...');

        $.post(ispag_ajax.ajax_url, {
            action: 'enroll_in_sequence',
            security: ispag_ajax.nonce, // Doit correspondre à check_ajax_referer('ispag_crm_nonce', 'security')
            contact_id: currentContactId,
            deal_id: currentDealId,
            sequence_id: sequenceId
        }, function(response) {
            if (response.success) {
                alert(response.data);
                $('#modal-enroll-sequence').fadeOut();
            } else {
                alert('Erreur : ' + response.data);
            }
            $btn.attr('disabled', false).text('Start');
        });
    });

    // 3. FERMETURE
    $(document).on('click', '.ispag-modal-cancel, .close-modal', function() {
        $('#modal-enroll-sequence').fadeOut();
    });
});