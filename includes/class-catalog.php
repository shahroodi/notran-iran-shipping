<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Notran_IR_Catalog {
    public static function provinces() {
        static $data = null;
        if ( null === $data ) $data = require NOTRAN_IR_SHIPPING_DIR . 'data/provinces.php';
        return $data;
    }

    /** Same normalization is used by the address selector and rate matching. */
    public static function key( $name ) {
        if ( ! is_scalar( $name ) ) return '';
        $name = strtr( (string) $name, array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'آ' => 'ا', 'أ' => 'ا', 'إ' => 'ا', 'ـ' => '' ) );
        return (string) preg_replace( '/[\p{Mn}\s\p{Z}\x{200C}\x{200D}\-_–]+/u', '', $name );
    }

    public static function state( $value ) {
        if ( ! is_scalar( $value ) || strlen( (string) $value ) > 160 ) return '';
        $value = trim( (string) $value );
        $code = strtoupper( $value );
        $states = self::provinces();
        if ( isset( $states[ $code ] ) ) return $code;
        if ( preg_match( '/\(([^()]*)\)\s*$/u', $value, $match ) ) $value = $match[1];
        $key = self::key( preg_replace( '/^استان\s*/u', '', $value ) );
        foreach ( $states as $canonical => $state ) {
            if ( in_array( $code, $state['aliases'], true ) || $key === self::key( $state['name'] ) ) return $canonical;
        }
        return '';
    }

    public static function cities( $state ) {
        static $cache = array();
        $state = self::state( $state );
        if ( ! $state ) return array();
        if ( ! isset( $cache[ $state ] ) ) {
            $json = file_get_contents( NOTRAN_IR_SHIPPING_DIR . 'data/cities/' . $state . '.json' );
            $cache[ $state ] = $json ? (array) json_decode( $json, true ) : array();
        }
        return $cache[ $state ];
    }

    /** City names stay readable in existing orders, exports and shipping labels. */
    public static function city( $state, $value ) {
        $key = self::key( $value );
        if ( '' === $key || strlen( $key ) > 400 ) return '';
        foreach ( self::cities( $state ) as $name ) {
            if ( self::key( $name ) === $key ) return $name;
        }
        return '';
    }

    public static function config() {
        $map = array();
        foreach ( self::provinces() as $code => $state ) {
            $map[ $code ] = $code;
            foreach ( $state['aliases'] as $alias ) $map[ $alias ] = $code;
        }
        if ( WC()->countries ) {
            foreach ( (array) WC()->countries->get_states( 'IR' ) as $value => $label ) {
                $canonical = self::state( $value ) ?: self::state( $label );
                if ( $canonical ) $map[ (string) $value ] = $canonical;
            }
        }
        return array(
            'url' => plugins_url( 'data/cities/', NOTRAN_IR_SHIPPING_FILE ),
            'version' => NOTRAN_IR_SHIPPING_VERSION,
            'states' => $map,
            'choose' => __( 'شهر را انتخاب کنید', 'notran-iran-shipping' ),
            'province' => __( 'ابتدا استان را انتخاب کنید', 'notran-iran-shipping' ),
            'loading' => __( 'در حال دریافت شهرها…', 'notran-iran-shipping' ),
            'error' => __( 'دریافت شهرها انجام نشد؛ دوباره تلاش کنید.', 'notran-iran-shipping' ),
            'retry' => __( 'تلاش دوباره', 'notran-iran-shipping' ),
        );
    }

    public static function amount( $value, $allow_empty = false ) {
        if ( ! is_scalar( $value ) ) throw new InvalidArgumentException( __( 'هزینهٔ ارسال معتبر نیست.', 'notran-iran-shipping' ) );
        $value = trim( strtr( (string) $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) ) );
        if ( '' === $value && $allow_empty ) return '';
        $value = str_replace( array( ',', '٬' ), '', $value );
        $value = str_replace( '٫', '.', $value );
        if ( ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $value ) ) throw new InvalidArgumentException( __( 'هزینه را به‌صورت عدد صفر یا مثبت و در واحد پول فروشگاه وارد کنید.', 'notran-iran-shipping' ) );
        return wc_format_decimal( $value, wc_get_price_decimals() );
    }
}
