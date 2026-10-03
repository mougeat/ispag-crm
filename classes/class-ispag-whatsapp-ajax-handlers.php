<?php
/**
 * ISPAG_Whatsapp_Ajax_Handlers
 *
 * Handlers admin-ajax pour la messagerie WhatsApp intégrée à la fiche contact.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISPAG_Whatsapp_Ajax_Handlers {

	public function register() {
		add_action( 'wp_ajax_ispag_whatsapp_send', array( $this, 'handle_send' ) );
		add_action( 'wp_ajax_ispag_whatsapp_get_conversation', array( $this, 'handle_get_conversation' ) );
	}

	/** Les conversations contiennent des échanges clients : réservées à ceux qui voient les contacts du CRM. */
	public static function can_use() {
		return current_user_can( 'manage_options' ) || current_user_can( 'view_contact' );
	}

	public function handle_send() {
		check_ajax_referer( 'ispag_whatsapp_nonce', 'nonce' );

		if ( ! self::can_use() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ispag-crm' ) ), 403 );
		}

		$line       = isset( $_POST['line'] ) ? sanitize_key( wp_unslash( $_POST['line'] ) ) : '';
		if ( $line !== '' && ! array_key_exists( $line, ispag_whatsapp_get_lines() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown WhatsApp line.', 'ispag-crm' ) ), 400 );
		}
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;

		if ( empty( $phone ) || empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Number and message required.', 'ispag-crm' ) ), 400 );
		}

		$client = new ISPAG_Whatsapp_Bridge_Client();
		$result = $client->send_message( $phone, $message, $line );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$repository = new ISPAG_Whatsapp_Repository();
		$repository->insert_outgoing( $phone, $message, $contact_id ? $contact_id : null, isset( $result['id'] ) ? $result['id'] : null, isset( $result['line'] ) ? $result['line'] : $line );

		wp_send_json_success( array( 'message' => __( 'Message sent.', 'ispag-crm' ) ) );
	}

	public function handle_get_conversation() {
		check_ajax_referer( 'ispag_whatsapp_nonce', 'nonce' );

		if ( ! self::can_use() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ispag-crm' ) ), 403 );
		}

		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$repository = new ISPAG_Whatsapp_Repository();

		// Par numéro d'abord : couvre aussi les messages arrivés avant que le contact existe dans le CRM
		if ( $phone ) {
			$messages = $repository->get_conversation_by_phone( $phone );
		} elseif ( $contact_id ) {
			$messages = $repository->get_conversation_by_contact( $contact_id );
		} else {
			wp_send_json_error( array( 'message' => __( 'contact_id or phone required.', 'ispag-crm' ) ), 400 );
		}
		foreach ( $messages as $m ) {
			$m->time_label = mysql2date( 'd.m.Y H:i', get_date_from_gmt( $m->created_at ) );
		}

		wp_send_json_success( array( 'messages' => $messages ) );
	}
}
