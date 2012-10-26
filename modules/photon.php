<?php
/**
 * Module Name: Photon
 * Module Description:
 * Sort Order: 15
 * First Introduced: 1.9
 */

class Jetpack_Photon {
	/**
	 * Class variables
	 */
	// Oh look, a singleton
	private static $__instance = null;

	// Allowed extensions must match http://code.trac.wordpress.org/browser/photon/index.php#L31
	protected $extensions = array(
		'gif',
		'jpg',
		'jpeg',
		'png'
	);

	// Don't access this directly. Instead, use this::image_sizes() so it's actually populated with something.
	protected static $image_sizes = null;

	// Don't access this directly. Instead, use this::allowed_hosts().
	protected static $allowed_hosts = null;

	/**
	 * Singleton implementation
	 *
	 * @return object
	 */
	public static function instance() {
		if ( ! is_a( self::$__instance, 'Jetpack_Photon' ) )
			self::$__instance = new Jetpack_Photon;

		return self::$__instance;
	}

	/**
	 * Register actions and filters, but only if basic Photon functions are available.
	 * The basic functions are found in ./functions.photon.php.
	 *
	 * @uses add_filter
	 * @return null
	 */
	private function __construct() {
		if ( ! function_exists( 'jetpack_photon_url' ) )
			return;

		// Images in post content
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 999999 );

		// Featured images aka post thumbnails
		add_action( 'begin_fetch_post_thumbnail_html', array( $this, 'action_begin_fetch_post_thumbnail_html' ) );
		add_action( 'end_fetch_post_thumbnail_html', array( $this, 'action_end_fetch_post_thumbnail_html' ) );
	}

	/**
	 ** IN-CONTENT IMAGE MANIPULATION FUNCTIONS
	 **/

	/**
	 * Identify images in post content, and if images are local (uploaded to the current site), pass through Photon.
	 *
	 * @param string $content
	 * @uses this::validate_image_url, jetpack_photon_url, esc_url
	 * @filter the_content
	 * @return string
	 */
	public function filter_the_content( $content ) {
		if ( false != preg_match_all( '#<img(.+?)src=["|\'](.+?)["|\'](.+?)/?>#i', $content, $images ) ) {
			global $content_width;

			foreach ( $images[0] as $index => $tag ) {
				$src = $src_orig = $images[2][ $index ];

				// Check if image URL should be used with Photon
				if ( ! $this->validate_image_url( $src ) )
					continue;

				// Ensure that the image source is acceptable
				$url_info = parse_url( $src );

				if ( ! is_array( $url_info ) || ! isset( $url_info['host'] ) )
					continue;

				if ( ! in_array( strtolower( pathinfo( $url_info['path'], PATHINFO_EXTENSION ) ), $this->extensions ) )
					continue;

				// Find the width and height attributes
				$width = $height = false;

				// First, check the image tag
				foreach ( array( 1, 3 ) as $search_index ) {
					if ( false === $width && preg_match( '#width=["|\']?(\d+)["|\']?#i', $images[ $search_index ][ $index ], $width_string ) )
						$width = (int) $width_string[1];

					if ( false === $height && preg_match( '#height=["|\']?(\d+)["|\']?#i', $images[ $search_index ][ $index ], $height_string ) )
						$height = (int) $height_string[1];
				}

				// If image tag lacks width and height arguments, try to determine from strings WP appends to resized image filenames.
				if ( false === $width && false === $height && false != preg_match( '#(-\d+x\d+)\.(' . implode('|', $this->extensions ) . '){1}$#i', $src, $width_height_string ) ) {
					$width = (int) $width_height_string[1];
					$height = (int) $width_height_string[2];
				}

				// If width is available, constrain to $content_width
				if ( false !== $width && is_numeric( $content_width ) ) {
					if ( $width > $content_width && false !== $height ) {
						$height = round( ( $content_width * $height ) / $width );
						$width = $content_width;
					}
					elseif ( $width > $content_width ) {
						$width = $content_width;
					}

					if ( false === $height )
						$height = 9999;
				}

				// Set a width if none is found and height is available, either $content_width or a very large value
				// Large value is used so as to not unnecessarily constrain image when passed to Photon
				if ( false === $width && false !== $height )
					$width = is_numeric( $content_width ) ? (int) $content_width : 9999;

				// Set a height if none is found and width is available, using a large value
				if ( false === $height && false !== $width )
					$height = 9999;

				// As a last resort, ensure that image won't be larger than $content_width if it is set.
				if ( false === $width && is_numeric( $content_width ) ) {
					$width = (int) $content_width;
					$height = 9999;
				}

				// Build URL, first removing WP's resized string so we pass the original image to Photon
				if ( false != preg_match( '#(-\d+x\d+)\.(' . implode('|', $this->extensions ) . '){1}$#i', $src, $src_parts ) )
					$src = str_replace( $src_parts[1], '', $src );

				$args = array();

				if ( false !== $width && false !== $height )
					$args['fit'] = $width . ',' . $height;

				$photon_url = jetpack_photon_url( $src, $args );

				// Modify image tag if Photon function provides a URL
				// Ensure changes are only applied to the current image by copying and modifying the matched tag, then replacing the entire tag with our modified version.
				if ( $src != $photon_url ) {
					$new_tag = $tag;

					// Supplant the original source value with our Photon URL
					$photon_url = esc_url( $photon_url );
					$new_tag = str_replace( $src_orig, $photon_url, $new_tag );

					// Remove the width and height arguments from the tag to prevent stretching
					$new_tag = preg_replace( '#(width|height)=["|\']?(\d+)["|\']?\s{1}#i', '', $new_tag );

					$content = str_replace( $tag, $new_tag, $content );
				}

			}
		}

		return $content;
	}

	/**
	 ** POST THUMBNAIL FUNCTIONS
	 **/

	/**
	 * Apply Photon to WP image retrieval functions for post thumbnails
	 *
	 * @uses add_filter
	 * @action begin_fetch_post_thumbnail_html
	 * @return null
	 */
	public function action_begin_fetch_post_thumbnail_html() {
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
	}

	/**
	 * Remove Photon from WP image functions when post thumbnail processing is finished
	 *
	 * @uses remove_filter
	 * @action end_fetch_post_thumbnail_html
	 * @return null
	 */
	public function action_end_fetch_post_thumbnail_html() {
		remove_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
	}

	/**
	 * Filter post thumbnail image retrieval, passing images through Photon
	 *
	 * @param string|bool $image
	 * @param int $attachment_id
	 * @param string|array $size
	 * @uses is_admin, apply_filters, wp_get_attachment_url, this::validate_image_url, this::image_sizes, jetpack_photon_url
	 * @filter image_downsize
	 * @return string|bool
	 */
	public function filter_image_downsize( $image, $attachment_id, $size ) {
		// Don't foul up the admin side of things, and provide plugins a way of preventing Photon from being applied to images.
		if ( is_admin() && apply_filters( 'jetpack_photon_override_image_downsize', true, compact( 'image', 'attachment_id', 'size' ) ) )
			return $image;

		// Get the image URL and proceed with Photon-ification if successful
		$image_url = wp_get_attachment_url( $attachment_id );

		if ( $image_url ) {
			// Check if image URL should be used with Photon
			if ( ! $this->validate_image_url( $image_url ) )
				return $image;

			// If an image is requested with a size known to WordPress, use that size's settings with Photon
			if ( array_key_exists( $size, $this->image_sizes() ) ) {
				$image_args = $this->image_sizes();
				$image_args = $image_args[ $size ];

				// Expose arguments to a filter before passing to Photon
				$photon_args = array();

				if ( $image_args['crop'] )
					$photon_args['resize'] = $image_args['width'] . ',' . $image_args['height'];
				else
					$photon_args['fit'] = $image_args['width'] . ',' . $image_args['height'];

				$photon_args = apply_filters( 'jetpack_photon_image_downsize_string', $photon_args, compact( 'image_args', 'image_url', 'attachment_id', 'size' ) );

				// Generate Photon URL
				$image = array(
					jetpack_photon_url( $image_url, $photon_args ),
					false,
					false
				);
			}
			elseif ( is_array( $size ) ) {
				// Pull width and height values from the provided array, if possible
				$width = isset( $size[0] ) ? (int) $size[0] : false;
				$height = isset( $size[1] ) ? (int) $size[1] : false;

				// Don't bother if necessary parameters aren't passed.
				if ( ! $width || ! $height )
					return $image;

				// Expose arguments to a filter before passing to Photon
				$photon_args = array(
					'fit' => $image_args['width'] . ',' . $image_args['height']
				);
				$photon_args = apply_filters( 'jetpack_photon_image_downsize_array', $photon_args, compact( 'width', 'height', 'image_url', 'attachment_id' ) );

				// Generate Photon URL
				$image = array(
					jetpack_photon_url( $image_url, $photon_args ),
					false,
					false
				);
			}
		}

		return $image;
	}

	/**
	 ** GENERAL FUNCTIONS
	 **/

	/**
	 * Exclude certain hosts from Photon-ification
	 * Facebook et al already serve images from CDNs, so no need to duplicate the effort.
	 *
	 * @param string $url
	 * @uses this::check_url_scheme_and_port, this::allowed_hosts
	 * @return bool
	 */
	protected function validate_image_url( $url ) {
		// Bail if scheme isn't http or port is set that isn't port 80
		if ( ! $this->check_url_scheme_and_port( $url ) )
			return false;

		// Get list of allowed hosts for Photon-ification
		$allowed_hosts = $this->allowed_hosts();

		// Compare URL
		if ( empty( $allowed_hosts ) )
			return false;
		else
			return preg_match( '#^(' . implode( '|', $allowed_hosts ) . ')#i', $url );
	}

	/**
	 * Build array of hosts permissible for Photon-ification
	 *
	 * @uses get_option, get_current_blog_id, get_original)url, apply_filters, this::normalize_allowed_url
	 * @return array
	 */
	function allowed_hosts() {
		if ( null == self::$allowed_hosts ) {
			// Base URL hosts to consider
			$allowed_hosts = array(
				get_option( 'home' ),
				get_option( 'siteurl' )
			);

			// Account for mapped domains care of WordPress MU Domain Mapping
			if ( defined( 'DOMAIN_MAPPING' ) && 1 == DOMAIN_MAPPING && function_exists( 'get_original_url' ) ) {
				$current_blog_id = get_current_blog_id();

				$allowed_hosts[] = get_original_url( 'home', $current_blog_id );
				$allowed_hosts[] = get_original_url( 'siteurl', $current_blog_id );
			}

			// Allow more domains to be whitelisted
			$allowed_hosts = apply_filters( 'jetpack_photon_allowed_hosts', $allowed_hosts );

			// Normalize URLs for comparison
			$allowed_hosts = array_map( array( $this, 'normalize_allowed_url' ), $allowed_hosts );
			$allowed_hosts = array_filter( $allowed_hosts );
			$allowed_hosts = array_unique( $allowed_hosts );

			self::$allowed_hosts = $allowed_hosts;
		}

		return is_array( self::$allowed_hosts ) ? self::$allowed_hosts : array();
	}

	/**
	 * Ensure URLs are comparable and apply a bit of sanity checking as well.
	 *
	 * @param string $url
	 * @uses trailinslashit, this::check_url_scheme_and_port
	 * @return bool|string
	 */
	protected function normalize_allowed_url( $url ) {
		$url = trailingslashit( $url );

		// Ensure that
		if ( ! $this->check_url_scheme_and_port( $url ) )
			return false;

		return $url;
	}

	/**
	 * Check if protocol and port of a given URL are compatible with Photon.
	 * Photon can only process images served over http on port 80.
	 *
	 * @param string $url
	 * @return bool
	 */
	protected function check_url_scheme_and_port( $url ) {
		return ( 'http' == parse_url( $url, PHP_URL_SCHEME ) || in_array( parse_url( $url, PHP_URL_PORT ), array( 80, null ) ) );
	}

	/**
	 * Provide an array of available image sizes and corresponding dimensions.
	 * Similar to get_intermediate_image_sizes() except that it includes image sizes' dimensions, not just their names.
	 *
	 * @global $wp_additional_image_sizes
	 * @uses get_option
	 * @return array
	 */
	protected function image_sizes() {
		if ( null == self::$image_sizes ) {
			global $_wp_additional_image_sizes;

			// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes
			$images = array(
				'thumb'  => array(
					'width'  => intval( get_option( 'thumbnail_size_w' ) ),
					'height' => intval( get_option( 'thumbnail_size_h' ) ),
					'crop'   => (bool) get_option( 'thumbnail_crop' )
				),
				'medium' => array(
					'width'  => intval( get_option( 'medium_size_w' ) ),
					'height' => intval( get_option( 'medium_size_h' ) ),
					'crop'   => false
				),
				'large'  => array(
					'width'  => intval( get_option( 'large_size_w' ) ),
					'height' => intval( get_option( 'large_size_h' ) ),
					'crop'   => false
				)
			);

			// Compatibility mapping as found in wp-includes/media.php
			$images['thumbnail'] = $images['thumb'];

			// Update class variable, merging in $_wp_additional_image_sizes if any are set
			if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) )
				self::$image_sizes = array_merge( $images, $_wp_additional_image_sizes );
			else
				self::$image_sizes = $images;
		}

		return is_array( self::$image_sizes ) ? self::$image_sizes : array();
	}
}

Jetpack_Photon::instance();