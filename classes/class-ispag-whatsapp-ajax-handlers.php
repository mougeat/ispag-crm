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

	public function handle_send() {
		check_ajax_referer( 'ispag_whatsapp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ispag-crm' ) ), 403 );
		}

		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;

		if ( empty( $phone ) || empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Number and message required.', 'ispag-crm' ) ), 400 );
		}

		$client = new ISPAG_Whatsapp_Bridge_Client();
		$result = $client->send_message( $phone, $message );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$repository = new ISPAG_Whatsapp_Repository();
		$repository->insert_outgoing( $phone, $message, $contact_id ? $contact_id : null, isset( $result['id'] ) ? $result['id'] : null );

		wp_send_json_success( array( 'message' => __( 'Message sent.', 'ispag-crm' ) ) );
	}

	public function handle_get_conversation() {
		check_ajax_referer( 'ispag_whatsapp_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ispag-crm' ) ), 403 );
		}

		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$repository = new ISPAG_Whatsapp_Repository();

		if ( $contact_id ) {
			$messages = $repository->get_conversation_by_contact( $contact_id );
		} elseif ( $phone ) {
			$messages = $repository->get_conversation_by_phone( $phone );
		} else {
			wp_send_json_error( array( 'message' => __( 'contact_id or phone required.', 'ispag-crm' ) ), 400 );
		}

		wp_send_json_success( array( 'messages' => $messages ) );
	}
}
