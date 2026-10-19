<?php
/**
 * Great-circle helpers.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Spherical-earth geometry.
 */
class VBR_Geo {

	const EARTH_RADIUS_KM = 6371.0088;

	/**
	 * Point reached by travelling $km from a start point on a bearing.
	 *
	 * @param float $lat     Start latitude (degrees).
	 * @param float $lng     Start longitude (degrees).
	 * @param float $bearing Bearing in degrees (0 = north, 90 = east).
	 * @param float $km      Distance in km.
	 * @return array{0: float, 1: float} [lat, lng]
	 */
	public static function destination( $lat, $lng, $bearing, $km ) {
		$delta = (float) $km / self::EARTH_RADIUS_KM;
		$theta = deg2rad( (float) $bearing );
		$phi1  = deg2rad( (float) $lat );
		$lam1  = deg2rad( (float) $lng );

		$phi2 = asin( sin( $phi1 ) * cos( $delta ) + cos( $phi1 ) * sin( $delta ) * cos( $theta ) );
		$lam2 = $lam1 + atan2(
			sin( $theta ) * sin( $delta ) * cos( $phi1 ),
			cos( $delta ) - sin( $phi1 ) * sin( $phi2 )
		);

		$lat2 = rad2deg( $phi2 );
		$lng2 = fmod( rad2deg( $lam2 ) + 540, 360 ) - 180;

		// Keep away from the poles so web-mercator maps stay sensible.
		$lat2 = max( -85.0, min( 85.0, $lat2 ) );

		return array( $lat2, $lng2 );
	}

	/**
	 * Haversine distance between two points in km.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 * @return float
	 */
	public static function distance_km( $lat1, $lng1, $lat2, $lng2 ) {
		$dphi = deg2rad( $lat2 - $lat1 );
		$dlam = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dphi / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlam / 2 ) ** 2;

		return 2 * self::EARTH_RADIUS_KM * asin( min( 1, sqrt( $a ) ) );
	}

	/**
	 * Whether a lat/lng pair is valid.
	 *
	 * @param mixed $lat Latitude.
	 * @param mixed $lng Longitude.
	 * @return bool
	 */
	public static function is_valid( $lat, $lng ) {
		return is_numeric( $lat ) && is_numeric( $lng )
			&& (float) $lat >= -90 && (float) $lat <= 90
			&& (float) $lng >= -180 && (float) $lng <= 180;
	}
}
