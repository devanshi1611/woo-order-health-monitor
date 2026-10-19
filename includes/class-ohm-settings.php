<?php
/**
 * Plugin settings storage and sanitization.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the ohm_settings option.
 */
class OHM_Settings {

	const OPTION_KEY = 'ohm_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'rules'                   => array(
				'failed'     => array(
					'enabled' => true,
				),
				'pending'    => array(
					'enabled'         => true,
					'threshold_hours' => 24,
				),
				'processing' => array(
					'enabled'         => true,
					'threshold_hours' => 72,
				),
				'on_hold'    => array(
					'enabled'         => true,
					'threshold_hours' => 48,
				),
			),
			'scan_frequency'          => 'hourly',
			'batch_size'              => 50,
			'scan_history_retention'  => 30,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::merge_defaults( $stored );
	}

	/**
	 * Get a top-level setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Whether a detection rule is enabled.
	 *
	 * @param string $type Rule type.
	 * @return bool
	 */
	public static function is_rule_enabled( $type ) {
		$settings = self::get_all();

		return ! empty( $settings['rules'][ $type ]['enabled'] );
	}

	/**
	 * Threshold in hours for an age-based rule.
	 *
	 * @param string $type Rule type.
	 * @return int
	 */
	public static function get_threshold_hours( $type ) {
		$settings  = self::get_all();
		$defaults  = self::defaults();
		$fallback  = isset( $defaults['rules'][ $type ]['threshold_hours'] )
			? (int) $defaults['rules'][ $type ]['threshold_hours']
			: 24;

		if ( ! isset( $settings['rules'][ $type ]['threshold_hours'] ) ) {
			return $fallback;
		}

		return max( 1, (int) $settings['rules'][ $type ]['threshold_hours'] );
	}

	/**
	 * Persist sanitized settings.
	 *
	 * @param array $input Raw settings.
	 * @return array|WP_Error Sanitized settings or error.
	 */
	public static function update( $input ) {
		$sanitized = self::sanitize( $input );

		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		update_option( self::OPTION_KEY, $sanitized, false );

		return $sanitized;
	}

	/**
	 * Reset settings to defaults.
	 *
	 * @return array
	 */
	public static function reset() {
		$defaults = self::defaults();
		update_option( self::OPTION_KEY, $defaults, false );
		return $defaults;
	}

	/**
	 * Sanitize and validate settings input.
	 *
	 * @param array $input Raw input.
	 * @return array|WP_Error
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'ohm_invalid_settings', __( 'Invalid settings payload.', 'woo-order-health-monitor' ) );
		}

		$defaults  = self::defaults();
		$sanitized = $defaults;

		$rule_types = array( 'failed', 'pending', 'processing', 'on_hold' );

		foreach ( $rule_types as $type ) {
			$sanitized['rules'][ $type ]['enabled'] = ! empty( $input['rules'][ $type ]['enabled'] );

			if ( 'failed' === $type ) {
				continue;
			}

			$hours = isset( $input['rules'][ $type ]['threshold_hours'] )
				? (int) $input['rules'][ $type ]['threshold_hours']
				: $defaults['rules'][ $type ]['threshold_hours'];

			if ( $hours < 1 || $hours > 8760 ) {
				return new WP_Error(
					'ohm_invalid_threshold',
					sprintf(
						/* translators: %s: rule type */
						__( 'Threshold for %s must be between 1 and 8760 hours.', 'woo-order-health-monitor' ),
						$type
					)
				);
			}

			$sanitized['rules'][ $type ]['threshold_hours'] = $hours;
		}

		$allowed_frequencies = array( 'hourly', 'twicedaily', 'daily' );
		$frequency           = isset( $input['scan_frequency'] ) ? sanitize_key( $input['scan_frequency'] ) : 'hourly';

		if ( ! in_array( $frequency, $allowed_frequencies, true ) ) {
			return new WP_Error( 'ohm_invalid_frequency', __( 'Invalid scan frequency.', 'woo-order-health-monitor' ) );
		}

		$sanitized['scan_frequency'] = $frequency;

		$batch_size = isset( $input['batch_size'] ) ? (int) $input['batch_size'] : 50;
		if ( $batch_size < 10 || $batch_size > 200 ) {
			return new WP_Error( 'ohm_invalid_batch', __( 'Batch size must be between 10 and 200.', 'woo-order-health-monitor' ) );
		}
		$sanitized['batch_size'] = $batch_size;

		$retention = isset( $input['scan_history_retention'] ) ? (int) $input['scan_history_retention'] : 30;
		if ( $retention < 1 || $retention > 365 ) {
			return new WP_Error( 'ohm_invalid_retention', __( 'Scan history retention must be between 1 and 365 days.', 'woo-order-health-monitor' ) );
		}
		$sanitized['scan_history_retention'] = $retention;

		return $sanitized;
	}

	/**
	 * Deep-merge stored values onto defaults.
	 *
	 * @param array $stored Stored settings.
	 * @return array
	 */
	private static function merge_defaults( $stored ) {
		$defaults = self::defaults();
		$merged   = $defaults;

		if ( isset( $stored['scan_frequency'] ) ) {
			$merged['scan_frequency'] = $stored['scan_frequency'];
		}
		if ( isset( $stored['batch_size'] ) ) {
			$merged['batch_size'] = (int) $stored['batch_size'];
		}
		if ( isset( $stored['scan_history_retention'] ) ) {
			$merged['scan_history_retention'] = (int) $stored['scan_history_retention'];
		}

		if ( ! empty( $stored['rules'] ) && is_array( $stored['rules'] ) ) {
			foreach ( $defaults['rules'] as $type => $rule ) {
				if ( ! isset( $stored['rules'][ $type ] ) || ! is_array( $stored['rules'][ $type ] ) ) {
					continue;
				}

				$merged['rules'][ $type ]['enabled'] = ! empty( $stored['rules'][ $type ]['enabled'] );

				if ( isset( $stored['rules'][ $type ]['threshold_hours'] ) ) {
					$merged['rules'][ $type ]['threshold_hours'] = (int) $stored['rules'][ $type ]['threshold_hours'];
				}
			}
		}

		return $merged;
	}
}
