<?php
/**
 * Plugin Name: حمل‌ونقل نوتران — استان و شهر ایران
 * Description: فهرست محلی استان‌ها و مقصدهای پستی ایران، ارسال رایگان، ثابت و پس‌کرایه و محاسبهٔ آنلاین کرایهٔ حمل همراه تخفیف و هزینه‌های اختصاصی هر سرویس.
 * Version: 2.0.0
 * Author: Ali Shahroodi
 * Author URI: https://shahroodi.com/
 * License: GPL-3.0-only
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: notran-iran-shipping
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.8
 * WC tested up to: 11.2.2
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined( 'NOTRAN_IR_SHIPPING_VERSION' ) ) {
    add_action( 'admin_notices', static function() {
        if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p>' . esc_html__( 'بیش از یک نسخه از حمل‌ونقل نوتران فعال است؛ فقط یک نسخه را فعال نگه دارید.', 'notran-iran-shipping' ) . '</p></div>';
    } );
    return;
}
define( 'NOTRAN_IR_SHIPPING_VERSION', '2.0.0' );
define( 'NOTRAN_IR_SHIPPING_FILE', __FILE__ );
define( 'NOTRAN_IR_SHIPPING_DIR', __DIR__ . '/' );

add_action( 'before_woocommerce_init', static function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        // City dropdowns target classic address forms.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
    }
} );

add_action( 'plugins_loaded', static function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function() {
            if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p>' . esc_html__( 'ارسال نوتران برای کارکرد به افزونهٔ فروشگاه نیاز دارد.', 'notran-iran-shipping' ) . '</p></div>';
        } );
        return;
    }
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-catalog.php';
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-addresses.php';
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-shipping-notes.php';
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-shipping-rates.php';
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-transfer.php';
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-amadast-api.php';
    require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-amadast.php';
    Notran_Amadast::init();
    Notran_IR_Transfer::init();
    Notran_IR_Addresses::init();
    Notran_IR_Shipping_Notes::init();
    Notran_IR_Shipping_Rates::init();
    add_action( 'woocommerce_shipping_init', static function() {
        require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-shipping-method.php';
        require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-amadast-method.php';
    } );
    add_filter( 'woocommerce_shipping_methods', static function( $methods ) {
        $methods['notran_iran'] = 'Notran_IR_Shipping_Method';
        $methods['notran_postpaid'] = 'Notran_IR_Postpaid_Method';
        $methods['notran_amadast'] = 'Notran_Amadast_Method';
        return $methods;
    } );
    if ( is_admin() ) {
        require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-admin.php';
        Notran_IR_Shipping_Admin::init();
    }
}, 20 );
