<?php
/**
 * Plugin Name:       PDF Download Gate
 * Description:       Gate multiple brochures/PDFs behind lead forms. Every brochure has its own PDF, its own questions (text, dropdown, radio buttons, checkboxes, tick boxes...), its own wording and its own lead list with CSV export. Use the [pdf_gate brochure="ID"] shortcode.
 * Version:           2.4.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       pdf-download-gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PDFG_Plugin {

	const TABLE         = 'pdfg_leads';
	const PROTECTED_DIR = 'pdf-gate-protected';
	const OPT           = 'pdfg_settings';
	const BROCHURES_OPT = 'pdfg_brochures';
	const TOKEN_TTL     = 900; // 15 minutes.
	const DB_VERSION    = '1.3';
	const PER_PAGE      = 50;
	const MAX_PDF_BYTES = 3145728; // 3 MB cap for brochure PDFs.
	const TOKEN_USES    = 3;       // A download link works this many times.
	const MAIL_LOG      = 'pdfg_mail_log';
	const VER           = '2.4.0';

	public function __construct() {
		add_shortcode( 'pdf_gate', array( $this, 'shortcode' ) );

		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'maybe_serve_download' ) );
		add_action( 'wp_ajax_pdfg_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_pdfg_submit', array( $this, 'ajax_submit' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_pdfg_export', array( $this, 'export_csv' ) );
		add_action( 'admin_post_pdfg_export_xlsx', array( $this, 'export_xlsx' ) );
		add_action( 'admin_post_pdfg_bulk_leads', array( $this, 'bulk_leads' ) );
		add_action( 'admin_post_pdfg_delete_lead', array( $this, 'delete_lead' ) );
		add_action( 'admin_post_pdfg_test_mail', array( $this, 'test_mail' ) );
		add_action( 'phpmailer_init', array( $this, 'smtp_init' ) );
		add_action( 'wp_mail_failed', array( $this, 'mail_failed' ) );
		add_action( 'admin_post_pdfg_save_brochure', array( $this, 'save_brochure' ) );
		add_action( 'admin_post_pdfg_duplicate_brochure', array( $this, 'duplicate_brochure' ) );
		add_action( 'admin_post_pdfg_delete_brochure', array( $this, 'delete_brochure' ) );
	}

	/* ------------------------------------------------------------------
	 * Activation / DB
	 * ---------------------------------------------------------------- */

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			brochure_id bigint(20) unsigned NOT NULL DEFAULT 0,
			brochure_name varchar(190) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL,
			email varchar(190) NOT NULL,
			organization varchar(190) NOT NULL DEFAULT '',
			extra longtext NULL,
			file_ref varchar(255) NOT NULL,
			page_url varchar(255) NOT NULL DEFAULT '',
			ip_address varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY email (email),
			KEY brochure_id (brochure_id)
		) {$charset};" );

		update_option( 'pdfg_db_version', self::DB_VERSION );
	}

	public static function ensure_protected_dir() {
		$dir = self::protected_path();
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents(
				$dir . '/.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
	}

	public static function activate() {
		self::install_table();
		self::ensure_protected_dir();
	}

	/** Runs when the plugin is deleted. Removes data only if "delete everything" was ticked in Settings. */
	public static function uninstall() {
		$o = get_option( self::OPT, array() );
		if ( empty( $o['delete_data'] ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( array( self::OPT, self::BROCHURES_OPT, self::MAIL_LOG, 'pdfg_db_version', 'pdfg_next_brochure_id' ) as $opt ) {
			delete_option( $opt );
		}
		$dir = self::protected_path();
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $f ) {
				if ( is_file( $f ) ) {
					@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public function maybe_upgrade() {
		if ( get_option( 'pdfg_db_version' ) !== self::DB_VERSION ) {
			self::install_table();
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	private static function protected_path() {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . self::PROTECTED_DIR;
	}

	/** Set while one of our own emails is being sent, so SMTP applies to those only (not to the whole site). */
	private $smtp_active = false;
	private $mail_error  = '';

	private function settings() {
		$defaults = array(
			'notify_email' => get_option( 'admin_email' ),
			'privacy_url'  => '',
			'from_name'    => '',
			'from_email'   => '',
			'smtp_on'      => 0,
			'smtp_host'    => '',
			'smtp_port'    => 587,
			'smtp_secure'  => 'tls',
			'smtp_user'    => '',
			'smtp_pass'    => '',
			'allow_legacy' => 0,
			'store_ip'     => 0,
			'delete_data'  => 0,
		);
		$saved = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/** Visitor IP. Only REMOTE_ADDR is trusted (headers like X-Forwarded-For can be faked); proxies can use the pdfg_client_ip filter. */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'pdfg_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** The page URL comes from the visitor's browser, so only keep it if it belongs to this site. */
	private function clean_page_url( $url ) {
		$url  = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host || ! $home || strtolower( $host ) !== strtolower( $home ) ) {
			return '';
		}
		return mb_substr( $url, 0, 255 );
	}

	/* ---- Encrypting the SMTP password at rest (libsodium, key derived from the site's secret salts) ---- */

	private function secret_key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ), true );
	}

	private function encrypt_secret( $plain ) {
		if ( '' === $plain || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->secret_key() ) );
	}

	private function decrypt_secret( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'v1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->secret_key() );
		return false === $plain ? '' : $plain;
	}

	/** The SMTP password: a wp-config.php constant wins; otherwise the decrypted saved value. */
	private function smtp_password() {
		if ( defined( 'PDFG_SMTP_PASS' ) ) {
			return (string) PDFG_SMTP_PASS;
		}
		$o = $this->settings();
		return $this->decrypt_secret( (string) $o['smtp_pass'] );
	}

	/* ---- Email ---- */

	/** "a@x.com, b@y.com" -> array of valid, unique addresses (max 5). */
	private function notify_recipients( $list ) {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY ) as $e ) {
			$e = sanitize_email( $e );
			if ( $e && is_email( $e ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
		}
		return array_slice( $out, 0, 5 );
	}

	/** Brochure-specific recipients, else the default ones from Settings. */
	private function lead_recipients( $brochure_notify ) {
		$list = $this->notify_recipients( $brochure_notify );
		if ( ! $list ) {
			$o    = $this->settings();
			$list = $this->notify_recipients( $o['notify_email'] );
		}
		return $list;
	}

	/** Strip anything that could break out of an email header. */
	private function header_text( $s ) {
		return trim( preg_replace( '/[\r\n"<>,;]+/', ' ', wp_strip_all_tags( (string) $s ) ) );
	}

	public function smtp_init( $phpmailer ) {
		if ( ! $this->smtp_active ) {
			return;
		}
		$o = $this->settings();
		if ( empty( $o['smtp_on'] ) || '' === $o['smtp_host'] ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host        = $o['smtp_host'];
		$phpmailer->Port        = (int) $o['smtp_port'];
		$phpmailer->SMTPSecure  = in_array( $o['smtp_secure'], array( 'tls', 'ssl' ), true ) ? $o['smtp_secure'] : '';
		$phpmailer->SMTPAutoTLS = ( '' !== $phpmailer->SMTPSecure );
		$phpmailer->Timeout     = 8;
		$phpmailer->getSMTPInstance()->Timelimit = 8; // Never wait longer than this for any single server reply.
		if ( '' !== $o['smtp_user'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $o['smtp_user'];
			$phpmailer->Password = $this->smtp_password();
		}
	}

	public function mail_failed( $err ) {
		$this->mail_error = is_wp_error( $err ) ? $err->get_error_message() : 'Unknown mail error';
	}

	/** Send one email (via SMTP if configured) and record the outcome. Returns array( bool $ok, string $error ). */
	private function send_mail( $to, $subject, $body, $reply_name = '', $reply_email = '' ) {
		$o = $this->settings();

		$from_email = is_email( $o['from_email'] ) ? $o['from_email'] : '';
		if ( '' === $from_email && ! empty( $o['smtp_on'] ) && is_email( $o['smtp_user'] ) ) {
			$from_email = $o['smtp_user']; // Most SMTP servers insist the sender is the login account.
		}
		if ( '' === $from_email ) {
			$host       = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$from_email = 'wordpress@' . $host;
		}
		$from_name = $this->header_text( '' !== $o['from_name'] ? $o['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . ( '' !== $from_name ? $from_name . ' ' : '' ) . '<' . $from_email . '>',
		);
		if ( $reply_email && is_email( $reply_email ) ) {
			$rn        = $this->header_text( $reply_name );
			$headers[] = 'Reply-To: ' . ( '' !== $rn ? $rn . ' ' : '' ) . '<' . $reply_email . '>';
		}
		$subject = trim( preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( (string) $subject ) ) );

		$this->mail_error  = '';
		$this->smtp_active = true;
		$ok                = (bool) wp_mail( $to, $subject, $body, $headers );
		$this->smtp_active = false;

		$err = $ok ? '' : ( '' !== $this->mail_error ? $this->mail_error : 'WordPress could not send the email (no reason was given).' );
		$this->log_mail( $to, $subject, $ok, $err );
		return array( $ok, $err );
	}

	private function log_mail( $to, $subject, $ok, $err ) {
		$log = get_option( self::MAIL_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't'  => time(),
				'to' => mb_substr( implode( ', ', (array) $to ), 0, 200 ),
				's'  => mb_substr( (string) $subject, 0, 150 ),
				'ok' => $ok ? 1 : 0,
				'e'  => mb_substr( (string) $err, 0, 300 ),
			)
		);
		update_option( self::MAIL_LOG, array_slice( $log, 0, 20 ), false );
	}

	/**
	 * Give the visitor their answer immediately (with an exact length and "Connection: close", so the browser is done),
	 * THEN send the notification email. A slow or broken mail server can therefore never delay a download.
	 */
	private function respond_then_mail( $payload, $mail ) {
		$json = wp_json_encode( array( 'success' => true, 'data' => $payload ) );
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Length: ' . strlen( $json ) );
			header( 'Connection: close' );
		}
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON built by wp_json_encode().
		while ( ob_get_level() ) {
			ob_end_flush();
		}
		flush();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		if ( $mail ) {
			$this->send_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['name'], $mail['email'] );
		}
		exit;
	}

	private function sign( $data ) {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}

	/** Tags allowed inside the consent text: links only. */
	private function consent_kses() {
		return array( 'a' => array( 'href' => true, 'title' => true ) );
	}

	/** Single-line consent text: drop script/style blocks, keep only safe links. */
	private function clean_consent( $text ) {
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$html = wp_kses( $text, $this->consent_kses(), array( 'http', 'https', 'mailto' ) );
		// Keep a link only if it points to a normal web / email address; otherwise keep just its text.
		return preg_replace_callback(
			'#<a\s+[^>]*?href="([^"]*)"[^>]*>(.*?)</a>#is',
			function ( $m ) {
				return preg_match( '#^(https?://|mailto:)#i', html_entity_decode( $m[1] ) ) ? $m[0] : $m[2];
			},
			$html
		);
	}

	/** Build the consent label HTML: {privacy_policy} token + safe <a> links that open in a new tab. */
	private function consent_html( $text ) {
		$opt  = $this->settings();
		$url  = ! empty( $opt['privacy_url'] ) ? $opt['privacy_url'] : get_privacy_policy_url();
		$link = $url ? '<a href="' . esc_url( $url ) . '">privacy policy</a>' : 'privacy policy';
		$text = str_replace( '{privacy_policy}', $link, (string) $text );
		$html = $this->clean_consent( $text );
		return preg_replace( '/<a\s+/i', '<a target="_blank" rel="noopener noreferrer" ', $html );
	}

	private function default_texts() {
		return array(
			'title'       => 'Download the PDF',
			'description' => 'Please fill in the form below to get your download.',
			'button'      => 'Download PDF',
			'consent'     => 'I agree to my details being stored and used to contact me about related content.',
		);
	}

	/** Turn "id:123" or "file:name.pdf" into a readable path, or false. */
	private function resolve( $ref ) {
		$path = '';

		if ( 0 === strpos( $ref, 'id:' ) ) {
			$id = (int) substr( $ref, 3 );
			if ( $id && 'application/pdf' === get_post_mime_type( $id ) ) {
				$path = get_attached_file( $id );
			}
		} elseif ( 0 === strpos( $ref, 'file:' ) ) {
			$name = sanitize_file_name( basename( substr( $ref, 5 ) ) );
			if ( 'pdf' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				$path = self::protected_path() . '/' . $name;
			}
		}

		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		return array( 'path' => $path, 'name' => basename( $path ) );
	}

	private function protected_files() {
		$out = array();
		foreach ( (array) glob( self::protected_path() . '/*.pdf' ) as $f ) {
			$out[] = basename( $f );
		}
		sort( $out );
		return $out;
	}

	/** Upload limits: the plugin's 3 MB cap, the server's own limit, and whichever is smaller. */
	private function pdf_limits() {
		$server = (int) wp_max_upload_size();
		return array(
			'cap'       => self::MAX_PDF_BYTES,
			'server'    => $server,
			'effective' => min( self::MAX_PDF_BYTES, $server ),
		);
	}

	/** Upload a PDF into the protected folder. Returns the stored filename or WP_Error. */
	private function upload_pdf( $file_array ) {
		$lim = $this->pdf_limits();

		// PHP refused the file because it exceeds upload_max_filesize.
		if ( isset( $file_array['error'] ) && UPLOAD_ERR_INI_SIZE === (int) $file_array['error'] ) {
			return new WP_Error( 'pdfg', 'The PDF is too large. Your server allows uploads up to ' . size_format( $lim['server'] ) . ' (the plugin limit is ' . size_format( $lim['cap'] ) . '). Use a smaller file, or ask your host to raise upload_max_filesize and post_max_size.' );
		}

		// Plugin limit: brochure PDFs may be at most 3 MB.
		$size = isset( $file_array['size'] ) ? (int) $file_array['size'] : 0;
		if ( $size > $lim['cap'] ) {
			$fname = isset( $file_array['name'] ) ? sanitize_file_name( $file_array['name'] ) : 'The file';
			return new WP_Error( 'pdfg', '"' . $fname . '" is ' . size_format( $size, 1 ) . ', which is over the ' . size_format( $lim['cap'] ) . ' limit for brochure PDFs. Please compress the PDF or choose a smaller one.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		self::ensure_protected_dir();

		add_filter( 'upload_dir', array( $this, 'protected_upload_dir' ) );
		$res = wp_handle_upload(
			$file_array,
			array(
				'test_form' => false,
				'mimes'     => array( 'pdf' => 'application/pdf' ),
				'unique_filename_callback' => array( $this, 'random_pdf_name' ),
			)
		);
		remove_filter( 'upload_dir', array( $this, 'protected_upload_dir' ) );

		if ( isset( $res['error'] ) ) {
			return new WP_Error( 'pdfg', $res['error'] );
		}
		// Belt and braces: a real PDF always starts with "%PDF-".
		$fh   = fopen( $res['file'], 'rb' );
		$head = $fh ? (string) fread( $fh, 5 ) : '';
		if ( $fh ) {
			fclose( $fh );
		}
		if ( '%PDF-' !== $head ) {
			wp_delete_file( $res['file'] );
			return new WP_Error( 'pdfg', 'That file does not look like a valid PDF.' );
		}
		return basename( $res['file'] );
	}

	/** Stored PDFs get an unguessable name, so the file cannot be fetched directly even if the folder rule is not enforced (e.g. Nginx). */
	public function random_pdf_name( $dir, $name, $ext ) {
		$base = sanitize_title( pathinfo( (string) $name, PATHINFO_FILENAME ) );
		$base = ( '' !== $base ) ? mb_substr( $base, 0, 60 ) : 'brochure';
		return $base . '-' . strtolower( wp_generate_password( 14, false, false ) ) . '.pdf';
	}

	public function protected_upload_dir( $dirs ) {
		// Do NOT call wp_upload_dir()/protected_path() here: this runs inside that filter and would recurse forever.
		$dirs['path']   = trailingslashit( $dirs['basedir'] ) . self::PROTECTED_DIR;
		$dirs['url']    = trailingslashit( $dirs['baseurl'] ) . self::PROTECTED_DIR;
		$dirs['subdir'] = '';
		return $dirs;
	}

	/* --------------------------- Brochure store ----------------------- */

	private function get_brochures() {
		$b = get_option( self::BROCHURES_OPT, array() );
		return is_array( $b ) ? $b : array();
	}

	private function get_brochure( $id ) {
		$all = $this->get_brochures();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		return wp_parse_args(
			$all[ $id ],
			array(
				'name'         => '',
				'file'         => '',
				'title'        => '',
				'description'  => '',
				'button'       => '',
				'consent'      => '',
				'notify_email' => '',
				'fields'       => array(),
			)
		);
	}

	/** Clean the question list coming from the builder. */
	private function sanitize_builder_fields( $data ) {
		$types  = array( 'text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkboxes', 'checkbox' );
		$choice = array( 'select', 'radio', 'checkboxes' );
		$out    = array();
		$seen   = array();

		foreach ( array_slice( $data, 0, 30 ) as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$label = ( isset( $q['label'] ) && is_scalar( $q['label'] ) ) ? sanitize_text_field( (string) $q['label'] ) : '';
			$label = trim( mb_substr( $label, 0, 150 ) );
			if ( '' === $label ) {
				continue;
			}

			$type = ( isset( $q['type'] ) && in_array( $q['type'], $types, true ) ) ? $q['type'] : 'text';
			$opts = array();

			if ( in_array( $type, $choice, true ) ) {
				$lines = ( isset( $q['options'] ) && is_array( $q['options'] ) ) ? $q['options'] : array();
				foreach ( $lines as $line ) {
					if ( ! is_scalar( $line ) ) {
						continue;
					}
					$line = trim( sanitize_text_field( (string) $line ) );
					if ( '' !== $line && '__other__' !== $line && ! in_array( $line, $opts, true ) ) {
						$opts[] = mb_substr( $line, 0, 190 );
					}
				}
				$opts = array_slice( $opts, 0, 30 );
				if ( ! $opts ) {
					$type = 'text';
				}
			}

			$key = sanitize_title( $label );
			if ( '' === $key ) {
				$key = 'question';
			}
			$base = $key;
			$n    = 2;
			while ( isset( $seen[ $key ] ) ) {
				$key = $base . '-' . $n++;
			}
			$seen[ $key ] = true;

			$out[] = array(
				'key'         => $key,
				'label'       => $label,
				'type'        => $type,
				'required'    => ! empty( $q['required'] ),
				'options'     => $opts,
				'allow_other' => ( $opts && ! empty( $q['allow_other'] ) ),
			);
		}

		return $out;
	}

	/**
	 * Parse the legacy shortcode "fields" spec (quick inline alternative to brochures).
	 * Format: "Label[*][:type[(opt1,opt2)]]; Label2; ..."  (* = required)
	 */
	private function parse_fields( $spec ) {
		$allowed = array( 'text', 'tel', 'email', 'number', 'date', 'textarea', 'select', 'radio', 'checkboxes', 'checkbox' );
		$choice  = array( 'select', 'radio', 'checkboxes' );
		$out     = array();
		$seen    = array();

		foreach ( explode( ';', (string) $spec ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}

			$label = $chunk;
			$type  = 'text';
			$opts  = array();

			if ( preg_match( '/^(.*?)\s*:\s*(\w+)\s*(?:\((.*)\))?\s*$/s', $chunk, $m ) ) {
				$label = trim( $m[1] );
				$type  = strtolower( $m[2] );
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					$opts = array_values( array_filter( array_map( 'trim', explode( ',', $m[3] ) ), 'strlen' ) );
				}
			}

			$required = false;
			if ( '*' === substr( $label, -1 ) ) {
				$required = true;
				$label    = trim( substr( $label, 0, -1 ) );
			}
			if ( '' === $label ) {
				continue;
			}
			if ( ! in_array( $type, $allowed, true ) || ( in_array( $type, $choice, true ) && ! $opts ) ) {
				$type = 'text';
			}

			$key = sanitize_title( $label );
			if ( '' === $key ) {
				$key = 'field-' . ( count( $out ) + 1 );
			}
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$out[] = array(
				'key'         => $key,
				'label'       => $label,
				'type'        => $type,
				'required'    => $required,
				'options'     => $opts,
				'allow_other' => false,
			);
		}

		return array_slice( $out, 0, 15 );
	}

	private function render_field( $f, $uid ) {
		$id    = $uid . '-' . $f['key'];
		$nm    = 'pdfg_x[' . $f['key'] . ']';
		$req   = $f['required'] ? ' required' : '';
		$lbl   = esc_html( $f['label'] ) . ( $f['required'] ? ' *' : '' );
		$other = '<input type="text" class="pdfg-other-text" name="' . esc_attr( 'pdfg_o[' . $f['key'] . ']' ) . '" placeholder="Please specify" maxlength="190">';

		switch ( $f['type'] ) {

			case 'checkbox': // Single tick box.
				return '<div class="pdfg-field pdfg-consent"><label><input type="checkbox" name="' . esc_attr( $nm ) . '" value="1"' . $req . '> ' . $lbl . '</label></div>';

			case 'radio':
			case 'checkboxes':
				$itype = ( 'radio' === $f['type'] ) ? 'radio' : 'checkbox';
				$name  = ( 'radio' === $f['type'] ) ? $nm : $nm . '[]';
				$html  = '<div class="pdfg-field"><fieldset><legend>' . $lbl . '</legend>';
				foreach ( $f['options'] as $o ) {
					$html .= '<label class="pdfg-opt"><input type="' . $itype . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $o ) . '"> ' . esc_html( $o ) . '</label>';
				}
				if ( ! empty( $f['allow_other'] ) ) {
					$html .= '<label class="pdfg-opt"><input type="' . $itype . '" name="' . esc_attr( $name ) . '" value="__other__"> Other</label>' . $other;
				}
				return $html . '</fieldset></div>';

			case 'select':
				$html = '<div class="pdfg-field"><label for="' . esc_attr( $id ) . '">' . $lbl . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '"' . $req . '><option value="">Select&hellip;</option>';
				foreach ( $f['options'] as $o ) {
					$html .= '<option value="' . esc_attr( $o ) . '">' . esc_html( $o ) . '</option>';
				}
				if ( ! empty( $f['allow_other'] ) ) {
					$html .= '<option value="__other__">Other</option>';
				}
				return $html . '</select>' . ( ! empty( $f['allow_other'] ) ? $other : '' ) . '</div>';

			case 'textarea':
				return '<div class="pdfg-field"><label for="' . esc_attr( $id ) . '">' . $lbl . '</label><textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '" rows="4"' . $req . '></textarea></div>';

			default: // text, email, tel, number, date.
				return '<div class="pdfg-field"><label for="' . esc_attr( $id ) . '">' . $lbl . '</label><input type="' . esc_attr( $f['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nm ) . '"' . $req . '></div>';
		}
	}

	/** Validate and clean submitted answers. Returns array(label => value) or WP_Error. */
	private function collect_answers( $fields, $posted, $other ) {
		$extra = array();

		foreach ( $fields as $f ) {
			$key  = $f['key'];
			$type = $f['type'];
			$raw  = isset( $posted[ $key ] ) ? $posted[ $key ] : '';
			$oth  = ( isset( $other[ $key ] ) && is_scalar( $other[ $key ] ) ) ? mb_substr( sanitize_text_field( (string) $other[ $key ] ), 0, 190 ) : '';
			$val  = '';
			$lbl  = $f['label'];

			if ( 'checkboxes' === $type ) {
				$chosen = array();
				foreach ( array_slice( (array) $raw, 0, 100 ) as $r ) {
					if ( ! is_scalar( $r ) ) {
						continue;
					}
					$s = sanitize_text_field( (string) $r );
					if ( '__other__' === $s && ! empty( $f['allow_other'] ) ) {
						if ( '' === $oth ) {
							return new WP_Error( 'pdfg', 'Please type your "Other" answer for: ' . $lbl );
						}
						$chosen[] = 'Other: ' . $oth;
					} elseif ( in_array( $s, $f['options'], true ) ) {
						$chosen[] = $s;
					} elseif ( '' !== $s ) {
						return new WP_Error( 'pdfg', 'Invalid choice for: ' . $lbl );
					}
				}
				$val = implode( ', ', $chosen );

			} elseif ( 'radio' === $type || 'select' === $type ) {
				$s = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
				if ( '__other__' === $s && ! empty( $f['allow_other'] ) ) {
					if ( '' === $oth ) {
						return new WP_Error( 'pdfg', 'Please type your "Other" answer for: ' . $lbl );
					}
					$val = 'Other: ' . $oth;
				} elseif ( '' !== $s ) {
					if ( ! in_array( $s, $f['options'], true ) ) {
						return new WP_Error( 'pdfg', 'Invalid choice for: ' . $lbl );
					}
					$val = $s;
				}

			} elseif ( 'checkbox' === $type ) {
				$val = $raw ? 'Yes' : 'No';

			} else {
				$s   = is_scalar( $raw ) ? (string) $raw : '';
				$val = ( 'textarea' === $type ) ? sanitize_textarea_field( $s ) : sanitize_text_field( $s );
				if ( '' !== $val && 'email' === $type && ! is_email( $val ) ) {
					return new WP_Error( 'pdfg', 'Please enter a valid email for: ' . $lbl );
				}
				if ( '' !== $val && 'number' === $type && ! is_numeric( $val ) ) {
					return new WP_Error( 'pdfg', 'Please enter a number for: ' . $lbl );
				}
			}

			$missing = ( '' === $val ) || ( 'checkbox' === $type && 'No' === $val );
			if ( ! empty( $f['required'] ) && $missing ) {
				return new WP_Error( 'pdfg', 'Please complete: ' . $lbl );
			}

			$extra[ $lbl ] = mb_substr( $val, 0, 1000 );
		}

		return $extra;
	}

	private function format_extra( $json, $html = true ) {
		$data = $json ? json_decode( $json, true ) : array();
		if ( ! is_array( $data ) || ! $data ) {
			return '';
		}
		$lines = array();
		foreach ( $data as $label => $value ) {
			$lines[] = $html
				? '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value )
				: $label . ': ' . $value;
		}
		return $html ? implode( '<br>', $lines ) : implode( ' | ', $lines );
	}

	/* ------------------------------------------------------------------
	 * Front end: shortcode
	 * ---------------------------------------------------------------- */

	public function shortcode( $atts ) {
		$a = shortcode_atts(
			array(
				'brochure'     => '',
				'title'        => '',
				'description'  => '',
				'button'       => '',
				'consent'      => '',
				// Legacy / quick mode (no saved brochure).
				'id'           => '',
				'file'         => '',
				'fields'       => '',
				'organization' => 'no',
			),
			$atts,
			'pdf_gate'
		);

		$d       = $this->default_texts();
		$editor  = current_user_can( 'edit_posts' );
		$bid     = (int) $a['brochure'];
		$spec    = '';
		$ref_out = '';
		$show_org = ( 'yes' === strtolower( $a['organization'] ) );

		if ( $bid ) {
			$b = $this->get_brochure( $bid );
			if ( ! $b ) {
				return $editor ? '<p><em>PDF Gate: brochure not found.</em></p>' : '';
			}
			if ( '' === $b['file'] || ! $this->resolve( 'file:' . $b['file'] ) ) {
				return $editor ? '<p><em>PDF Gate: this brochure has no PDF file yet.</em></p>' : '';
			}
			$fields   = $b['fields'];
			$title    = '' !== $a['title'] ? $a['title'] : ( '' !== $b['title'] ? $b['title'] : $b['name'] );
			$desc     = '' !== $a['description'] ? $a['description'] : ( '' !== $b['description'] ? $b['description'] : $d['description'] );
			$btn      = '' !== $a['button'] ? $a['button'] : ( '' !== $b['button'] ? $b['button'] : $d['button'] );
			$consent  = '' !== $a['consent'] ? $a['consent'] : ( '' !== $b['consent'] ? $b['consent'] : $d['consent'] );
			$show_org = false;
		} else {
			$s_legacy = $this->settings();
			if ( empty( $s_legacy['allow_legacy'] ) ) {
				return $editor ? '<p><em>PDF Gate: use brochure="ID" (see PDF Gate &rarr; Brochures). Older file= / id= shortcodes are switched off in Settings.</em></p>' : '';
			}
			if ( $a['id'] ) {
				$ref_out = 'id:' . (int) $a['id'];
			} elseif ( $a['file'] ) {
				$ref_out = 'file:' . sanitize_file_name( $a['file'] );
			} else {
				return $editor ? '<p><em>PDF Gate: use brochure="ID" (see PDF Gate &rarr; Brochures).</em></p>' : '';
			}
			if ( ! $this->resolve( $ref_out ) ) {
				return $editor ? '<p><em>PDF Gate: PDF not found.</em></p>' : '';
			}
			$spec    = sanitize_text_field( $a['fields'] ); // Normalised so the signed value matches.
			$fields  = $this->parse_fields( $spec );
			$title   = '' !== $a['title'] ? $a['title'] : $d['title'];
			$desc    = '' !== $a['description'] ? $a['description'] : $d['description'];
			$btn     = '' !== $a['button'] ? $a['button'] : $d['button'];
			$consent = '' !== $a['consent'] ? $a['consent'] : $d['consent'];
		}

		$this->enqueue_assets();
		$uid = wp_unique_id( 'pdfg-' );

		ob_start();
		?>
		<div class="pdfg-wrap">
			<form class="pdfg-form" method="post" novalidate>
				<?php if ( $title ) : ?><h3 class="pdfg-title"><?php echo esc_html( $title ); ?></h3><?php endif; ?>
				<?php if ( $desc ) : ?><p class="pdfg-desc"><?php echo esc_html( $desc ); ?></p><?php endif; ?>

				<div class="pdfg-field">
					<label for="<?php echo esc_attr( $uid ); ?>-name">Full name *</label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="pdfg_name" required autocomplete="name">
				</div>
				<div class="pdfg-field">
					<label for="<?php echo esc_attr( $uid ); ?>-email">Email address *</label>
					<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="pdfg_email" required autocomplete="email">
				</div>
				<?php if ( $show_org ) : ?>
				<div class="pdfg-field">
					<label for="<?php echo esc_attr( $uid ); ?>-org">Organization</label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-org" name="pdfg_org" autocomplete="organization">
				</div>
				<?php endif; ?>

				<?php
				foreach ( $fields as $f ) {
					echo $this->render_field( $f, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside render_field().
				}
				?>

				<div class="pdfg-field pdfg-consent">
					<label>
						<input type="checkbox" name="pdfg_consent" value="1" required>
						<?php echo $this->consent_html( $consent ); // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised with wp_kses in consent_html(). ?>
					</label>
				</div>

				<!-- Honeypot: real visitors never see or fill this. -->
				<p class="pdfg-hp" aria-hidden="true">
					<label>Leave this field empty <input type="text" name="pdfg_website" tabindex="-1" autocomplete="off"></label>
				</p>

				<input type="hidden" name="pdfg_ref" value="<?php echo esc_attr( $ref_out ); ?>">
				<input type="hidden" name="pdfg_fields" value="<?php echo esc_attr( $spec ); ?>">
				<input type="hidden" name="pdfg_brochure" value="<?php echo (int) $bid; ?>">
				<input type="hidden" name="pdfg_sig" value="<?php echo esc_attr( $this->sign( $ref_out . "\n" . $spec . "\n" . $bid ) ); ?>">

				<p><button type="submit" class="pdfg-btn"><?php echo esc_html( $btn ); ?></button></p>
				<div class="pdfg-msg" role="status" aria-live="polite"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private function enqueue_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_register_style( 'pdfg', false, array(), self::VER );
		wp_enqueue_style( 'pdfg' );
		wp_add_inline_style( 'pdfg', '
			.pdfg-wrap{max-width:480px;margin:1.5em 0}
			.pdfg-form{padding:1.5em;border:1px solid #d9d9d9;border-radius:8px;background:#fafafa}
			.pdfg-field{margin:0 0 1em}
			.pdfg-form label,.pdfg-form legend{display:block;font-weight:600;margin-bottom:.3em;padding:0}
			.pdfg-form fieldset{border:0;padding:0;margin:0}
			.pdfg-form .pdfg-opt{font-weight:400;margin-bottom:.25em}
			.pdfg-form .pdfg-opt input,.pdfg-consent input{margin-right:.4em}
			.pdfg-form .pdfg-consent label{font-weight:400}
			.pdfg-form input[type=text],.pdfg-form input[type=email],.pdfg-form input[type=tel],.pdfg-form input[type=number],.pdfg-form input[type=date],.pdfg-form textarea,.pdfg-form select{width:100%;padding:.6em;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}
			.pdfg-form .pdfg-other-text{display:none;margin-top:.4em}
			.pdfg-hp{position:absolute!important;left:-9999px!important;height:0;overflow:hidden}
			.pdfg-btn{padding:.7em 1.4em;border:0;border-radius:4px;background:#0b5cab;color:#fff;font-weight:600;cursor:pointer}
			.pdfg-btn:disabled{opacity:.6;cursor:wait}
			.pdfg-msg{margin-top:.6em}
			.pdfg-msg.pdfg-ok{color:#1a7f37}
			.pdfg-msg.pdfg-err{color:#b32d2e}
		' );

		wp_register_script( 'pdfg', '', array(), self::VER, true );
		wp_enqueue_script( 'pdfg' );
		wp_add_inline_script( 'pdfg', 'window.PDFG=' . wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ) ) ) . ';', 'before' );
		wp_add_inline_script( 'pdfg', <<<'JS'
var pdfgLoaded = Date.now();
document.addEventListener('change', function (e) {
	var box = e.target.closest ? e.target.closest('.pdfg-field') : null;
	if (!box) return;
	var other = box.querySelector('.pdfg-other-text');
	if (!other) return;
	var on = false;
	box.querySelectorAll('input[value="__other__"]').forEach(function (i) { if (i.checked) on = true; });
	var sel = box.querySelector('select');
	if (sel && sel.value === '__other__') on = true;
	other.style.display = on ? 'block' : 'none';
});
document.addEventListener('submit', function (e) {
	var f = e.target;
	if (!f.classList || !f.classList.contains('pdfg-form')) return;
	e.preventDefault();
	var msg = f.querySelector('.pdfg-msg'), btn = f.querySelector('button[type=submit]');
	msg.className = 'pdfg-msg';
	msg.textContent = '';
	btn.disabled = true;
	var fd = new FormData(f);
	fd.append('action', 'pdfg_submit');
	fd.append('pdfg_page', window.location.href);
	fd.append('pdfg_t', String(Date.now() - pdfgLoaded));
	fetch(window.PDFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (res && res.success) {
				msg.className = 'pdfg-msg pdfg-ok';
				msg.textContent = res.data.message + ' ';
				var a = document.createElement('a');
				a.href = res.data.url;
				a.textContent = 'Download not starting? Click here.';
				msg.appendChild(a);
				f.reset();
				f.querySelectorAll('.pdfg-other-text').forEach(function (o) { o.style.display = 'none'; });
				window.location.href = res.data.url;
			} else {
				msg.className = 'pdfg-msg pdfg-err';
				msg.textContent = (res && res.data && res.data.message) || 'Something went wrong. Please try again.';
			}
		})
		.catch(function () {
			msg.className = 'pdfg-msg pdfg-err';
			msg.textContent = 'Network error. Please try again.';
		})
		.then(function () { btn.disabled = false; });
});
JS
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX: validate, store lead, issue download token
	 * ---------------------------------------------------------------- */

	public function ajax_submit() {
		ignore_user_abort( true );

		// Honeypot: real visitors never see or fill this field.
		if ( ! empty( $_POST['pdfg_website'] ) ) {
			wp_send_json_error( array( 'message' => 'Submission rejected.' ), 400 );
		}

		// The page script reports how long the form was open. Bots that skip JavaScript, or submit instantly, are refused.
		$open_ms = isset( $_POST['pdfg_t'] ) ? (int) $_POST['pdfg_t'] : 0;
		if ( $open_ms < 800 ) {
			wp_send_json_error( array( 'message' => 'Please wait a moment and try again.' ), 400 );
		}

		// Spam limits: 5 attempts per visitor IP per 10 minutes, plus a site-wide ceiling per hour (blocks floods from many IPs).
		$ip     = $this->client_ip();
		$rl_key = 'pdfg_rl_' . md5( $ip . wp_salt( 'nonce' ) );
		$count  = (int) get_transient( $rl_key );
		if ( $count >= 5 ) {
			wp_send_json_error( array( 'message' => 'Too many attempts. Please wait a few minutes and try again.' ), 429 );
		}
		$hour_key = 'pdfg_rlg_' . gmdate( 'YmdH' );
		$hour_n   = (int) get_transient( $hour_key );
		if ( $hour_n >= (int) apply_filters( 'pdfg_global_hourly_limit', 500 ) ) {
			wp_send_json_error( array( 'message' => 'This form is very busy right now. Please try again shortly.' ), 429 );
		}
		set_transient( $rl_key, $count + 1, 10 * MINUTE_IN_SECONDS );
		set_transient( $hour_key, $hour_n + 1, HOUR_IN_SECONDS );

		$name  = isset( $_POST['pdfg_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pdfg_name'] ) ) : '';
		$email = isset( $_POST['pdfg_email'] ) ? sanitize_email( wp_unslash( $_POST['pdfg_email'] ) ) : '';
		$org   = isset( $_POST['pdfg_org'] ) ? sanitize_text_field( wp_unslash( $_POST['pdfg_org'] ) ) : '';
		$ref   = isset( $_POST['pdfg_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['pdfg_ref'] ) ) : '';
		$spec  = isset( $_POST['pdfg_fields'] ) ? sanitize_text_field( wp_unslash( $_POST['pdfg_fields'] ) ) : '';
		$bid   = isset( $_POST['pdfg_brochure'] ) ? (int) $_POST['pdfg_brochure'] : 0;
		$sig   = isset( $_POST['pdfg_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['pdfg_sig'] ) ) : '';
		$page  = isset( $_POST['pdfg_page'] ) ? $this->clean_page_url( wp_unslash( $_POST['pdfg_page'] ) ) : '';

		if ( '' === $name || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Please enter your name and a valid email address.' ), 422 );
		}
		if ( empty( $_POST['pdfg_consent'] ) ) {
			wp_send_json_error( array( 'message' => 'Please tick the consent box to continue.' ), 422 );
		}
		if ( ! hash_equals( $this->sign( $ref . "\n" . $spec . "\n" . $bid ), $sig ) ) {
			wp_send_json_error( array( 'message' => 'This file is not available.' ), 400 );
		}

		$o = $this->settings();

		// File and questions always come from the saved brochure, never from the browser.
		$bname  = '';
		$notify = '';
		if ( $bid ) {
			$b = $this->get_brochure( $bid );
			if ( ! $b || '' === $b['file'] ) {
				wp_send_json_error( array( 'message' => 'This download is no longer available.' ), 400 );
			}
			$ref    = 'file:' . $b['file'];
			$fields = $b['fields'];
			$bname  = $b['name'];
			$notify = $b['notify_email'];
			$org    = '';
		} else {
			if ( empty( $o['allow_legacy'] ) ) {
				wp_send_json_error( array( 'message' => 'This download is no longer available.' ), 400 );
			}
			$fields = $this->parse_fields( $spec );
		}

		$resolved = $this->resolve( $ref );
		if ( ! $resolved ) {
			wp_send_json_error( array( 'message' => 'This file is not available.' ), 400 );
		}

		$posted = ( isset( $_POST['pdfg_x'] ) && is_array( $_POST['pdfg_x'] ) ) ? wp_unslash( $_POST['pdfg_x'] ) : array();
		$other  = ( isset( $_POST['pdfg_o'] ) && is_array( $_POST['pdfg_o'] ) ) ? wp_unslash( $_POST['pdfg_o'] ) : array();
		$extra  = $this->collect_answers( $fields, $posted, $other );
		if ( is_wp_error( $extra ) ) {
			wp_send_json_error( array( 'message' => $extra->get_error_message() ), 422 );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'created_at'    => current_time( 'mysql' ),
				'brochure_id'   => $bid,
				'brochure_name' => mb_substr( $bname, 0, 190 ),
				'name'          => mb_substr( $name, 0, 190 ),
				'email'         => mb_substr( $email, 0, 190 ),
				'organization'  => mb_substr( $org, 0, 190 ),
				'extra'         => $extra ? wp_json_encode( $extra ) : '',
				'file_ref'      => $ref,
				'page_url'      => $page,
				'ip_address'    => ! empty( $o['store_ip'] ) ? mb_substr( $ip, 0, 64 ) : '',
			)
		);

		// Notification email: sent right after the visitor has their response (see respond_then_mail).
		$to   = $this->lead_recipients( $notify );
		$mail = array();
		if ( $to ) {
			$label = $bname ? $bname : basename( $ref );
			$body  = "A new lead downloaded a brochure.\n\nBrochure: {$label}\nName: {$name}\nEmail: {$email}\n";
			if ( $org ) {
				$body .= "Organization: {$org}\n";
			}
			foreach ( $extra as $q => $value ) {
				$body .= "{$q}: {$value}\n";
			}
			if ( $page ) {
				$body .= "Page: {$page}\n";
			}
			$body .= "\nAll leads: " . admin_url( 'admin.php?page=pdfg-leads' ) . "\n";
			$mail = array( 'to' => $to, 'subject' => 'New download (' . $label . '): ' . $name, 'body' => $body, 'name' => $name, 'email' => $email );
		}

		// Short-lived download token: expires after 15 minutes and works at most TOKEN_USES times.
		$token = wp_generate_password( 32, false, false );
		$dl    = $bid ? sanitize_file_name( $bname ) : '';
		$dl    = ( '' !== $dl ) ? $dl . '.pdf' : $resolved['name'];
		set_transient(
			'pdfg_tok_' . $token,
			array( 'ref' => $ref, 'n' => 0, 'dl' => $dl, 'exp' => time() + self::TOKEN_TTL ),
			self::TOKEN_TTL
		);

		$this->respond_then_mail(
			array(
				'message' => 'Thank you! Your download is starting.',
				'url'     => add_query_arg( 'pdfg_download', $token, home_url( '/' ) ),
			),
			$mail
		);
	}

	/* ------------------------------------------------------------------
	 * Stream the PDF for a valid token
	 * ---------------------------------------------------------------- */

	public function maybe_serve_download() {
		if ( empty( $_GET['pdfg_download'] ) ) {
			return;
		}
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['pdfg_download'] ) );
		$data  = ( 32 === strlen( $token ) ) ? get_transient( 'pdfg_tok_' . $token ) : false;
		$file  = ( is_array( $data ) && ! empty( $data['ref'] ) ) ? $this->resolve( $data['ref'] ) : false;

		if ( ! $file || (int) $data['n'] >= self::TOKEN_USES ) {
			wp_die( 'This download link has expired. Please go back and submit the form again.', 'Link expired', array( 'response' => 403 ) );
		}

		// Count this use, keeping the original expiry.
		$data['n'] = (int) $data['n'] + 1;
		$left      = max( 1, (int) $data['exp'] - time() );
		set_transient( 'pdfg_tok_' . $token, $data, $left );

		$dl    = ! empty( $data['dl'] ) ? $data['dl'] : $file['name'];
		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', $dl );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $dl ) );
		header( 'Content-Length: ' . filesize( $file['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $file['path'] );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Admin: menu, settings, notices
	 * ---------------------------------------------------------------- */

	public function admin_menu() {
		add_menu_page( 'PDF Gate Leads', 'PDF Gate', 'manage_options', 'pdfg-leads', array( $this, 'page_leads' ), 'dashicons-media-document', 58 );
		add_submenu_page( 'pdfg-leads', 'PDF Gate Leads', 'Leads', 'manage_options', 'pdfg-leads', array( $this, 'page_leads' ) );
		add_submenu_page( 'pdfg-leads', 'PDF Gate Brochures', 'Brochures', 'manage_options', 'pdfg-brochures', array( $this, 'page_brochures' ) );
		add_submenu_page( 'pdfg-leads', 'PDF Gate Settings', 'Settings', 'manage_options', 'pdfg-settings', array( $this, 'page_settings' ) );
	}

	public function register_settings() {
		register_setting(
			'pdfg_group',
			self::OPT,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	public function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$old = $this->settings();

		// SMTP password: keep the saved one unless a new one is typed or "remove" is ticked.
		$pass = (string) $old['smtp_pass'];
		if ( ! empty( $in['smtp_pass_clear'] ) ) {
			$pass = '';
		} elseif ( isset( $in['smtp_pass'] ) && '' !== trim( (string) $in['smtp_pass'] ) ) {
			$typed = (string) $in['smtp_pass'];
			// WordPress can run this callback twice on the very first save; don't encrypt an already-encrypted value.
			$pass = ( '' !== $this->decrypt_secret( $typed ) ) ? $typed : $this->encrypt_secret( $typed );
		}

		$secure = ( isset( $in['smtp_secure'] ) && in_array( $in['smtp_secure'], array( 'tls', 'ssl', 'none' ), true ) ) ? $in['smtp_secure'] : 'tls';
		$port   = isset( $in['smtp_port'] ) ? (int) $in['smtp_port'] : 587;

		return array(
			'notify_email' => implode( ', ', $this->notify_recipients( isset( $in['notify_email'] ) ? $in['notify_email'] : '' ) ),
			'privacy_url'  => isset( $in['privacy_url'] ) ? esc_url_raw( trim( (string) $in['privacy_url'] ) ) : '',
			'from_name'    => isset( $in['from_name'] ) ? $this->header_text( $in['from_name'] ) : '',
			'from_email'   => isset( $in['from_email'] ) ? sanitize_email( $in['from_email'] ) : '',
			'smtp_on'      => empty( $in['smtp_on'] ) ? 0 : 1,
			'smtp_host'    => isset( $in['smtp_host'] ) ? preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', trim( (string) $in['smtp_host'] ) ) : '',
			'smtp_port'    => ( $port >= 1 && $port <= 65535 ) ? $port : 587,
			'smtp_secure'  => $secure,
			'smtp_user'    => isset( $in['smtp_user'] ) ? sanitize_text_field( $in['smtp_user'] ) : '',
			'smtp_pass'    => $pass,
			'allow_legacy' => empty( $in['allow_legacy'] ) ? 0 : 1,
			'store_ip'     => empty( $in['store_ip'] ) ? 0 : 1,
			'delete_data'  => empty( $in['delete_data'] ) ? 0 : 1,
		);
	}

	/** One-time admin messages are kept server-side (not in the URL), so nobody can craft a link that shows a fake message. */
	private function flash( $msg, $is_error = false ) {
		set_transient( 'pdfg_flash_' . get_current_user_id(), array( 'm' => (string) $msg, 'e' => (bool) $is_error ), 120 );
	}

	private function admin_notice() {
		$key = 'pdfg_flash_' . get_current_user_id();
		$f   = get_transient( $key );
		if ( is_array( $f ) && ! empty( $f['m'] ) ) {
			delete_transient( $key );
			echo '<div class="notice ' . ( ! empty( $f['e'] ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $f['m'] ) . '</p></div>';
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}
	}

	/** Warn when the most recent lead notification could not be sent. */
	private function mail_warning() {
		$log = get_option( self::MAIL_LOG, array() );
		if ( ! is_array( $log ) || empty( $log[0] ) || ! empty( $log[0]['ok'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Lead notification emails are not reaching you.</strong> The last attempt failed: ' . esc_html( $log[0]['e'] ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pdfg-settings' ) ) . '">Fix this under Settings &rarr; Email delivery</a>. Your leads are still saved on this page.</p></div>';
	}

	private function redirect( $args, $msg = '', $is_error = false ) {
		if ( isset( $args['pdfg_err'] ) ) {
			unset( $args['pdfg_err'] );
			$is_error = true;
		}
		if ( '' !== $msg ) {
			$this->flash( $msg, $is_error );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** "Send test email" button on the Settings page. */
	public function test_mail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pdfg_test_mail' );

		$o  = $this->settings();
		$to = $this->notify_recipients( $o['notify_email'] );
		if ( ! $to ) {
			$u  = wp_get_current_user();
			$to = array( $u->user_email );
		}
		list( $ok, $err ) = $this->send_mail(
			$to,
			'PDF Gate test email',
			"This is a test email from the PDF Gate plugin on " . home_url() . ".\n\nIf you can read this, lead notifications will reach you.\n"
		);
		$msg = $ok
			? 'Test email accepted for delivery to ' . implode( ', ', $to ) . '. Check that inbox (and the spam folder).'
			: 'The test email could not be sent: ' . $err;
		$this->redirect( array( 'page' => 'pdfg-settings' ), $msg, ! $ok );
	}

	/* ------------------------------------------------------------------
	 * Leads (filter by brochure, per-question columns, CSV)
	 * ---------------------------------------------------------------- */

	private function leads_where( $bsel, $ids = array() ) {
		$conds  = array();
		$params = array();
		if ( $bsel > 0 ) {
			$conds[]  = 'brochure_id = %d';
			$params[] = $bsel;
		} elseif ( -1 === $bsel ) {
			$conds[] = 'brochure_id = 0';
		}
		if ( $ids ) {
			$conds[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, array_map( 'intval', $ids ) );
		}
		return array( $conds ? 'WHERE ' . implode( ' AND ', $conds ) : '', $params );
	}

	private function query_leads( $bsel, $limit = 0, $offset = 0, $ids = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		list( $where, $params ) = $this->leads_where( $bsel, $ids );

		$sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC";
		if ( $limit ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}
		return $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Keep a page number inside the valid range (e.g. after deleting the last row of the last page). */
	private function clamp_page( $bsel, $paged ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		list( $where, $params ) = $this->leads_where( $bsel );
		$sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$n   = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$max = max( 1, (int) ceil( $n / self::PER_PAGE ) );
		return min( max( 1, (int) $paged ), $max );
	}

	private function delete_leads( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = $wpdb->prefix . self::TABLE;
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->rows_affected;
	}

	/** Question labels to use as columns when a single brochure is selected. */
	private function question_columns( $rows, $bsel ) {
		if ( $bsel <= 0 ) {
			return array();
		}
		$cols = array();
		$b    = $this->get_brochure( $bsel );
		if ( $b ) {
			foreach ( $b['fields'] as $f ) {
				$cols[ $f['label'] ] = true;
			}
		}
		foreach ( $rows as $r ) {
			$d = $r->extra ? json_decode( $r->extra, true ) : array();
			if ( is_array( $d ) ) {
				foreach ( array_keys( $d ) as $k ) {
					$cols[ $k ] = true;
				}
			}
		}
		return array_keys( $cols );
	}

	public function page_leads() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$bsel  = isset( $_GET['brochure'] ) ? (int) $_GET['brochure'] : 0; // 0 = all, -1 = older shortcodes.

		// Dropdown data: every brochure (even deleted ones that still have leads).
		$names  = array();
		foreach ( $this->get_brochures() as $id => $b ) {
			$names[ (int) $id ] = array( 'name' => $b['name'], 'count' => 0 );
		}
		foreach ( (array) $wpdb->get_results( "SELECT brochure_id, MAX(brochure_name) AS n, COUNT(*) AS c FROM {$table} WHERE brochure_id > 0 GROUP BY brochure_id" ) as $g ) {
			$id = (int) $g->brochure_id;
			if ( ! isset( $names[ $id ] ) ) {
				$names[ $id ] = array( 'name' => $g->n . ' (deleted)', 'count' => 0 );
			}
			$names[ $id ]['count'] = (int) $g->c;
		}
		$legacy_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE brochure_id = 0" );

		$per_page = self::PER_PAGE;
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		list( $where, $params ) = $this->leads_where( $bsel );
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows      = $this->query_leads( $bsel, $per_page, ( $paged - 1 ) * $per_page );
		$cols      = $this->question_columns( $rows, $bsel );

		$has_org = false;
		foreach ( $rows as $r ) {
			if ( '' !== $r->organization ) {
				$has_org = true;
			}
		}

		$export      = wp_nonce_url( admin_url( 'admin-post.php?action=pdfg_export&brochure=' . $bsel ), 'pdfg_export' );
		$export_xlsx = wp_nonce_url( admin_url( 'admin-post.php?action=pdfg_export_xlsx&brochure=' . $bsel ), 'pdfg_export' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">PDF Gate Leads</h1>
			<a href="<?php echo esc_url( $export_xlsx ); ?>" class="page-title-action">Export Excel (.xlsx)<?php echo $bsel ? ' - this brochure' : ' - all'; ?></a>
			<a href="<?php echo esc_url( $export ); ?>" class="page-title-action">Export CSV</a>
			<hr class="wp-header-end">
			<?php $this->admin_notice(); ?>
			<?php $this->mail_warning(); ?>

			<form method="get" style="margin:1em 0">
				<input type="hidden" name="page" value="pdfg-leads">
				<label for="pdfg-bsel"><strong>Brochure:</strong></label>
				<select id="pdfg-bsel" name="brochure" onchange="this.form.submit()">
					<option value="0"<?php selected( $bsel, 0 ); ?>>All brochures (<?php echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); ?>)</option>
					<?php foreach ( $names as $id => $n ) : ?>
						<option value="<?php echo (int) $id; ?>"<?php selected( $bsel, $id ); ?>><?php echo esc_html( $n['name'] . ' (' . $n['count'] . ')' ); ?></option>
					<?php endforeach; ?>
					<?php if ( $legacy_count ) : ?>
						<option value="-1"<?php selected( $bsel, -1 ); ?>>Older shortcodes without a brochure (<?php echo (int) $legacy_count; ?>)</option>
					<?php endif; ?>
				</select>
				<noscript><button class="button">Filter</button></noscript>
			</form>

			<form id="pdfg-bulk" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=pdfg_bulk_leads' ) ); ?>">
				<?php wp_nonce_field( 'pdfg_bulk' ); ?>
				<input type="hidden" name="brochure" value="<?php echo (int) $bsel; ?>">
				<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">

				<div style="margin:0 0 8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<label for="pdfg-bulk-action" class="screen-reader-text">Bulk action</label>
					<select id="pdfg-bulk-action" name="bulk_action">
						<option value="">Bulk actions</option>
						<option value="xlsx">Export selected to Excel (.xlsx)</option>
						<option value="csv">Export selected to CSV</option>
						<option value="delete">Delete selected</option>
					</select>
					<button type="submit" class="button action">Apply</button>
					<span class="pdfg-count description"></span>
				</div>

				<div style="overflow-x:auto">
				<table class="widefat striped">
					<thead>
						<tr>
							<td class="check-column" style="width:2.2em"><input type="checkbox" class="pdfg-check-all" aria-label="Select all leads on this page"></td>
							<th>Date</th><th>Name</th><th>Email</th>
							<?php if ( $has_org ) : ?><th>Organization</th><?php endif; ?>
							<?php if ( $bsel > 0 ) : foreach ( $cols as $c ) : ?><th><?php echo esc_html( $c ); ?></th><?php endforeach; else : ?><th>Brochure</th><th>Details</th><?php endif; ?>
							<th>Page</th><th></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="99">No leads yet.</td></tr>
					<?php else : foreach ( $rows as $r ) :
						$d = $r->extra ? json_decode( $r->extra, true ) : array();
						$d = is_array( $d ) ? $d : array();
						$del = wp_nonce_url( admin_url( 'admin-post.php?action=pdfg_delete_lead&lead_id=' . (int) $r->id . '&brochure=' . $bsel . '&paged=' . $paged ), 'pdfg_del_lead_' . (int) $r->id );
						?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="lead_ids[]" value="<?php echo (int) $r->id; ?>" aria-label="Select this lead"></th>
							<td><?php echo esc_html( $r->created_at ); ?></td>
							<td><?php echo esc_html( $r->name ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a></td>
							<?php if ( $has_org ) : ?><td><?php echo esc_html( $r->organization ); ?></td><?php endif; ?>
							<?php if ( $bsel > 0 ) : foreach ( $cols as $c ) : ?>
								<td><?php echo isset( $d[ $c ] ) ? esc_html( $d[ $c ] ) : ''; ?></td>
							<?php endforeach; else : ?>
								<td><?php echo esc_html( $r->brochure_name ? $r->brochure_name : $r->file_ref ); ?></td>
								<td><?php echo wp_kses( $this->format_extra( $r->extra, true ), array( 'strong' => array(), 'br' => array() ) ); ?></td>
							<?php endif; ?>
							<td><?php echo $r->page_url ? '<a href="' . esc_url( $r->page_url ) . '" target="_blank" rel="noopener">view</a>' : ''; ?></td>
							<td><a href="<?php echo esc_url( $del ); ?>" class="pdfg-del" style="color:#b32d2e">Delete</a></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				</div>
			</form>

			<?php
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => max( 1, (int) ceil( $total / $per_page ) ),
					)
				)
			) . '</div></div>';
			?>
			<p class="description">
				Tick leads to export or delete just those. "Select all" ticks the leads on this page only.
				<?php if ( 0 === $bsel ) : ?>Pick a single brochure above to see each of its questions as its own column.<?php endif; ?>
			</p>

			<script>
			(function () {
				var f = document.getElementById('pdfg-bulk');
				if (!f) return;
				var all = f.querySelector('.pdfg-check-all');
				function boxes() { return f.querySelectorAll('input[name="lead_ids[]"]'); }
				function ticked() { return f.querySelectorAll('input[name="lead_ids[]"]:checked'); }
				function update() {
					var n = ticked().length;
					f.querySelector('.pdfg-count').textContent = n ? n + ' selected' : '';
					if (all) all.checked = n > 0 && n === boxes().length;
				}
				if (all) all.addEventListener('change', function () {
					boxes().forEach(function (c) { c.checked = all.checked; });
					update();
				});
				f.addEventListener('change', function (e) { if (e.target.name === 'lead_ids[]') update(); });
				f.addEventListener('submit', function (e) {
					var n = ticked().length, a = f.querySelector('select[name=bulk_action]').value;
					if (!a) { alert('Choose an action first.'); e.preventDefault(); return; }
					if (!n) { alert('Tick at least one lead first.'); e.preventDefault(); return; }
					if (a === 'delete' && !confirm('Permanently delete ' + n + ' selected lead' + (n > 1 ? 's' : '') + '? This cannot be undone.')) e.preventDefault();
				});
				f.querySelectorAll('a.pdfg-del').forEach(function (a) {
					a.addEventListener('click', function (e) {
						if (!confirm('Permanently delete this lead? This cannot be undone.')) e.preventDefault();
					});
				});
			})();
			</script>
		</div>
		<?php
	}

	/** Build the export table (header row + data rows) for the selected brochure filter. */
	private function leads_matrix( $bsel, $ids = array() ) {
		$rows = $this->query_leads( $bsel, 0, 0, $ids );
		$cols = $this->question_columns( $rows, $bsel );

		$has_org = false;
		foreach ( $rows as $r ) {
			if ( '' !== $r->organization ) {
				$has_org = true;
			}
		}

		$has_ip = false;
		foreach ( $rows as $r ) {
			if ( '' !== $r->ip_address ) {
				$has_ip = true;
			}
		}

		$head = array( 'Date', 'Name', 'Email' );
		if ( $has_org ) {
			$head[] = 'Organization';
		}
		if ( $bsel > 0 ) {
			$head = array_merge( $head, $cols );
		} else {
			$head[] = 'Brochure';
			$head[] = 'Details';
		}
		$head[] = 'Page';
		if ( $has_ip ) {
			$head[] = 'IP';
		}

		$matrix = array( $head );
		foreach ( $rows as $r ) {
			$d = $r->extra ? json_decode( $r->extra, true ) : array();
			$d = is_array( $d ) ? $d : array();

			$line = array( $r->created_at, $r->name, $r->email );
			if ( $has_org ) {
				$line[] = $r->organization;
			}
			if ( $bsel > 0 ) {
				foreach ( $cols as $c ) {
					$line[] = isset( $d[ $c ] ) ? $d[ $c ] : '';
				}
			} else {
				$line[] = $r->brochure_name ? $r->brochure_name : $r->file_ref;
				$line[] = $this->format_extra( $r->extra, false );
			}
			$line[]   = $r->page_url;
			if ( $has_ip ) {
				$line[] = $r->ip_address;
			}
			$matrix[] = $line;
		}
		return $matrix;
	}

	/** Returns array( file-name slug, readable title ) for the current filter. */
	private function export_label( $bsel ) {
		if ( $bsel > 0 ) {
			$b     = $this->get_brochure( $bsel );
			$title = $b ? $b['name'] : 'Brochure ' . $bsel;
			return array( sanitize_title( $title ), $title );
		}
		if ( -1 === $bsel ) {
			return array( 'older', 'Older leads' );
		}
		return array( 'all', 'All leads' );
	}

	private function export_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pdfg_export' );
		return isset( $_GET['brochure'] ) ? (int) $_GET['brochure'] : 0;
	}

	private function output_csv( $matrix, $slug ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pdf-gate-leads-' . $slug . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows accented characters correctly.
		foreach ( $matrix as $i => $line ) {
			if ( $i > 0 ) {
				// Neutralise spreadsheet formula injection.
				foreach ( $line as $k => $v ) {
					if ( preg_match( '/^[=+\-@]/', (string) $v ) ) {
						$line[ $k ] = "'" . $v;
					}
				}
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	private function output_xlsx( $matrix, $slug, $title ) {
		$tmp = $this->build_xlsx( $matrix, $title );
		if ( ! $tmp || ! is_file( $tmp ) ) {
			wp_die( 'Could not create the Excel file on this server (the PHP zip extension is missing). Please use the CSV export instead.' );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="pdf-gate-leads-' . $slug . '-' . gmdate( 'Y-m-d' ) . '.xlsx"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		unlink( $tmp );
		exit;
	}

	public function export_csv() {
		$bsel = $this->export_guard();
		list( $slug ) = $this->export_label( $bsel );
		$this->output_csv( $this->leads_matrix( $bsel ), $slug );
	}

	public function export_xlsx() {
		$bsel                 = $this->export_guard();
		list( $slug, $title ) = $this->export_label( $bsel );
		$this->output_xlsx( $this->leads_matrix( $bsel ), $slug, $title );
	}

	/** Bulk actions from the Leads table: export selected (xlsx/csv) or delete selected. */
	public function bulk_leads() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pdfg_bulk' );

		$ids = ( isset( $_POST['lead_ids'] ) && is_array( $_POST['lead_ids'] ) ) ? array_map( 'intval', wp_unslash( $_POST['lead_ids'] ) ) : array();
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 1000 );
		$act = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';

		$bsel  = isset( $_POST['brochure'] ) ? (int) $_POST['brochure'] : 0;
		$paged = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		$back  = array( 'page' => 'pdfg-leads' );
		if ( $bsel ) {
			$back['brochure'] = $bsel;
		}

		if ( ! $ids || ! in_array( $act, array( 'delete', 'xlsx', 'csv' ), true ) ) {
			$this->redirect( $back, 'Choose an action and tick at least one lead.' );
		}

		if ( 'delete' === $act ) {
			$n     = $this->delete_leads( $ids );
			$paged = $this->clamp_page( $bsel, $paged );
			if ( $paged > 1 ) {
				$back['paged'] = $paged;
			}
			$this->redirect( $back, $n . ( 1 === $n ? ' lead deleted.' : ' leads deleted.' ) );
		}

		// Export only the ticked leads. One brochure among them -> a column per question.
		$rows   = $this->query_leads( 0, 0, 0, $ids );
		$bids   = array();
		foreach ( $rows as $r ) {
			$bids[ (int) $r->brochure_id ] = true;
		}
		$bids = array_keys( $bids );
		$sel  = ( 1 === count( $bids ) && $bids[0] > 0 ) ? $bids[0] : 0;

		list( $slug, $title ) = $this->export_label( $sel );
		$slug                 = $sel ? $slug . '-selected' : 'selected';
		$title                = $sel ? $title . ' (selected)' : 'Selected leads';
		$matrix               = $this->leads_matrix( $sel, $ids );

		if ( 'xlsx' === $act ) {
			$this->output_xlsx( $matrix, $slug, $title );
		}
		$this->output_csv( $matrix, $slug );
	}

	/** Delete a single lead (row "Delete" link). */
	public function delete_lead() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['lead_id'] ) ? (int) $_GET['lead_id'] : 0;
		check_admin_referer( 'pdfg_del_lead_' . $id );

		$n     = $this->delete_leads( array( $id ) );
		$bsel  = isset( $_GET['brochure'] ) ? (int) $_GET['brochure'] : 0;
		$paged = $this->clamp_page( $bsel, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );

		$back = array( 'page' => 'pdfg-leads' );
		if ( $bsel ) {
			$back['brochure'] = $bsel;
		}
		if ( $paged > 1 ) {
			$back['paged'] = $paged;
		}
		$this->redirect( $back, $n ? 'Lead deleted.' : 'Lead not found.' );
	}

	/* ------------------------- Excel (.xlsx) writer ------------------ */

	/** 0 => A, 25 => Z, 26 => AA ... */
	private function xlsx_col( $i ) {
		$s = '';
		$i++;
		while ( $i > 0 ) {
			$m = ( $i - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$i = intdiv( $i - 1, 26 );
		}
		return $s;
	}

	private function xlsx_text( $v ) {
		$v = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v ); // Characters XML cannot hold.
		return htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/** Creates a temp .xlsx file from a table of strings and returns its path (or false). */
	private function build_xlsx( $matrix, $sheet_title ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$sheet_title = trim( preg_replace( '/[\[\]\:\*\?\/\\\\]/', ' ', (string) $sheet_title ) );
		$sheet_title = trim( mb_substr( '' !== $sheet_title ? $sheet_title : 'Leads', 0, 31 ), "' " );
		if ( '' === $sheet_title ) {
			$sheet_title = 'Leads';
		}

		$ncols = count( $matrix[0] );
		$nrows = count( $matrix );
		$last  = $this->xlsx_col( $ncols - 1 ) . $nrows;

		// Column widths from the longest value (capped).
		$widths = array_fill( 0, $ncols, 8 );
		foreach ( $matrix as $row ) {
			foreach ( $row as $c => $v ) {
				$len = 0;
				foreach ( explode( "\n", (string) $v ) as $part ) {
					$len = max( $len, mb_strlen( $part ) );
				}
				$widths[ $c ] = max( $widths[ $c ], min( 60, $len + 2 ) );
			}
		}
		$cols_xml = '<cols>';
		foreach ( $widths as $c => $w ) {
			$cols_xml .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		$cols_xml .= '</cols>';

		// All cells are written as text, so phone numbers keep their leading zeros and nothing runs as a formula.
		$rows_xml = '';
		foreach ( $matrix as $r => $row ) {
			$rows_xml .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( $row as $c => $v ) {
				$style     = ( 0 === $r ) ? ' s="1"' : '';
				$rows_xml .= '<c r="' . $this->xlsx_col( $c ) . ( $r + 1 ) . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $this->xlsx_text( $v ) . '</t></is></c>';
			}
			$rows_xml .= '</row>';
		}

		$ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$qs   = "'" . str_replace( "'", "''", $sheet_title ) . "'";

		$files = array(
			'[Content_Types].xml'        => $decl . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
			'_rels/.rels'                => $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/workbook.xml'            => $decl . '<workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $this->xlsx_text( $sheet_title ) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">' . $this->xlsx_text( $qs ) . '!$A$1:$' . $this->xlsx_col( $ncols - 1 ) . '$' . $nrows . '</definedName></definedNames></workbook>',
			'xl/_rels/workbook.xml.rels' => $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
			'xl/styles.xml'              => $decl . '<styleSheet xmlns="' . $ns . '"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
			'xl/worksheets/sheet1.xml'   => $decl . '<worksheet xmlns="' . $ns . '"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>' . $cols_xml . '<sheetData>' . $rows_xml . '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>',
		);

		$tmp = wp_tempnam( 'pdfg-export.xlsx' );
		if ( ! $tmp ) {
			return false;
		}

		if ( class_exists( 'ZipArchive' ) && method_exists( 'ZipArchive', 'addFromString' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				return false;
			}
			foreach ( $files as $name => $content ) {
				$zip->addFromString( $name, $content );
			}
			$zip->close();
			return $tmp;
		}

		// Fallback: the PclZip library that ships with WordPress.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$list = array();
		foreach ( $files as $name => $content ) {
			$list[] = array( PCLZIP_ATT_FILE_NAME => $name, PCLZIP_ATT_FILE_CONTENT => $content );
		}
		unlink( $tmp );
		$tmp = wp_tempnam( 'pdfg-export.xlsx' );
		$z   = new PclZip( $tmp );
		return ( 0 !== $z->create( $list ) ) ? $tmp : false;
	}

	/* ------------------------------------------------------------------
	 * Brochures: list, edit, save, duplicate, delete
	 * ---------------------------------------------------------------- */

	public function page_brochures() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		if ( 'edit' === $action || 'new' === $action ) {
			$this->page_brochure_edit();
			return;
		}

		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT brochure_id, COUNT(*) AS c FROM {$table} WHERE brochure_id > 0 GROUP BY brochure_id" ) as $g ) {
			$counts[ (int) $g->brochure_id ] = (int) $g->c;
		}

		$brochures = $this->get_brochures();
		$new_url   = admin_url( 'admin.php?page=pdfg-brochures&action=new' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">PDF Gate Brochures</h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">Add New</a>
			<hr class="wp-header-end">
			<?php $this->admin_notice(); ?>
			<p>Each brochure has its own PDF, its own questions and its own leads. Paste its shortcode into any page or post.</p>
			<div style="overflow-x:auto">
			<table class="widefat striped" style="max-width:1200px">
				<thead><tr><th>ID</th><th>Brochure</th><th>PDF file</th><th>Questions</th><th>Leads</th><th>Shortcode</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $brochures ) : ?>
					<tr><td colspan="7">No brochures yet. <a href="<?php echo esc_url( $new_url ); ?>">Add your first brochure</a>.</td></tr>
				<?php else : foreach ( $brochures as $id => $b ) :
					$id    = (int) $id;
					$edit  = admin_url( 'admin.php?page=pdfg-brochures&action=edit&brochure_id=' . $id );
					$dup   = wp_nonce_url( admin_url( 'admin-post.php?action=pdfg_duplicate_brochure&brochure_id=' . $id ), 'pdfg_dup_' . $id );
					$del   = wp_nonce_url( admin_url( 'admin-post.php?action=pdfg_delete_brochure&brochure_id=' . $id ), 'pdfg_del_' . $id );
					$leads = admin_url( 'admin.php?page=pdfg-leads&brochure=' . $id );
					?>
					<tr>
						<td><?php echo (int) $id; ?></td>
						<td><strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $b['name'] ); ?></a></strong></td>
						<td><?php echo $b['file'] ? esc_html( $b['file'] ) : '<em>none</em>'; ?></td>
						<td><?php echo (int) count( $b['fields'] ); ?></td>
						<td><a href="<?php echo esc_url( $leads ); ?>"><?php echo isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0; ?></a></td>
						<td><code>[pdf_gate brochure="<?php echo (int) $id; ?>"]</code></td>
						<td>
							<a href="<?php echo esc_url( $edit ); ?>">Edit</a> |
							<a href="<?php echo esc_url( $dup ); ?>">Duplicate</a> |
							<a href="<?php echo esc_url( $leads ); ?>">Leads</a> |
							<a href="<?php echo esc_url( $del ); ?>" onclick="return confirm('Delete this brochure? Its leads are kept, but pages using its shortcode will stop showing the form. The PDF file is not deleted.');" style="color:#b32d2e">Delete</a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	private function page_brochure_edit() {
		$id        = isset( $_GET['brochure_id'] ) ? (int) $_GET['brochure_id'] : 0;
		$brochure  = $id ? $this->get_brochure( $id ) : false;
		if ( ! $brochure ) {
			$id       = 0;
			$brochure = array( 'name' => '', 'file' => '', 'title' => '', 'description' => '', 'button' => '', 'consent' => '', 'notify_email' => '', 'fields' => array() );
		}
		$d     = $this->default_texts();
		$files = $this->protected_files();
		$lim   = $this->pdf_limits();
		?>
		<div class="wrap">
			<h1><?php echo $id ? 'Edit brochure' : 'Add new brochure'; ?></h1>
			<?php $this->admin_notice(); ?>

			<style>
				#pdfg-qlist{max-width:900px}
				.pdfg-q{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;margin-bottom:10px}
				.pdfg-q-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.pdfg-q-label{flex:1 1 260px}
				.pdfg-q-opts{display:none;margin-top:10px}
				.pdfg-q-opts textarea{width:100%}
				.pdfg-q-req{white-space:nowrap}
			</style>

			<form id="pdfg-builder" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=pdfg_save_brochure' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="pdfg_save_brochure">
				<?php wp_nonce_field( 'pdfg_save_brochure' ); ?>
				<input type="hidden" name="brochure_id" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="fields_json" id="pdfg-fields-json" value="">

				<h2>Brochure</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdfg-name">Brochure name</label></th>
						<td>
							<input type="text" id="pdfg-name" name="b_name" class="regular-text" required value="<?php echo esc_attr( $brochure['name'] ); ?>" placeholder="e.g. Undergraduate Prospectus 2027">
							<p class="description">Used in the leads list and emails. Also the heading on the form unless you set one below.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg-file-existing">PDF file</label></th>
						<td>
							<select id="pdfg-file-existing" name="b_file">
								<option value="">&mdash; Choose an uploaded PDF &mdash;</option>
								<?php foreach ( $files as $f ) : ?>
									<option value="<?php echo esc_attr( $f ); ?>"<?php selected( $brochure['file'], $f ); ?>><?php echo esc_html( $f ); ?></option>
								<?php endforeach; ?>
							</select>
							<p style="margin:.6em 0 0">Or upload a new PDF: <input type="file" id="pdfg-file" name="pdfg_file" accept="application/pdf" data-max="<?php echo (int) $lim['effective']; ?>" data-max-label="<?php echo esc_attr( size_format( $lim['effective'] ) ); ?>" data-server-limited="<?php echo $lim['server'] < $lim['cap'] ? '1' : '0'; ?>"></p>
							<div id="pdfg-file-error" class="notice notice-error inline" role="alert" style="display:none;margin:.6em 0 0"><p></p></div>
							<p class="description">PDFs are stored in a protected folder and can only be downloaded after the form is completed. Uploading a file here overrides the selection above. <strong>Maximum file size: <?php echo esc_html( size_format( $lim['cap'] ) ); ?> (PDF only).</strong></p>
							<?php if ( $lim['server'] < $lim['cap'] ) : ?>
							<div class="notice notice-warning inline" style="margin:.6em 0 0"><p>Your server currently only allows uploads up to <strong><?php echo esc_html( size_format( $lim['server'] ) ); ?></strong>, so PDFs between <?php echo esc_html( size_format( $lim['server'] ) ); ?> and <?php echo esc_html( size_format( $lim['cap'] ) ); ?> will be rejected. To allow the full <?php echo esc_html( size_format( $lim['cap'] ) ); ?>, ask your host to set <code>upload_max_filesize</code> and <code>post_max_size</code> to at least <code>4M</code>.</p></div>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $id ) : ?>
					<tr>
						<th scope="row">Shortcode</th>
						<td><code>[pdf_gate brochure="<?php echo (int) $id; ?>"]</code></td>
					</tr>
					<?php endif; ?>
				</table>

				<h2>Wording <span class="description" style="font-weight:400">(optional - blank uses the default)</span></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="pdfg-title">Form heading</label></th>
						<td><input type="text" id="pdfg-title" name="b_title" class="regular-text" value="<?php echo esc_attr( $brochure['title'] ); ?>" placeholder="<?php echo esc_attr( 'Brochure name' ); ?>"></td></tr>
					<tr><th scope="row"><label for="pdfg-desc">Short description</label></th>
						<td><input type="text" id="pdfg-desc" name="b_description" class="large-text" value="<?php echo esc_attr( $brochure['description'] ); ?>" placeholder="<?php echo esc_attr( $d['description'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="pdfg-btn">Button text</label></th>
						<td><input type="text" id="pdfg-btn" name="b_button" class="regular-text" value="<?php echo esc_attr( $brochure['button'] ); ?>" placeholder="<?php echo esc_attr( $d['button'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="pdfg-consent">Consent text</label></th>
						<td>
							<input type="text" id="pdfg-consent" name="b_consent" class="large-text" value="<?php echo esc_attr( $brochure['consent'] ); ?>" placeholder="<?php echo esc_attr( $d['consent'] ); ?>">
							<p class="description">To link your privacy policy, type <code>{privacy_policy}</code> where the link should appear, e.g. <em>I agree to the processing of my data as described in the {privacy_policy}.</em> Or add your own link: <code>&lt;a href="https://example.com/privacy"&gt;Privacy Policy&lt;/a&gt;</code>. Links open in a new tab.</p>
						</td></tr>
					<tr><th scope="row"><label for="pdfg-notify">Send leads to</label></th>
						<td>
							<input type="text" id="pdfg-notify" name="b_notify" class="regular-text" value="<?php echo esc_attr( $brochure['notify_email'] ); ?>" placeholder="Uses the default address from Settings">
							<p class="description">Different team handles this brochure? Enter their email here (several addresses can be separated by commas).</p>
						</td></tr>
				</table>

				<h2>Questions for this brochure</h2>
				<p class="description">Full name, email address and the consent checkbox are always shown. Add anything extra below.</p>
				<div id="pdfg-qlist"></div>
				<p><button type="button" class="button" id="pdfg-add">+ Add question</button></p>

				<?php submit_button( 'Save brochure' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pdfg-brochures' ) ); ?>">&larr; Back to brochures</a>
			</form>

			<script type="application/json" id="pdfg-init"><?php echo wp_json_encode( array_values( $brochure['fields'] ), JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
			<script>
			(function () {
				var TYPES = [
					['text', 'Short answer'],
					['textarea', 'Paragraph (long answer)'],
					['email', 'Email address'],
					['tel', 'Phone number'],
					['number', 'Number'],
					['date', 'Date'],
					['select', 'Dropdown (pick one)'],
					['radio', 'Multiple choice - radio buttons (pick one)'],
					['checkboxes', 'Checkboxes (pick many)'],
					['checkbox', 'Single tick box (e.g. "I agree")']
				];
				var CHOICE = { select: 1, radio: 1, checkboxes: 1 };
				var list = document.getElementById('pdfg-qlist');
				var init = [];
				try { init = JSON.parse(document.getElementById('pdfg-init').textContent) || []; } catch (e) {}

				function el(tag, cls, text) {
					var n = document.createElement(tag);
					if (cls) n.className = cls;
					if (text) n.textContent = text;
					return n;
				}

				function addRow(q) {
					q = q || { label: '', type: 'text', required: false, options: [], allow_other: false };
					var row = el('div', 'pdfg-q');
					var head = el('div', 'pdfg-q-head');

					var label = el('input', 'pdfg-q-label');
					label.type = 'text';
					label.placeholder = 'Type your question';
					label.value = q.label || '';

					var type = el('select', 'pdfg-q-type');
					TYPES.forEach(function (t) {
						var o = el('option', '', t[1]);
						o.value = t[0];
						if (t[0] === q.type) o.selected = true;
						type.appendChild(o);
					});

					var reqWrap = el('label', 'pdfg-q-req');
					var req = el('input');
					req.type = 'checkbox';
					req.checked = !!q.required;
					reqWrap.appendChild(req);
					reqWrap.appendChild(document.createTextNode(' Required'));

					var up = el('button', 'button', '\u2191');
					up.type = 'button';
					up.title = 'Move up';
					var down = el('button', 'button', '\u2193');
					down.type = 'button';
					down.title = 'Move down';
					var del = el('button', 'button', 'Remove');
					del.type = 'button';

					head.appendChild(label);
					head.appendChild(type);
					head.appendChild(reqWrap);
					head.appendChild(up);
					head.appendChild(down);
					head.appendChild(del);

					var opts = el('div', 'pdfg-q-opts');
					opts.appendChild(el('p', 'description', 'Answer options - one per line:'));
					var ta = el('textarea');
					ta.rows = 4;
					ta.placeholder = 'Option 1\nOption 2\nOption 3';
					ta.value = (q.options || []).join('\n');
					var otherWrap = el('label');
					var other = el('input');
					other.type = 'checkbox';
					other.checked = !!q.allow_other;
					otherWrap.appendChild(other);
					otherWrap.appendChild(document.createTextNode(' Add an "Other (please specify)" option'));
					opts.appendChild(ta);
					opts.appendChild(otherWrap);

					function sync() { opts.style.display = CHOICE[type.value] ? 'block' : 'none'; }
					type.addEventListener('change', sync);
					sync();

					up.addEventListener('click', function () {
						var p = row.previousElementSibling;
						if (p) list.insertBefore(row, p);
					});
					down.addEventListener('click', function () {
						var n = row.nextElementSibling;
						if (n) list.insertBefore(n, row);
					});
					del.addEventListener('click', function () {
						if (confirm('Remove this question?')) list.removeChild(row);
					});

					row.appendChild(head);
					row.appendChild(opts);
					row._get = function () {
						return {
							label: label.value.trim(),
							type: type.value,
							required: req.checked,
							options: ta.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
							allow_other: other.checked
						};
					};
					list.appendChild(row);
					return row;
				}

				init.forEach(addRow);

				document.getElementById('pdfg-add').addEventListener('click', function () {
					var r = addRow();
					r.querySelector('.pdfg-q-label').focus();
				});

				document.getElementById('pdfg-builder').addEventListener('submit', function (e) {
					var data = [], rows = list.children, i, q;
					for (i = 0; i < rows.length; i++) {
						q = rows[i]._get();
						if (!q.label) {
							alert('Question ' + (i + 1) + ' needs some text (or remove it).');
							e.preventDefault();
							return;
						}
						if (CHOICE[q.type] && !q.options.length) {
							alert('"' + q.label + '" needs at least one answer option.');
							e.preventDefault();
							return;
						}
						data.push(q);
					}
					document.getElementById('pdfg-fields-json').value = JSON.stringify(data);
				});
			})();
			</script>
			<script>
			// Warn immediately when a PDF is too big or is not a PDF, before the form is saved.
			(function () {
				var input = document.getElementById('pdfg-file');
				var box = document.getElementById('pdfg-file-error');
				if (!input || !box) return;
				var max = parseInt(input.getAttribute('data-max'), 10) || 0;
				var label = input.getAttribute('data-max-label') || '';
				var serverLimited = input.getAttribute('data-server-limited') === '1';
				function mb(n) { return (n / 1048576).toFixed(1) + ' MB'; }
				function show(msg) {
					box.querySelector('p').textContent = msg;
					box.style.display = 'block';
					input.value = '';
					box.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
				input.addEventListener('change', function () {
					box.style.display = 'none';
					var f = input.files && input.files[0];
					if (!f) return;
					if (!/\.pdf$/i.test(f.name) || (f.type && f.type !== 'application/pdf')) {
						show('"' + f.name + '" is not a PDF. Only PDF files can be uploaded.');
						return;
					}
					if (max && f.size > max) {
						show('"' + f.name + '" is ' + mb(f.size) + ', which is over the ' + label + ' limit' + (serverLimited ? ' your server allows' : '') + '. Please compress the PDF or choose a smaller file.');
					}
				});
			})();
			</script>
		</div>
		<?php
	}

	public function save_brochure() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		// If the upload is bigger than the server allows, PHP discards the whole form (so the nonce would "expire").
		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			$lim = $this->pdf_limits();
			$why = ' Nothing was saved. The maximum PDF size is ' . size_format( $lim['effective'] ) . '.';
			if ( $lim['server'] < $lim['cap'] ) {
				$why .= ' Your server limits uploads to ' . size_format( $lim['server'] ) . '; ask your host to raise upload_max_filesize and post_max_size to at least 4M.';
			}
			$this->redirect( array( 'page' => 'pdfg-brochures', 'pdfg_err' => 1 ), 'The PDF is too large.' . $why );
		}
		check_admin_referer( 'pdfg_save_brochure' );

		$id   = isset( $_POST['brochure_id'] ) ? (int) $_POST['brochure_id'] : 0;
		$all  = $this->get_brochures();
		$json = isset( $_POST['fields_json'] ) ? wp_unslash( $_POST['fields_json'] ) : '[]';
		$data = json_decode( $json, true );
		$data = is_array( $data ) ? $data : array();

		$name = isset( $_POST['b_name'] ) ? sanitize_text_field( wp_unslash( $_POST['b_name'] ) ) : '';
		$file = isset( $_POST['b_file'] ) ? sanitize_file_name( basename( wp_unslash( $_POST['b_file'] ) ) ) : '';
		$msg  = 'Brochure saved.';
		$upload_error = '';

		// A freshly uploaded PDF wins over the dropdown choice.
		if ( ! empty( $_FILES['pdfg_file']['name'] ) ) {
			$up = $this->upload_pdf( $_FILES['pdfg_file'] );
			if ( is_wp_error( $up ) ) {
				$upload_error = $up->get_error_message();
			} else {
				$file = $up;
			}
		}
		if ( $file && ! $this->resolve( 'file:' . $file ) ) {
			$file = '';
		}

		if ( ! $id || ! isset( $all[ $id ] ) ) {
			$id = max( 1, (int) get_option( 'pdfg_next_brochure_id', 1 ) );
			update_option( 'pdfg_next_brochure_id', $id + 1, false );
		}

		$notify = implode( ', ', $this->notify_recipients( isset( $_POST['b_notify'] ) ? wp_unslash( $_POST['b_notify'] ) : '' ) );

		$all[ $id ] = array(
			'name'         => '' !== $name ? $name : 'Untitled brochure',
			'file'         => $file,
			'title'        => isset( $_POST['b_title'] ) ? sanitize_text_field( wp_unslash( $_POST['b_title'] ) ) : '',
			'description'  => isset( $_POST['b_description'] ) ? sanitize_text_field( wp_unslash( $_POST['b_description'] ) ) : '',
			'button'       => isset( $_POST['b_button'] ) ? sanitize_text_field( wp_unslash( $_POST['b_button'] ) ) : '',
			'consent'      => isset( $_POST['b_consent'] ) ? $this->clean_consent( wp_unslash( $_POST['b_consent'] ) ) : '',
			'notify_email' => $notify,
			'fields'       => $this->sanitize_builder_fields( $data ),
		);
		update_option( self::BROCHURES_OPT, $all, false );

		// Upload problem: stay on the edit page so the PDF can be fixed straight away.
		if ( '' !== $upload_error ) {
			$this->redirect(
				array( 'page' => 'pdfg-brochures', 'action' => 'edit', 'brochure_id' => $id, 'pdfg_err' => 1 ),
				'Your changes were saved, but the PDF was NOT uploaded. ' . $upload_error
			);
		}

		if ( '' === $file ) {
			$msg .= ' Remember to choose or upload a PDF.';
		}

		$this->redirect( array( 'page' => 'pdfg-brochures' ), $msg );
	}

	public function duplicate_brochure() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['brochure_id'] ) ? (int) $_GET['brochure_id'] : 0;
		check_admin_referer( 'pdfg_dup_' . $id );

		$all = $this->get_brochures();
		if ( ! isset( $all[ $id ] ) ) {
			$this->redirect( array( 'page' => 'pdfg-brochures' ), 'Brochure not found.' );
		}

		$new_id = max( 1, (int) get_option( 'pdfg_next_brochure_id', 1 ) );
		update_option( 'pdfg_next_brochure_id', $new_id + 1, false );

		$copy         = $all[ $id ];
		$copy['name'] = 'Copy of ' . $copy['name'];
		$all[ $new_id ] = $copy;
		update_option( self::BROCHURES_OPT, $all, false );

		$this->redirect( array( 'page' => 'pdfg-brochures', 'action' => 'edit', 'brochure_id' => $new_id ), 'Duplicated. Change the name and PDF file, then save.' );
	}

	public function delete_brochure() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 'Forbidden', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['brochure_id'] ) ? (int) $_GET['brochure_id'] : 0;
		check_admin_referer( 'pdfg_del_' . $id );

		$all = $this->get_brochures();
		unset( $all[ $id ] );
		update_option( self::BROCHURES_OPT, $all, false );

		$this->redirect( array( 'page' => 'pdfg-brochures' ), 'Brochure deleted. Its leads were kept.' );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	public function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = $this->settings();
		$n       = self::OPT;
		$log     = get_option( self::MAIL_LOG, array() );
		$log     = is_array( $log ) ? $log : array();
		$to_now  = $this->notify_recipients( $o['notify_email'] );
		$has_pw  = defined( 'PDFG_SMTP_PASS' ) || '' !== $this->smtp_password();
		?>
		<div class="wrap">
			<h1>PDF Gate Settings</h1>
			<?php $this->admin_notice(); ?>
			<?php $this->mail_warning(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'pdfg_group' ); ?>

				<h2>Lead notifications</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdfg_notify">Send new leads to</label></th>
						<td>
							<input type="text" id="pdfg_notify" name="<?php echo esc_attr( $n ); ?>[notify_email]" value="<?php echo esc_attr( $o['notify_email'] ); ?>" class="regular-text" placeholder="name@example.com, other@example.com">
							<p class="description">
								<?php if ( $to_now ) : ?>Currently emailing: <strong><?php echo esc_html( implode( ', ', $to_now ) ); ?></strong>.<?php else : ?><strong>No address set, so notifications are switched off.</strong><?php endif; ?>
								Separate several addresses with commas (up to 5). A brochure can have its own address, which overrides this one.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg_from_name">"From" name</label></th>
						<td><input type="text" id="pdfg_from_name" name="<?php echo esc_attr( $n ); ?>[from_name]" value="<?php echo esc_attr( $o['from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg_from_email">"From" email</label></th>
						<td>
							<input type="email" id="pdfg_from_email" name="<?php echo esc_attr( $n ); ?>[from_email]" value="<?php echo esc_attr( $o['from_email'] ); ?>" class="regular-text" placeholder="leads@your-domain.com">
							<p class="description">Use an address on your own domain that your mail provider is allowed to send for. Mail "from" a domain the server isn't authorised for is often rejected or lands in spam. With SMTP below, leave blank to use the SMTP username.</p>
						</td>
					</tr>
				</table>

				<h2 id="email-delivery">Email delivery (SMTP)</h2>
				<p>If you are not receiving lead emails, your web server most likely has no working mail service. Sending through a real mailbox (SMTP) fixes that. These settings are used only for this plugin's emails, not the rest of your site.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Send through SMTP</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[smtp_on]" value="1"<?php checked( ! empty( $o['smtp_on'] ) ); ?>> Yes, use the SMTP account below</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg_smtp_host">SMTP host</label></th>
						<td><input type="text" id="pdfg_smtp_host" name="<?php echo esc_attr( $n ); ?>[smtp_host]" value="<?php echo esc_attr( $o['smtp_host'] ); ?>" class="regular-text" placeholder="smtp.example.com" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg_smtp_port">Port</label></th>
						<td>
							<input type="number" id="pdfg_smtp_port" name="<?php echo esc_attr( $n ); ?>[smtp_port]" value="<?php echo (int) $o['smtp_port']; ?>" class="small-text" min="1" max="65535">
							<select name="<?php echo esc_attr( $n ); ?>[smtp_secure]" aria-label="Encryption">
								<option value="tls"<?php selected( $o['smtp_secure'], 'tls' ); ?>>STARTTLS (usually port 587)</option>
								<option value="ssl"<?php selected( $o['smtp_secure'], 'ssl' ); ?>>SSL/TLS (usually port 465)</option>
								<option value="none"<?php selected( $o['smtp_secure'], 'none' ); ?>>No encryption (not recommended)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg_smtp_user">Username</label></th>
						<td><input type="text" id="pdfg_smtp_user" name="<?php echo esc_attr( $n ); ?>[smtp_user]" value="<?php echo esc_attr( $o['smtp_user'] ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdfg_smtp_pass">Password</label></th>
						<td>
							<?php if ( defined( 'PDFG_SMTP_PASS' ) ) : ?>
								<p class="description">The password is set in <code>wp-config.php</code> (<code>PDFG_SMTP_PASS</code>), which is the safest place for it.</p>
							<?php else : ?>
								<input type="password" id="pdfg_smtp_pass" name="<?php echo esc_attr( $n ); ?>[smtp_pass]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_pw ? '&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226; (saved - leave blank to keep)' : ''; ?>">
								<?php if ( $has_pw ) : ?><label style="display:block;margin-top:.4em"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[smtp_pass_clear]" value="1"> Remove the saved password</label><?php endif; ?>
								<p class="description">Stored encrypted. For Gmail / Google Workspace or Microsoft 365, create an "app password" rather than using the normal account password. Tip: for extra safety, define <code>PDFG_SMTP_PASS</code> in <code>wp-config.php</code> instead.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2>Privacy and data</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdfg_privacy">Privacy policy URL</label></th>
						<td>
							<input type="url" id="pdfg_privacy" name="<?php echo esc_attr( $n ); ?>[privacy_url]" value="<?php echo esc_attr( $o['privacy_url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) get_privacy_policy_url() ); ?>">
							<p class="description">Used wherever a consent text contains <code>{privacy_policy}</code>. Leave blank to use the page set under Settings &rarr; Privacy.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Visitor IP address</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[store_ip]" value="1"<?php checked( ! empty( $o['store_ip'] ) ); ?>> Save each visitor's IP address with their lead</label>
						<p class="description">Off by default (it is personal data you may not need). Spam limits work either way.</p></td>
					</tr>
					<tr>
						<th scope="row">Older shortcodes</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[allow_legacy]" value="1"<?php checked( ! empty( $o['allow_legacy'] ) ); ?>> Allow <code>[pdf_gate file="..."]</code> and <code>[pdf_gate id="..."]</code></label>
						<p class="description">Off by default. These let anyone who can edit a page choose any PDF to expose. Use <strong>Brochures</strong> instead, and only switch this on if you still rely on old shortcodes.</p></td>
					</tr>
					<tr>
						<th scope="row">When the plugin is deleted</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[delete_data]" value="1"<?php checked( ! empty( $o['delete_data'] ) ); ?>> Also delete all leads, brochures, settings and uploaded PDFs</label>
						<p class="description">Off by default, so deleting or reinstalling the plugin never loses your leads.</p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Send a test email</h2>
			<p>Uses the settings <strong>as last saved</strong> (save first). It goes to <?php echo $to_now ? '<strong>' . esc_html( implode( ', ', $to_now ) ) . '</strong>' : 'your own admin email'; ?>.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=pdfg_test_mail' ) ); ?>">
				<?php wp_nonce_field( 'pdfg_test_mail' ); ?>
				<?php submit_button( 'Send test email', 'secondary', 'submit', false ); ?>
			</form>

			<h3 style="margin-top:1.5em">Recent email attempts</h3>
			<?php if ( ! $log ) : ?>
				<p class="description">Nothing sent yet. Each lead notification (and test email) will be listed here, with the reason if it failed.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th>When</th><th>To</th><th>Subject</th><th>Result</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $row['t'] ) ); ?></td>
							<td><?php echo esc_html( $row['to'] ); ?></td>
							<td><?php echo esc_html( $row['s'] ); ?></td>
							<td><?php echo ! empty( $row['ok'] ) ? '<span style="color:#1a7f37">Sent</span>' : '<span style="color:#b32d2e">Failed</span>: ' . esc_html( $row['e'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>
			<h2>How it works</h2>
			<ol>
				<li>Go to <a href="<?php echo esc_url( admin_url( 'admin.php?page=pdfg-brochures&action=new' ) ); ?>">PDF Gate &rarr; Brochures &rarr; Add New</a>, upload the PDF and add the questions for it.</li>
				<li>Paste the brochure's shortcode, e.g. <code>[pdf_gate brochure="2"]</code>, into any page or post.</li>
				<li>View and export leads under PDF Gate &rarr; Leads, filtered per brochure.</li>
			</ol>
			<p>Optional shortcode overrides: <code>title</code>, <code>description</code>, <code>button</code>, <code>consent</code>.</p>
			<p><strong>Nginx servers:</strong> the protected-folder rule uses <code>.htaccess</code>, which only works on Apache. On Nginx add <code>location ~ ^/wp-content/uploads/<?php echo esc_html( self::PROTECTED_DIR ); ?>/ { deny all; }</code>. New uploads also get an unguessable file name as a second layer of protection.</p>
			<p><strong>Behind Cloudflare or another proxy?</strong> The spam limit looks at the connecting IP. If every visitor appears to share one IP, a developer can map the real one with the <code>pdfg_client_ip</code> filter.</p>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'PDFG_Plugin', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'PDFG_Plugin', 'uninstall' ) );
new PDFG_Plugin();
