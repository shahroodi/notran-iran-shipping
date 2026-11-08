<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Notran_IR_Addresses {
    public static function enabled() { return 'yes' === get_option( 'notran_ir_cities_enabled', 'yes' ); }
    public static function init() {
        add_filter( 'woocommerce_states', array( __CLASS__, 'states' ), 50 );
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'billing' ), 50, 2 );
        add_filter( 'woocommerce_shipping_fields', array( __CLASS__, 'shipping' ), 50, 2 );
        add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'normalize' ) );
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 20, 2 );
        add_action( 'woocommerce_after_save_address_validation', array( __CLASS__, 'validate_address' ), 20, 4 );
        add_filter( 'woocommerce_shipping_calculator_enable_city', static function( $enabled ) { return self::enabled() ? true : $enabled; } );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
        // Classic templates may be rendered even when the saved page contains a block.
        add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'classic_assets' ), 1 );
    }

    public static function states( $countries ) {
        if ( ! self::enabled() ) return $countries;
        $present = array();
        foreach ( (array) ( $countries['IR'] ?? array() ) as $key => $label ) {
            $code = Notran_IR_Catalog::state( $key ) ?: Notran_IR_Catalog::state( $label );
            if ( $code ) {
                $countries['IR'][ $key ] = Notran_IR_Catalog::provinces()[ $code ]['name'];
                $present[ $code ] = true;
            }
        }
        foreach ( Notran_IR_Catalog::provinces() as $code => $item ) {
            if ( ! isset( $present[ $code ] ) ) $countries['IR'][ $code ] = $item['name'];
        }
        return $countries;
    }

    private static function value( $key ) {
        if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) return wc_clean( wp_unslash( $_POST[ $key ] ) ); // Standard address value only; no state changes here.
        if ( WC()->customer ) {
            $method = 'get_' . $key;
            if ( is_callable( array( WC()->customer, $method ) ) ) return WC()->customer->$method();
        }
        return '';
    }

    private static function fields( $fields, $country, $prefix ) {
        if ( ! self::enabled() || 'IR' !== $country || ( is_admin() && ! wp_doing_ajax() ) || ! isset( $fields[ $prefix . 'city' ] ) ) return $fields;
        $state = Notran_IR_Catalog::state( self::value( $prefix . 'state' ) );
        $city = &$fields[ $prefix . 'city' ];
        $city['type'] = 'select';
        $city['input_class'] = array( 'city_select' );
        $city['class'] = array_unique( array_merge( (array) ( $city['class'] ?? array() ), array( 'update_totals_on_change' ) ) );
        $city['options'] = array( '' => $state ? __( 'شهر را انتخاب کنید', 'notran-iran-shipping' ) : __( 'ابتدا استان را انتخاب کنید', 'notran-iran-shipping' ) );
        foreach ( Notran_IR_Catalog::cities( $state ) as $name ) $city['options'][ $name ] = $name;
        $city['custom_attributes']['data-nt-state'] = $state;
        $city['custom_attributes']['data-nt-value'] = self::value( $prefix . 'city' );
        $city['custom_attributes']['autocomplete'] = 'address-level2';
        return $fields;
    }
    public static function billing( $fields, $country = '' ) { return self::fields( $fields, $country, 'billing_' ); }
    public static function shipping( $fields, $country = '' ) { return self::fields( $fields, $country, 'shipping_' ); }

    public static function normalize( $data ) {
        if ( ! self::enabled() ) return $data;
        foreach ( array( 'billing_', 'shipping_' ) as $prefix ) {
            if ( 'IR' !== ( $data[ $prefix . 'country' ] ?? '' ) ) continue;
            $city = Notran_IR_Catalog::city( $data[ $prefix . 'state' ] ?? '', $data[ $prefix . 'city' ] ?? '' );
            if ( $city ) $data[ $prefix . 'city' ] = $city;
        }
        return $data;
    }

    public static function validate( $data, $errors ) {
        if ( ! self::enabled() ) return;
        foreach ( array( 'billing_', 'shipping_' ) as $prefix ) {
            if ( 'shipping_' === $prefix && empty( $data['ship_to_different_address'] ) ) continue;
            if ( 'IR' !== ( $data[ $prefix . 'country' ] ?? '' ) || ! isset( $data[ $prefix . 'city' ] ) ) continue;
            if ( ! Notran_IR_Catalog::city( $data[ $prefix . 'state' ] ?? '', $data[ $prefix . 'city' ] ) ) {
                $errors->add( 'notran_ir_' . $prefix . 'city', 'shipping_' === $prefix ? __( 'شهر نشانی ارسال را از فهرست استان انتخاب‌شده انتخاب کنید.', 'notran-iran-shipping' ) : __( 'شهر صورتحساب را از فهرست استان انتخاب‌شده انتخاب کنید.', 'notran-iran-shipping' ), array( 'id' => $prefix . 'city' ) );
            }
        }
    }

    public static function assets() {
        if ( ! self::enabled() ) return;
        $checkout = is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' );
        $address = is_account_page() && is_wc_endpoint_url( 'edit-address' );
        if ( ! $checkout && ! $address && ! is_cart() ) return;
        if ( $checkout && has_block( 'woocommerce/checkout', get_post() ) ) return;
        self::enqueue_assets();
    }

    public static function classic_assets() {
        if ( self::enabled() && is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' ) ) self::enqueue_assets();
    }

    private static function enqueue_assets() {
        if ( wp_script_is( 'notran-ir-cities', 'enqueued' ) ) return;
        wp_enqueue_script( 'notran-ir-cities', plugins_url( 'assets/cities.min.js', NOTRAN_IR_SHIPPING_FILE ), array( 'jquery', 'wc-country-select' ), NOTRAN_IR_SHIPPING_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
        wp_localize_script( 'notran-ir-cities', 'notranIRCities', Notran_IR_Catalog::config() );
    }

    public static function validate_address( $user_id, $type, $fields, $customer ) {
        if ( ! self::enabled() || ! in_array( $type, array( 'billing', 'shipping' ), true ) || ! $customer instanceof WC_Customer ) return;
        $get_country = 'get_' . $type . '_country';
        if ( 'IR' !== $customer->$get_country() ) return;
        $get_state = 'get_' . $type . '_state'; $get_city = 'get_' . $type . '_city';
        $city = Notran_IR_Catalog::city( $customer->$get_state(), $customer->$get_city() );
        if ( ! $city ) { wc_add_notice( __( 'شهر را از فهرست استان انتخاب‌شده انتخاب کنید.', 'notran-iran-shipping' ), 'error' ); return; }
        $setter = 'set_' . $type . '_city'; $customer->$setter( $city );
    }
}
