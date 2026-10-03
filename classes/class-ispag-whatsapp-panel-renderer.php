<?php
/**
 * ISPAG_Whatsapp_Panel_Renderer
 *
 * Affiche le panneau de conversation WhatsApp (façon chat) dans la fiche contact.
 * Usage : (new ISPAG_Whatsapp_Panel_Renderer())->render( $contact_id, $contact_phone );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ISPAG_Whatsapp_Panel_Renderer {

	public function render( $contact_id, $contact_phone ) {
		$repository   = new ISPAG_Whatsapp_Repository();
		$messages     = $contact_phone
			? $repository->get_conversation_by_phone( $contact_phone )
			: $repository->get_conversation_by_contact( $contact_id );
		$lines        = ispag_whatsapp_get_lines();
		$nonce        = wp_create_nonce( 'ispag_whatsapp_nonce' );
		?>
		<div class="ispag-whatsapp-panel"
			 data-contact-id="<?php echo esc_attr( $contact_id ); ?>"
			 data-phone="<?php echo esc_attr( $contact_phone ); ?>"
			 data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<div class="ispag-whatsapp-header">
				<span class="dashicons dashicons-whatsapp"></span>
				<?php esc_html_e( 'WhatsApp', 'ispag-crm' ); ?>
				<span class="ispag-whatsapp-phone"><?php echo esc_html( $contact_phone ); ?></span>
			</div>

			<div class="ispag-whatsapp-messages" id="ispag-whatsapp-messages">
				<?php if ( empty( $messages ) ) : ?>
					<p class="ispag-whatsapp-empty"><?php esc_html_e( 'No messages for the moment.', 'ispag-crm' ); ?></p>
				<?php else : ?>
					<?php foreach ( $messages as $msg ) : ?>
						<?php $this->render_bubble( $msg ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="ispag-whatsapp-composer">
				<?php if ( count( $lines ) > 1 ) : ?>
					<select id="ispag-whatsapp-line" class="ispag-whatsapp-line" aria-label="<?php esc_attr_e( 'Send from', 'ispag-crm' ); ?>">
						<?php foreach ( $lines as $line_id => $label ) : ?>
							<option value="<?php echo esc_attr( $line_id ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<textarea
					id="ispag-whatsapp-input"
					placeholder="<?php esc_attr_e( 'Write a message…', 'ispag-crm' ); ?>"
					rows="2"
				></textarea>
				<button type="button" id="ispag-whatsapp-send-btn" class="button button-primary">
					<?php esc_html_e( 'Send', 'ispag-crm' ); ?>
				</button>
			</div>
			<div class="ispag-whatsapp-status" id="ispag-whatsapp-status" aria-live="polite"></div>
		</div>
		<?php
	}

	private function render_bubble( $msg ) {
		$direction_class = ( $msg->direction === 'out' ) ? 'is-outgoing' : 'is-incoming';
		?>
		<div class="ispag-whatsapp-bubble <?php echo esc_attr( $direction_class ); ?>">
			<div class="ispag-whatsapp-bubble-body"><?php echo $msg->has_media && '' === (string) $msg->body ? '📎 ' . esc_html( $msg->media_type ?: __( 'Attachment', 'ispag-crm' ) ) : esc_html( $msg->body ); ?></div>
			<div class="ispag-whatsapp-bubble-time"><?php echo esc_html( mysql2date( 'd.m.Y H:i', get_date_from_gmt( $msg->created_at ) ) ); ?><?php echo ( ! empty( $msg->line_id ) && count( ispag_whatsapp_get_lines() ) > 1 ) ? ' · ' . esc_html( ispag_whatsapp_get_lines()[ $msg->line_id ] ?? $msg->line_id ) : ''; ?></div>
		</div>
		<?php
	}
}
