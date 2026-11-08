<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class Notran_Amadast {
    public const SECTION = 'notran-amadast';
    private static $view_package = array();
    public static function init() {
        add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'signature' ), 110 );
        add_action( 'wc_ajax_notran_amadast_status', array( __CLASS__, 'status' ) );
        add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'rate_status' ), 20, 2 );
        add_action( 'woocommerce_before_template_part', array( __CLASS__, 'view_package' ), 10, 4 );
        add_action( 'woocommerce_after_template_part', static function( $name ) { if ( 'cart/cart-shipping.php' === $name ) self::$view_package = array(); } );
        foreach ( array( 'woocommerce_no_shipping_available_html', 'woocommerce_cart_no_shipping_available_html', 'woocommerce_shipping_may_be_available_html', 'woocommerce_shipping_not_enabled_on_cart_html' ) as $hook ) add_filter( $hook, array( __CLASS__, 'empty_status' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 31 );
        if ( is_admin() ) {
            add_filter( 'woocommerce_get_sections_shipping', static function( $sections ) { $sections[ self::SECTION ] = __( 'نرخ آنلاین نوتران', 'notran-iran-shipping' ); return $sections; } );
            add_filter( 'woocommerce_get_settings_shipping', array( __CLASS__, 'settings' ), 21, 2 );
            add_action( 'wp_loaded', array( __CLASS__, 'normalize_settings_route' ), 1 );
            add_action( 'woocommerce_update_options_shipping', array( __CLASS__, 'settings_saved' ), 30 );
            add_action( 'admin_notices', array( __CLASS__, 'admin_status' ) );
            add_filter( 'plugin_action_links_' . plugin_basename( NOTRAN_IR_SHIPPING_FILE ), static function( $links ) {
                array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'تنظیمات سرویس آنلاین', 'notran-iran-shipping' ) . '</a>' ); return $links;
            } );
            add_action( 'admin_post_notran_amadast_refresh', array( __CLASS__, 'refresh' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        }
    }

    public static function enabled() { return 'yes' === get_option( 'notran_amadast_enabled', 'no' ); }

    public static function settings_url() { return admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . self::SECTION ); }

    /** Route the settings alias away from the shipping-method save branch. */
    public static function normalize_settings_route() {
        if ( 'wc-settings' !== ( $_GET['page'] ?? '' ) || 'shipping' !== ( $_GET['tab'] ?? '' ) ) return;
        $aliases = array( 'notran_amadast' => self::SECTION );
        $section = $_GET['section'] ?? '';
        if ( ! is_string( $section ) || ! isset( $aliases[ $section ] ) ) return;
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
            $_GET['section'] = $aliases[ $section ]; $_REQUEST['section'] = $aliases[ $section ];
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . $aliases[ $section ] ) ); exit;
    }

    public static function settings_saved() {
        global $current_section;
        if ( ! in_array( $current_section, array( self::SECTION, 'notran-locations' ), true ) ) return;
        update_option( 'notran_amadast_config', wp_generate_uuid4(), false );
        WC_Cache_Helper::get_transient_version( 'shipping', true );
    }

    public static function setup_issues() {
        $issues = array();
        if ( ! self::enabled() ) $issues[] = __( 'اتصال سرویس آنلاین هنوز فعال و ذخیره نشده است.', 'notran-iran-shipping' );
        $from = absint( get_option( 'notran_amadast_origin', 0 ) ); $found = false;
        foreach ( Notran_Amadast_API::cities() as $cities ) if ( isset( $cities[ $from ] ) ) { $found = true; break; }
        if ( ! $found ) $issues[] = __( 'شهر مبدأ را در تنظیمات اتصال انتخاب و ذخیره کنید.', 'notran-iran-shipping' );
        $box = absint( get_option( 'notran_amadast_package_type', 0 ) );
        if ( $box < 1 || $box > 14 ) $issues[] = __( 'سایز بستهٔ آمادهٔ ارسال را انتخاب و ذخیره کنید.', 'notran-iran-shipping' );
        if ( ! in_array( get_woocommerce_currency(), array( 'IRR', 'IRT' ), true ) ) $issues[] = __( 'واحد پول این اتصال باید ریال (IRR) یا تومان (IRT) باشد.', 'notran-iran-shipping' );
        return $issues;
    }

    public static function admin_status() {
        if ( ! current_user_can( 'manage_woocommerce' ) || 'wc-settings' !== ( $_GET['page'] ?? '' ) || 'shipping' !== ( $_GET['tab'] ?? '' ) ) return;
        $instance = null;
        if ( ! empty( $_GET['instance_id'] ) ) $instance = WC_Shipping_Zones::get_shipping_method( absint( $_GET['instance_id'] ) );
        if ( self::SECTION !== ( $_GET['section'] ?? '' ) && ( ! $instance || 'notran_amadast' !== $instance->id ) ) return;
        $issues = self::setup_issues();
        if ( $instance && ! $instance->service_ids() ) $issues[] = __( 'شرکت و سرویس را در تنظیمات همین روش انتخاب و ذخیره کنید.', 'notran-iran-shipping' );
        if ( $issues ) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'سرویس آنلاین برای محاسبهٔ کرایه آماده نیست.', 'notran-iran-shipping' ) . '</strong></p><ul>';
            foreach ( $issues as $issue ) echo '<li>' . esc_html( $issue ) . '</li>';
            echo '</ul><p><a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'بازکردن تنظیمات اتصال سرویس آنلاین', 'notran-iran-shipping' ) . '</a></p></div>';
        }
    }

    public static function signature( $packages ) {
        if ( ! self::enabled() ) return $packages;
        $signature = get_option( 'notran_amadast_config', '' ) . ':' . floor( time() / 300 ) . ':' . ( WC()->session ? WC()->session->get( 'notran_amadast_revision', '' ) : '' );
        foreach ( $packages as &$package ) $package['notran_amadast'] = $signature;
        unset( $package );
        return $packages;
    }

    /** Shared payload across all enabled instances in the matching Woo zone. */
    public static function context( $package ) {
        if ( ! self::enabled() || ! in_array( get_woocommerce_currency(), array( 'IRR', 'IRT' ), true ) || 'IR' !== ( $package['destination']['country'] ?? '' ) ) return array();
        $to = Notran_Amadast_API::city_id( $package['destination']['state'] ?? '', $package['destination']['city'] ?? '' );
        $from = absint( get_option( 'notran_amadast_origin', 0 ) );
        $valid_origin = false;
        foreach ( Notran_Amadast_API::cities() as $cities ) if ( isset( $cities[ $from ] ) ) { $valid_origin = true; break; }
        if ( ! $to || ! $valid_origin ) return array();
        $catalog = Notran_Amadast_API::services();
        $couriers = array(); $prepaid = array();
        $zone = WC_Shipping_Zones::get_zone_matching_package( $package );
        foreach ( $zone->get_shipping_methods( true ) as $method ) {
            if ( 'free_shipping' === $method->id && $method->is_available( $package ) ) return array();
            if ( in_array( $method->id, array( 'notran_iran', 'flat_rate' ), true ) ) {
                foreach ( $method->get_rates_for_package( $package ) as $rate ) {
                    if ( null !== Notran_IR_Shipping_Rates::free_priority( $rate ) ) return array();
                    $prepaid[] = $rate;
                }
            }
            if ( 'notran_amadast' !== $method->id || ! $method->is_available( $package ) ) continue;
            foreach ( $method->service_ids() as $service ) $couriers[] = (int) $catalog[ $service ]['courier'];
        }
        $theme_promotion = str_replace( '-', '_', get_template() ) . '_add_threshold_free_shipping_rate';
        if ( $prepaid && 'yes' !== get_option( 'notran_ir_only_shipping_rules', 'no' ) && function_exists( $theme_promotion ) ) {
            foreach ( (array) $theme_promotion( $prepaid, $package ) as $rate ) if ( Notran_IR_Shipping_Rates::is_theme_promotion( $rate ) ) return array();
        }
        $couriers = array_values( array_unique( array_filter( $couriers ) ) ); sort( $couriers );
        if ( ! $couriers ) return array();
        $weight = max( 0, (float) get_option( 'notran_amadast_package_weight', 0 ) );
        $value = 0; $count = 0;
        foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
            $product = $item['data'] ?? null;
            if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) continue;
            $quantity = (float) ( $item['quantity'] ?? 0 );
            if ( $quantity <= 0 || ! is_finite( $quantity ) ) continue;
            $grams = $product->has_weight() ? wc_get_weight( (float) $product->get_weight(), 'g' ) : (float) get_option( 'notran_amadast_default_weight', 0 );
            if ( $grams <= 0 || ! is_finite( $grams ) ) return array();
            $weight += $grams * $quantity;
            $value += max( 0, (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ) );
            $count++;
        }
        if ( ! $count || $weight <= 0 || $weight > 1e7 || ! is_finite( $weight ) || ! is_finite( $value ) ) return array();
        $rial_value = $value * ( 'IRT' === get_woocommerce_currency() ? 10 : 1 );
        if ( $rial_value > 1e15 ) return array();
        $box = absint( get_option( 'notran_amadast_package_type', 0 ) );
        if ( $box < 1 || $box > 14 ) return array();
        return array( 'from_city' => $from, 'to_city' => $to, 'weight' => (int) ceil( $weight ), 'package_type' => $box,
            'value' => (int) ceil( $rial_value ),
            'couriers' => $couriers, 'is_plugin' => true,
            'meta_data' => array( 'integration' => 'notran-iran-shipping', 'plugin_version' => NOTRAN_IR_SHIPPING_VERSION ) );
    }

    private static function states() {
        if ( ! self::enabled() || ! WC()->cart ) return array();
        $states = array();
        foreach ( array_slice( WC()->cart->get_shipping_packages(), 0, 5 ) as $package ) {
            $context = self::context( $package );
            if ( $context ) { $key = Notran_Amadast_API::key( $context ); $states[ $key ] = Notran_Amadast_API::state( $key ); }
        }
        return $states;
    }

    public static function status() {
        if ( ! check_ajax_referer( 'notran_amadast_status', 'nonce', false ) ) wp_send_json_error( array(), 403 );
        if ( ! WC()->cart || ! WC()->session ) wp_send_json_error( array(), 400 );
        $states = self::states(); $advanced = false; $changed = false; $pending = false; $delay = 10;
        foreach ( $states as $key => $state ) {
            $before = $state['status'] ?? '';
            if ( ! $advanced && in_array( $before, array( 'queued', 'pending' ), true ) && time() >= ( $state['next'] ?? 0 ) ) {
                $last = (int) WC()->session->get( 'notran_amadast_last_start', 0 );
                if ( 'queued' !== $before || $last <= time() - 4 ) {
                    if ( 'queued' === $before ) WC()->session->set( 'notran_amadast_last_start', time() );
                    $state = Notran_Amadast_API::advance( $key ); $advanced = true;
                }
            }
            $current = $state['status'] ?? '';
            if ( in_array( $current, array( 'queued', 'pending' ), true ) ) { $pending = true; $delay = min( $delay, max( 4, (int) ( $state['next'] ?? 0 ) - time() ) ); }
            if ( in_array( $before, array( 'queued', 'pending' ), true ) && in_array( $current, array( 'ready', 'error' ), true ) ) $changed = true;
        }
        // The Woo package cache may still contain empty rates from the initial view.
        if ( $changed || ! $pending ) WC()->session->set( 'notran_amadast_revision', wp_generate_uuid4() );
        wp_send_json_success( array( 'pending' => $pending, 'refresh' => $changed || ! $pending, 'delay' => $delay ) );
    }

    /** Use native shipping markup: no extra table row, faux rate or template override. */
    public static function view_package( $name, $path, $located, $args ) {
        if ( 'cart/cart-shipping.php' === $name ) self::$view_package = $args['package'] ?? array();
    }
    public static function empty_status( $html ) { return self::status_html( self::$view_package ) ?: $html; }
    public static function rate_status( $rate, $index ) {
        $package = WC()->shipping()->get_packages()[ $index ] ?? array();
        $rates = $package['rates'] ?? array();
        if ( $rates && $rate === end( $rates ) ) echo self::status_html( $package ); // Escaped in status_html.
    }
    private static function status_html( $package ) {
        if ( ! self::enabled() || ! $package || 'IR' !== ( $package['destination']['country'] ?? '' ) ) return '';
        $message = ''; $pending = false; $key = '';
        $has_rate = false; $free = false;
        foreach ( (array) ( $package['rates'] ?? array() ) as $rate ) {
            if ( null !== Notran_IR_Shipping_Rates::free_priority( $rate ) ) $free = true;
            if ( 'notran_amadast' === $rate->get_method_id() ) $has_rate = true;
        }
        if ( $free ) return '';
        $selected = false;
        foreach ( WC_Shipping_Zones::get_zone_matching_package( $package )->get_shipping_methods( true ) as $method ) {
            if ( 'notran_amadast' === $method->id && 'yes' === $method->get_option( 'enabled', 'yes' ) && $method->service_ids() ) { $selected = true; break; }
        }
        if ( ! $selected ) return '';
        $context = self::context( $package );
        $key = $context ? Notran_Amadast_API::key( $context ) : '';
        $state = $key ? Notran_Amadast_API::state( $key ) : array();
        if ( in_array( $state['status'] ?? '', array( 'queued', 'pending' ), true ) ) {
            $pending = true; $message = __( 'محاسبهٔ ارسال…', 'notran-iran-shipping' );
        } elseif ( $has_rate ) {
            return '';
        } elseif ( empty( $package['destination']['city'] ) || empty( $package['destination']['state'] ) ) {
            $message = __( 'برای محاسبهٔ هزینهٔ ارسال، استان و شهر مقصد را انتخاب کنید.', 'notran-iran-shipping' );
        } elseif ( 'error' === ( $state['status'] ?? '' ) ) {
            $message = __( 'دریافت هزینهٔ ارسال آنلاین انجام نشد؛ در صورت وجود، روش ارسال دیگری انتخاب کنید.', 'notran-iran-shipping' );
        } elseif ( 'ready' === ( $state['status'] ?? '' ) || ! Notran_Amadast_API::city_id( $package['destination']['state'], $package['destination']['city'] ) ) {
            $message = __( 'برای این مقصد، از سرویس‌های آنلاین انتخاب‌شده نرخی دریافت نشد.', 'notran-iran-shipping' );
        } else {
            $message = __( 'هزینهٔ ارسال آنلاین این سبد فعلاً قابل محاسبه نیست؛ در صورت وجود، روش ارسال دیگری انتخاب کنید.', 'notran-iran-shipping' );
        }
        return '<span class="notran-amadast-status' . ( $pending ? ' notran-amadast-pending' : '' ) . '" role="status" aria-live="polite" data-notran-key="' . esc_attr( $key ) . '"><span class="notran-amadast-message">' . esc_html( $message ) . '</span></span>';
    }

    public static function assets() {
        if ( ! self::enabled() || ( ! is_cart() && ! is_checkout() ) || is_order_received_page() || is_wc_endpoint_url( 'order-pay' ) ) return;
        wp_enqueue_script( 'notran-amadast', plugins_url( 'assets/amadast.min.js', NOTRAN_IR_SHIPPING_FILE ), array( 'jquery' ), NOTRAN_IR_SHIPPING_VERSION, true );
        wp_localize_script( 'notran-amadast', 'notranAmadast', array( 'url' => WC_AJAX::get_endpoint( 'notran_amadast_status' ), 'nonce' => wp_create_nonce( 'notran_amadast_status' ), 'checkout' => is_checkout(),
            'error' => __( 'دریافت هزینهٔ ارسال انجام نشد.', 'notran-iran-shipping' ), 'loading' => __( 'محاسبهٔ ارسال…', 'notran-iran-shipping' ), 'retry' => __( 'تلاش دوباره', 'notran-iran-shipping' ) ) );
        wp_add_inline_style( 'notran-ir-shipping-notes', '.notran-amadast-status{display:inline-flex;align-items:center;flex-wrap:wrap;gap:.4em;margin-inline-start:.65em;font-size:11px;font-weight:400;line-height:1.8;color:#626854;vertical-align:middle;max-width:100%}.notran-amadast-pending:not(.is-error):before{content:"";width:.75em;height:.75em;flex:none;border:1.5px solid currentColor;border-inline-end-color:transparent;border-radius:50%;animation:notran-spin .9s linear infinite}.notran-amadast-retry{font:inherit!important;color:inherit!important;background:none!important;border:0!important;box-shadow:none!important;padding:0 .2em!important;text-decoration:underline;cursor:pointer}@keyframes notran-spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.notran-amadast-pending:before{animation:none!important}}' );
    }

    public static function settings( $settings, $section ) {
        if ( self::SECTION !== $section ) return $settings;
        $options = array( '' => __( 'انتخاب شهر مبدأ', 'notran-iran-shipping' ) );
        foreach ( Notran_Amadast_API::cities() as $state => $cities ) foreach ( $cities as $id => $name ) $options[ $id ] = Notran_IR_Catalog::provinces()[ $state ]['name'] . ' — ' . $name;
        $refresh = wp_nonce_url( admin_url( 'admin-post.php?action=notran_amadast_refresh' ), 'notran_amadast_refresh' );
        $desc = __( 'در مناطق حمل‌ونقل، روش «ارسال آنلاین نوتران» را اضافه و یک یا چند شرکت و سرویس را انتخاب کنید. تخفیف و هزینه‌های آن برای همهٔ انتخاب‌ها یکسان است؛ برای شرایط مستقل، روش دیگری بسازید. در همان روش تخفیف و هزینه‌های اضافه را تنظیم کنید. فقط سرویس‌های فعال استعلام می‌شوند. درخواست شامل شهرها، وزن، ارزش، نوع بسته و شناسهٔ شرکت است؛ نام، تلفن و نشانی مشتری ارسال نمی‌شوند.', 'notran-iran-shipping' );
        if ( isset( $_GET['notran_result'] ) ) $desc .= '<p>' . esc_html( 'success' === $_GET['notran_result'] ? __( 'فهرست سرویس‌ها به‌روز شد.', 'notran-iran-shipping' ) : __( 'ارتباط برقرار نشد؛ فهرست قبلی حفظ شد.', 'notran-iran-shipping' ) ) . '</p>';
        return array(
            array( 'title' => __( 'محاسبهٔ آنلاین سرویس آنلاین', 'notran-iran-shipping' ), 'type' => 'title', 'id' => 'notran_amadast_settings', 'desc' => $desc ),
            array( 'title' => __( 'فعال‌سازی اتصال', 'notran-iran-shipping' ), 'id' => 'notran_amadast_enabled', 'type' => 'checkbox', 'default' => 'no', 'desc' => __( 'دریافت کرایه از API عمومی سرویس آنلاین برای روش‌های انتخاب‌شده', 'notran-iran-shipping' ) ),
            array( 'title' => __( 'شهر مبدأ', 'notran-iran-shipping' ), 'id' => 'notran_amadast_origin', 'type' => 'select', 'class' => 'wc-enhanced-select', 'options' => $options ),
            array( 'title' => __( 'بستهٔ آمادهٔ ارسال', 'notran-iran-shipping' ), 'id' => 'notran_amadast_package_type', 'type' => 'select', 'default' => '', 'options' => self::boxes(), 'desc' => __( 'سایز بسته‌بندیِ سفارش را انتخاب کنید؛ اندازهٔ بازِ حوله یا پارچه، سایز بستهٔ پستی نیست.', 'notran-iran-shipping' ) ),
            array( 'title' => __( 'وزن بسته‌بندی (گرم)', 'notran-iran-shipping' ), 'id' => 'notran_amadast_package_weight', 'type' => 'number', 'default' => '0', 'custom_attributes' => array( 'min' => '0', 'max' => '100000', 'step' => '1' ), 'desc' => __( 'به وزن محصولات هر بسته افزوده می‌شود.', 'notran-iran-shipping' ) ),
            array( 'title' => __( 'وزن جایگزین هر واحد (گرم)', 'notran-iran-shipping' ), 'id' => 'notran_amadast_default_weight', 'type' => 'number', 'default' => '0', 'custom_attributes' => array( 'min' => '0', 'max' => '1000000', 'step' => '1' ), 'desc' => __( 'فقط برای محصول فاقد وزن؛ صفر یعنی تا تکمیل وزن، نرخ آنلاین ارائه نشود. برای پارچه وزن یک متر را در محصول ثبت کنید؛ مقدار اعشاری سفارش لحاظ می‌شود.', 'notran-iran-shipping' ) ),
            array( 'type' => 'sectionend', 'id' => 'notran_amadast_settings', 'desc' => '' ),
            array( 'title' => __( 'فهرست شرکت‌ها و سرویس‌ها', 'notran-iran-shipping' ), 'type' => 'title', 'id' => 'notran_amadast_catalog', 'desc' => '<a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'به‌روزرسانی فهرست از سرویس آنلاین', 'notran-iran-shipping' ) . '</a><p>' . esc_html__( 'فهرست فقط با این دکمه دریافت می‌شود؛ تازه‌شدن فهرست، سرویس جدیدی را خودکار فعال نمی‌کند. واحدهای پول پشتیبانی‌شده: ریال (IRR) و تومان (IRT). این اتصال فقط کرایه را استعلام می‌کند؛ ثبت مرسوله و خرید بارکد انجام نمی‌دهد.', 'notran-iran-shipping' ) . '</p>' ),
            array( 'type' => 'sectionend', 'id' => 'notran_amadast_catalog' ),
        );
    }

    public static function boxes() {
        $out = array( '' => __( 'انتخاب سایز بسته', 'notran-iran-shipping' ) );
        for ( $i = 1; $i <= 10; $i++ ) $out[ $i ] = sprintf( __( 'کارتن سایز %d', 'notran-iran-shipping' ), $i );
        return $out + array( 11 => __( 'کارتن بزرگ‌تر از سایز ۱۰', 'notran-iran-shipping' ), 12 => __( 'پاکت A4/A5', 'notran-iran-shipping' ), 13 => __( 'بسته‌بندی برنج', 'notran-iran-shipping' ), 14 => __( 'پاکت A3', 'notran-iran-shipping' ) );
    }

    public static function refresh() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'دسترسی مجاز نیست.', 'notran-iran-shipping' ), '', array( 'response' => 403 ) );
        check_admin_referer( 'notran_amadast_refresh' );
        $result = Notran_Amadast_API::refresh_services();
        wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . self::SECTION . '&notran_result=' . ( is_wp_error( $result ) ? 'error' : 'success' ) ) ); exit;
    }

    public static function admin_assets() {
        if ( ! current_user_can( 'manage_woocommerce' ) || 'wc-settings' !== ( $_GET['page'] ?? '' ) || 'shipping' !== ( $_GET['tab'] ?? '' ) || empty( $_GET['instance_id'] ) ) return;
        $method = WC_Shipping_Zones::get_shipping_method( absint( $_GET['instance_id'] ) );
        if ( ! $method || 'notran_amadast' !== $method->id ) return;
        wp_enqueue_script( 'notran-amadast-admin', plugins_url( 'assets/amadast-admin.min.js', NOTRAN_IR_SHIPPING_FILE ), array(), NOTRAN_IR_SHIPPING_VERSION, true );
        wp_enqueue_style( 'notran-amadast-admin', plugins_url( 'assets/amadast-admin.css', NOTRAN_IR_SHIPPING_FILE ), array(), NOTRAN_IR_SHIPPING_VERSION );
    }
}
