<?php
/**
 * Canonical customer site profile.
 *
 * Identity, market and competitor data used to be spread across
 * `asgm_settings.business`, `asgm_competitors`, WordPress defaults and
 * feature-specific guesses. This class is the single site-level resolver for
 * every tier. It intentionally lives in Free because Free owns the shared REST
 * layer and admin application.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolve, migrate and sanitize the site's reusable business profile. */
class Site_Profile {

	const OPTION  = 'asgm_site_profile_v1';
	const VERSION = 1;

	/** Return the canonical profile, migrating legacy settings on first use. */
	public static function get() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			$stored = self::migrate_legacy();
		}
		return self::sanitize( $stored );
	}

	/** Save a complete profile and keep legacy readers in sync during rollout. */
	public static function save( $raw, $confirmed = null ) {
		$current = self::get();
		$raw     = is_array( $raw ) ? $raw : array();
		$merged  = self::merge_recursive( $current, $raw );
		if ( isset( $raw['market']['primary_country'] ) && ! array_key_exists( 'search_location_code', (array) $raw['market'] ) ) {
			$merged['market']['search_location_code'] = 0;
		}
		if ( null !== $confirmed ) {
			$merged['confirmed'] = (bool) $confirmed;
		}
		$profile = self::sanitize( $merged );
		$profile['updated_at'] = current_time( 'mysql', true );
		if ( ! empty( $profile['confirmed'] ) && empty( $profile['confirmed_at'] ) ) {
			$profile['confirmed_at'] = $profile['updated_at'];
		}
		update_option( self::OPTION, $profile, false );
		self::sync_legacy( $profile );
		return $profile;
	}

	/**
	 * Keep profile identity current when an older admin bundle saves only the
	 * historical business subtree.
	 */
	public static function merge_legacy_business( $business ) {
		if ( ! is_array( $business ) ) {
			return self::get();
		}
		$profile = self::get();
		$patch   = array( 'identity' => array(), 'market' => array(), 'context' => array() );
		$map = array(
			'name' => array( 'identity', 'business_name' ),
			'url' => array( 'identity', 'canonical_domain' ),
			'type' => array( 'market', 'business_type' ),
			'location' => array( 'market', 'registered_location' ),
			'description' => array( 'context', 'description' ),
		);
		foreach ( $map as $legacy_key => $target ) {
			if ( array_key_exists( $legacy_key, $business ) ) {
				$patch[ $target[0] ][ $target[1] ] = $business[ $legacy_key ];
			}
		}
		return self::save( self::merge_recursive( $profile, $patch ) );
	}

	/** Values suggested from WordPress and installed commerce plugins. */
	public static function suggestions() {
		$profile = self::get();
		$homepage = self::homepage_suggestions();
		$names   = array();
		foreach ( array( 'product', 'download' ) as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			$posts = get_posts( array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => 8,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			foreach ( $posts as $post_id ) {
				$title = sanitize_text_field( get_the_title( $post_id ) );
				if ( '' !== $title ) {
					$names[] = $title;
				}
			}
		}

		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		$aliases   = (array) ( $homepage['aliases'] ?? array() );
		if ( '' !== $site_name && 0 !== strcasecmp( $site_name, (string) $profile['identity']['business_name'] ) ) {
			$aliases[] = $site_name;
		}
		$stem = preg_replace( '/\.[a-z0-9.-]+$/i', '', (string) $profile['identity']['canonical_domain'] );
		if ( is_string( $stem ) && strlen( $stem ) >= 3 ) {
			$aliases[] = $stem;
		}

		return array(
			'aliases'          => self::text_list( $aliases, 20, 120 ),
			'products_services'=> self::text_list( array_merge( $names, (array) ( $homepage['products_services'] ?? array() ) ), 30, 160 ),
			'detected_locale'  => sanitize_text_field( (string) get_locale() ),
			'detected_domain'  => self::domain( home_url() ),
		);
	}

	/** Detect Organization/Product/Service names already emitted on the homepage. */
	private static function homepage_suggestions() {
		$cached = get_transient( 'asgm_site_profile_homepage_suggestions_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array( 'aliases' => array(), 'products_services' => array() );
		$response = wp_safe_remote_get( home_url( '/' ), array(
			'timeout'             => 6,
			'redirection'         => 2,
			'limit_response_size' => 524288,
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( 'asgm_site_profile_homepage_suggestions_v1', $out, HOUR_IN_SECONDS );
			return $out;
		}
		$html = (string) wp_remote_retrieve_body( $response );
		foreach ( array( 'og:site_name', 'application-name' ) as $property ) {
			if ( preg_match( '/<meta[^>]+(?:property|name)=["\']' . preg_quote( $property, '/' ) . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $match )
				|| preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . preg_quote( $property, '/' ) . '["\']/i', $html, $match ) ) {
				$out['aliases'][] = html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			$walk = function ( $node ) use ( &$walk, &$out ) {
				if ( ! is_array( $node ) ) {
					return;
				}
				$type = (array) ( $node['@type'] ?? array() );
				$type = array_map( 'strtolower', array_map( 'strval', $type ) );
				$name = is_scalar( $node['name'] ?? null ) ? (string) $node['name'] : '';
				if ( array_intersect( $type, array( 'organization', 'localbusiness', 'corporation', 'website' ) ) ) {
					$out['aliases'][] = $name;
					foreach ( (array) ( $node['alternateName'] ?? array() ) as $alias ) {
						if ( is_scalar( $alias ) ) {
							$out['aliases'][] = (string) $alias;
						}
					}
				}
				if ( array_intersect( $type, array( 'product', 'service', 'softwareapplication', 'webapplication' ) ) && '' !== $name ) {
					$out['products_services'][] = $name;
				}
				foreach ( $node as $value ) {
					if ( is_array( $value ) ) {
						$walk( $value );
					}
				}
			};
			foreach ( $matches[1] as $json ) {
				$decoded = json_decode( html_entity_decode( trim( $json ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
				if ( is_array( $decoded ) ) {
					$walk( $decoded );
				}
			}
		}
		$out['aliases'] = self::text_list( $out['aliases'], 20, 120 );
		$out['products_services'] = self::text_list( $out['products_services'], 30, 160 );
		set_transient( 'asgm_site_profile_homepage_suggestions_v1', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Completeness contract used by setup and feature-level notices. */
	public static function completeness( $profile = null ) {
		$profile = is_array( $profile ) ? self::sanitize( $profile ) : self::get();
		$missing = array();
		$checks  = array(
			'business_name'   => $profile['identity']['business_name'],
			'canonical_domain'=> $profile['identity']['canonical_domain'],
			'business_type'   => $profile['market']['business_type'],
			'primary_country' => $profile['market']['primary_country'],
			'language'        => $profile['market']['language_code'],
			'service_scope'   => $profile['market']['service_scope'],
			'description'     => $profile['context']['description'],
			'primary_audience'=> $profile['context']['primary_audience'],
		);
		if ( 'local' === $profile['market']['service_scope'] ) {
			$checks['registered_location'] = $profile['market']['registered_location'];
		}
		foreach ( $checks as $key => $value ) {
			if ( '' === trim( (string) $value ) ) {
				$missing[] = $key;
			}
		}
		$complete_count = count( $checks ) - count( $missing );
		return array(
			'confirmed'          => ! empty( $profile['confirmed'] ),
			'complete'           => empty( $missing ) && ! empty( $profile['confirmed'] ),
			'percent'            => (int) round( 100 * $complete_count / count( $checks ) ),
			'missing'            => $missing,
			'competitors_missing'=> empty( self::competitor_domains( $profile ) ),
		);
	}

	/** Exact aliases used for branded-query and mention classification. */
	public static function brand_aliases( $profile = null ) {
		$profile = is_array( $profile ) ? self::sanitize( $profile ) : self::get();
		$values  = array_merge(
			array( $profile['identity']['business_name'], $profile['identity']['canonical_domain'] ),
			(array) $profile['identity']['aliases'],
			(array) $profile['identity']['products_services'],
			(array) $profile['identity']['previous_domains']
		);
		$domain_stems = array();
		foreach ( array_merge( array( $profile['identity']['canonical_domain'] ), (array) $profile['identity']['previous_domains'] ) as $domain ) {
			$stem = preg_replace( '/\.[a-z0-9.-]+$/i', '', (string) $domain );
			if ( is_string( $stem ) && strlen( $stem ) >= 3 ) {
				$domain_stems[] = $stem;
			}
		}
		return self::text_list( array_merge( $values, $domain_stems ), 80, 160 );
	}

	/** Confirmed competitor domains only. */
	public static function competitor_domains( $profile = null ) {
		$profile = is_array( $profile ) ? self::sanitize( $profile ) : self::get();
		$out     = array();
		foreach ( (array) $profile['competitors'] as $competitor ) {
			if ( ! empty( $competitor['confirmed'] ) && '' !== $competitor['domain'] ) {
				$out[] = $competitor['domain'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** DataForSEO-compatible market defaults derived from the confirmed profile. */
	public static function market() {
		$profile = self::get();
		return array(
			'location_code' => (int) $profile['market']['search_location_code'],
			'language_code' => (string) $profile['market']['language_code'],
			'country'       => (string) $profile['market']['primary_country'],
			'target_countries' => (array) $profile['market']['target_countries'],
			'service_scope' => (string) $profile['market']['service_scope'],
		);
	}

	/** Sanitize a full profile and guarantee a stable response shape. */
	public static function sanitize( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$identity = is_array( $raw['identity'] ?? null ) ? $raw['identity'] : array();
		$market   = is_array( $raw['market'] ?? null ) ? $raw['market'] : array();
		$context  = is_array( $raw['context'] ?? null ) ? $raw['context'] : array();
		$domain   = self::domain( $identity['canonical_domain'] ?? home_url() );
		$locale   = self::locale_parts( $market['language_code'] ?? get_locale(), $market['primary_country'] ?? '' );
		$scope    = sanitize_key( (string) ( $market['service_scope'] ?? 'global' ) );
		if ( ! in_array( $scope, array( 'global', 'national', 'local' ), true ) ) {
			$scope = 'global';
		}

		$competitors = array();
		foreach ( array_slice( (array) ( $raw['competitors'] ?? array() ), 0, 20 ) as $candidate ) {
			if ( is_scalar( $candidate ) ) {
				$candidate = array( 'domain' => $candidate, 'name' => $candidate, 'confirmed' => true );
			}
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$candidate_domain = self::domain( $candidate['domain'] ?? '' );
			$name             = self::text( $candidate['name'] ?? $candidate_domain, 160 );
			if ( '' === $candidate_domain && '' === $name ) {
				continue;
			}
			$competitors[] = array(
				'name'      => $name ?: $candidate_domain,
				'domain'    => $candidate_domain,
				'products'  => self::text_list( $candidate['products'] ?? array(), 20, 160 ),
				'confirmed' => ! empty( $candidate['confirmed'] ),
			);
		}

		$country = self::country_code( $market['primary_country'] ?? $locale['country'] );
		$targets = self::country_list( $market['target_countries'] ?? array() );
		if ( empty( $targets ) && '' !== $country ) {
			$targets[] = $country;
		}
		$location_code = absint( $market['search_location_code'] ?? 0 );
		if ( ! $location_code ) {
			$location_code = self::location_code( $country );
		}

		return array(
			'version'      => self::VERSION,
			'confirmed'    => ! empty( $raw['confirmed'] ),
			'confirmed_at' => self::text( $raw['confirmed_at'] ?? '', 40 ),
			'updated_at'   => self::text( $raw['updated_at'] ?? '', 40 ),
			'identity'     => array(
				'business_name'    => self::text( $identity['business_name'] ?? get_bloginfo( 'name' ), 160 ),
				'aliases'          => self::text_list( $identity['aliases'] ?? array(), 40, 160 ),
				'products_services'=> self::text_list( $identity['products_services'] ?? array(), 40, 180 ),
				'canonical_domain' => $domain,
				'previous_domains' => self::domain_list( $identity['previous_domains'] ?? array() ),
			),
			'market'       => array(
				'primary_country'    => $country,
				'target_countries'   => $targets,
				'language'           => self::text( $market['language'] ?? $locale['language_name'], 80 ),
				'language_code'      => $locale['language'],
				'service_scope'      => $scope,
				'business_type'      => self::text( $market['business_type'] ?? 'Blog', 100 ),
				'registered_location'=> self::text( $market['registered_location'] ?? '', 180 ),
				'search_location_code'=> $location_code,
			),
			'competitors' => array_values( $competitors ),
			'context'     => array(
				'description'      => self::textarea( $context['description'] ?? get_bloginfo( 'description' ), 500 ),
				'primary_audience' => self::textarea( $context['primary_audience'] ?? '', 300 ),
				'main_topics'      => self::text_list( $context['main_topics'] ?? array(), 40, 160 ),
			),
		);
	}

	/** Initial one-way migration from the historical settings/options. */
	private static function migrate_legacy() {
		$settings    = get_option( 'asgm_settings', array() );
		$business    = is_array( $settings['business'] ?? null ) ? $settings['business'] : array();
		$competitors = array();
		foreach ( (array) get_option( 'asgm_competitors', array() ) as $domain ) {
			$competitors[] = array( 'name' => $domain, 'domain' => $domain, 'products' => array(), 'confirmed' => true );
		}
		$raw = array(
			'version'   => self::VERSION,
			'confirmed' => false,
			'identity'  => array(
				'business_name'    => $business['name'] ?? get_bloginfo( 'name' ),
				'aliases'          => $business['aliases'] ?? array(),
				'products_services'=> $business['products_services'] ?? array(),
				'canonical_domain' => $business['url'] ?? home_url(),
				'previous_domains' => $business['previous_domains'] ?? array(),
			),
			'market' => array(
				'primary_country'    => $business['country'] ?? '',
				'target_countries'   => $business['target_countries'] ?? array(),
				'language_code'      => $business['language_code'] ?? get_locale(),
				'service_scope'      => $business['service_scope'] ?? ( empty( $business['location'] ) ? 'global' : 'national' ),
				'business_type'      => $business['type'] ?? 'Blog',
				'registered_location'=> $business['location'] ?? '',
				'search_location_code'=> $business['location_code'] ?? 0,
			),
			'competitors' => $competitors,
			'context' => array(
				'description'      => $business['description'] ?? get_bloginfo( 'description' ),
				'primary_audience' => $business['primary_audience'] ?? '',
				'main_topics'      => $business['main_topics'] ?? array(),
			),
		);
		$profile = self::sanitize( $raw );
		update_option( self::OPTION, $profile, false );
		return $profile;
	}

	/** Keep schema/older bundles functional while consumers migrate. */
	private static function sync_legacy( $profile ) {
		$settings = get_option( 'asgm_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$business = is_array( $settings['business'] ?? null ) ? $settings['business'] : array();
		$business = array_merge( $business, array(
			'name'              => $profile['identity']['business_name'],
			'url'               => '' !== $profile['identity']['canonical_domain'] ? 'https://' . $profile['identity']['canonical_domain'] : home_url(),
			'type'              => $profile['market']['business_type'],
			'location'          => $profile['market']['registered_location'],
			'description'       => $profile['context']['description'],
			'aliases'           => $profile['identity']['aliases'],
			'products_services' => $profile['identity']['products_services'],
			'country'           => $profile['market']['primary_country'],
			'target_countries'  => $profile['market']['target_countries'],
			'language_code'     => $profile['market']['language_code'],
			'service_scope'     => $profile['market']['service_scope'],
			'primary_audience'  => $profile['context']['primary_audience'],
			'main_topics'       => $profile['context']['main_topics'],
		) );
		$settings['business'] = $business;
		update_option( 'asgm_settings', $settings );
		update_option( 'asgm_competitors', self::competitor_domains( $profile ), false );
	}

	private static function merge_recursive( $base, $patch ) {
		foreach ( (array) $patch as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $value ) ) {
				$base[ $key ] = self::merge_recursive( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	private static function is_list( $value ) {
		if ( empty( $value ) ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function text( $value, $limit ) {
		return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	private static function textarea( $value, $limit ) {
		return mb_substr( sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	private static function text_list( $values, $limit, $length ) {
		if ( is_string( $values ) ) {
			$values = preg_split( '/[\r\n,]+/', $values );
		}
		$out = array();
		foreach ( array_slice( (array) $values, 0, $limit ) as $value ) {
			$value = self::text( $value, $length );
			$key   = mb_strtolower( $value );
			if ( '' !== $value && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $value;
			}
		}
		return array_values( $out );
	}

	private static function domain( $value ) {
		$value = trim( is_scalar( $value ) ? (string) $value : '' );
		if ( '' === $value ) {
			return '';
		}
		$url  = preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value;
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = strtolower( preg_replace( '#^www\.#i', '', $host ) );
		return preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';
	}

	private static function domain_list( $values ) {
		$out = array();
		foreach ( array_slice( (array) $values, 0, 20 ) as $value ) {
			$domain = self::domain( $value );
			if ( '' !== $domain ) {
				$out[] = $domain;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function country_list( $values ) {
		if ( is_string( $values ) ) {
			$values = preg_split( '/[\r\n,]+/', $values );
		}
		$out = array();
		foreach ( array_slice( (array) $values, 0, 20 ) as $value ) {
			$code = self::country_code( $value );
			if ( '' !== $code ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function country_code( $value ) {
		$value = strtoupper( trim( is_scalar( $value ) ? (string) $value : '' ) );
		$map   = array(
			'UNITED KINGDOM' => 'GB', 'UK' => 'GB', 'GREAT BRITAIN' => 'GB',
			'UNITED STATES' => 'US', 'USA' => 'US', 'UNITED STATES OF AMERICA' => 'US',
			'AUSTRALIA' => 'AU', 'CANADA' => 'CA', 'NEW ZEALAND' => 'NZ',
			'IRELAND' => 'IE', 'INDIA' => 'IN', 'SOUTH AFRICA' => 'ZA',
		);
		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}
		return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
	}

	private static function locale_parts( $language, $country ) {
		$locale = str_replace( '-', '_', strtolower( trim( (string) $language ) ) );
		$parts  = explode( '_', $locale );
		$lang   = preg_match( '/^[a-z]{2}$/', $parts[0] ?? '' ) ? $parts[0] : 'en';
		$names  = array( 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch' );
		$detected_country = self::country_code( $country );
		if ( '' === $detected_country && ! empty( $parts[1] ) ) {
			$detected_country = self::country_code( $parts[1] );
		}
		return array( 'language' => $lang, 'language_name' => $names[ $lang ] ?? strtoupper( $lang ), 'country' => $detected_country ?: 'US' );
	}

	private static function location_code( $country ) {
		$codes = array( 'US' => 2840, 'GB' => 2826, 'AU' => 2036, 'CA' => 2124, 'NZ' => 2554, 'IE' => 2372, 'IN' => 2356, 'ZA' => 2710 );
		return (int) ( $codes[ $country ] ?? 2840 );
	}
}
