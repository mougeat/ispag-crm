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

	/** Appel HTTP au relais ; retourne le JSON décodé ou WP_Error. */
	private function request( $method, $path, $body = null, $timeout = 15 ) {
		$base_url = $this->get_base_url();
		$api_key  = $this->get_api_key();

		if ( empty( $base_url ) || empty( $api_key ) ) {
			return new WP_Error( 'ispag_whatsapp_not_configured', __( 'The WhatsApp bridge is not configured (URL or API key missing).', 'ispag-crm' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array( 'Content-Type' => 'application/json', 'x-api-key' => $api_key ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ispag_whatsapp_bad_response', __( 'Unexpected answer from the WhatsApp bridge.', 'ispag-crm' ), array( 'status' => $code ) );
		}
		if ( $code >= 400 || ( isset( $data['success'] ) && ! $data['success'] ) ) {
			$msg = isset( $data['error'] ) ? $data['error'] : __( 'Unknown error from the WhatsApp bridge.', 'ispag-crm' );
			return new WP_Error( 'ispag_whatsapp_bridge_error', $msg, array( 'status' => $code ) );
		}
		return $data;
	}

	/**
	 * Envoie un message WhatsApp via le relais, depuis la ligne (numéro) indiquée.
	 *
	 * @param string $phone   Numéro international, ex: 41791234567
	 * @param string $message
	 * @param string $line    Identifiant de la ligne ; vide = première ligne connectée
	 * @return array|WP_Error
	 */
	public function send_message( $phone, $message, $line = '' ) {
		$body = array(
			'phone'   => preg_replace( '/[^0-9]/', '', $phone ),
			'message' => $message,
		);
		if ( $line !== '' ) {
			$body['line'] = $line;
		}
		return $this->request( 'POST', '/send', $body );
	}

	/** Statut global : ['status' => ..., 'lines' => [ [id,label,status,phone,qr], ... ]]. */
	public function get_status() {
		return $this->request( 'GET', '/status', null, 10 );
	}

	/** Crée (ou relance) une ligne : le relais affichera un QR code à scanner depuis le téléphone. */
	public function create_line( $id, $label ) {
		return $this->request( 'POST', '/lines', array( 'id' => $id, 'label' => $label ), 20 );
	}

	/** Déconnecte une ligne et efface sa session. */
	public function delete_line( $id ) {
		return $this->request( 'DELETE', '/lines/' . rawurlencode( $id ), null, 20 );
	}
}
