<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Genuine delivery promotions must not turn collection-on-delivery into free delivery. */
final class Notran_IR_Shipping_Rates {
    public static function init() {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'only_free' ), PHP_INT_MAX, 2 );
        add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'cache_version' ), 100 );
    }

    public static function is_postpaid( $rate ) {
        if ( ! $rate instanceof WC_Shipping_Rate ) return false;
        $meta = $rate->get_meta_data();
        return 'notran_postpaid' === $rate->get_method_id() || ! empty( $meta['_notran_postpaid'] );
    }

    public static function free_priority( $rate ) {
        if ( ! $rate instanceof WC_Shipping_Rate || self::is_postpaid( $rate ) ) return null;
        $priority = array( 'notran_iran' => 0, 'free_shipping' => 1, 'notran_amadast' => 2, 'flat_rate' => 3 );
        $method = $rate->get_method_id();
        if ( self::is_theme_promotion( $rate ) ) return 4;
        if ( ! isset( $priority[ $method ] ) || 0.0 !== (float) $rate->get_cost() || 0.0 !== (float) array_sum( $rate->get_taxes() ) ) return null;
        return $priority[ $method ];
    }

    /** Some themes add a free promotion outside registered zone methods. */
    public static function is_theme_promotion( $rate ) {
        return $rate instanceof WC_Shipping_Rate && ! $rate->get_instance_id()
            && str_replace( '-', '_', get_template() ) . '_free_shipping' === $rate->get_method_id()
            && 0.0 === (float) $rate->get_cost() && 0.0 === (float) array_sum( $rate->get_taxes() );
    }

    public static function only_free( $rates, $package = array() ) {
        if ( 'yes' === get_option( 'notran_ir_only_shipping_rules', 'no' ) ) {
            foreach ( $rates as $key => $rate ) if ( self::is_theme_promotion( $rate ) ) unset( $rates[ $key ] );
        }
        $delivery = false;
        foreach ( $rates as $rate ) {
            if ( ! $rate instanceof WC_Shipping_Rate || self::is_postpaid( $rate ) || self::is_theme_promotion( $rate ) || in_array( $rate->get_method_id(), array( 'local_pickup', 'pickup_location' ), true ) ) continue;
            if ( (float) $rate->get_cost() > 0 || null !== self::free_priority( $rate ) ) { $delivery = true; break; }
        }
        // The legacy theme creates a promotion from any zero-cost method. A
        // postpaid/pickup-only zone does not provide eligible prepaid delivery.
        if ( ! $delivery ) {
            foreach ( $rates as $key => $rate ) {
                if ( self::is_theme_promotion( $rate ) ) unset( $rates[ $key ] );
            }
        }
        $selected = null; $best = PHP_INT_MAX;
        foreach ( $rates as $key => $rate ) {
            $priority = self::free_priority( $rate );
            if ( null !== $priority && $priority < $best ) { $selected = $key; $best = $priority; }
        }
        return null === $selected ? $rates : array( $selected => $rates[ $selected ] );
    }

    public static function cache_version( $packages ) {
        foreach ( $packages as &$package ) $package['notran_ir_rate_policy'] = NOTRAN_IR_SHIPPING_VERSION;
        unset( $package );
        return $packages;
    }
}
