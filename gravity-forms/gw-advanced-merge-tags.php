<?php
/**
 * Gravity Wiz // Gravity Forms // Advanced Merge Tags
 *
 * Adds support for several advanced merge tags:
 *   + post:id=xx&prop=xxx
 *       retrieve the desired property of the specified post (by ID)
 *   + post_meta:id=xx&meta_key=xxx
 *       retrieve the desired post meta value from the specified post and meta key
 *   + get() modifier
 *       retrieve the desired property from the query string ($_GET)
 *       Example: post_meta:id=get(xx)&meta_key=xxx
 *   + post() modifier
 *       retrieve the enclosed property from the $_POST
 *       Example: post_meta:id=post(xx)&meta_key=xxx
 *   + get:xxx
 *      retrieve property from query string
 *   + HTML fields
 *      {HTML:3}
 *      {all_fields:allowHtmlFields}
 *
 * Coming soon...
 *   + {Address:1}
 *        Output values from all Address inputs.
 *   + {Name:1}
 *        Output values from all Name inputs.
 *   + {Date:1:mdy}
 *        Format date field output: https://gist.github.com/spivurno/f1fb2f0f3650d63acfb5ed644296abda
 *
 * Use Cases
 *
 *   + You have a multiple realtors each represented by their own WordPress page. On each page is a "Contact this Realtor"
 *       link. The user clicks the link and is directed to a contact form. Rather than creating a host of different
 *       contact forms for each realtor, you can use this snippet to populate a HTML field with a bit of text like:
 *       "You are contacting realtor Bob Smith" except instead of Bob Smith, you would use "{post:id=pid&prop=post_title}.
 *       In this example, "pid" would be passed via the query string from the contact link and "Bob Smith" would be the
 *       "post_title" of the post the user is coming from.
 *
 * @version 1.2
 * @author  David Smith <david@gravitywiz.com>
 * @license GPL-2.0+
 * @link    https://gravitywiz.com/
 *
 * Plugin Name: Gravity Forms Advanced Merge Tags
 * Plugin URI: https://gravitywiz.com
 * Description: Provides a host of new ways to work with Gravity Forms merge tags.
 * Version: 1.1
 * Author: Gravity Wiz
 * Author URI: https://gravitywiz.com/
 */
class GW_Advanced_Merge_Tags {

	/**
	 * @TODO:
	 *   - add support for validating based on the merge tag (to prevent values from being changed)
	 *   - add support for merge tags in dynamic population parameters
	 *   - add merge tag builder
	 */

	private $_args = null;

	public static $instance = null;

	public static function get_instance( $args ) {

		if ( null == self::$instance ) {
			self::$instance = new self( $args );
		}

		return self::$instance;
	}

	private function __construct( $args ) {

		if ( ! class_exists( 'GFForms' ) ) {
			return;
		}

		$this->_args = wp_parse_args( $args, array(
			'save_source_post_id' => false,
		) );

		add_action( 'gform_pre_render', array( $this, 'support_default_value_and_html_content_merge_tags' ) );
		add_action( 'gform_pre_render', array( $this, 'support_dynamic_population_merge_tags' ) );

		add_action( 'gform_merge_tag_filter', array( $this, 'support_html_field_merge_tags' ), 10, 4 );
		add_action( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 3 );
		add_action( 'gform_pre_replace_merge_tags', array( $this, 'replace_get_variables' ), 10, 5 );
		add_action( 'gform_merge_tag_filter', array( $this, 'handle_field_modifiers' ), 10, 6 );

		if ( $this->_args['save_source_post_id'] ) {
			add_filter( 'gform_entry_created', array( $this, 'save_source_post_id' ), 10, 2 );
		}

	}

	public function support_default_value_and_html_content_merge_tags( $form ) {

		$current_page = max( 1, (int) rgars( GFFormDisplay::$submission, "{$form['id']}/page_number" ) );
		$fields       = array();

		foreach ( $form['fields'] as &$field ) {

			//            $default_value = rgar( $field, 'defaultValue' );
			//            preg_match_all( '/{.+}/', $default_value, $matches, PREG_SET_ORDER );
			//            if( ! empty( $matches ) ) {
			//                if( rgar( $field, 'pageNumber' ) != $current_page ) {
			//                    $field['defaultValue'] = '';
			//                } else {
			//                    $field['defaultValue'] = $this->replace_merge_tags( $default_value, $form, null );
			//                }
			//            }

			// only run 'content' filter for fields on the current page
			//            if( rgar( $field, 'pageNumber' ) != $current_page )
			//                continue;
			//
			//            $html_content = rgar( $field, 'content' );
			//            preg_match_all( '/{.+}/', $html_content, $matches, PREG_SET_ORDER );
			//            if( ! empty( $matches ) )
			//                $field['content'] = $this->replace_merge_tags( $html_content, $form, null );

		}

		return $form;
	}

	public function support_dynamic_population_merge_tags( $form ) {

		$filter_names = array();

		foreach ( $form['fields'] as &$field ) {

			if ( ! rgar( $field, 'allowsPrepopulate' ) ) {
				continue;
			}

			// complex fields store inputName in the "name" property of the inputs array
			if ( is_array( rgar( $field, 'inputs' ) ) && $field['type'] != 'checkbox' ) {
				foreach ( $field['inputs'] as $input ) {
					if ( rgar( $input, 'name' ) ) {
						$filter_names[] = array(
							'type' => $field['type'],
							'name' => rgar( $input, 'name' ),
						);
					}
				}
			} else {
				$filter_names[] = array(
					'type' => $field['type'],
					'name' => rgar( $field, 'inputName' ),
				);
			}
		}

		foreach ( $filter_names as $filter_name ) {

			// do standard GF prepop replace first...
			$filtered_name = GFCommon::replace_variables_prepopulate( $filter_name['name'] );

			// if default prepop doesn't find anything, do our advanced replace
			if ( $filter_name['name'] == $filtered_name ) {
				$filtered_name = $this->replace_merge_tags( $filter_name['name'], $form, null );
			}

			if ( $filter_name['name'] == $filtered_name ) {
				continue;
			}

			add_filter( "gform_field_value_{$filter_name['name']}", function() use ( $filtered_name ) {
				return (string) $filtered_name;
			} );
		}

		return $form;
	}

	public function replace_merge_tags( $text, $form, $entry ) {

		// at some point GF started passing a pre-submission generated entry, it will have a null ID
		if ( rgar( $entry, 'id' ) == null ) {
			$entry = null;
		}

		// matches {Label:#fieldId#}
		//         {Label:#fieldId#:#options#}
		//         {Custom:#options#}
		while ( preg_match_all( '/{(\w+)(:([\w&,=)(\-]+)){1,2}}/mi', $text, $matches, PREG_SET_ORDER ) ) {

			foreach ( $matches as $match ) {

				list( $tag, $type, $args_match, $args_str ) = array_pad( $match, 4, false );
				parse_str( $args_str, $args );

				$args  = array_map( array( $this, 'check_for_value_modifiers' ), $args );
				$value = '';

				switch ( $type ) {
					case 'post':
						$value = $this->get_post_merge_tag_value( $args );
						break;
					case 'post_meta':
					case 'custom_field':
						$value = $this->get_post_meta_merge_tag_value( $args );
						break;
					case 'entry':
						$args['entry'] = $entry;
						$value         = $this->get_entry_merge_tag_value( $args );
						break;
					case 'entry_meta':
						$args['entry'] = $entry;
						$value         = $this->get_entry_meta_merge_tag_value( $args );
						break;
					case 'callback':
						$args['callback'] = array_shift( array_keys( $args ) );
						unset( $args[ $args['callback'] ] );
						$args['entry'] = $entry;
						$value         = $this->get_callback_merge_tag_value( $args );
						break;
				}

				// @todo: figure out if/how to support values that are not strings
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = '';
				}

				$text = str_replace( $tag, $value, $text );

			}
		}

		return $text;
	}

	public function save_source_post_id( $entry, $form ) {

		if ( is_singular() && ! rgget( 'gf_page' ) ) {
			$post_id = get_queried_object_id();
			gform_update_meta( $entry['id'], 'source_post_id', $post_id );
		}

	}

	public function check_for_value_modifiers( $text ) {

		// modifier regex (i.e. "get(value)")
		preg_match_all( '/([a-z]+)\(([a-z_\-]+)\)/mi', $text, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) {
			return $text;
		}

		foreach ( $matches as $match ) {

			list( $tag, $type, $arg ) = array_pad( $match, 3, false );
			$value                    = '';

			switch ( $type ) {
				case 'get':
					$value = rgget( $arg );
					break;
				case 'post':
					$value = rgpost( $arg );
					break;
			}

			$text = str_replace( $tag, $value, $text );

		}

		return $text;
	}

	public function get_post_merge_tag_value( $args ) {

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( wp_parse_args( $args, array(
			'id'   => false,
			'prop' => false,
		) ) );

		if ( ! $id || ! $prop ) {
			return '';
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}

		return isset( $post->$prop ) ? $post->$prop : '';
	}

	public function get_post_meta_merge_tag_value( $args ) {

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( wp_parse_args( $args, array(
			'id'       => false,
			'meta_key' => false,
		) ) );

		if ( ! $id || ! $meta_key ) {
			return '';
		}

		$value = get_post_meta( $id, $meta_key, true );

		return $value;
	}

	public function get_entry_merge_tag_value( $args ) {

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( wp_parse_args( $args, array(
			'id'    => false,
			'prop'  => false,
			'entry' => false,
		) ) );

		if ( ! $entry ) {

			if ( ! $id ) {
				$id = rgget( 'eid' );
			}

			if ( is_callable( 'gw_post_content_merge_tags' ) ) {
				$id = gw_post_content_merge_tags()->maybe_decrypt_entry_id( $id );
			}

			$entry = GFAPI::get_entry( $id );

		}

		if ( ! $prop ) {
			$prop = key( $args );
		}

		if ( ! $entry || is_wp_error( $entry ) || ! $prop ) {
			return '';
		}

		$value = rgar( $entry, $prop );

		return $value;
	}

	public function get_entry_meta_merge_tag_value( $args ) {

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( wp_parse_args( $args, array(
			'id'       => false,
			'meta_key' => false,
			'entry'    => false,
		) ) );

		if ( ! $id ) {
			if ( rgget( 'eid' ) ) {
				$id = rgget( 'eid' );
			} elseif ( isset( $entry['id'] ) ) {
				$id = $entry['id'];
			}
		}

		if ( ! $meta_key ) {
			$meta_key = key( $args );
		}

		if ( ! $id || ! $meta_key ) {
			return '';
		}

		if ( is_callable( 'gw_post_content_merge_tags' ) ) {
			$id = gw_post_content_merge_tags()->maybe_decrypt_entry_id( $id );
		}

		$value = gform_get_meta( $id, $meta_key );

		return $value;
	}

	public function get_callback_merge_tag_value( $args ) {

		$callback = $args['callback'];
		unset( $args['callback'] );

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( wp_parse_args( $args, array(
			'entry' => false,
		) ) );

		if ( ! is_callable( $callback ) ) {
			return '';
		}

		return call_user_func( $callback, $args );
	}

	/**
	 * Replace {get:xxx} merge tags. Thanks, Gravity View!
	 *
	 * @param       $text
	 * @param array $form
	 * @param array $entry
	 * @param bool $url_encode
	 *
	 * @return mixed
	 */
	public function replace_get_variables( $text, $form, $entry, $url_encode, $esc_html, $get = null ) {

		if ( $get === null ) {
			$get = $_GET;
		}

		preg_match_all( '/{get:(.*?)}/ism', $text, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) {
			return $text;
		}

		foreach ( $matches as $match ) {

			list( $search, $property ) = $match;

			$value = stripslashes_deep( rgget( $property, $get ) );

			$glue  = gf_apply_filters( array( 'gpamt_get_glue', $property ), ', ', $property );
			$value = is_array( $value ) ? implode( $glue, $value ) : $value;
			$value = $url_encode ? urlencode( $value ) : $value;

			$esc_html = gf_apply_filters( array( 'gpamt_get_esc_html', $property ), $esc_html );
			$value    = $esc_html ? esc_html( $value ) : $value;

			$value = gf_apply_filters( array( 'gpamt_get_value', $property ), $value, $text, $form, $entry );

			$text = str_replace( $search, $value, $text );
		}

		return $text;
	}

	public function support_html_field_merge_tags( $value, $tag, $modifiers, $field ) {
		if ( $field->type == 'html' && ( $tag != 'all_fields' || in_array( 'allowHtmlFields', explode( ',', $modifiers ) ) ) ) {
			$value = $field->content;
		}

		return $value;
	}

	/**
	 * @param $value
	 * @param $input_id
	 * @param $modifier
	 * @param \GF_Field $field
	 * @param $raw_value
	 * @param $format
	 *
	 * @return mixed|void
	 */
	public function handle_field_modifiers( $value, $input_id, $modifier, $field, $raw_value, $format ) {

		$modifiers = $field->get_modifiers();
		if ( empty( $modifiers ) ) {
			return $value;
		}

		foreach ( $modifiers as $modifier ) {
			switch ( $modifier ) {
				case 'wordcount':
					// Note: str_word_count() is not a great solution as it does not support characters with accents reliably.
					// Updated to use the same method we use in GP Pay Per Word.
					return count( array_filter( preg_split( '/[ \n\r]+/', trim( $value ) ) ) );
					break;
				case 'urlencode':
					return urlencode( $value );
					break;
				case 'rawurlencode':
					return rawurlencode( $value );
					break;
			}
		}

		return $value;
	}

}

function gw_advanced_merge_tags( $args = array() ) {
	return GW_Advanced_Merge_Tags::get_instance( $args );
}

gw_advanced_merge_tags( array(
	'save_source_post_id' => false,
) );
