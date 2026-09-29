/**
 * ispag-whatsapp-panel.js
 * Gère l'envoi de messages et le rafraîchissement de la conversation WhatsApp
 * dans le panneau de la fiche contact.
 */
(function ($) {
	'use strict';

	function initPanel(panel) {
		var $panel = $(panel);
		var contactId = $panel.data('contact-id');
		var phone = $panel.data('phone');
		var nonce = $panel.data('nonce');
		var $messages = $panel.find('#ispag-whatsapp-messages');
		var $input = $panel.find('#ispag-whatsapp-input');
		var $sendBtn = $panel.find('#ispag-whatsapp-send-btn');
		var $status = $panel.find('#ispag-whatsapp-status');

		function scrollToBottom() {
			$messages.scrollTop($messages[0].scrollHeight);
		}

		function renderMessages(messages) {
			$messages.empty();
			if (!messages || messages.length === 0) {
				$messages.append('<p class="ispag-whatsapp-empty">No messages for the moment.</p>');
				return;
			}
			messages.forEach(function (msg) {
				var cls = msg.direction === 'out' ? 'is-outgoing' : 'is-incoming';
				var time = msg.created_at ? msg.created_at.substring(0, 16).replace('T', ' ') : '';
				var $bubble = $('<div class="ispag-whatsapp-bubble ' + cls + '">' +
					'<div class="ispag-whatsapp-bubble-body"></div>' +
					'<div class="ispag-whatsapp-bubble-time"></div>' +
					'</div>');
				$bubble.find('.ispag-whatsapp-bubble-body').text(msg.body || '');
				$bubble.find('.ispag-whatsapp-bubble-time').text(time);
				$messages.append($bubble);
			});
			scrollToBottom();
		}

		function refreshConversation() {
			$.post(ajaxurl, {
				action: 'ispag_whatsapp_get_conversation',
				nonce: nonce,
				contact_id: contactId,
				phone: phone
			}).done(function (response) {
				if (response.success) {
					renderMessages(response.data.messages);
				}
			});
		}

		function sendMessage() {
			var text = $input.val().trim();
			if (!text) {
				return;
			}

			$sendBtn.prop('disabled', true);
			$status.text('Envoi en cours…');

			$.post(ajaxurl, {
				action: 'ispag_whatsapp_send',
				nonce: nonce,
				contact_id: contactId,
				phone: phone,
				message: text
			}).done(function (response) {
				if (response.success) {
					$input.val('');
					$status.text('');
					refreshConversation();
				} else {
					$status.text('Erreur : ' + (response.data && response.data.message ? response.data.message : 'échec de l\'envoi'));
				}
			}).fail(function () {
				$status.text('Network error lors de l\'envoi.');
			}).always(function () {
				$sendBtn.prop('disabled', false);
			});
		}

		$sendBtn.on('click', sendMessage);
		$input.on('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		scrollToBottom();

		// Rafraîchit la conversation toutes les 20 secondes (polling simple ; un webhook temps réel
		// via websockets pourrait remplacer ceci plus tard si besoin).
		setInterval(refreshConversation, 20000);
	}

	$(document).ready(function () {
		$('.ispag-whatsapp-panel').each(function () {
			initPanel(this);
		});
	});
})(jQuery);
