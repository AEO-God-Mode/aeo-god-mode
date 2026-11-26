<?php
/**
 * Site Profile "Fill with AI": reads the site's own name, tagline and key
 * pages, asks the AI service for a best-guess profile, and returns only
 * values that pass the same validation as a typed profile. Nothing is saved
 * here; the customer reviews the suggestions and saves them herself.
 *
 * Costs 1 credit per fill on every plan. The proxy builds the prompt itself,
 * so this class only sends site data, never instructions.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Profile_AI {

	const API_URL = 'https://aeogodmode.io/wp-json/asgm/v1/ai-assist';

	const BUSINESS_TYPES = array(
		'Blog', 'News Publisher', 'E-commerce', 'Local Business', 'SaaS', 'Agency',
		'Membership / Community', 'Education / Course', 'Nonprofit',
		'Portfolio / Personal', 'Affiliate / Reviews', 'Other',
	);

	const LANGUAGES = array(
		'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
		'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch',
	);

	/**
	 * Run one fill. Returns a REST-ready array; 'status' is the HTTP code.
	 *
	 * @return array
	 */
	public static function fill() {
		$key = License::is_pro_build() ? License::get_key() : '';

		$response = wp_remote_post( self::API_URL, array(
			'body'    => wp_json_encode( array(
				'license_key'        => $key,
				'installation_token' => '' === $key ? Account_Connection::get_token() : '',
				'install_id'         => '' === $key ? Account_Connection::get_install_id() : '',
				'task'               => 'site_profile_fill',
				'payload'            => base64_encode( wp_json_encode( self::site_data() ) ),
				'request_id'         => 'spf-' . bin2hex( random_bytes( 12 ) ),
				'site_url'           => home_url(),
			) ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 502,
				'success' => false,
				'error'   => __( 'Could not reach the AI service. Nothing was charged. Try again in a moment.', 'aeo-god-mode' ),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'status'  => 502,
				'success' => false,
				'error'   => __( 'The AI service returned something unreadable. Nothing was charged.', 'aeo-god-mode' ),
			);
		}

		if ( empty( $decoded['success'] ) ) {
			// Pass the credit markers through so the app-wide credits popup opens.
			$out = array(
				'status'  => $code >= 400 ? $code : 400,
				'success' => false,
				'error'   => sanitize_text_field( (string) ( $decoded['error'] ?? __( 'The AI service could not complete that request.', 'aeo-god-mode' ) ) ),
			);
			foreach ( array( 'code', 'status', 'required', 'remaining', 'resets_on', 'plan' ) as $field ) {
				if ( isset( $decoded[ $field ] ) && is_scalar( $decoded[ $field ] ) ) {
					$out[ 'status' === $field ? 'reason' : $field ] = sanitize_text_field( (string) $decoded[ $field ] );
				}
			}
			if ( isset( $out['reason'] ) ) {
				$out['code'] = $out['code'] ?? $out['reason'];
			}
			if ( isset( $decoded['credits'] ) && is_array( $decoded['credits'] ) ) {
				$out['credits'] = $decoded['credits'];
			}
			return $out;
		}

		$result = $decoded['result'] ?? null;
		if ( is_string( $result ) ) {
			$result = json_decode( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $result ) ), true );
		}
		if ( ! is_array( $result ) ) {
			return array(
				'status'  => 502,
				'success' => false,
				'error'   => __( 'The AI answer came back in an unexpected shape. Try again.', 'aeo-god-mode' ),
				'credits' => $decoded['credits'] ?? null,
			);
		}

		return array(
			'status'      => 200,
			'success'     => true,
			'suggestions' => self::validate( $result ),
			'credits'     => $decoded['credits'] ?? null,
		);
	}

	/**
	 * What the AI reads: the site's own public name, tagline and key pages.
	 *
	 * @return array
	 */
	public static function site_data() {
		$pages = array();
		$seen  = array();

		$add = function ( $post, $role ) use ( &$pages, &$seen ) {
			if ( ! $post instanceof \WP_Post || isset( $seen[ $post->ID ] ) || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
				return;
			}
			$seen[ $post->ID ] = true;
			$text = self::plain( strip_shortcodes( (string) $post->post_content ), 2500 );
			if ( '' === $text && '' === $post->post_excerpt ) {
				return;
			}
			$pages[] = array(
				'title' => self::plain( get_the_title( $post ), 160 ),
				'role'  => $role,
				'text'  => '' !== $text ? $text : self::plain( $post->post_excerpt, 2500 ),
			);
		};

		$front = (int) get_option( 'page_on_front' );
		if ( $front ) {
			$add( get_post( $front ), 'homepage' );
		}

		foreach ( array(
			'about'    => array( 'about', 'about-us', 'our-story', 'who-we-are' ),
			'services' => array( 'services', 'what-we-do', 'products', 'shop' ),
			'contact'  => array( 'contact', 'contact-us', 'get-in-touch' ),
		) as $role => $slugs ) {
			foreach ( $slugs as $slug ) {
				$found = get_page_by_path( $slug );
				if ( $found ) {
					$add( $found, $role );
					break;
				}
			}
		}

		$titles = get_posts( array(
			'post_type'              => array( 'post', 'page', 'product', 'download' ),
			'post_status'            => 'publish',
			'posts_per_page'         => 25,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'has_password'           => false,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		return array(
			'site_name'     => self::plain( get_bloginfo( 'name' ), 120 ),
			'tagline'       => self::plain( get_bloginfo( 'description' ), 200 ),
			'domain'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'locale'        => (string) get_locale(),
			'has_shop'      => class_exists( 'WooCommerce' ) || function_exists( 'EDD' ),
			'pages'         => array_slice( $pages, 0, 6 ),
			'recent_titles' => array_values( array_filter( array_map( function ( $id ) {
				return self::plain( get_the_title( $id ), 140 );
			}, $titles ) ) ),
		);
	}

	/**
	 * Keep only values the profile can hold. Anything else becomes empty, so
	 * the customer never sees an invented enum or a malformed country code.
	 *
	 * @param array $r Raw model output.
	 * @return array
	 */
	public static function validate( $r ) {
		$list = function ( $v, $max, $len ) {
			$out = array();
			foreach ( array_slice( is_array( $v ) ? $v : array(), 0, $max ) as $item ) {
				$item = self::plain( is_scalar( $item ) ? $item : '', $len );
				if ( '' !== $item && ! in_array( $item, $out, true ) ) {
					$out[] = $item;
				}
			}
			return $out;
		};
		$country = function ( $v ) {
			$v = strtoupper( trim( is_scalar( $v ) ? (string) $v : '' ) );
			return preg_match( '/^[A-Z]{2}$/', $v ) ? $v : '';
		};

		$type  = is_scalar( $r['business_type'] ?? null ) ? (string) $r['business_type'] : '';
		$scope = is_scalar( $r['service_scope'] ?? null ) ? strtolower( (string) $r['service_scope'] ) : '';
		$lang  = is_scalar( $r['language_code'] ?? null ) ? strtolower( substr( (string) $r['language_code'], 0, 2 ) ) : '';

		$targets = array();
		foreach ( array_slice( is_array( $r['target_countries'] ?? null ) ? $r['target_countries'] : array(), 0, 10 ) as $c ) {
			$c = $country( $c );
			if ( '' !== $c && ! in_array( $c, $targets, true ) ) {
				$targets[] = $c;
			}
		}

		$competitors = array();
		foreach ( array_slice( is_array( $r['competitors'] ?? null ) ? $r['competitors'] : array(), 0, 5 ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$name   = self::plain( $c['name'] ?? '', 160 );
			$domain = strtolower( (string) preg_replace( '#^(https?://)?(www\.)?#i', '', self::plain( $c['domain'] ?? '', 120 ) ) );
			$domain = preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', rtrim( $domain, '/' ) ) ? rtrim( $domain, '/' ) : '';
			if ( '' !== $name || '' !== $domain ) {
				$competitors[] = array( 'name' => $name ?: $domain, 'domain' => $domain );
			}
		}

		return array(
			'business_type'       => in_array( $type, self::BUSINESS_TYPES, true ) ? $type : '',
			'service_scope'       => in_array( $scope, array( 'global', 'national', 'local' ), true ) ? $scope : '',
			'primary_country'     => $country( $r['primary_country'] ?? '' ),
			'target_countries'    => $targets,
			'language_code'       => isset( self::LANGUAGES[ $lang ] ) ? $lang : '',
			'language'            => self::LANGUAGES[ $lang ] ?? '',
			'registered_location' => self::plain( $r['registered_location'] ?? '', 180 ),
			'aliases'             => $list( $r['aliases'] ?? array(), 10, 160 ),
			'products_services'   => $list( $r['products_services'] ?? array(), 8, 180 ),
			'description'         => self::plain( $r['description'] ?? '', 500 ),
			'primary_audience'    => self::plain( $r['primary_audience'] ?? '', 300 ),
			'main_topics'         => $list( $r['main_topics'] ?? array(), 8, 160 ),
			'competitors'         => $competitors,
		);
	}

	/**
	 * Plain text within a budget, with em dashes turned into commas.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Character budget.
	 * @return string
	 */
	private static function plain( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( array( "\u{2014}", "\u{2013}" ), array( ', ', '-' ), $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return mb_strlen( $value ) > $max ? rtrim( mb_substr( $value, 0, $max ) ) : $value;
	}
}
