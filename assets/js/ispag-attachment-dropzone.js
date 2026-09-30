jQuery(function ($) {

    let pendingDropFile = null;
    let pendingDropContext = null; // { articleId, source }

    // Empêcher le navigateur d'ouvrir le fichier si on rate la dropzone
    ['dragover', 'drop'].forEach(evt => {
        document.addEventListener(evt, function (e) {
            if (e.target.closest('.ispag-dropzone__field')) {
                e.preventDefault();
            }
        }, false);
    });

    // --- Feedback visuel (compteur pour éviter le clignotement enfant/parent) ---
    let dragCounter = 0;

    $(document).on('dragenter', '.ispag-dropzone__field', function (e) {
        e.preventDefault();
        dragCounter++;
        $(this).addClass('is-dragover');
    });

    $(document).on('dragleave', '.ispag-dropzone__field', function (e) {
        e.preventDefault();
        dragCounter--;
        if (dragCounter <= 0) {
            dragCounter = 0;
            $(this).removeClass('is-dragover');
        }
    });

    $(document).on('dragover', '.ispag-dropzone__field', function (e) {
        e.preventDefault(); // obligatoire pour autoriser le drop
    });

    // --- Le drop lui-même ---
    $(document).on('drop', '.ispag-dropzone__field', function (e) {
        e.preventDefault();
        dragCounter = 0;
        $(this).removeClass('is-dragover');

        const dt = e.originalEvent.dataTransfer;
        const files = dt.files;

        if (!files || !files.length) {
            // On regarde si quelque chose a quand même été déposé (texte/HTML = mail non exportable)
            const hasNonFileData = dt.items && Array.from(dt.items).some(item => item.kind === 'string');

            if (hasNonFileData) {
                alert(
                    "Unable to retrieve this file directly from Outlook.\n\n" +
                    "Faites d'abord glisser le mail vers votre Bureau (ou utilisez « Enregistrer sous »), " +
                    "then drop the resulting .eml/.msg file here."
                );
            }
            return;
        }

        // On prend le premier fichier (extension possible : boucle pour multi-drop)
        startArticleUpload($(this), files[0]);
    });

    // Fichier déposé OU choisi via le sélecteur : même traitement
    function startArticleUpload($field, file) {
        // Récupération du contexte depuis le bloc article parent
        const $article = $field.closest('.ispag-article, [data-article-id]');
        const articleId = $article.data('article-id');
        const source = $article.data('source') || 'project'; // 'project' ou 'purchase'

        if (!articleId) {
            console.error('[Dropzone] article_id introuvable, opération annulée.');
            return;
        }

        pendingDropFile = file;
        pendingDropContext = { articleId, source };

        openDocTypeModalForDrop(articleId, source);
    }

    // --- Clic sur la zone d'un article : sélecteur de fichiers, puis même chose qu'un dépôt ---
    $(document).on('click', '.ispag-article .ispag-dropzone__field', function (e) {
        if ($(e.target).closest('a, button, input').length) return;
        const $field = $(this);
        const input = document.createElement('input');
        input.type = 'file';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            if (input.files && input.files.length) { startArticleUpload($field, input.files[0]); }
            input.remove();
        });
        input.click();
    });

    /**
     * Ouvre la modal existante de sélection du type de document.
     * On réutilise ton action AJAX ispag_get_upload_modal.
     */
    function openDocTypeModalForDrop(articleId, source) {
        $.post(ajaxurl, {
            action: 'ispag_get_upload_modal',
            article_id: articleId,
            source: source
        }, function (response) {
            if (!response.success) {
                alert('Error while loading the document type modal.');
                return;
            }

            // Injecte le HTML de la modal (adapter le conteneur cible à ton markup réel)
            $('#ispag-attachment-modal-container').html(response.data.html);
            $('.ispag-modal-overlay#ispag-upload-modal').addClass('is-open');

            // Pré-remplir le nom de fichier visible dans la modal si le champ existe
            $('#ispag-upload-modal .ispag-selected-filename').text(pendingDropFile.name);
        });
    }

    /**
     * Sur clic "Valider" dans la modal existante : on envoie le fichier en attente
     * au lieu de celui d'un <input type="file">.
     * Adapte le sélecteur du bouton et le nom du champ doc_type à ton HTML réel.
     */
    $(document).on('click', '#ispag-upload-modal .btn-confirm-upload', function () {
        if (!pendingDropFile) return; // upload classique via input, on ne touche pas

        const docType = $('#ispag-upload-modal select[name="doc_type"]').val();
        const { articleId, source } = pendingDropContext;

        const formData = new FormData();
        formData.append('action', 'ispag_upload_attachment');
        formData.append('article_id', articleId);
        formData.append('source', source);
        formData.append('doc_type', docType);
        formData.append('file', pendingDropFile);
        formData.append('_ajax_nonce', ISPAG_TANK.nonce); // ou ton nonce dédié aux attachments

        const $btn = $(this);
        $btn.prop('disabled', true).text('Envoi...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function () {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (evt) {
                    if (evt.lengthComputable) {
                        const percent = Math.round((evt.loaded / evt.total) * 100);
                        $('#ispag-upload-modal .upload-progress-bar').css('width', percent + '%');
                    }
                });
                return xhr;
            },
            success: function (response) {
                if (response.success) {
                    // Rafraîchir la liste des attachments pour cet article
                    $.post(ajaxurl, {
                        action: 'ispag_refresh_attachments',
                        article_id: articleId,
                        source: source
                    }, function (refreshResponse) {
                        if (refreshResponse.success) {
                            $('.ispag-docu-card__body[data-article-id="' + articleId + '"]')
                                .replaceWith(refreshResponse.data.html);
                        }
                    });

                    $('.ispag-modal-overlay#ispag-upload-modal').removeClass('is-open');
                } else {
                    alert('Upload error: ' + (response.data || 'inconnue'));
                }
            },
            error: function () {
                alert('Network error lors de l\'upload.');
            },
            complete: function () {
                pendingDropFile = null;
                pendingDropContext = null;
                $btn.prop('disabled', false).text('Valider');
            }
        });
    });

});