<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Small server-side adapter for the public Amadast calculator; no order creation. */
final class Notran_Amadast_API {
    private const BASE = 'https://api.amadast.com/api/v2.0/tool/shipping-calculator';

    public static function services() {
        $saved = get_option( 'notran_amadast_services', array() );
        return $saved ?: require NOTRAN_IR_SHIPPING_DIR . 'data/amadast-services.php';
    }

    public static function refresh_services() {
        $response = wp_remote_get( 'https://api.amadast.com/api/v1.0/plugin/couriers', self::args( 12 ) );
        $body = self::decode( $response );
        if ( is_wp_error( $body ) ) return $body;
        $services = array();
        foreach ( (array) ( $body['data'] ?? array() ) as $courier ) {
            if ( ! is_array( $courier ) || empty( $courier['id'] ) ) continue;
            foreach ( (array) ( $courier['shipping_methods'] ?? array() ) as $method ) {
                if ( ! is_array( $method ) || empty( $method['id'] ) || ! is_scalar( $method['title'] ?? null ) ) continue;
                $title = sanitize_text_field( $method['title'] );
                $company = sanitize_text_field( $courier['title'] ?? '' );
                $services[ absint( $method['id'] ) ] = array( 'courier' => absint( $courier['id'] ), 'title' => $company === $title ? $title : $company . ' — ' . $title );
            }
        }
        if ( ! $services ) return new WP_Error( 'notran_catalog', __( 'فهرست معتبری از سرویس آنلاین دریافت نشد؛ فهرست قبلی حفظ شد.', 'notran-iran-shipping' ) );
        update_option( 'notran_amadast_services', $services, false );
        WC_Cache_Helper::get_transient_version( 'shipping', true );
        return $services;
    }

    public static function cities() {
        static $cities;
        if ( null === $cities ) $cities = require NOTRAN_IR_SHIPPING_DIR . 'data/amadast-cities.php';
        return $cities;
    }

    public static function city_id( $state, $name ) {
        $cities = self::cities()[ Notran_IR_Catalog::state( $state ) ] ?? array();
        $key = Notran_IR_Catalog::key( $name );
        foreach ( $cities as $id => $city ) if ( Notran_IR_Catalog::key( $city ) === $key ) return (int) $id;
        return 0; // Never match a city in another province or fabricate an ID.
    }

    public static function key( $payload ) { return 'notran_amq_' . md5( wp_json_encode( $payload ) ); }
    public static function state( $key ) { return get_transient( $key ) ?: array(); }

    /** Queue locally. The small status request starts the external HTTP call. */
    public static function quote( $payload ) {
        $key = self::key( $payload );
        $state = self::state( $key );
        if ( $state ) return $state;
        $state = array( 'status' => 'queued', 'payload' => $payload, 'started' => time(), 'next' => 0, 'polls' => 0 );
        set_transient( $key, $state, 180 );
        return $state;
    }

    private static function args( $timeout ) {
        return array( 'timeout' => $timeout, 'redirection' => 0, 'limit_response_size' => 262144,
            'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'NotranShipping/' . NOTRAN_IR_SHIPPING_VERSION ) );
    }

    private static function decode( $response ) {
        if ( is_wp_error( $response ) ) return new WP_Error( 'notran_network', __( 'پاسخ سرویس آنلاین دریافت نشد.', 'notran-iran-shipping' ) );
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! in_array( $code, array( 200, 202 ), true ) || ! is_array( $body ) || ( isset( $body['result'] ) && ! $body['result'] ) ) {
            return new WP_Error( 'notran_response', __( 'پاسخ معتبر محاسبهٔ کرایه دریافت نشد.', 'notran-iran-shipping' ) );
        }
        return $body;
    }

    private static function failure( $key, $code ) {
        $state = array( 'status' => 'error', 'code' => $code );
        set_transient( $key, $state, 45 );
        return $state;
    }

    /** One job for all companies; a slow start can include its first poll. */
    public static function advance( $key ) {
        $state = self::state( $key );
        if ( ! in_array( $state['status'] ?? '', array( 'queued', 'pending' ), true ) || time() < ( $state['next'] ?? 0 ) ) return $state;
        if ( time() - ( $state['started'] ?? 0 ) > 120 || (int) ( $state['polls'] ?? 0 ) >= 13 ) return self::failure( $key, 'timeout' );
        $lock = $key . '_lock';
        if ( ! add_option( $lock, time(), '', false ) ) {
            if ( (int) get_option( $lock ) < time() - 30 ) delete_option( $lock );
            return $state;
        }
        try {
            // Re-read after acquiring the lock: another worker may have completed.
            $state = self::state( $key );
            if ( ! in_array( $state['status'] ?? '', array( 'queued', 'pending' ), true ) || time() < ( $state['next'] ?? 0 ) ) return $state;
            $started = microtime( true );
            for ( $step = 0; $step < 2; $step++ ) {
                $poll = 'pending' === $state['status'];
                $timeout = $step ? max( 1, min( 6, 18 - ( microtime( true ) - $started ) ) ) : ( $poll ? 7 : 12 );
                $args = self::args( $timeout );
                if ( $poll ) {
                    $response = wp_remote_get( self::BASE . '/' . rawurlencode( $state['job'] ), $args );
                    $state['polls']++;
                } else {
                    $args['body'] = wp_json_encode( $state['payload'] );
                    $response = wp_remote_post( self::BASE, $args );
                }
                $body = self::decode( $response );
                if ( is_wp_error( $body ) ) return self::failure( $key, $body->get_error_code() );
                $data = $body['data'] ?? array();
                if ( ! is_array( $data ) ) return self::failure( $key, 'invalid_data' );
                $items = $data['data']['items'] ?? $data['items'] ?? null;
                $percent = $data['progress_detail']['percent'] ?? null;
                if ( is_array( $items ) && ( null === $percent || ( is_numeric( $percent ) && (float) $percent >= 100 ) ) ) {
                    $state = array( 'status' => 'ready', 'items' => self::clean_items( $items ) );
                    set_transient( $key, $state, 300 );
                    return $state;
                }
                $job = $data['request_id'] ?? $state['job'] ?? '';
                if ( ! is_string( $job ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,160}$/D', $job ) ) return self::failure( $key, 'invalid_job' );
                $state['status'] = 'pending'; $state['job'] = $job;
                unset( $state['payload'] );
                // Count the initial four seconds from the start, not after a slow POST.
                // Later polls keep the old backoff; no aggressive per-company polling.
                $elapsed = microtime( true ) - $started;
                $wait = $poll ? min( 10, 4 + 2 * $state['polls'] ) : max( 0, (int) ceil( 4 - $elapsed ) );
                $state['next'] = time() + $wait;
                set_transient( $key, $state, 180 );
                if ( $poll || $wait ) return $state;
            }
            return $state;
        } finally { delete_option( $lock ); }
    }

    private static function clean_items( $items ) {
        $out = array();
        foreach ( array_slice( $items, 0, 200 ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            $id = absint( $item['shipping_method'] ?? 0 );
            if ( ! $id ) $id = absint( $item['id'] ?? 0 );
            if ( ! $id ) continue;
            $prices = array(); $invalid = false;
            foreach ( array( 'price', 'discounted_price' ) as $field ) {
                $value = $item[ $field ] ?? null;
                if ( null === $value ) { $prices[ $field ] = null; continue; }
                if ( ! is_scalar( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 || (float) $value > 1e12 ) { $invalid = true; break; }
                $prices[ $field ] = (float) $value;
            }
            if ( $invalid ) continue;
            if ( null === $prices['price'] && null === $prices['discounted_price'] ) continue;
            $out[] = array_merge( array( 'service' => $id, 'courier' => absint( $item['id'] ?? 0 ) ), $prices );
        }
        return $out;
    }
}
