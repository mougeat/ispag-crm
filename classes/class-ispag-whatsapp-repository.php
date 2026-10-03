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

	/** Nom réel de la table (préfixe du site). */
	const TABLE_NAME = 'whatsapp_messages'; // utiliser self::table()
	const DB_VERSION = '2';
	const DB_OPTION  = 'ispag_whatsapp_db_version';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/** Crée / met à jour la table une seule fois par version de schéma. */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::maybe_create_table();
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Crée la table si elle n'existe pas (à appeler depuis le hook d'activation du plugin).
	 */
	public static function maybe_create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wa_message_id VARCHAR(191) NOT NULL,
			direction VARCHAR(10) NOT NULL,
			line_id VARCHAR(40) NULL,
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
			KEY contact_id (contact_id),
			KEY line_id (line_id)
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

		$phone      = preg_replace( '/[^0-9]/', '', (string) $data['phone'] );
		$contact_id = $this->find_contact_id_by_phone( $phone );
		// Message reçu ('in') ou envoyé depuis le téléphone ('out') : le relais transmet les deux
		$direction  = ( isset( $data['direction'] ) && $data['direction'] === 'out' ) ? 'out' : 'in';
		$line_id    = isset( $data['line'] ) ? sanitize_key( $data['line'] ) : null;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table() . "
					(wa_message_id, direction, line_id, phone, contact_id, contact_name, body, has_media, media_type, status, created_at)
				VALUES (%s, %s, %s, %s, %d, %s, %s, %d, %s, %s, %s)",
				sanitize_text_field( $data['wa_message_id'] ),
				$direction,
				$line_id,
				$phone,
				$contact_id ? $contact_id : null,
				isset( $data['contact_name'] ) ? sanitize_text_field( $data['contact_name'] ) : null,
				isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
				! empty( $data['has_media'] ) ? 1 : 0,
				isset( $data['media_type'] ) ? sanitize_text_field( $data['media_type'] ) : null,
				( $direction === 'out' ? 'sent' : 'received' ),
				isset( $data['created_at'] ) ? gmdate( 'Y-m-d H:i:s', intval( $data['created_at'] ) / 1000 ) : current_time( 'mysql', true )
			)
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Enregistre un message sortant envoyé depuis le CRM.
	 */
	public function insert_outgoing( $phone, $body, $contact_id = null, $wa_message_id = null, $line_id = null ) {
		global $wpdb;

		if ( ! $contact_id ) {
			$contact_id = $this->find_contact_id_by_phone( $phone );
		}

		$wpdb->insert(
			self::table(),
			array(
				'wa_message_id' => $wa_message_id ? $wa_message_id : ( 'out_' . wp_generate_uuid4() ),
				'direction'     => 'out',
				'line_id'       => $line_id ? sanitize_key( $line_id ) : null,
				'phone'         => preg_replace( '/[^0-9]/', '', (string) $phone ),
				'contact_id'    => $contact_id ? $contact_id : null,
				'body'          => wp_kses_post( $body ),
				'status'        => 'sent',
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/** Conversations dont le numéro ne correspond à aucun contact du CRM (à rattacher / créer). */
	public function get_unmatched_threads( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT phone, MAX(contact_name) AS contact_name, MAX(created_at) AS last_message_at, COUNT(*) AS total
				 FROM " . self::table() . "
				 WHERE contact_id IS NULL OR contact_id = 0
				 GROUP BY phone
				 ORDER BY last_message_at DESC
				 LIMIT %d",
				intval( $limit )
			)
		);
	}

	/**
	 * Récupère la conversation liée à un contact (par ID contact CRM).
	 */
	public function get_conversation_by_contact( $contact_id, $limit = 100 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . "
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
				"SELECT * FROM " . self::table() . "
				 WHERE phone LIKE %s
				 ORDER BY created_at ASC
				 LIMIT %d",
				'%' . $wpdb->esc_like( substr( preg_replace( '/[^0-9]/', '', (string) $phone ), -9 ) ),
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
				 FROM " . self::table() . "
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
	/** Contact CRM (utilisateur WordPress) dont le téléphone se termine par les 9 derniers chiffres du numéro. */
	public function find_contact_id_by_phone( $phone ) {
		global $wpdb;

		$normalized = preg_replace( '/[^0-9]/', '', (string) $phone );
		if ( strlen( $normalized ) < 7 ) {
			return null;
		}
		$tail = substr( $normalized, -9 );

		$contact_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = %s
				   AND REPLACE(REPLACE(REPLACE(REPLACE(meta_value, ' ', ''), '+', ''), '-', ''), '.', '') LIKE %s
				 LIMIT 1",
				ISPAG_Crm_Contact_Constants::META_LEAD_PHONE,
				'%' . $wpdb->esc_like( $tail )
			)
		);

		return $contact_id ? intval( $contact_id ) : null;
	}
}
