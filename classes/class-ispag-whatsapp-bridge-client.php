<?php
/**
 * ISPAG_Whatsapp_Bridge_Client
 *
 * Appelle l'API du serveur bridge Node.js (whatsapp-web.js) pour envoyer des messages
 * et consulter son statut de connexion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISPAG_Whatsapp_Bridge_Client {

	/**
	 * URL de base du bridge, ex: https://barthels.duckdns.org/wa-bridge
	 * Stockée en option pour pouvoir la changer sans toucher au code.
	 */
	private function get_base_url() {
		return rtrim( get_option( 'ispag_whatsapp_bridge_url', '' ), '/' );
	}

	private function get_api_key() {
		return get_option( 'ispag_whatsapp_bridge_api_key', '' );
	}

	/**
	 * Envoie un message WhatsApp via le bridge.
	 *
	 * @param string $phone   Numéro au format international sans '+', ex: 41791234567
	 * @param string $message
	 * @return array|WP_Error
	 */
	public function send_message( $phone, $message ) {
		$base_url = $this->get_base_url();
		$api_key  = $this->get_api_key();

		if ( empty( $base_url ) || empty( $api_key ) ) {
			return new WP_Error( 'ispag_whatsapp_not_configured', __( 'The WhatsApp bridge is not configured (URL or API key missing).', 'ispag-crm' ) );
		}

		$response = wp_remote_post(
			$base_url . '/send',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'phone'   => preg_replace( '/[^0-9]/', '', $phone ),
						'message' => $message,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['success'] ) ) {
			$error_message = isset( $body['error'] ) ? $body['error'] : __( 'Unknown error from the WhatsApp bridge.', 'ispag-crm' );
			return new WP_Error( 'ispag_whatsapp_send_failed', $error_message, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * Récupère le statut de connexion du bridge (ready, qr, initializing...).
	 *
	 * @return array|WP_Error
	 */
	public function get_status() {
		$base_url = $this->get_base_url();
		$api_key  = $this->get_api_key();

		if ( empty( $base_url ) || empty( $api_key ) ) {
			return new WP_Error( 'ispag_whatsapp_not_configured', __( 'The WhatsApp bridge is not configured.', 'ispag-crm' ) );
		}

		$response = wp_remote_get(
			$base_url . '/status',
			array(
				'timeout' => 10,
				'headers' => array( 'x-api-key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
