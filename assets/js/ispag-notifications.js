jQuery(document).ready(function($) {
    // =========================================================================
    // 1. VARIABLES GLOBALES
    // =========================================================================
    let isFormModified = false; // Pour suivre les modifications du formulaire
    let originalFaviconUrl = '';  // Pour stocker l'URL du favicon d'origine

    // =========================================================================
    // 2. INITIALISATION DU FAVICON
    // =========================================================================

    // On cible spécifiquement le favicon principal de l'onglet (celui en 32x32)
    let $faviconLink = $("link[rel='icon'][sizes='32x32']");

    if ($faviconLink.length) {
        originalFaviconUrl = $faviconLink.attr('href');
    }

    // Mettre à jour le favicon (Point rouge si count > 0, sinon normal)
    function updateFaviconBadge(count) {
        if (!originalFaviconUrl || !$faviconLink.length) return;

        // Si 0 notification, on remet le favicon d'origine directement
        if (count <= 0) {
            $faviconLink.attr('href', originalFaviconUrl);
            return;
        }

        // S'il y a des notifications, on dessine le point rouge
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.src = originalFaviconUrl;

        img.onload = function() {
            const canvas = document.createElement('canvas');
            canvas.width = 32;
            canvas.height = 32;
            const ctx = canvas.getContext('2d');

            // 1. Dessiner le favicon original
            ctx.drawImage(img, 0, 0, 32, 32);

            // 2. Dessiner le point rouge (pastille) en haut à droite
            ctx.beginPath();
            ctx.arc(22, 10, 8, 0, 2 * Math.PI); // x: 22, y: 10, rayon: 8
            ctx.fillStyle = '#ef4444'; // Rouge
            ctx.fill();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#ffffff'; // Petite bordure blanche pour le contraste
            ctx.stroke();

            // Injecter le nouveau favicon
            $faviconLink.attr('href', canvas.toDataURL('image/png'));
        };
    }

    // =========================================================================
    // 3. INITIALISATION ONESIGNAL (NOUVELLE VERSION)
    // =========================================================================

    // Charger le SDK OneSignal dynamiquement
    const oneSignalScript = document.createElement('script');
    oneSignalScript.src = "https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js";
    oneSignalScript.defer = true;
    document.head.appendChild(oneSignalScript);

    window.OneSignalDeferred = window.OneSignalDeferred || [];
    OneSignalDeferred.push(async function(OneSignal) {
        // 1. Initialiser OneSignal
        await OneSignal.init({ 
            appId: ispag_notifications_obj.app_id, // Assurez-vous que ispag_onesignal_obj.app_id est défini dans wp_localize_script
            safari_web_id: "web.onesignal.auto.4dbe0dd2-36c1-4474-980b-740086f7dd0e",
            notifyButton: {
                enable: false, // Désactive la cloche
            },
            serviceWorkerPath: 'OneSignalSDKWorker.js',
            serviceWorkerParam: { scope: '/' },
            autoRegister: false, // Désactive l'abonnement automatique
        });

        // 2. Attendre que le Service Worker soit prêt
        await OneSignal.ServiceWorker.register();

        // 3. Lier l'utilisateur WordPress (si connecté)
        const currentUserId = ispag_notifications_obj.current_user_id; // Définissez cette variable dans wp_localize_script
        if (currentUserId !== "0") {
            console.log('🔗 Login OneSignal pour l\'utilisateur WP :', currentUserId);
            await OneSignal.login("WP_" + currentUserId);

            // 4. Demander les permissions pour les notifications
            console.log('🔔 Demande de permission pour les notifications...');
            const permission = await OneSignal.Notifications.requestPermission();
            console.log('Permission pour les notifications :', permission);

            // 5. (Optionnel) Demander la permission pour la géolocalisation
            // Décommentez si vous utilisez la géolocalisation
            // const locationPermission = await OneSignal.Location.requestPermission();
            // console.log('Permission pour la géolocalisation :', locationPermission);
        }

        // 6. Écouteurs d'événements pour les notifications
        OneSignal.Notifications.addEventListener('click', function(event) {
            console.log('Notification cliquée :', event);
            markNotificationAsRead(event.notification.id);
        });

        OneSignal.Notifications.addEventListener('display', function(event) {
            console.log('Notification affichée :', event);
            markNotificationAsRead(event.notification.id);
        });

        // Fonction pour marquer une notification comme lue
        function markNotificationAsRead(onesignalNotificationId) {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ispag_mark_notification_as_read',
                    onesignal_notification_id: onesignalNotificationId,
                    _ajax_nonce: ispag_ajax_obj.nonce
                },
                success: function(response) {
                    console.log('Notification marquée comme lue :', response);
                }
            });
        }
    });

    // =========================================================================
    // 4. GESTION DES FENÊTRES CONCEPTUELLES (MODALE DYNAMIQUE)
    // =========================================================================

    /**
     * Vérifie et affiche les notifications de type 'conceptual_window' non lues
     */
    function checkConceptualNotifications() {
        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_get_conceptual_notifications',
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    showConceptualModal(response.data[0]);
                }
            },
            error: function(xhr, status, error) {
                // console.error('[ISPAG Modal] Error lors de la vérification des notifications conceptuelles :', error);
            }
        });
    }

    /**
     * Affiche la notification conceptuelle dans le conteneur #ispag-bulk-message
     */
    function showConceptualModal(notification) {
        // console.log('[ISPAG Bulk] Tentative d\'affichage de la notification ID:', notification.id);

        const $bulkContainer = $('#ispag-bulk-message');

        if ($bulkContainer.length === 0) {
            console.warn('[ISPAG Bulk] Le conteneur #ispag-bulk-message est introuvable sur la page.');
            return;
        }

        // Vérifier si un message est déjà en cours d'affichage
        if ($bulkContainer.is(':visible') && $bulkContainer.html().trim() !== '') {
            // console.log('[ISPAG Bulk] Le conteneur est déjà occupé. Affichage ignoré pour l\'instant.');
            return;
        }

        // Injecter le contenu dans le conteneur existant
        $bulkContainer.html(`
            <div class="ispag-bulk-content">
                <div class="ispag-bulk-header">
                    <h3>${escapeHtml(notification.title || 'Information')}</h3>
                </div>
                <div class="ispag-bulk-body">
                    <p>${notification.content}</p>
                    ${notification.url ? `<a href="${notification.url}" class="button button-primary" target="_blank" style="margin-top: 10px; display: inline-block;">` + (ispag_texts?.consult || "Consulter") + `</a>` : ''}
                </div>
            </div>
        `);

        // Afficher le conteneur
        $bulkContainer.fadeIn(200);
        console.log('[ISPAG Bulk] Message affiché dans #ispag-bulk-message. Fermeture automatique dans 10 secondes...');

        // Fermeture automatique après 5 secondes
        const autoCloseTimer = setTimeout(() => {
            console.log('[ISPAG Bulk] 5 secondes écoulées. Masquage automatique de la notification ID:', notification.id);
            closeBulkMessage(notification.id, $bulkContainer);
        }, 5000);

        // Fermeture manuelle au clic sur le bouton de fermeture
        $bulkContainer.off('click.bulkClose').on('click.bulkClose', '.ispag-bulk-dismiss', function() {
            clearTimeout(autoCloseTimer);
            closeBulkMessage(notification.id, $bulkContainer);
        });
    }

    /**
     * Masque le message, le supprime du DOM, et le marque comme lu
     */
    function closeBulkMessage(id, $container) {
        console.log('[ISPAG Bulk] Fermeture et marquage comme lu pour la notification ID:', id);
        markConceptualAsRead(id);

        $container.fadeOut(300, function() {
            $(this).html(''); // Vider le contenu du div une fois masqué
            console.log('[ISPAG Bulk] Conteneur vidé et masqué. Vérification des suivantes dans 400ms...');
            setTimeout(checkConceptualNotifications, 400);
        });
    }

    /**
     * Marque une notification conceptuelle comme lue via AJAX
     */
    function markConceptualAsRead(id) {
        // console.log('[ISPAG Modal] Envoi de la requête AJAX pour marquer comme lue la notification ID:', id);
        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_mark_conceptual_read',
                _ajax_nonce: ispag_notifications_obj.nonce,
                id: id
            },
            success: function(response) {
                // console.log('[ISPAG Modal] Notification marquée comme lue avec succès sur le serveur.', response);
                updateNotificationBadge();
            },
            error: function(xhr, status, error) {
                // console.error('[ISPAG Modal] Error lors du marquage de la notification comme lue :', error);
            }
        });
    }

    /**
     * Utilitaire d'échappement HTML pour sécuriser l'affichage du titre
     */
    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // =========================================================================
    // 5. GESTION DE LA SIDEBAR ET DES NOTIFICATIONS STANDARD
    // =========================================================================

    // Ouvrir la sidebar au clic sur la cloche
    $('#ispag-notification-bell').on('click', function(e) {
        e.preventDefault();
        $('#ispag-notification-sidebar').addClass('active');
        $('#ispag-notification-overlay').addClass('active');
        loadUnreadNotifications();
    });

    // Fermer la sidebar au clic sur la croix ou l'overlay
    $('#ispag-close-notification-sidebar, #ispag-notification-overlay').on('click', function() {
        $('#ispag-notification-sidebar').removeClass('active');
        $('#ispag-notification-overlay').removeClass('active');
    });

    // Gérer les onglets de la sidebar
    $(document).on('click', '.notification-tab', function(e) {
        e.preventDefault();
        $('.notification-tab').removeClass('active');
        $(this).addClass('active');
        const tab = $(this).data('tab');
        loadNotifications(tab);
    });

    // Charger les notifications en fonction de l'onglet
    function loadNotifications(tab = 'unread') {
        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_get_notifications_by_tab',
                tab: tab,
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#ispag-notification-list').html(response.data.html);
                    if (tab === 'unread') {
                        const count = response.data.count || 0;
                        $('#unread-count').text(count);
                    }
                } else {
                    $('#ispag-notification-list').html(
                        '<p style="text-align: center; color: #94a3b8;">' +
                        response.data.message +
                        '</p>'
                    );
                }
            },
            error: function() {
                $('#ispag-notification-list').html(
                    '<p style="text-align: center; color: #ef4444;">Loading error.</p>'
                );
            }
        });
    }

    // Gérer le clic sur le bouton "Ouvrir" pour marquer la notification comme lue ET ouvrir le lien
    $(document).on('click', '.notification-open-button', function(e) {
        e.preventDefault(); // Empêche le comportement par défaut du lien
        e.stopPropagation(); // Empêche le clic de remonter au parent

        const $button = $(this);
        const $notificationItem = $button.closest('.notification-item');
        const notificationId = $notificationItem.data('notification-id');
        const onesignalId = $notificationItem.data('onesignal-id');
        const url = $button.attr('href');

        // Ajouter un spinner au bouton "Ouvrir"
        $button.html('<span class="dashicons dashicons-update spin"></span> ' + $button.text());

        // Marquer la notification comme lue avant d'ouvrir le lien
        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_mark_notification_as_read',
                notification_id: notificationId,
                onesignal_id: onesignalId,
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function() {
                // Ouvrir le lien dans un nouvel onglet après avoir marqué comme lue
                window.open(url, '_blank');
                // Mettre à jour l'interface
                loadUnreadNotifications();
                updateNotificationBadge();
            },
            error: function() {
                // En cas d'erreur, ouvrir le lien quand même
                window.open(url, '_blank');
                alert('Error marking notification as read. The page will open anyway.');
            }
        });
    });

    // Gérer le clic sur le bouton "Marquer comme lue"
    $(document).on('click', '.notification-mark-as-read', function(e) {
        e.preventDefault(); // Empêche le comportement par défaut du lien
        e.stopPropagation();

        const $button = $(this);
        const notificationId = $button.data('notification-id');
        const onesignalId = $button.data('onesignal-id');

        // Désactiver le bouton et ajouter un spinner Dashicons
        $button.prop('disabled', true);
        $button.html('<span class="dashicons dashicons-update spin"></span> ' + $button.text());

        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_mark_notification_as_read',
                notification_id: notificationId,
                onesignal_id: onesignalId,
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function() {
                loadUnreadNotifications();
                updateNotificationBadge();
            },
            error: function() {
                $button.prop('disabled', false);
                $button.html('Marquer comme lue');
                alert('An error occurred. Please try again.');
            }
        });
    });

    // Gérer le clic sur le bouton "Supprimer" (Corbeille - is_deleted = 1)
    $(document).on('click', '.notification-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $button = $(this);
        const notificationId = $button.data('notification-id');
        const $item = $button.closest('.notification-item');

        // Désactiver et remplacer l'icône par un spinner
        $button.prop('disabled', true);
        const $icon = $button.find('.dashicons');
        $icon.removeClass('dashicons-trash').addClass('dashicons-update spin');

        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_delete_notification',
                notification_id: notificationId,
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function() {
                // Animation fluide de suppression de la liste
                $item.fadeOut(300, function() {
                    $(this).remove();
                    updateNotificationBadge();
                });
            },
            error: function() {
                $button.prop('disabled', false);
                $icon.removeClass('dashicons-update spin').addClass('dashicons-trash');
                alert('An error occurred. Please try again.');
            }
        });
    });

    // =========================================================================
    // 6. GESTION DE LA MODALE DE CONFIGURATION
    // =========================================================================

    $(document).on('click', '#ispag-notification-settings', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#ispag-notification-settings-modal').addClass('active').show();
        loadNotificationSettingsForm();
    });

    $(document).on('click', '#ispag-close-settings-modal, #ispag-notification-settings-modal', function(e) {
        if (e.target.id === 'ispag-close-settings-modal' || e.target.id === 'ispag-notification-settings-modal') {
            if (isFormModified) {
                if (typeof ispagConfirm === 'function') {
                    ispagConfirm(
                        "You have unsaved changes. Do you really want to leave without saving?",
                        {
                            labelOk: "Oui, quitter",
                            labelCancel: "Non, rester",
                            danger: true
                        }
                    ).then((confirmed) => {
                        if (confirmed) {
                            $('#ispag-notification-settings-modal').removeClass('active').hide();
                            isFormModified = false;
                        }
                    });
                } else {
                    // Fallback si ispagConfirm n'est pas défini
                    if (confirm("You have unsaved changes. Do you really want to leave without saving?")) {
                        $('#ispag-notification-settings-modal').removeClass('active').hide();
                        isFormModified = false;
                    }
                }
            } else {
                $('#ispag-notification-settings-modal').removeClass('active').hide();
            }
        }
    });

    function loadNotificationSettingsForm() {
        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_get_notification_settings_form',
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#ispag-notification-settings-form').html(response.data.html);
                    isFormModified = false;
                    setupFormChangeListener();
                } else {
                    $('#ispag-notification-settings-form').html(
                        '<p style="text-align: center; color: #ef4444;">' +
                        response.data.message +
                        '</p>'
                    );
                }
            },
            error: function() {
                $('#ispag-notification-settings-form').html(
                    '<p style="text-align: center; color: #ef4444;">Loading error.</p>'
                );
            }
        });
    }

    function setupFormChangeListener() {
        $('#ispag-notification-settings-form input[type="checkbox"]').on('change', function() {
            isFormModified = true;
        });
    }

    $(document).on('click', '#ispag-save-notification-settings', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalButtonText = $button.text();

        $button.prop('disabled', true)
               .html('<span class="ispag-spinner"></span> ' + (ispag_texts?.saving || "Enregistrement..."));

        const formData = $('#ispag-notification-settings-form form').serialize();

        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: formData + '&action=ispag_save_notification_settings&_ajax_nonce=' + ispag_notifications_obj.nonce,
            success: function(response) {
                if (response.success) {
                    isFormModified = false;
                    $('#ispag-notification-settings-modal').removeClass('active').hide();
                    showNotificationMessage(
                        ispag_texts?.settings_saved || "Preferences saved successfully!",
                        'success'
                    );
                } else {
                    showNotificationMessage(
                        (ispag_texts?.error_prefix || "Error: ") + response.data.message,
                        'error'
                    );
                }
            },
            error: function() {
                showNotificationMessage(
                    ispag_texts?.connection_error || "Connection error. Please try again.",
                    'error'
                );
            },
            complete: function() {
                $button.prop('disabled', false).text(originalButtonText);
            }
        });
    });

    // =========================================================================
    // 7. FONCTIONS UTILITAIRES DE MESSAGERIE GLOBALE
    // =========================================================================

    function showNotificationMessage(message, type = 'success') {
        const $messageContainer = $('<div class="ispag-notification-message ispag-notification-message-' + type + '"></div>')
            .text(message)
            .hide()
            .appendTo('body');

        $messageContainer.css({
            position: 'fixed',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            'z-index': 1000001,
            padding: '15px 25px',
            'border-radius': '4px',
            'box-shadow': '0 4px 12px rgba(0, 0, 0, 0.15)',
            'max-width': '400px',
            'text-align': 'center'
        });

        if (type === 'success') {
            $messageContainer.css('background-color', '#dcfce7').css('color', '#166534');
        } else if (type === 'error') {
            $messageContainer.css('background-color', '#fef2f2').css('color', '#dc2626');
        }

        $messageContainer.fadeIn(300).delay(2000).fadeOut(300, function() {
            $(this).remove();
        });
    }

    function loadUnreadNotifications() {
        loadNotifications('unread');
    }

    // =========================================================================
    // 8. MISE À JOUR DU BADGE (CLOCHE + FAVICON)
    // =========================================================================

    function updateNotificationBadge() {
        $.ajax({
            url: ispag_notifications_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_get_unread_notification_count',
                _ajax_nonce: ispag_notifications_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    const count = response.data.count;
                    const badge = $('#ispag-notification-badge');

                    // Mettre à jour le badge de la cloche
                    if (count > 0) {
                        badge.text(count).show();
                    } else {
                        badge.hide();
                    }

                    // Mettre à jour le compteur dans l'onglet "Non lues"
                    $('#unread-count').text(count);

                    // Mettre à jour le favicon (Point rouge ou normal)
                    updateFaviconBadge(count);
                }
            }
        });
    }

    // =========================================================================
    // 9. INITIALISATION
    // =========================================================================

    // Mettre à jour le badge et vérifier les fenêtres conceptuelles toutes les 30 secondes
    setInterval(function() {
        updateNotificationBadge();
        checkConceptualNotifications();
    }, 30000);

    // Appeler une première fois au chargement de la page
    updateNotificationBadge();
    checkConceptualNotifications();
});