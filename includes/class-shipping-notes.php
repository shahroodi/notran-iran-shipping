<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Notran_IR_Shipping_Notes {
    public static function init() {
        add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'attach_fields' ), PHP_INT_MAX );
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'rate_notes' ), 100, 2 );
        add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'label' ), 20, 2 );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_notes' ), 22 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'order_notes' ), 22, 4 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 30 );
        add_filter( 'theme_mod_' . str_replace( '-', '_', get_template() ) . '_free_shipping_enabled', static function( $enabled ) { return 'yes' === get_option( 'notran_ir_only_shipping_rules', 'no' ) ? false : $enabled; } );
        add_action( 'woocommerce_update_options_shipping_notran_iran', static function() { WC_Cache_Helper::get_transient_version( 'shipping', true ); } );
    }

    public static function postpaid_text() {
        return __( 'هزینهٔ ارسال در مبلغ سفارش محاسبه نشده است و هنگام تحویل مرسوله جداگانه دریافت می‌شود.', 'notran-iran-shipping' );
    }

    /** Add one native textarea per zone-method instance, including core methods. */
    public static function attach_fields( $methods ) {
        static $attached = array();
        foreach ( $methods as $id => $class ) {
            if ( ! is_string( $id ) || isset( $attached[ $id ] ) ) continue;
            add_filter( 'woocommerce_shipping_instance_form_fields_' . $id, array( __CLASS__, 'field' ) );
            add_filter( 'woocommerce_shipping_' . $id . '_instance_settings_values', static function( $settings ) { WC_Cache_Helper::get_transient_version( 'shipping', true ); return $settings; } );
            $attached[ $id ] = true;
        }
        return $methods;
    }

    public static function field( $fields ) {
        $fields['notran_description'] = array(
            'title' => __( 'توضیحات روش ارسال', 'notran-iran-shipping' ),
            'type' => 'textarea', 'default' => '',
            'description' => __( 'زیر گزینهٔ همین روش در سبد و صورتحساب نمایش داده می‌شود و همراه سفارش نگه‌داری می‌شود. متن ساده و چندخطی مجاز است.', 'notran-iran-shipping' ),
            'sanitize_callback' => static function( $value ) { return is_scalar( $value ) ? sanitize_textarea_field( wp_unslash( (string) $value ) ) : ''; },
        );
        return $fields;
    }

    public static function rate_notes( $rates, $package ) {
        foreach ( $rates as $rate ) {
            if ( ! $rate instanceof WC_Shipping_Rate ) continue;
            $instance = $rate->get_instance_id();
            if ( $instance ) $settings = get_option( 'woocommerce_' . $rate->get_method_id() . '_' . $instance . '_settings', array() );
            elseif ( Notran_IR_Shipping_Rates::is_theme_promotion( $rate ) ) $settings = array( 'notran_description' => get_option( 'notran_ir_promotion_description', '' ) );
            else continue;
            $text = is_scalar( $settings['notran_description'] ?? null ) ? sanitize_textarea_field( $settings['notran_description'] ) : '';
            if ( '' !== $text ) {
                $rate->add_meta_data( '_notran_shipping_description', $text );
                // WC cleans scalar rate metadata as one line. Keep a line array
                // too, while retaining the scalar for integrations.
                if ( false !== strpos( $text, "\n" ) ) $rate->add_meta_data( '_notran_shipping_description_lines', explode( "\n", $text ) );
            }
        }
        return $rates;
    }

    private static function text( $method_id, $custom, $historical = false ) {
        if ( is_array( $custom ) ) $custom = implode( "\n", array_filter( $custom, 'is_scalar' ) );
        $custom = is_scalar( $custom ) ? sanitize_textarea_field( (string) $custom ) : '';
        if ( '' !== $custom ) return $custom;
        if ( 'notran_postpaid' === $method_id ) return self::postpaid_text();
        if ( $historical ) foreach ( Notran_IR_Transfer::source_prefixes() as $prefix ) if ( $prefix . '_postpaid' === $method_id ) return self::postpaid_text();
        return '';
    }

    public static function label( $label, $rate ) {
        if ( ! $rate instanceof WC_Shipping_Rate ) return $label;
        if ( 'notran_postpaid' === $rate->get_method_id() ) $label = esc_html( $rate->get_label() );
        if ( 'notran_iran' === $rate->get_method_id() && null !== Notran_IR_Shipping_Rates::free_priority( $rate ) && false === strpos( Notran_IR_Catalog::key( $rate->get_label() ), 'رایگان' ) ) {
            $label .= '<small class="notran-shipping-description">' . esc_html__( 'ارسال رایگان', 'notran-iran-shipping' ) . '</small>';
        }
        $meta = $rate->get_meta_data();
        $text = self::text( $rate->get_method_id(), $meta['_notran_shipping_description_lines'] ?? $meta['_notran_shipping_description'] ?? '' );
        if ( ! empty( $meta['_notran_amadast_notes'] ) && is_array( $meta['_notran_amadast_notes'] ) ) $text = trim( $text . "\n" . implode( "\n", array_filter( $meta['_notran_amadast_notes'], 'is_scalar' ) ) );
        return $text ? $label . '<small class="notran-shipping-description">' . nl2br( esc_html( $text ) ) . '</small>' : $label;
    }

    public static function order_notes( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
        if ( ! $order instanceof WC_Order ) return;
        $notes = array();
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            $custom = $item->get_meta( '_notran_shipping_description_lines', true ) ?: $item->get_meta( '_notran_shipping_description', true );
            if ( ! $custom ) {
                foreach ( Notran_IR_Transfer::source_prefixes() as $prefix ) {
                    $custom = $item->get_meta( '_' . $prefix . '_shipping_description', true );
                    if ( $custom ) break;
                }
            }
            $text = self::text( $item->get_method_id(), $custom, true );
            $extra = $item->get_meta( '_notran_amadast_notes', true );
            if ( is_array( $extra ) ) $text = trim( $text . "\n" . implode( "\n", array_filter( $extra, 'is_scalar' ) ) );
            if ( $text ) $notes[] = array( $item->get_method_title(), $text );
        }
        if ( ! $notes ) return;
        if ( $plain_text ) {
            echo "\n" . __( 'توضیحات ارسال', 'notran-iran-shipping' ) . "\n";
            foreach ( $notes as $note ) echo $note[0] . "\n" . $note[1] . "\n";
            return;
        }
        echo '<section class="notran-order-shipping-notes"><h3>' . esc_html__( 'توضیحات ارسال', 'notran-iran-shipping' ) . '</h3>';
        foreach ( $notes as $note ) echo '<p><strong>' . esc_html( $note[0] ) . '</strong><br>' . nl2br( esc_html( $note[1] ) ) . '</p>';
        echo '</section>';
    }

    public static function styles() {
        if ( ! is_cart() && ! is_checkout() && ! ( is_account_page() && is_wc_endpoint_url( 'view-order' ) ) ) return;
        wp_register_style( 'notran-ir-shipping-notes', false, array(), NOTRAN_IR_SHIPPING_VERSION );
        wp_enqueue_style( 'notran-ir-shipping-notes' );
        wp_add_inline_style( 'notran-ir-shipping-notes', '.notran-shipping-description{display:block;margin-top:.35em;font-size:.84em;line-height:1.8;font-weight:400;color:inherit;overflow-wrap:anywhere}.notran-order-shipping-notes{margin-block:1rem;font-size:.9em;line-height:1.9}' );
    }
}
