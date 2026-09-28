<?php
/**
 * ISPAG_Whatsapp_Webhook_Controller
 *
 * Expose l'endpoint REST wp-json/ispag/v1/whatsapp/incoming appelé par le bridge Node.js
 * à chaque message WhatsApp entrant.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISPAG_Whatsapp_Webhook_Controller {

	public function register_routes() {
		register_rest_route(
			'ispag/v1',
			'/whatsapp/incoming',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_incoming' ),
				'permission_callback' => array( $this, 'verify_webhook_secret' ),
			)
		);
	}

	/**
	 * Vérifie le header x-webhook-secret contre la valeur stockée en option.
	 * Doit correspondre EXACTEMENT à WP_WEBHOOK_SECRET dans le .env du bridge.
	 */
	public function verify_webhook_secret( WP_REST_Request $request ) {
		$expected = get_option( 'ispag_whatsapp_webhook_secret', '' );
		$received = $request->get_header( 'x-webhook-secret' );

		if ( empty( $expected ) || empty( $received ) ) {
			return false;
		}

		return hash_equals( $expected, $received );
	}

	public function handle_incoming( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['phone'] ) || empty( $params['id'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Paramètres manquants (phone, id).' ), 400 );
		}

		$repository = new ISPAG_Whatsapp_Repository();

		$inserted = $repository->insert_incoming(
			array(
				'wa_message_id' => $params['id'],
				'phone'         => $params['phone'],
				'contact_name'  => isset( $params['contact_name'] ) ? $params['contact_name'] : null,
				'body'          => isset( $params['body'] ) ? $params['body'] : '',
				'has_media'     => ! empty( $params['has_media'] ),
				'media_type'    => isset( $params['media_type'] ) ? $params['media_type'] : null,
				'created_at'    => isset( $params['created_at'] ) ? $params['created_at'] : ( time() * 1000 ),
			)
		);

		/**
		 * Hook pour brancher une notification (admin bar, e-mail interne, etc.)
		 * quand un nouveau message WhatsApp arrive.
		 */
		if ( $inserted ) {
			do_action( 'ispag_whatsapp_message_received', $inserted, $params );
		}

		return new WP_REST_Response( array( 'success' => true, 'inserted' => (bool) $inserted ), 200 );
	}
}
