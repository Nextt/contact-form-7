<?php

class WPCF7_ContactForm {

	var $initial = false;

	var $id;
	var $title;
	var $form;
	var $mail;
	var $mail_2;
	var $messages;
	var $additional_settings;

	var $unit_tag;

	var $responses_count = 0;
	var $scanned_form_tags;

	// Return true if this form is the same one as currently POSTed.
	function is_posted() {
		if ( ! isset( $_POST['_wpcf7_unit_tag'] ) || empty( $_POST['_wpcf7_unit_tag'] ) )
			return false;

		if ( $this->unit_tag == $_POST['_wpcf7_unit_tag'] )
			return true;

		return false;
	}

	/* Generating Form HTML */

	function form_html() {
		$form = '<div class="wpcf7" id="' . $this->unit_tag . '">';

		$url = parse_url( $_SERVER['REQUEST_URI'] );
		$url = $url['path'] . ( empty( $url['query'] ) ? '' : '?' . $url['query'] ) . '#' . $this->unit_tag;

		$multipart = (bool) $this->form_scan_shortcode(
			array( 'type' => array( 'file', 'file*' ) ) );

		$enctype = $multipart ? ' enctype="multipart/form-data"' : '';

		$form .= '<form action="' . $url . '" method="post" class="wpcf7-form"' . $enctype . '>';
		$form .= '<div style="display: none;">';
		$form .= '<input type="hidden" name="_wpcf7" value="' . $this->id . '" />';
		$form .= '<input type="hidden" name="_wpcf7_version" value="' . WPCF7_VERSION . '" />';
		$form .= '<input type="hidden" name="_wpcf7_unit_tag" value="' . $this->unit_tag . '" />';
		$form .= '</div>';
		$form .= $this->form_elements();

		if ( ! $this->responses_count )
			$form .= $this->form_response_output();

		$form .= '</form>';

		$form .= '</div>';

		if ( WPCF7_AUTOP )
			$form = wpcf7_wpautop_substitute( $form );

		return $form;
	}

	function form_response_output() {
		$class = 'wpcf7-response-output';

		if ( $this->is_posted() ) { // Post response output for non-AJAX
			if ( isset( $_POST['_wpcf7_mail_sent'] ) && $_POST['_wpcf7_mail_sent']['id'] == $this->id ) {
				if ( $_POST['_wpcf7_mail_sent']['ok'] ) {
					$class .= ' wpcf7-mail-sent-ok';
					$content = $_POST['_wpcf7_mail_sent']['message'];
				} else {
					$class .= ' wpcf7-mail-sent-ng';
					if ( $_POST['_wpcf7_mail_sent']['spam'] )
						$class .= ' wpcf7-spam-blocked';
					$content = $_POST['_wpcf7_mail_sent']['message'];
				}
			} elseif ( isset( $_POST['_wpcf7_validation_errors'] ) && $_POST['_wpcf7_validation_errors']['id'] == $this->id ) {
				$class .= ' wpcf7-validation-errors';
				$content = $this->message( 'validation_error' );
			}
		} else {
			$class .= ' wpcf7-display-none';
		}

		$class = ' class="' . $class . '"';

		return '<div' . $class . '>' . $content . '</div>';
	}

	function validation_error( $name ) {
		if ( $this->is_posted() && $ve = $_POST['_wpcf7_validation_errors']['messages'][$name] )
			return '<span class="wpcf7-not-valid-tip-no-ajax">' . esc_html( $ve ) . '</span>';

		return '';
	}

	/* Form Elements */

	function form_do_shortcode() {
		global $wpcf7_shortcode_manager;

		$form = $wpcf7_shortcode_manager->do_shortcode( $this->form );
		$this->scanned_form_tags = $wpcf7_shortcode_manager->scanned_tags;
		return $form;
	}

	function form_scan_shortcode( $cond = null ) {
		global $wpcf7_shortcode_manager;

		if ( ! empty( $this->scanned_form_tags ) ) {
			$scanned = $this->scanned_form_tags;
		} else {
			$scanned = $wpcf7_shortcode_manager->scan_shortcode( $this->form );
			$this->scanned_form_tags = $scanned;
		}

		if ( empty( $scanned ) )
			return null;

		if ( ! is_array( $cond ) || empty( $cond ) )
			return $scanned;

		for ( $i = 0, $size = count( $scanned ); $i < $size; $i++ ) {

			if ( is_string( $cond['type'] ) && ! empty( $cond['type'] ) ) {
				if ( $scanned[$i]['type'] != $cond['type'] ) {
					unset( $scanned[$i] );
					continue;
				}
			} elseif ( is_array( $cond['type'] ) ) {
				if ( ! in_array( $scanned[$i]['type'], $cond['type'] ) ) {
					unset( $scanned[$i] );
					continue;
				}
			}

			if ( is_string( $cond['name'] ) && ! empty( $cond['name'] ) ) {
				if ( $scanned[$i]['name'] != $cond['name'] ) {
					unset ( $scanned[$i] );
					continue;
				}
			} elseif ( is_array( $cond['name'] ) ) {
				if ( ! in_array( $scanned[$i]['name'], $cond['name'] ) ) {
					unset( $scanned[$i] );
					continue;
				}
			}
		}

		return array_values( $scanned );
	}

	function form_elements() {
		$form = $this->form_do_shortcode();

		// Submit button
		$submit_regex = '%\[\s*submit(\s[-0-9a-zA-Z:#_/\s]*)?(\s+(?:"[^"]*"|\'[^\']*\'))?\s*\]%';
		$form = preg_replace_callback( $submit_regex,
			array( &$this, 'submit_replace_callback' ), $form );

		// Response output
		$response_regex = '%\[\s*response\s*\]%';
		$form = preg_replace_callback( $response_regex,
			array( &$this, 'response_replace_callback' ), $form );

		return $form;
	}

	function submit_replace_callback( $matches ) {
		$atts = '';
		$options = preg_split( '/[\s]+/', trim( $matches[1] ) );

		$id_att = '';
		$class_att = '';

		foreach ( $options as $option ) {
			if ( preg_match( '%^id:([-0-9a-zA-Z_]+)$%', $option, $op_matches ) ) {
				$id_att = $op_matches[1];

			} elseif ( preg_match( '%^class:([-0-9a-zA-Z_]+)$%', $option, $op_matches ) ) {
				$class_att .= ' ' . $op_matches[1];
			}
		}

		if ( $id_att )
			$atts .= ' id="' . trim( $id_att ) . '"';

		if ( $class_att )
			$atts .= ' class="' . trim( $class_att ) . '"';

		if ( $matches[2] )
			$value = wpcf7_strip_quote( $matches[2] );
		if ( empty( $value ) )
			$value = __( 'Send', 'wpcf7' );
		$ajax_loader_image_url = wpcf7_plugin_url( 'images/ajax-loader.gif' );

		$html = '<input type="submit" value="' . esc_attr( $value ) . '"' . $atts . ' />';
		$html .= ' <img class="ajax-loader" style="visibility: hidden;" alt="ajax loader" src="' . $ajax_loader_image_url . '" />';
		return $html;
	}

	function response_replace_callback( $matches ) {
		$this->responses_count += 1;
		return $this->form_response_output();
	}

	/* Mail + Pipe */

	function pipe_all_posted() {
		global $wpcf7_posted_data;

		$fes = $contact_form->form_scan_shortcode();

		foreach ( $fes as $fe ) {
			$name = $fe['name'];
			$pipes = $fe['pipes'];

			if ( is_a( $pipes, 'WPCF7_Pipes' ) && ! $pipes->zero() ) {
				if ( isset( $wpcf7_posted_data[$name] ) )
					$wpcf7_posted_data[$name] = $pipes->do_pipe( $wpcf7_posted_data[$name] );
			}
		}
	}

	/* Validate */

	function validate() {
		$fes = $this->form_scan_shortcode();

		$result = array( 'valid' => true, 'reason' => array() );

		foreach ( $fes as $fe ) {
			$type = $fe['type'];
			$name = $fe['name'];
			$values = $fe['values'];
			$raw_values = $fe['raw_values'];

			// Before validation corrections
			if ( preg_match( '/^(?:text|email|captchar|textarea)[*]?$/', $type ) )
				$_POST[$name] = (string) $_POST[$name];

			if ( preg_match( '/^(?:select|checkbox|radio)[*]?$/', $type ) ) {
				if ( is_array( $_POST[$name] ) ) {
					foreach ( $_POST[$name] as $key => $value ) {
						$value = stripslashes( $value );
						if ( ! in_array( $value, (array) $values ) ) // Not in given choices.
							unset( $_POST[$name][$key] );
					}
				} else {
					$value = stripslashes( $_POST[$name] );
					if ( ! in_array( $value, (array) $values ) ) //  Not in given choices.
						$_POST[$name] = '';
				}
			}

			if ( 'acceptance' == $type )
				$_POST[$name] = $_POST[$name] ? 1 : 0;

			$result = apply_filters( 'wpcf7_validate_' . $type, $result, $fe );

			if ( 'checkbox*' == $type ) {
				if ( empty( $_POST[$name] ) ) {
					$result['valid'] = false;
					$result['reason'][$name] = $this->message( 'invalid_required' );
				}
			}

			if ( 'select*' == $type ) {
				if ( empty( $_POST[$name] ) ||
						! is_array( $_POST[$name] ) && '---' == $_POST[$name] ||
						is_array( $_POST[$name] ) && 1 == count( $_POST[$name] ) && '---' == $_POST[$name][0] ) {
					$result['valid'] = false;
					$result['reason'][$name] = $this->message( 'invalid_required' );
				}
			}

			if ( preg_match( '/^captchar$/', $type ) ) {
				$captchac = '_wpcf7_captcha_challenge_' . $name;
				if ( ! wpcf7_check_captcha( $_POST[$captchac], $_POST[$name] ) ) {
					$result['valid'] = false;
					$result['reason'][$name] = $this->message( 'captcha_not_match' );
				}
				wpcf7_remove_captcha( $_POST[$captchac] );
			}

			if ( 'quiz' == $type ) {
				$answer = wpcf7_canonicalize( $_POST[$name] );
				$answer_hash = wp_hash( $answer, 'wpcf7_quiz' );
				$expected_hash = $_POST['_wpcf7_quiz_answer_' . $name];
				if ( $answer_hash != $expected_hash ) {
					$result['valid'] = false;
					$result['reason'][$name] = $this->message( 'quiz_answer_not_correct' );
				}
			}
		}

		return $result;
	}

	/* Message */

	function message( $status ) {
		$messages = $this->messages;

		if ( ! is_array( $messages ) || ! isset( $messages[$status] ) )
			return wpcf7_default_message( $status );

		return $messages[$status];
	}

	/* Additional settings */

	function additional_setting( $name, $max = 1 ) {
		$tmp_settings = (array) explode( "\n", $this->additional_settings );

		$count = 0;
		$values = array();

		foreach ( $tmp_settings as $setting ) {
			if ( preg_match('/^([a-zA-Z0-9_]+)\s*:(.*)$/', $setting, $matches ) ) {
				if ( $matches[1] != $name )
					continue;

				if ( ! $max || $count < (int) $max ) {
					$values[] = trim( $matches[2] );
					$count += 1;
				}
			}
		}

		return $values;
	}

	/* Upgrade */

	function upgrade() {
		if ( ! isset( $this->mail['recipient'] ) )
			$this->mail['recipient'] = get_option( 'admin_email' );


		if ( ! is_array( $this->messages ) )
			$this->messages = array();

		$messages = array(
			'mail_sent_ok', 'mail_sent_ng', 'akismet_says_spam', 'validation_error', 'accept_terms',
			'invalid_email', 'invalid_required', 'captcha_not_match', 'upload_failed', 'upload_file_type_invalid',
			'upload_file_too_large', 'quiz_answer_not_correct' );

		foreach ($messages as $message) {
			if ( ! isset( $this->messages[$message] ) )
				$this->messages[$message] = wpcf7_default_message( $message );
		}
	}

	/* Save */

	function save() {
		global $wpdb;

		$table_name = wpcf7_table_name();

		if ( $this->initial ) {
			$result = $wpdb->insert( $table_name, array(
				'title' => $this->title,
				'form' => maybe_serialize( $this->form ),
				'mail' => maybe_serialize( $this->mail ),
				'mail_2' => maybe_serialize ( $this->mail_2 ),
				'messages' => maybe_serialize( $this->messages ),
				'additional_settings' => maybe_serialize( $this->additional_settings ) ) );

			if ( $result ) {
				$this->initial = false;
				$this->id = $wpdb->insert_id;

				do_action_ref_array( 'wpcf7_after_create', array( &$this ) );
			} else {
				return false; // Failed to save
			}

		} else { // Update
			if ( ! (int) $this->id )
				return false; // Missing ID

			$result = $wpdb->update( $table_name, array(
				'title' => $this->title,
				'form' => maybe_serialize( $this->form ),
				'mail' => maybe_serialize( $this->mail ),
				'mail_2' => maybe_serialize ( $this->mail_2 ),
				'messages' => maybe_serialize( $this->messages ),
				'additional_settings' => maybe_serialize( $this->additional_settings )
				), array( 'cf7_unit_id' => absint( $this->id) ) );

			if ( false !== $result ) {
				do_action_ref_array( 'wpcf7_after_update', array( &$this ) );
			} else {
				return false; // Failed to save
			}
		}

		do_action_ref_array( 'wpcf7_after_save', array( &$this ) );
		return true; // Succeeded to save
	}

	function copy() {
		$new = new WPCF7_ContactForm();
		$new->initial = true;

		$new->title = $this->title . '_copy';
		$new->form = $this->form;
		$new->mail = $this->mail;
		$new->mail_2 = $this->mail_2;
		$new->messages = $this->messages;
		$new->additional_settings = $this->additional_settings;

		return $new;
	}

	function delete() {
		global $wpdb;

		if ( $this->initial )
			return;

		$table_name = wpcf7_table_name();

		$query = $wpdb->prepare(
			"DELETE FROM $table_name WHERE cf7_unit_id = %d LIMIT 1",
			absint( $this->id ) );

		$wpdb->query( $query );

		$this->initial = true;
		$this->id = null;
	}

}

function wpcf7_contact_form( $id ) {
	global $wpdb;

	$table_name = wpcf7_table_name();

	$id = (int) $id;

	$query = $wpdb->prepare( "SELECT * FROM $table_name WHERE cf7_unit_id = %d", $id );

	if ( ! $row = $wpdb->get_row( $query ) )
		return false; // No data

	$contact_form = new WPCF7_ContactForm();
	$contact_form->id = $row->cf7_unit_id;
	$contact_form->title = stripslashes_deep( $row->title );
	$contact_form->form = stripslashes_deep( maybe_unserialize( $row->form ) );
	$contact_form->mail = stripslashes_deep( maybe_unserialize( $row->mail ) );
	$contact_form->mail_2 = stripslashes_deep( maybe_unserialize( $row->mail_2 ) );
	$contact_form->messages = stripslashes_deep( maybe_unserialize( $row->messages ) );
	$contact_form->additional_settings = stripslashes_deep( maybe_unserialize( $row->additional_settings ) );

	$contact_form->upgrade();

	return $contact_form;
}

function wpcf7_contact_form_default_pack() {
	$contact_form = new WPCF7_ContactForm();
	$contact_form->initial = true;

	$contact_form->title = __( 'Untitled', 'wpcf7' );
	$contact_form->form = wpcf7_default_form_template();
	$contact_form->mail = wpcf7_default_mail_template();
	$contact_form->mail_2 = wpcf7_default_mail_2_template();
	$contact_form->messages = wpcf7_default_messages_template();

	return $contact_form;
}

?>