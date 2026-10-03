<?php
/**
 * ispag-whatsapp-init.php
 *
 * Module WhatsApp du CRM : journalise dans le CRM les messages WhatsApp reçus ET envoyés (CRM ou téléphone) pour
 * un ou plusieurs numéros de l'entreprise, sans coût d'API.
 *
 * Fonctionnement : un petit serveur « relais » (dossier whatsapp-bridge/, Node.js) relie chaque numéro comme appareil lié
 * (QR code à scanner une fois depuis le téléphone, comme WhatsApp Web) et appelle le webhook REST de ce plugin à chaque
 * message. Cette page (Réglages > WhatsApp CRM) permet de configurer le relais et d'ajouter / retirer des numéros (« lignes »).
 *
 * Panneau de conversation : onglet « WhatsApp » de la fiche contact (voir ISPAG_Whatsapp_Panel_Renderer).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ISPAG_WA_OPT_LINES = 'ispag_whatsapp_lines';

/**
 * Lignes (numéros) connues : [ id => libellé ]. Mémorisées à chaque lecture du statut du relais, pour ne pas
 * interroger le relais à chaque affichage de fiche.
 */
function ispag_whatsapp_get_lines() {
	$lines = get_option( ISPAG_WA_OPT_LINES, array() );
	return is_array( $lines ) ? $lines : array();
}

/** Le module est-il configuré (relais + au moins une ligne) ? */
function ispag_whatsapp_is_enabled() {
	return get_option( 'ispag_whatsapp_bridge_url', '' ) !== '' && get_option( 'ispag_whatsapp_bridge_api_key', '' ) !== '' && ! empty( ispag_whatsapp_get_lines() );
}

// --- Table de messages : création / mise à jour du schéma (une fois par version) ---
add_action( 'init', array( 'ISPAG_Whatsapp_Repository', 'maybe_upgrade' ) );

// --- Routes REST (webhook entrant depuis le relais) ---
add_action( 'rest_api_init', function () {
	$controller = new ISPAG_Whatsapp_Webhook_Controller();
	$controller->register_routes();
} );

// --- Handlers AJAX (envoi / lecture de conversation) ---
add_action( 'init', function () {
	$handlers = new ISPAG_Whatsapp_Ajax_Handlers();
	$handlers->register();
} );

// --- Assets : fiche contact (côté site) ---
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() || ! ISPAG_Whatsapp_Ajax_Handlers::can_use() || ! ispag_whatsapp_is_enabled() ) {
		return;
	}
	$dir = plugin_dir_path( __FILE__ ) . 'assets/';
	$url = plugin_dir_url( __FILE__ ) . 'assets/';

	wp_enqueue_style( 'ispag-whatsapp-panel', $url . 'css/ispag-whatsapp-panel.css', array(), (string) @filemtime( $dir . 'css/ispag-whatsapp-panel.css' ) );
	wp_enqueue_script( 'ispag-whatsapp-panel', $url . 'js/ispag-whatsapp-panel.js', array( 'jquery' ), (string) @filemtime( $dir . 'js/ispag-whatsapp-panel.js' ), true );
	wp_localize_script( 'ispag-whatsapp-panel', 'ispag_whatsapp', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'i18n'     => array(
			'empty'   => __( 'No messages for the moment.', 'ispag-crm' ),
			'sending' => __( 'Sending…', 'ispag-crm' ),
			'error'   => __( 'Error', 'ispag-crm' ),
			'network' => __( 'Network error while sending.', 'ispag-crm' ),
			'failed'  => __( 'Sending failed', 'ispag-crm' ),
		),
	) );
} );

// --- Réglages : CRM ISPAG > WhatsApp ---
add_action( 'admin_menu', function () {
	// Réglages > WhatsApp CRM : accessible à tout administrateur (le menu « CRM ISPAG » dépend d'un droit CRM spécifique)
	add_options_page(
		__( 'WhatsApp Settings', 'ispag-crm' ),
		__( 'WhatsApp CRM', 'ispag-crm' ),
		'manage_options',
		'ispag-whatsapp-settings',
		'ispag_whatsapp_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'ispag_whatsapp_settings_group', 'ispag_whatsapp_bridge_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
	register_setting( 'ispag_whatsapp_settings_group', 'ispag_whatsapp_bridge_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'ispag_whatsapp_settings_group', 'ispag_whatsapp_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
} );

function ispag_whatsapp_settings_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'ispag-whatsapp-settings' ), $args ), admin_url( 'options-general.php' ) );
}

// Ajout d'une ligne : le relais démarre une session et fournit un QR code à scanner
add_action( 'admin_post_ispag_wa_add_line', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied', 'ispag-crm' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'ispag_wa_add_line' );

	$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
	$id    = sanitize_title( $label );
	$id    = substr( preg_replace( '/[^a-z0-9_-]/', '', $id ), 0, 40 );
	if ( strlen( $id ) < 2 ) {
		wp_safe_redirect( ispag_whatsapp_settings_url( array( 'wa_error' => rawurlencode( __( 'Give the line a name (2 characters minimum).', 'ispag-crm' ) ) ) ) );
		exit;
	}

	$client = new ISPAG_Whatsapp_Bridge_Client();
	$result = $client->create_line( $id, $label );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( ispag_whatsapp_settings_url( array( 'wa_error' => rawurlencode( $result->get_error_message() ) ) ) );
		exit;
	}
	$lines        = ispag_whatsapp_get_lines();
	$lines[ $id ] = $label;
	update_option( ISPAG_WA_OPT_LINES, $lines, false );

	wp_safe_redirect( ispag_whatsapp_settings_url( array( 'wa_added' => 1 ) ) );
	exit;
} );

// Retrait d'une ligne (déconnecte le numéro ; l'historique des messages est conservé dans le CRM)
add_action( 'admin_post_ispag_wa_remove_line', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied', 'ispag-crm' ), '', array( 'response' => 403 ) );
	}
	$id = isset( $_POST['line'] ) ? sanitize_key( wp_unslash( $_POST['line'] ) ) : '';
	check_admin_referer( 'ispag_wa_remove_line_' . $id );

	$client = new ISPAG_Whatsapp_Bridge_Client();
	$result = $client->delete_line( $id );
	// Ligne inconnue du relais (404) : on la retire quand même de la liste
	if ( is_wp_error( $result ) && 404 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
		wp_safe_redirect( ispag_whatsapp_settings_url( array( 'wa_error' => rawurlencode( $result->get_error_message() ) ) ) );
		exit;
	}
	$lines = ispag_whatsapp_get_lines();
	unset( $lines[ $id ] );
	update_option( ISPAG_WA_OPT_LINES, $lines, false );

	wp_safe_redirect( ispag_whatsapp_settings_url( array( 'wa_removed' => 1 ) ) );
	exit;
} );

function ispag_whatsapp_status_label( $status ) {
	$labels = array(
		'ready'         => array( __( 'Connected', 'ispag-crm' ), '#1a7f37' ),
		'qr'            => array( __( 'Waiting for QR scan', 'ispag-crm' ), '#b45309' ),
		'initializing'  => array( __( 'Starting…', 'ispag-crm' ), '#6b7280' ),
		'authenticated' => array( __( 'Authenticated…', 'ispag-crm' ), '#6b7280' ),
		'disconnected'  => array( __( 'Disconnected — remove and add the line again', 'ispag-crm' ), '#b91c1c' ),
		'auth_failure'  => array( __( 'Authentication failed — remove and add the line again', 'ispag-crm' ), '#b91c1c' ),
		'error'         => array( __( 'Error — see the bridge logs', 'ispag-crm' ), '#b91c1c' ),
	);
	return isset( $labels[ $status ] ) ? $labels[ $status ] : array( $status, '#6b7280' );
}

function ispag_whatsapp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$configured = get_option( 'ispag_whatsapp_bridge_url', '' ) !== '' && get_option( 'ispag_whatsapp_bridge_api_key', '' ) !== '';
	$status     = null;
	if ( $configured ) {
		$client = new ISPAG_Whatsapp_Bridge_Client();
		$status = $client->get_status();
		if ( ! is_wp_error( $status ) && isset( $status['lines'] ) ) {
			// Les lignes du relais font foi : on mémorise leurs libellés pour la fiche contact
			$known = array();
			foreach ( $status['lines'] as $l ) {
				$known[ sanitize_key( $l['id'] ) ] = sanitize_text_field( $l['label'] ?? $l['id'] );
			}
			if ( $known !== ispag_whatsapp_get_lines() ) {
				update_option( ISPAG_WA_OPT_LINES, $known, false );
			}
		}
	}
	$lines_status = ( $status && ! is_wp_error( $status ) && ! empty( $status['lines'] ) ) ? $status['lines'] : array();
	$pending      = false;
	foreach ( $lines_status as $l ) {
		if ( in_array( $l['status'], array( 'qr', 'initializing', 'authenticated' ), true ) ) {
			$pending = true;
		}
	}
	$webhook_url = rest_url( 'ispag/v1/whatsapp/incoming' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WhatsApp Settings', 'ispag-crm' ); ?></h1>

		<?php if ( isset( $_GET['wa_error'] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['wa_error'] ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['wa_added'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Line added. Scan the QR code below with the phone of this number: WhatsApp > Settings > Linked devices > Link a device.', 'ispag-crm' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['wa_removed'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Line removed. The message history stays in the CRM.', 'ispag-crm' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( '1. Bridge connection', 'ispag-crm' ); ?></h2>
		<p><?php esc_html_e( 'The bridge is the small free server (folder whatsapp-bridge/ of this plugin) that keeps your numbers linked to WhatsApp.', 'ispag-crm' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'ispag_whatsapp_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="ispag_whatsapp_bridge_url"><?php esc_html_e( 'Bridge URL', 'ispag-crm' ); ?></label></th>
					<td><input type="url" id="ispag_whatsapp_bridge_url" name="ispag_whatsapp_bridge_url" value="<?php echo esc_attr( get_option( 'ispag_whatsapp_bridge_url', '' ) ); ?>" class="regular-text" placeholder="https://wa-bridge.example.ch" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ispag_whatsapp_bridge_api_key"><?php esc_html_e( 'Bridge API key', 'ispag-crm' ); ?></label></th>
					<td>
						<input type="password" id="ispag_whatsapp_bridge_api_key" name="ispag_whatsapp_bridge_api_key" value="<?php echo esc_attr( get_option( 'ispag_whatsapp_bridge_api_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Must match BRIDGE_API_KEY in the bridge .env file.', 'ispag-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ispag_whatsapp_webhook_secret"><?php esc_html_e( 'Webhook secret', 'ispag-crm' ); ?></label></th>
					<td>
						<input type="password" id="ispag_whatsapp_webhook_secret" name="ispag_whatsapp_webhook_secret" value="<?php echo esc_attr( get_option( 'ispag_whatsapp_webhook_secret', '' ) ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Must match WP_WEBHOOK_SECRET in the bridge .env file.', 'ispag-crm' ); ?>
							<?php esc_html_e( 'Webhook address (WP_WEBHOOK_URL):', 'ispag-crm' ); ?> <code><?php echo esc_html( $webhook_url ); ?></code></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( '2. Numbers (lines)', 'ispag-crm' ); ?></h2>
		<?php if ( ! $configured ) : ?>
			<p><em><?php esc_html_e( 'Fill in and save the bridge connection first.', 'ispag-crm' ); ?></em></p>
		<?php elseif ( is_wp_error( $status ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: %s error */ __( 'Cannot reach the bridge: %s', 'ispag-crm' ), $status->get_error_message() ) ); ?></p></div>
		<?php else : ?>
			<?php if ( empty( $lines_status ) ) : ?>
				<p><?php esc_html_e( 'No number connected yet.', 'ispag-crm' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:820px">
					<thead><tr><th><?php esc_html_e( 'Line', 'ispag-crm' ); ?></th><th><?php esc_html_e( 'Number', 'ispag-crm' ); ?></th><th><?php esc_html_e( 'Status', 'ispag-crm' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $lines_status as $l ) : list( $label, $color ) = ispag_whatsapp_status_label( $l['status'] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $l['label'] ?? $l['id'] ); ?></strong><br><code><?php echo esc_html( $l['id'] ); ?></code></td>
							<td><?php echo ! empty( $l['phone'] ) ? esc_html( '+' . $l['phone'] ) : '—'; ?></td>
							<td>
								<span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( $label ); ?></span>
								<?php if ( ! empty( $l['qr'] ) && 0 === strpos( $l['qr'], 'data:image/png;base64,' ) ) : ?>
									<div style="margin-top:8px"><img src="<?php echo esc_attr( $l['qr'] ); ?>" alt="QR" width="220" height="220" style="border:1px solid #ddd;padding:6px;background:#fff"></div>
									<p class="description"><?php esc_html_e( 'WhatsApp on the phone > Settings > Linked devices > Link a device, then scan.', 'ispag-crm' ); ?></p>
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this number?', 'ispag-crm' ) ); ?>');">
									<input type="hidden" name="action" value="ispag_wa_remove_line">
									<input type="hidden" name="line" value="<?php echo esc_attr( $l['id'] ); ?>">
									<?php wp_nonce_field( 'ispag_wa_remove_line_' . $l['id'] ); ?>
									<button class="button"><?php esc_html_e( 'Disconnect', 'ispag-crm' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="ispag_wa_add_line">
				<?php wp_nonce_field( 'ispag_wa_add_line' ); ?>
				<label for="ispag-wa-label"><strong><?php esc_html_e( 'Add a number', 'ispag-crm' ); ?></strong></label>
				<input type="text" id="ispag-wa-label" name="label" class="regular-text" maxlength="40" placeholder="<?php esc_attr_e( 'Name, e.g. Sales, Purchasing', 'ispag-crm' ); ?>" required>
				<button class="button button-primary"><?php esc_html_e( 'Connect', 'ispag-crm' ); ?></button>
			</form>
			<?php if ( $pending ) : ?>
				<script>setTimeout(function(){ location.reload(); }, 8000);</script>
			<?php endif; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( '3. Conversations without a CRM contact', 'ispag-crm' ); ?></h2>
		<?php
		$unmatched = ( new ISPAG_Whatsapp_Repository() )->get_unmatched_threads( 20 );
		if ( empty( $unmatched ) ) :
			?>
			<p><?php esc_html_e( 'None. Every number that wrote or was written to matches a CRM contact (phone number field).', 'ispag-crm' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:820px">
				<thead><tr><th><?php esc_html_e( 'Number', 'ispag-crm' ); ?></th><th><?php esc_html_e( 'WhatsApp name', 'ispag-crm' ); ?></th><th><?php esc_html_e( 'Messages', 'ispag-crm' ); ?></th><th><?php esc_html_e( 'Last message', 'ispag-crm' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $unmatched as $t ) : ?>
					<tr>
						<td>+<?php echo esc_html( $t->phone ); ?></td>
						<td><?php echo esc_html( $t->contact_name ); ?></td>
						<td><?php echo (int) $t->total; ?></td>
						<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', get_date_from_gmt( $t->last_message_at ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Create a contact with this phone number (field Phone) and the history appears on its page.', 'ispag-crm' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
