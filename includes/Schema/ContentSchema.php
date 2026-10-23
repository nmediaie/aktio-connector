<?php
/**
 * Schema treści: Organization/LocalBusiness, WebSite, WebPage, Article, BreadcrumbList.
 * Dokłada węzły tylko gdy Aktio „posiada” content_schema (żadna wtyczka SEO jej nie dostarcza).
 *
 * @package AktioConnector
 */

namespace AktioConnector\Schema;

use AktioConnector\Detector;
use AktioConnector\Language;
use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class ContentSchema {

	public static function register(): void {
		add_filter( Graph::FILTER, array( __CLASS__, 'add_nodes' ) );
		add_filter( 'woocommerce_structured_data_breadcrumblist', array( __CLASS__, 'suppress_wc_breadcrumb' ) );
	}

	/**
	 * Na stronach, gdzie Aktio samo dodaje BreadcrumbList (wpis/strona/produkt,
	 * nie strona główna), wycina okruszki WooCommerce, żeby lista była jedna.
	 * Pusta tablica sprawia, że WC_Structured_Data::set_data() odrzuca węzeł.
	 */
	public static function suppress_wc_breadcrumb( $markup ) {
		if ( Detector::owns( 'content_schema' ) && is_singular() && ! is_front_page() ) {
			return array();
		}
		return $markup;
	}

	public static function add_nodes( $nodes ) {
		if ( ! is_array( $nodes ) || ! Detector::owns( 'content_schema' ) ) {
			return $nodes;
		}
		$nodes[] = self::organization();
		$nodes[] = self::website();

		if ( is_front_page() || is_singular() ) {
			$post = get_queried_object();
			$url  = is_front_page() ? home_url( '/' ) : ( $post instanceof \WP_Post ? get_permalink( $post ) : '' );
			if ( $url ) {
				$nodes[] = self::webpage( $post instanceof \WP_Post ? $post : null, $url );
				if ( $post instanceof \WP_Post && ! is_front_page() ) {
					$nodes[] = self::breadcrumb( $post, $url );
					if ( 'post' === $post->post_type ) {
						$nodes[] = self::article( $post, $url );
					}
				}
			}
		}
		return $nodes;
	}

	public static function organization(): array {
		$c    = Settings::get()['company'];
		$node = array(
			'@type' => $c['type'],
			'@id'   => home_url( '/#organization' ),
			'name'  => '' !== $c['name'] ? $c['name'] : get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
		$logo = $c['logo'];
		if ( '' === $logo ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		}
		if ( '' !== $logo ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}
		if ( '' !== $c['phone'] ) {
			$node['telephone'] = $c['phone'];
		}
		if ( '' !== $c['email'] ) {
			$node['email'] = $c['email'];
		}
		$a       = $c['address'];
		$address = array_filter(
			array(
				'streetAddress'   => $a['street'],
				'addressLocality' => $a['city'],
				'postalCode'      => $a['postal_code'],
				'addressCountry'  => $a['country'],
			),
			'strlen'
		);
		if ( $address ) {
			$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}
		if ( ! empty( $c['same_as'] ) ) {
			$node['sameAs'] = array_values( $c['same_as'] );
		}

		$description = Graph::plain( $c['description'] );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		if ( '' !== $c['founding_date'] ) {
			$node['foundingDate'] = $c['founding_date'];
		}
		if ( '' !== $c['area_served'] ) {
			$node['areaServed'] = $c['area_served'];
		}
		$cp    = $c['contact_point'];
		$point = array_filter(
			array(
				'contactType' => $cp['contact_type'],
				'telephone'   => $cp['telephone'],
				'email'       => $cp['email'],
			),
			'strlen'
		);
		if ( '' !== $cp['telephone'] || '' !== $cp['email'] || ! empty( $cp['languages'] ) ) {
			$node['contactPoint'] = array( '@type' => 'ContactPoint' ) + $point;
			if ( ! empty( $cp['languages'] ) ) {
				$node['contactPoint']['availableLanguage'] = array_values( $cp['languages'] );
			}
		}

		// Godziny i współrzędne mają sens tylko dla firmy lokalnej (dane są zapisywane niezależnie od typu).
		if ( 'LocalBusiness' === $c['type'] ) {
			$days  = array(
				'Mo' => 'Monday',
				'Tu' => 'Tuesday',
				'We' => 'Wednesday',
				'Th' => 'Thursday',
				'Fr' => 'Friday',
				'Sa' => 'Saturday',
				'Su' => 'Sunday',
			);
			$hours = array();
			foreach ( $c['opening_hours'] as $entry ) {
				$hours[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array_map(
						static function ( $day ) use ( $days ) {
							return $days[ $day ];
						},
						$entry['days']
					),
					'opens'     => $entry['opens'],
					'closes'    => $entry['closes'],
				);
			}
			if ( $hours ) {
				$node['openingHoursSpecification'] = $hours;
			}
			if ( is_array( $c['geo'] ) ) {
				$node['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => $c['geo']['lat'],
					'longitude' => $c['geo']['lng'],
				);
			}
		}
		return $node;
	}

	public static function website(): array {
		return array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/#website' ),
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'inLanguage'      => Language::current(),
			'publisher'     => array( '@id' => home_url( '/#organization' ) ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	private static function title( ?\WP_Post $post ): string {
		if ( ! $post ) {
			return get_bloginfo( 'name' );
		}
		// Surowy tytuł bez filtrów (wptexturize itd.), ale bez HTML.
		return Graph::plain( $post->post_title, 200 );
	}

	private static function description( \WP_Post $post ): string {
		// Treść chronioną hasłem lub niepublikowaną nie wypisujemy do <head>.
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return '';
		}
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		return Graph::plain( $text );
	}

	private static function webpage( ?\WP_Post $post, string $url ): array {
		$node = array(
			'@type'    => 'WebPage',
			'@id'      => $url . '#webpage',
			'url'      => $url,
			'name'       => self::title( $post ),
			'inLanguage' => Language::current(),
			'isPartOf'   => array( '@id' => home_url( '/#website' ) ),
		);
		if ( $post ) {
			$node['datePublished'] = get_post_time( 'c', true, $post );
			$node['dateModified']  = get_post_modified_time( 'c', true, $post );
			$description           = self::description( $post );
			if ( '' !== $description ) {
				$node['description'] = $description;
			}
			if ( ! is_front_page() ) {
				$node['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			}
		}
		return $node;
	}

	private static function article( \WP_Post $post, string $url ): array {
		$node = array(
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'headline'         => self::title( $post ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				'url'   => get_author_posts_url( (int) $post->post_author ),
			),
			'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
		);
		$description = self::description( $post );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( $image ) {
			$node['image'] = $image;
		}
		return $node;
	}

	private static function breadcrumb( \WP_Post $post, string $url ): array {
		$items = array(
			array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
		);
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $parent_id ) {
			$items[] = array(
				'name' => self::title( get_post( $parent_id ) ),
				'url'  => get_permalink( $parent_id ),
			);
		}
		$items[] = array(
			'name' => self::title( $post ),
			'url'  => $url,
		);

		$list = array();
		foreach ( $items as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $list,
		);
	}
}
