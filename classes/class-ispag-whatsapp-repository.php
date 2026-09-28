<?php
/**
 * ISPAG_Whatsapp_Repository
 *
 * Gère l'accès à la table wor9711_whatsapp_messages.
 * Attention : wor9711_ est déjà le préfixe complet, ne PAS utiliser $wpdb->prefix devant.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISPAG_Whatsapp_Repository {

	const TABLE_NAME = 'wor9711_whatsapp_messages';

	/**
	 * Crée la table si elle n'existe pas (à appeler depuis le hook d'activation du plugin).
	 */
	public static function maybe_create_table() {
		global $wpdb;

		$table_name      = self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wa_message_id VARCHAR(191) NOT NULL,
			direction VARCHAR(10) NOT NULL,
			phone VARCHAR(32) NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			contact_name VARCHAR(191) NULL,
			body LONGTEXT NULL,
			has_media TINYINT(1) NOT NULL DEFAULT 0,
			media_type VARCHAR(32) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wa_message_id (wa_message_id),
			KEY phone (phone),
			KEY contact_id (contact_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insère un message entrant (idempotent grâce à la clé unique wa_message_id).
	 *
	 * @param array $data
	 * @return int|false Insert ID ou false si déjà existant / échec.
	 */
	public function insert_incoming( array $data ) {
		global $wpdb;

		$phone      = sanitize_text_field( $data['phone'] );
		$contact_id = $this->find_contact_id_by_phone( $phone );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::TABLE_NAME . "
					(wa_message_id, direction, phone, contact_id, contact_name, body, has_media, media_type, status, created_at)
				VALUES (%s, %s, %s, %d, %s, %s, %d, %s, %s, %s)",
				sanitize_text_field( $data['wa_message_id'] ),
				'in',
				$phone,
				$contact_id ? $contact_id : null,
				isset( $data['contact_name'] ) ? sanitize_text_field( $data['contact_name'] ) : null,
				isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
				! empty( $data['has_media'] ) ? 1 : 0,
				isset( $data['media_type'] ) ? sanitize_text_field( $data['media_type'] ) : null,
				'received',
				isset( $data['created_at'] ) ? gmdate( 'Y-m-d H:i:s', intval( $data['created_at'] ) / 1000 ) : current_time( 'mysql', true )
			)
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Enregistre un message sortant envoyé depuis le CRM.
	 */
	public function insert_outgoing( $phone, $body, $contact_id = null, $wa_message_id = null ) {
		global $wpdb;

		if ( ! $contact_id ) {
			$contact_id = $this->find_contact_id_by_phone( $phone );
		}

		$wpdb->insert(
			self::TABLE_NAME,
			array(
				'wa_message_id' => $wa_message_id ? $wa_message_id : ( 'out_' . wp_generate_uuid4() ),
				'direction'     => 'out',
				'phone'         => sanitize_text_field( $phone ),
				'contact_id'    => $contact_id ? $contact_id : null,
				'body'          => wp_kses_post( $body ),
				'status'        => 'sent',
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Récupère la conversation liée à un contact (par ID contact CRM).
	 */
	public function get_conversation_by_contact( $contact_id, $limit = 100 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::TABLE_NAME . "
				 WHERE contact_id = %d
				 ORDER BY created_at ASC
				 LIMIT %d",
				intval( $contact_id ),
				intval( $limit )
			)
		);
	}

	/**
	 * Récupère la conversation par numéro de téléphone brut (fallback si pas de contact lié).
	 */
	public function get_conversation_by_phone( $phone, $limit = 100 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::TABLE_NAME . "
				 WHERE phone = %s
				 ORDER BY created_at ASC
				 LIMIT %d",
				sanitize_text_field( $phone ),
				intval( $limit )
			)
		);
	}

	/**
	 * Retourne les conversations groupées par contact/numéro, triées par dernier message.
	 * Utile pour une éventuelle vue "boîte de réception" globale.
	 */
	public function get_recent_threads( $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT phone, contact_id, contact_name,
						MAX(created_at) AS last_message_at,
						SUBSTRING_INDEX(GROUP_CONCAT(body ORDER BY created_at DESC), ',', 1) AS last_body
				 FROM " . self::TABLE_NAME . "
				 GROUP BY phone
				 ORDER BY last_message_at DESC
				 LIMIT %d",
				intval( $limit )
			)
		);
	}

	/**
	 * Tente de retrouver un contact CRM à partir d'un numéro de téléphone.
	 * Adapte le nom de table/colonne à ta table de contacts existante.
	 */
	private function find_contact_id_by_phone( $phone ) {
		global $wpdb;

		$normalized = preg_replace( '/[^0-9]/', '', $phone );
		if ( empty( $normalized ) ) {
			return null;
		}

		// NOTE: adapte 'wor9711_contacts' et la colonne 'telephone' au schéma réel de tes contacts.
		$contact_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM wor9711_contacts
				 WHERE REPLACE(REPLACE(REPLACE(telephone, ' ', ''), '+', ''), '-', '') LIKE %s
				 LIMIT 1",
				'%' . $wpdb->esc_like( substr( $normalized, -9 ) ) . '%'
			)
		);

		return $contact_id ? intval( $contact_id ) : null;
	}
}
