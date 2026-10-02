<?php
/**
 * Prépare un brouillon e-mail au format .eml (HTML + texte) à ouvrir dans le client de messagerie.
 *
 * Remplace le lien mailto: (pas de HTML, longueur limitée). L'en-tête X-Unsent: 1 fait
 * s'ouvrir le fichier en brouillon modifiable (Outlook), prêt à être relu puis envoyé.
 * Le mail embarque la référence « Ref: [D-…] [U-…] [C-…] [T-…] » et une copie cachée vers
 * l'adresse de journalisation, ce qui permet au webhook du CRM de le classer à l'envoi.
 */
if ( ! class_exists( 'ISPAG_Eml_Builder' ) ) :

class ISPAG_Eml_Builder {

    const LOG_BCC = 'log@ispag-asp.com';

    public function __construct() {
        add_action( 'wp_ajax_ispag_build_eml', array( $this, 'ajax_build_eml' ) );
    }

    public function ajax_build_eml() {
        check_ajax_referer( 'ispag_crm_nonce', 'security' );
        if ( ! current_user_can( 'manage_order' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient rights.', 'ispag-crm' ) ) );
        }

        $recipients = $this->parse_addresses( wp_unslash( $_POST['to'] ?? '' ) );
        $subject    = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
        $html       = wp_kses_post( wp_unslash( $_POST['body_html'] ?? '' ) );

        if ( trim( wp_strip_all_tags( $html ) ) === '' ) {
            wp_send_json_error( array( 'message' => __( 'Please enter some content.', 'ispag-crm' ) ) );
        }

        $ref_parts = array();
        foreach ( array( 'D' => 'deal_ref', 'U' => 'user_id', 'C' => 'company_id' ) as $tag => $key ) {
            $v = preg_replace( '/[^\w-]/', '', (string) ( $_POST[ $key ] ?? '' ) );
            if ( $v !== '' ) $ref_parts[] = "[$tag-$v]";
        }
        $task_ts = absint( $_POST['task_ts'] ?? 0 );
        if ( $task_ts ) $ref_parts[] = "[T-$task_ts]";

        $eml = $this->build( $recipients, $subject, $html, implode( ' ', $ref_parts ) );

        $filename = sanitize_file_name( ( $subject !== '' ? $subject : 'mail' ) ) . '.eml';
        wp_send_json_success( array(
            'filename'   => $filename,
            'eml'        => base64_encode( $eml ),
            'recipients' => count( $recipients ),
        ) );
    }

    /** Extrait les adresses valides et uniques d'une chaîne séparée par , ou ; */
    private function parse_addresses( $raw ) {
        $out = array();
        foreach ( preg_split( '/[;,\s]+/', (string) $raw ) as $addr ) {
            $addr = sanitize_email( trim( $addr ) );
            if ( $addr && is_email( $addr ) ) $out[ strtolower( $addr ) ] = $addr;
        }
        return array_values( $out );
    }

    private function header_text( $text ) {
        $text = trim( preg_replace( '/[\r\n]+/', ' ', (string) $text ) );
        return preg_match( '/[^\x20-\x7E]/', $text ) ? '=?UTF-8?B?' . base64_encode( $text ) . '?=' : $text;
    }

    private function html_to_text( $html ) {
        $text = preg_replace( '#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', "\n", $html );
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
        return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
    }

    private function build( array $to, $subject, $html, $ref ) {
        $user = wp_get_current_user();
        $from = $user->user_email
            ? $this->header_text( $user->display_name ) . ' <' . $user->user_email . '>'
            : '';
        $bcc  = apply_filters( 'ispag_eml_bcc', self::LOG_BCC );

        $text = $this->html_to_text( $html );
        $html_doc = '<html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">'
                  . $html;
        if ( $ref !== '' ) {
            $text    .= "\n\n\n" . 'Ref: ' . $ref;
            $html_doc .= '<div style="margin-top:24px;color:#999;font-size:11px">Ref: ' . esc_html( $ref ) . '</div>';
        }
        $html_doc .= '</body></html>';

        $boundary = 'ispag_' . wp_generate_password( 24, false );
        $h   = array();
        $h[] = 'X-Unsent: 1';
        if ( $from ) $h[] = 'From: ' . $from;
        $h[] = 'To: ' . implode( ', ', $to );
        if ( $bcc ) $h[] = 'Bcc: ' . $bcc;
        $h[] = 'Subject: ' . $this->header_text( $subject );
        $h[] = 'Date: ' . gmdate( 'r' );
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $crlf = static fn( $s ) => str_replace( array( "\r\n", "\r" ), "\n", $s );
        $part = function ( $type, $body ) use ( $boundary, $crlf ) {
            return '--' . $boundary . "\r\n"
                 . 'Content-Type: ' . $type . '; charset="UTF-8"' . "\r\n"
                 . 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n"
                 . quoted_printable_encode( str_replace( "\n", "\r\n", $crlf( $body ) ) ) . "\r\n";
        };

        return implode( "\r\n", $h ) . "\r\n\r\n"
             . $part( 'text/plain', $text )
             . $part( 'text/html', $html_doc )
             . '--' . $boundary . '--' . "\r\n";
    }
}

endif;
