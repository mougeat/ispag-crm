(function($) {
    "use strict";

    /**
     * Initialise une dropzone donnée (support multi-fichiers).
     */
    function initDropzone($dz) {
        if ($dz.data('ispag-dz-init')) {
            return;
        }
        $dz.data('ispag-dz-init', true);

        const $field     = $dz.find('.ispag-dropzone__field');
        const $input     = $dz.find('.ispag-dropzone__input');
        const $preview   = $dz.find('.ispag-dropzone__file-preview');
        const $fileName  = $dz.find('.ispag-dropzone__file-name');
        const $removeBtn = $dz.find('.ispag-dropzone__file-remove');
        const $select    = $dz.find('.ispag-dropzone__doctype-select');
        const $submit    = $dz.find('.ispag-dropzone__submit');
        const $status    = $dz.find('.ispag-dropzone__status');
        const $progress  = $dz.find('.ispag-dropzone__progress');
        const $progressBar = $dz.find('.ispag-dropzone__progress-bar');

        let selectedFiles = [];

        $input.on('click', function(e) {
            e.stopPropagation();
        });

        function setFiles(files) {
            if (files && files.length > 0) {
                selectedFiles = Array.from(files);

                if (selectedFiles.length === 1) {
                    $fileName.text(selectedFiles[0].name);
                } else {
                    $fileName.text(selectedFiles.length + ' files selected');
                }

                $preview.prop('hidden', false);
                $field.prop('hidden', true);
            } else {
                selectedFiles = [];
                $fileName.text('');
                $preview.prop('hidden', true);
                $field.prop('hidden', false);
                $input.val('');
                hideProgress();
                $status.text('');
            }
            updateSubmitState();
        }

        function updateSubmitState() {
            $submit.prop('disabled', !(selectedFiles.length > 0 && $select.val()));
        }

        function showProgress() {
            $progressBar.css('width', '0%');
            $progress.prop('hidden', false);
        }

        function setProgress(percent) {
            $progressBar.css('width', percent + '%');
            $status.text(percent + '%');
        }

        function hideProgress() {
            $progress.prop('hidden', true);
            $progressBar.css('width', '0%');
        }

        $field.on('click', function() {
            $input.trigger('click');
        });

        $field.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $input.trigger('click');
            }
        });

        $field.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dz.addClass('is-dragover');
        });

        $field.on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dz.removeClass('is-dragover');
        });

        $field.on('drop', function(e) {
            const files = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
            if (files && files.length) {
                setFiles(files);
            }
        });

        $input.on('change', function() {
            if (this.files && this.files.length) {
                setFiles(this.files);
            }
        });

        $removeBtn.on('click', function() {
            setFiles(null);
        });

        $select.on('change', updateSubmitState);

        $submit.on('click', function() {
            if (selectedFiles.length === 0 || !$select.val()) {
                return;
            }

            const $selectedOption = $select.find(':selected');
            const articleId = $selectedOption.attr('data-article-id') || 0;

            const formData = new FormData();
            formData.append('action', 'ispag_upload_attachment');
            formData.append('security', ispagAttachmentsAjax.nonce);
            formData.append('entity_type', $dz.data('entity-type'));
            formData.append('entity_id', $dz.data('entity-id'));
            formData.append('doc_type', $select.val());
            formData.append('article_id', articleId);

            selectedFiles.forEach(function(file) {
                formData.append('files[]', file);
            });

            $submit.prop('disabled', true);
            showProgress();
            $status.text('0%');

            $.ajax({
                url: ispagAttachmentsAjax.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                // jQuery ne remonte pas la progression par défaut : il faut
                // intercepter l'objet xhr avant l'envoi et écouter
                // xhr.upload (pas xhr tout court, qui suit la réponse, pas l'envoi).
                xhr: function() {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            setProgress(percent);
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    if (response.success) {
                        setProgress(100);
                        $status.text(ispagAttachmentsAjax.texts.uploadSuccess);
                        setTimeout(hideProgress, 400);
                        setFiles(null);
                        $select.val('');
                        $dz.trigger('ispag:attachment-uploaded', [response.data]);

                        // Zone de dépôt intégrée à la page (onglet Documents…) : la modal n'est pas là pour rafraîchir la liste
                        if (!$dz.closest('.ispag-modal-overlay').length) {
                            $('.ispag-docu-card[data-entity-type="' + $dz.data('entity-type') + '"][data-entity-id="' + $dz.data('entity-id') + '"]')
                                .trigger('ispag:refresh-attachments');
                            $submit.prop('disabled', false);
                        }
                    } else {
                        hideProgress();
                        $status.text((response.data && response.data.message) || ispagAttachmentsAjax.texts.uploadError);
                        $submit.prop('disabled', false);
                    }
                },
                error: function(xhr) {
                    console.error('[attachments] upload error', xhr.status, xhr.responseText);
                    hideProgress();
                    $status.text(ispagAttachmentsAjax.texts.uploadError);
                    $submit.prop('disabled', false);
                },
            });
        });
    }

    /** Initialise toutes les dropzones présentes dans un scope donné */
    function initAllDropzones($scope) {
        ($scope || $(document)).find('.ispag-dropzone').addBack('.ispag-dropzone').each(function() {
            initDropzone($(this));
        });
    }

    $(document).ready(function() {
        initAllDropzones();
    });

    $(document).on('ispag:content-injected', function(e, $container) {
        initAllDropzones($container);
    });

    // ------------------------------------------------------------------
    // Ouverture / fermeture de la modal d'upload
    // ------------------------------------------------------------------

    $(document).on('click', '.ispag-docu-card__add-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        openUploadModal($btn.data('entity-type'), $btn.data('entity-id'));
    });

    function openUploadModal(entityType, entityId) {
        let $modal = $('#ispag-upload-modal');

        if ($modal.length) {
            $modal.find('.ispag-dropzone').attr({
                'data-entity-type': entityType,
                'data-entity-id': entityId,
            });
            initAllDropzones($modal);
            bindModalEvents($modal);
            showModal($modal);
            return;
        }

        $.ajax({
            url: ispagAttachmentsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_get_upload_modal',
                security: ispagAttachmentsAjax.nonce,
                entity_type: entityType,
                entity_id: entityId,
            },
            success: function(response) {
                if (!response.success) {
                    console.error('[attachments] erreur création modal', response);
                    return;
                }
                $('body').append(response.data.html);
                $modal = $('#ispag-upload-modal');
                initAllDropzones($modal);
                bindModalEvents($modal);
                showModal($modal);
            },
            error: function(xhr) {
                console.error('[attachments] erreur chargement modal', xhr.status, xhr.responseText);
            },
        });
    }

    /**
     * NB: on ne garde QUE le toggle de classe .is-open — le CSS s'occupe de
     * display:flex + opacity + transform. Ajouter .fadeIn()/.css('right', ..)
     * par-dessus (comme dans une version précédente) entre en conflit :
     * .fadeIn() force display:block en style inline, ce qui écrase le
     * display:flex nécessaire au centrage, et .css('right','0') est un
     * reliquat du pattern de la sidebar coulissante (sans effet ici, cette
     * modal n'est pas positionnée en absolute/fixed).
     */
    function showModal($modal) {
        $modal.prop('hidden', false);
        requestAnimationFrame(function() {
            $modal.addClass('is-open');
        });
    }

    function hideModal($modal) {
        $modal.removeClass('is-open');
        setTimeout(function() {
            $modal.prop('hidden', true);
        }, 150);
    }

    function bindModalEvents($modal) {
        if ($modal.data('ispag-modal-bound')) {
            return;
        }
        $modal.data('ispag-modal-bound', true);

        $modal.on('click', '.ispag-modal-upload__close', function() {
            hideModal($modal);
        });

        $modal.on('click', function(e) {
            if (e.target === this) {
                hideModal($modal);
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                hideModal($modal);
            }
        });

        $modal.on('ispag:attachment-uploaded', function(e) {
            hideModal($modal);

            const $dz = $(e.target);
            const entityType = $dz.data('entity-type');
            const entityId = $dz.data('entity-id');

            $('.ispag-docu-card[data-entity-type="' + entityType + '"][data-entity-id="' + entityId + '"]')
                .trigger('ispag:refresh-attachments');
        });
    }

    // ------------------------------------------------------------------
    // Suppression d'une pièce jointe
    // ------------------------------------------------------------------

    $(document).on('click', '.ispag-docu-card__remove', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $item = $(this).closest('.ispag-docu-card__item');
        const mediaId = $(this).data('media-id');

        if (!mediaId) {
            console.error('[attachments] media-id manquant sur le bouton de suppression');
            return;
        }

        if (typeof ispagConfirm !== 'function') {
            console.error('[attachments] ispagConfirm() est introuvable — vérifie qu\'elle est bien chargée avant ce script');
            return;
        }

        ispagConfirm(ispagAttachmentsAjax.texts.confirmDeleteDoc, {
            labelOk: ispagAttachmentsAjax.texts.confirm,
            labelCancel: ispagAttachmentsAjax.texts.cancel,
            danger: true,
        }).then(function(confirmed) {
            if (!confirmed) {
                return;
            }

            $.ajax({
                url: ispagAttachmentsAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ispag_delete_attachment',
                    security: ispagAttachmentsAjax.nonce,
                    media_id: mediaId,
                },
                success: function(response) {
                    if (response.success) {
                        $item.fadeOut(200, function() {
                            $(this).remove();
                        });
                    } else {
                        console.error('[attachments] delete error', response);
                        alert((response.data && response.data.message) || 'Error while deleting.');
                    }
                },
                error: function(xhr) {
                    console.error('[attachments] delete AJAX error', xhr.status, xhr.responseText);
                    alert('Error while deleting.');
                },
            });
        });
    });

    // ------------------------------------------------------------------
    // Rafraîchissement d'une carte/liste après upload ou suppression
    // ------------------------------------------------------------------

    $(document).on('ispag:refresh-attachments', '.ispag-docu-card', function() {
        const $el = $(this);
        const entityType = $el.data('entity-type');
        const entityId = $el.data('entity-id');
        const view = $el.data('view') || 'card';

        $.ajax({
            url: ispagAttachmentsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_refresh_attachments',
                security: ispagAttachmentsAjax.nonce,
                entity_type: entityType,
                entity_id: entityId,
                view: view,
            },
            success: function(response) {
                if (!response.success) {
                    console.error('[attachments] refresh error', response);
                    return;
                }

                if (view === 'card') {
                    $el.replaceWith(response.data.html);
                } else {
                    $el.html(response.data.html);
                }
            },
            error: function(xhr) {
                console.error('[attachments] refresh AJAX error', xhr.status, xhr.responseText);
            },
        });
    });

})(jQuery);


jQuery(function ($) {

    let dragCounter = 0;
    let pendingDropFile = null;

    // Empêche le navigateur d'ouvrir le fichier si on rate la zone
    ['dragover', 'drop'].forEach(evt => {
        document.addEventListener(evt, function (e) {
            if (e.target.closest('.ispag-docu-card__body .ispag-dropzone__field')) {
                e.preventDefault();
            }
        }, false);
    });

    // --- Feedback visuel sur la carte ---
    $(document).on('dragenter', '.ispag-docu-card__body .ispag-dropzone__field', function (e) {
        e.preventDefault();
        dragCounter++;
        $(this).addClass('is-dragover');
    });

    $(document).on('dragleave', '.ispag-docu-card__body .ispag-dropzone__field', function (e) {
        e.preventDefault();
        dragCounter--;
        if (dragCounter <= 0) {
            dragCounter = 0;
            $(this).removeClass('is-dragover');
        }
    });

    $(document).on('dragover', '.ispag-docu-card__body .ispag-dropzone__field', function (e) {
        e.preventDefault();
    });

    // Ouvre la modal d'ajout de la carte et y injecte le fichier (dépôt OU choix via le sélecteur de fichiers)
    function startUploadFromCard($card, file) {
        if (!file) return;
        pendingDropFile = file;

        const entityType = $card.data('entity-type');
        const entityId = $card.data('entity-id');

        // 1. Ouvre la modal exactement comme le ferait un clic manuel sur "Ajouter"
        $card.find('.ispag-docu-card__add-btn[data-entity-type="' + entityType + '"][data-entity-id="' + entityId + '"]')
            .trigger('click');

        // 2. On attend que la modal soit dans le DOM pour y injecter le fichier
        waitForModalDropzone(entityType, entityId);
    }

    // --- Le drop sur la carte ---
    $(document).on('drop', '.ispag-docu-card__body .ispag-dropzone__field', function (e) {
        e.preventDefault();
        dragCounter = 0;
        $(this).removeClass('is-dragover');

        const files = e.originalEvent.dataTransfer.files;
        if (!files || !files.length) return;

        startUploadFromCard($(this).closest('.ispag-docu-card'), files[0]);
    });

    // --- Le clic sur la carte : même chose que le dépôt, avec le sélecteur de fichiers (Parcourir) ---
    $(document).on('click', '.ispag-docu-card__body .ispag-dropzone__field', function (e) {
        if ($(e.target).closest('a, button, input').length) return;
        const $card = $(this).closest('.ispag-docu-card');
        const input = document.createElement('input');
        input.type = 'file';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            if (input.files && input.files.length) {
                startUploadFromCard($card, input.files[0]);
            }
            input.remove();
        });
        input.click();
    });

    /**
     * Attend l'apparition de la vraie dropzone dans la modal, puis y injecte
     * le fichier déposé sur la carte, en déclenchant le 'change' attendu
     * par le JS existant de la modal.
     */
    function waitForModalDropzone(entityType, entityId, attempts = 0) {
        const $dropzone = $('.ispag-dropzone[data-entity-type="' + entityType + '"][data-entity-id="' + entityId + '"]');
        const $input = $dropzone.find('.ispag-dropzone__input');

        if (!$input.length) {
            if (attempts < 20) { // ~2s max
                setTimeout(() => waitForModalDropzone(entityType, entityId, attempts + 1), 100);
            } else {
                console.error('[Dropzone carte] Modal introuvable après attente.');
            }
            return;
        }

        if (!pendingDropFile) return;

        // Un input file ne peut recevoir un fichier que via un objet DataTransfer
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(pendingDropFile);
        $input[0].files = dataTransfer.files;

        // Déclenche le 'change' que le JS de ta modal écoute déjà
        $input[0].dispatchEvent(new Event('change', { bubbles: true }));

        pendingDropFile = null;
    }

});