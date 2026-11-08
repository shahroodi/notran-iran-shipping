<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Transfer compatible saved shipping methods without hard-coded vendor names. */
final class Notran_IR_Transfer {
    private const SCHEMA = '2.0.0';

    public static function init() {
        add_action( 'woocommerce_init', array( __CLASS__, 'maybe_transfer' ), 5 );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
            add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
            add_action( 'admin_post_notran_ir_transfer', array( __CLASS__, 'submit' ) );
        }
    }

    /** The saved prefixes are used only to read historical shipping descriptions. */
    public static function source_prefixes() {
        $prefixes = array();
        foreach ( (array) get_option( 'notran_ir_transfer_sources', array() ) as $prefix ) {
            if ( is_string( $prefix ) && preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $prefix ) && 'notran' !== $prefix ) $prefixes[] = $prefix;
        }
        return array_values( array_unique( $prefixes ) );
    }

    private static function rows() {
        global $wpdb;
        return $wpdb->get_results( "SELECT instance_id, method_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods ORDER BY instance_id LIMIT 5001", ARRAY_A );
    }

    /** Candidates are shown for review; automatic transfer requires an extra match. */
    private static function candidates( $rows ) {
        $groups = array(); $online = array();
        foreach ( (array) $rows as $row ) {
            $id = absint( $row['instance_id'] ?? 0 ); $method = $row['method_id'] ?? '';
            if ( ! $id || ! is_string( $method ) ) continue;
            if ( 'notran_amadast' === $method ) {
                $online[] = get_option( 'woocommerce_' . $method . '_' . $id . '_settings', array() );
                continue;
            }
            if ( ! preg_match( '/^([a-z][a-z0-9_]{0,39})_(iran|postpaid)$/D', $method, $match ) || 'notran' === $match[1] ) continue;
            $settings = get_option( 'woocommerce_' . $method . '_' . $id . '_settings', array() );
            if ( ! is_array( $settings ) || ( 'iran' === $match[2] && ! array_key_exists( 'city_rules', $settings ) && ! array_key_exists( 'cost', $settings ) ) ) continue;
            if ( 'postpaid' === $match[2] && ! array_key_exists( 'title', $settings ) && ! array_key_exists( $match[1] . '_description', $settings ) ) continue;
            $groups[ $match[1] ][] = array( 'instance' => $id, 'method' => $method, 'kind' => $match[2] );
        }
        // An installation may use the online method alone, without fixed or postpaid methods.
        foreach ( $online as $settings ) {
            if ( ! is_array( $settings ) || array_key_exists( 'notran_description', $settings ) ) continue;
            foreach ( array_keys( $settings ) as $key ) {
                if ( is_string( $key ) && preg_match( '/^([a-z][a-z0-9_]{0,39})_description$/D', $key, $match ) && 'notran' !== $match[1] && ! isset( $groups[ $match[1] ] ) ) $groups[ $match[1] ] = array();
            }
        }
        $out = array();
        foreach ( $groups as $prefix => $methods ) {
            $online_match = false;
            foreach ( $online as $settings ) if ( is_array( $settings ) && ! array_key_exists( 'notran_description', $settings ) && array_key_exists( $prefix . '_description', $settings ) ) { $online_match = true; break; }
            $global_match = null !== get_option( $prefix . '_ir_cities_enabled', null ) || null !== get_option( $prefix . '_ir_only_shipping_rules', null ) || null !== get_option( $prefix . '_ir_promotion_description', null );
            $confirmed = $online_match && ( $methods || $global_match );
            $out[ $prefix ] = array( 'methods' => $methods, 'confirmed' => $confirmed );
        }
        return $out;
    }

    public static function maybe_transfer() {
        if ( self::SCHEMA === get_option( 'notran_ir_schema_version', '' ) ) return;
        if ( ! self::lock() ) return;
        try {
            // Another worker may have completed between the first check and the lock.
            wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
            wp_cache_delete( 'notran_ir_schema_version', 'options' );
            if ( self::SCHEMA === get_option( 'notran_ir_schema_version', '' ) ) return;
            $rows = self::rows();
            if ( ! is_array( $rows ) || count( $rows ) > 5000 ) { update_option( 'notran_ir_transfer_error', 'notran_read', false ); self::state( 'review' ); return; }
            $candidates = self::candidates( $rows );
            $confirmed = array_keys( array_filter( $candidates, static function( $item ) { return $item['confirmed']; } ) );
            if ( 1 === count( $confirmed ) && 1 === count( $candidates ) ) {
                $result = self::transfer( $confirmed[0], $rows, $candidates );
                update_option( 'notran_ir_transfer_error', is_wp_error( $result ) ? $result->get_error_code() : '', false );
                self::state( is_wp_error( $result ) ? 'review' : 'done' );
            } else {
                self::state( $candidates ? 'review' : 'done' );
            }
        } finally { delete_option( 'notran_ir_transfer_lock' ); }
    }

    private static function lock() {
        if ( add_option( 'notran_ir_transfer_lock', time(), '', false ) ) return true;
        if ( (int) get_option( 'notran_ir_transfer_lock', 0 ) < time() - 600 ) {
            delete_option( 'notran_ir_transfer_lock' );
            return add_option( 'notran_ir_transfer_lock', time(), '', false );
        }
        return false;
    }

    private static function state( $status ) {
        update_option( 'notran_ir_transfer_status', $status, false );
        update_option( 'notran_ir_schema_version', self::SCHEMA, true );
    }

    /** Only matched rows are changed; source options and historical orders are retained. */
    private static function transfer( $prefix, $rows, $candidates ) {
        global $wpdb;
        if ( ! is_string( $prefix ) || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $prefix ) || 'notran' === $prefix || ! isset( $candidates[ $prefix ] ) ) return new WP_Error( 'notran_source' );
        if ( count( $candidates[ $prefix ]['methods'] ) > 1000 ) return new WP_Error( 'notran_read' );
        $shipping = WC()->shipping();
        $registered = is_callable( array( $shipping, 'get_shipping_method_class_names' ) ) ? $shipping->get_shipping_method_class_names()
            : apply_filters( 'woocommerce_shipping_methods', array( 'flat_rate' => 'WC_Shipping_Flat_Rate', 'free_shipping' => 'WC_Shipping_Free_Shipping', 'local_pickup' => 'WC_Shipping_Local_Pickup' ) );
        if ( isset( $registered[ $prefix . '_iran' ] ) || isset( $registered[ $prefix . '_postpaid' ] ) ) return new WP_Error( 'notran_source_active' );
        $plan = array(); $new_options = array();
        foreach ( $candidates[ $prefix ]['methods'] as $item ) {
            $id = $item['instance']; $from = $item['method']; $to = 'notran_' . $item['kind'];
            $old_key = 'woocommerce_' . $from . '_' . $id . '_settings';
            $new_key = 'woocommerce_' . $to . '_' . $id . '_settings';
            $settings = get_option( $old_key, array() );
            if ( ! is_array( $settings ) ) return new WP_Error( 'notran_settings' );
            if ( array_key_exists( $prefix . '_description', $settings ) && ! array_key_exists( 'notran_description', $settings ) ) $settings['notran_description'] = $settings[ $prefix . '_description' ];
            unset( $settings[ $prefix . '_description' ] );
            $existing = get_option( $new_key, null );
            if ( null !== $existing && $existing !== $settings ) return new WP_Error( 'notran_conflict' );
            $new_options[ $new_key ] = $settings;
            $plan[] = array( 'instance' => $id, 'from' => $from, 'to' => $to );
        }
        foreach ( array( 'cities_enabled', 'only_shipping_rules', 'promotion_description' ) as $suffix ) {
            $old = get_option( $prefix . '_ir_' . $suffix, null );
            $new = get_option( 'notran_ir_' . $suffix, null );
            if ( null !== $old && null !== $new && $old !== $new ) return new WP_Error( 'notran_conflict' );
            if ( null !== $old ) $new_options[ 'notran_ir_' . $suffix ] = $old;
        }
        // Copy only missing destinations. Keep a record so a failed transfer can undo its own writes.
        $created = array(); $descriptions = array();
        foreach ( $new_options as $key => $value ) {
            if ( null === get_option( $key, null ) && add_option( $key, $value, '', 0 === strpos( $key, 'notran_ir_' ) ) ) $created[ $key ] = $value;
            if ( get_option( $key, null ) !== $value ) { self::revert_options( $created, $descriptions ); return new WP_Error( 'notran_write' ); }
        }
        // Preserve descriptions on standard and online methods too, without deleting old data.
        foreach ( (array) $rows as $row ) {
            $id = absint( $row['instance_id'] ?? 0 ); $method = $row['method_id'] ?? '';
            if ( ! $id || ! is_string( $method ) || ! preg_match( '/^[a-z0-9_-]+$/D', $method ) ) continue;
            if ( in_array( $method, array( $prefix . '_iran', $prefix . '_postpaid' ), true ) ) continue;
            $key = 'woocommerce_' . $method . '_' . $id . '_settings'; $settings = get_option( $key, null );
            if ( ! is_array( $settings ) || ! array_key_exists( $prefix . '_description', $settings ) || array_key_exists( 'notran_description', $settings ) ) continue;
            $settings['notran_description'] = $settings[ $prefix . '_description' ]; $descriptions[ $key ] = $settings['notran_description']; update_option( $key, $settings );
            if ( get_option( $key, null ) !== $settings ) { self::revert_options( $created, $descriptions ); return new WP_Error( 'notran_write' ); }
        }
        update_option( 'notran_ir_transfer_backup', $plan, false );
        if ( get_option( 'notran_ir_transfer_backup', null ) !== $plan ) { self::revert_options( $created, $descriptions ); return new WP_Error( 'notran_write' ); }
        $sources = self::source_prefixes(); $sources[] = $prefix; $sources = array_values( array_unique( $sources ) );
        update_option( 'notran_ir_transfer_sources', $sources, false );
        if ( get_option( 'notran_ir_transfer_sources', null ) !== $sources ) { self::revert_options( $created, $descriptions ); return new WP_Error( 'notran_write' ); }
        $table = $wpdb->prefix . 'woocommerce_shipping_zone_methods'; $updated = array();
        $wpdb->query( 'START TRANSACTION' );
        foreach ( $plan as $item ) {
            $count = $wpdb->update( $table, array( 'method_id' => $item['to'] ), array( 'instance_id' => $item['instance'], 'method_id' => $item['from'] ), array( '%s' ), array( '%d', '%s' ) );
            if ( 1 !== $count ) {
                $wpdb->query( 'ROLLBACK' );
                // Also recover non-transactional tables, only where our change remains.
                foreach ( $updated as $done ) $wpdb->update( $table, array( 'method_id' => $done['from'] ), array( 'instance_id' => $done['instance'], 'method_id' => $done['to'] ), array( '%s' ), array( '%d', '%s' ) );
                if ( self::rows_restored( $plan ) ) self::revert_options( $created, $descriptions );
                self::invalidate(); return new WP_Error( 'notran_update' );
            }
            $updated[] = $item;
        }
        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            foreach ( $updated as $done ) $wpdb->update( $table, array( 'method_id' => $done['from'] ), array( 'instance_id' => $done['instance'], 'method_id' => $done['to'] ), array( '%s' ), array( '%d', '%s' ) );
            if ( self::rows_restored( $plan ) ) self::revert_options( $created, $descriptions );
            self::invalidate(); return new WP_Error( 'notran_update' );
        }
        update_option( 'notran_ir_transfer_count', count( $plan ) + count( $descriptions ) + count( $created ), false );
        self::invalidate();
        return true;
    }

    private static function revert_options( $created, $descriptions ) {
        foreach ( $descriptions as $key => $value ) {
            $settings = get_option( $key, null );
            if ( is_array( $settings ) && array_key_exists( 'notran_description', $settings ) && $settings['notran_description'] === $value ) {
                unset( $settings['notran_description'] ); update_option( $key, $settings );
            }
        }
        foreach ( $created as $key => $value ) if ( get_option( $key, null ) === $value ) delete_option( $key );
    }

    private static function rows_restored( $plan ) {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
        foreach ( $plan as $item ) {
            $method = $wpdb->get_var( $wpdb->prepare( "SELECT method_id FROM $table WHERE instance_id=%d", $item['instance'] ) );
            if ( $item['from'] !== $method ) return false;
        }
        return true;
    }

    private static function invalidate() {
        if ( class_exists( 'WC_Cache_Helper' ) ) { WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' ); WC_Cache_Helper::get_transient_version( 'shipping', true ); }
    }

    public static function menu() {
        add_submenu_page( 'woocommerce', __( 'انتقال تنظیمات ارسال', 'notran-iran-shipping' ), __( 'انتقال تنظیمات ارسال', 'notran-iran-shipping' ), 'manage_woocommerce', 'notran-shipping-transfer', array( __CLASS__, 'page' ) );
    }
    public static function notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) || 'review' !== get_option( 'notran_ir_transfer_status', '' ) ) return;
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'چند روش ذخیره‌شده نیاز به بررسی دارند. از ابزار انتقال تنظیمات ارسال، مبدأ مناسب را انتخاب کنید.', 'notran-iran-shipping' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=notran-shipping-transfer' ) ) . '">' . esc_html__( 'بازکردن ابزار انتقال', 'notran-iran-shipping' ) . '</a></p></div>';
    }
    public static function page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'دسترسی مجاز نیست.', 'notran-iran-shipping' ) );
        $rows = self::rows(); $valid = is_array( $rows ) && count( $rows ) <= 5000;
        $candidates = $valid ? self::candidates( $rows ) : array();
        echo '<div class="wrap"><h1>' . esc_html__( 'انتقال تنظیمات ارسال', 'notran-iran-shipping' ) . '</h1><p>' . esc_html__( 'روش‌های هم‌ساختار را بررسی و تنظیمات هر منطقه را به شناسه‌های نوتران منتقل کنید. اطلاعات مبدأ و سفارش‌های ثبت‌شده حذف نمی‌شوند.', 'notran-iran-shipping' ) . '</p>';
        if ( 'done' === get_option( 'notran_ir_transfer_status', '' ) && (int) get_option( 'notran_ir_transfer_count', 0 ) ) echo '<div class="notice notice-success inline"><p>' . esc_html__( 'انتقال روش‌های شناسایی‌شده انجام شد.', 'notran-iran-shipping' ) . '</p></div>';
        $error = get_option( 'notran_ir_transfer_error', '' );
        if ( $error ) {
            if ( 'notran_conflict' === $error ) $message = __( 'تنظیمات مقصد از قبل با مقادیر متفاوت وجود دارد؛ برای جلوگیری از بازنویسی، انتقال انجام نشد.', 'notran-iran-shipping' );
            elseif ( 'notran_source_active' === $error ) $message = __( 'افزونهٔ مبدأ هنوز فعال است؛ برای جلوگیری از تداخل، ابتدا وضعیت آن را بررسی کنید.', 'notran-iran-shipping' );
            else $message = __( 'انتقال انجام نشد. تنظیمات مبدأ نگه داشته شده‌اند؛ وضعیت پایگاه داده و دسترسی نوشتن را بررسی کنید.', 'notran-iran-shipping' );
            echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
        }
        if ( ! $valid ) { echo '<p>' . esc_html__( 'روش‌های ارسال بیش از حد مجاز هستند یا خواندن آن‌ها از پایگاه داده ممکن نیست.', 'notran-iran-shipping' ) . '</p></div>'; return; }
        if ( ! $candidates ) { echo '<p>' . esc_html__( 'روش قابل انتقالی پیدا نشد.', 'notran-iran-shipping' ) . '</p></div>'; return; }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="notran_ir_transfer">';
        wp_nonce_field( 'notran_ir_transfer' );
        echo '<label for="notran-source">' . esc_html__( 'پیشوند روش‌های مبدأ', 'notran-iran-shipping' ) . '</label> <select id="notran-source" name="source" required>';
        foreach ( $candidates as $prefix => $candidate ) {
            $count = count( $candidate['methods'] );
            $label = $count ? sprintf( __( '%d روش', 'notran-iran-shipping' ), $count ) : __( 'تنظیمات روش آنلاین', 'notran-iran-shipping' );
            echo '<option value="' . esc_attr( $prefix ) . '">' . esc_html( $prefix . ' — ' . $label ) . '</option>';
        }
        echo '</select> <button type="submit" class="button button-primary">' . esc_html__( 'انتقال تنظیمات', 'notran-iran-shipping' ) . '</button></form></div>';
    }
    public static function submit() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'دسترسی مجاز نیست.', 'notran-iran-shipping' ), '', array( 'response' => 403 ) );
        check_admin_referer( 'notran_ir_transfer' );
        if ( ! self::lock() ) wp_die( esc_html__( 'انتقال دیگری در حال انجام است؛ کمی بعد دوباره تلاش کنید.', 'notran-iran-shipping' ), '', array( 'response' => 409 ) );
        $prefix = isset( $_POST['source'] ) && is_string( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        try {
            $rows = self::rows(); $candidates = is_array( $rows ) && count( $rows ) <= 5000 ? self::candidates( $rows ) : array();
            $result = self::transfer( $prefix, $rows, $candidates );
            update_option( 'notran_ir_transfer_error', is_wp_error( $result ) ? $result->get_error_code() : '', false );
            self::state( is_wp_error( $result ) ? 'review' : 'done' );
        } finally { delete_option( 'notran_ir_transfer_lock' ); }
        wp_safe_redirect( admin_url( 'admin.php?page=notran-shipping-transfer' ) ); exit;
    }
}
