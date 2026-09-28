<?php
/**
 * ispag-whatsapp-init.php
 *
 * Point d'entrée du module WhatsApp : enregistre les routes REST, les handlers AJAX,
 * la page de réglages, et les assets JS/CSS.
 *
 * À inclure depuis le fichier principal du plugin (ispag-crm-manager.php), par ex. :
 *   require_once ISPAG_CRM_PATH . 'includes/ispag-whatsapp-init.php';
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Routes REST (webhook entrant depuis le bridge Node.js) ---
add_action( 'rest_api_init', function () {
	$controller = new ISPAG_Whatsapp_Webhook_Controller();
	$controller->register_routes();
} );

// --- Handlers AJAX (envoi / lecture de conversation depuis l'admin) ---
add_action( 'init', function () {
	$handlers = new ISPAG_Whatsapp_Ajax_Handlers();
	$handlers->register();
} );

// --- Assets JS/CSS, uniquement chargés sur les écrans admin du CRM ---
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	// Adapte cette condition aux slugs réels de tes pages de fiche contact/entreprise.
	if ( strpos( $hook, 'ispag' ) === false ) {
		return;
	}

	wp_enqueue_style(
		'ispag-whatsapp-panel',
		plugins_url( 'css/ispag-whatsapp-panel.css', __FILE__ ),
		array(),
		'1.0.0'
	);

	wp_enqueue_script(
		'ispag-whatsapp-panel',
		plugins_url( 'js/ispag-whatsapp-panel.js', __FILE__ ),
		array( 'jquery' ),
		'1.0.0',
		true
	);
} );

// --- Table de messages : création à l'activation du plugin ---
// À appeler depuis le hook register_activation_hook() du fichier principal :
//   register_activation_hook( __FILE__, array( 'ISPAG_Whatsapp_Repository', 'maybe_create_table' ) );

// --- Page de réglages (URL bridge + clé API + secret webhook) ---
add_action( 'admin_menu', function () {
	add_submenu_page(
		'ispag-entreprises',
		__( 'WhatsApp Settings', 'ispag-crm' ),
		__( 'WhatsApp', 'ispag-crm' ),
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

function ispag_whatsapp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$status_html = '';
	if ( isset( $_GET['ispag_wa_check'] ) ) {
		$client = new ISPAG_Whatsapp_Bridge_Client();
		$status = $client->get_status();
		if ( is_wp_error( $status ) ) {
			$status_html = '<div class="notice notice-error"><p>' . esc_html( $status->get_error_message() ) . '</p></div>';
		} else {
			$status_html = '<div class="notice notice-success"><p>' . esc_html( 'Statut bridge : ' . ( $status['status'] ?? 'inconnu' ) ) . '</p></div>';
		}
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WhatsApp Settings', 'ispag-crm' ); ?></h1>
		<?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ispag_whatsapp_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="ispag_whatsapp_bridge_url"><?php esc_html_e( 'Bridge URL', 'ispag-crm' ); ?></label></th>
					<td>
						<input type="url" id="ispag_whatsapp_bridge_url" name="ispag_whatsapp_bridge_url"
							   value="<?php echo esc_attr( get_option( 'ispag_whatsapp_bridge_url', '' ) ); ?>"
							   class="regular-text" placeholder="https://wa-bridge.barthels.duckdns.org" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ispag_whatsapp_bridge_api_key"><?php esc_html_e( 'Clé API du bridge', 'ispag-crm' ); ?></label></th>
					<td>
						<input type="text" id="ispag_whatsapp_bridge_api_key" name="ispag_whatsapp_bridge_api_key"
							   value="<?php echo esc_attr( get_option( 'ispag_whatsapp_bridge_api_key', '' ) ); ?>"
							   class="regular-text" />
						<p class="description"><?php esc_html_e( 'Must match BRIDGE_API_KEY in the Node server\'s .env file.', 'ispag-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ispag_whatsapp_webhook_secret"><?php esc_html_e( 'Secret webhook', 'ispag-crm' ); ?></label></th>
					<td>
						<input type="text" id="ispag_whatsapp_webhook_secret" name="ispag_whatsapp_webhook_secret"
							   value="<?php echo esc_attr( get_option( 'ispag_whatsapp_webhook_secret', '' ) ); ?>"
							   class="regular-text" />
						<p class="description"><?php esc_html_e( 'Must match WP_WEBHOOK_SECRET in the Node server\'s .env file.', 'ispag-crm' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<p>
			<a href="<?php echo esc_url( add_query_arg( 'ispag_wa_check', '1' ) ); ?>" class="button">
				<?php esc_html_e( 'Test the connection to the bridge', 'ispag-crm' ); ?>
			</a>
		</p>
	</div>
	<?php
}
