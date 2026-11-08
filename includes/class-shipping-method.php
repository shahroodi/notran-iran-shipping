<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Notran_IR_Shipping_Method extends WC_Shipping_Method {
    protected $postpaid = false;
    public function __construct( $instance_id = 0 ) {
        $this->id = $this->postpaid ? 'notran_postpaid' : 'notran_iran';
        $this->instance_id = absint( $instance_id );
        $this->method_title = $this->postpaid ? __( 'ارسال پس‌کرایه نوتران', 'notran-iran-shipping' ) : __( 'ارسال نوتران', 'notran-iran-shipping' );
        $this->method_description = $this->postpaid ? Notran_IR_Shipping_Notes::postpaid_text() : __( 'هزینهٔ ثابت برای ایران و ارسال رایگان یا مبلغ اختصاصی برای شهرهای منتخب. مبلغ هر بسته در واحد پول فروشگاه محاسبه می‌شود.', 'notran-iran-shipping' );
        $this->supports = array( 'shipping-zones', 'instance-settings' );
        $this->instance_form_fields = array(
            'title' => array( 'title' => __( 'عنوان روش ارسال', 'notran-iran-shipping' ), 'type' => 'text', 'default' => $this->postpaid ? __( 'ارسال پس‌کرایه', 'notran-iran-shipping' ) : __( 'ارسال', 'notran-iran-shipping' ) ),
        );
        if ( ! $this->postpaid ) $this->instance_form_fields += array(
            'cost' => array( 'title' => sprintf( __( 'هزینهٔ ثابت پیش‌فرض (%s)', 'notran-iran-shipping' ), get_woocommerce_currency() ), 'type' => 'text', 'default' => '', 'description' => __( 'برای سایر شهرهای معتبر این منطقه. خالی: این روش برای سایر شهرها نمایش داده نمی‌شود؛ صفر: ارسال رایگان. مبلغ از سرویس پست استعلام نمی‌شود.', 'notran-iran-shipping' ), 'custom_attributes' => array( 'inputmode' => 'decimal' ) ),
            'tax_status' => array( 'title' => __( 'مالیات ارسال', 'notran-iran-shipping' ), 'type' => 'select', 'default' => 'none', 'options' => array( 'none' => __( 'بدون مالیات', 'notran-iran-shipping' ), 'taxable' => __( 'مشمول مالیات', 'notran-iran-shipping' ) ) ),
            'city_rules' => array( 'title' => __( 'قوانین شهرها', 'notran-iran-shipping' ), 'type' => 'notran_rules', 'default' => array() ),
        );
        $this->title = $this->get_option( 'title', $this->postpaid ? __( 'ارسال پس‌کرایه', 'notran-iran-shipping' ) : __( 'ارسال', 'notran-iran-shipping' ) );
        $this->tax_status = $this->postpaid ? 'none' : $this->get_option( 'tax_status', 'none' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function validate_cost_field( $key, $value ) {
        return Notran_IR_Catalog::amount( $value ?? '', true );
    }

    public function validate_city_rules_field( $key, $value ) {
        if ( null === $value ) return array();
        if ( ! is_array( $value ) || count( $value ) > 80 ) throw new InvalidArgumentException( __( 'حداکثر ۸۰ ردیف قانون مجاز است.', 'notran-iran-shipping' ) );
        $out = array(); $used = array();
        foreach ( $value as $row ) {
            if ( ! is_array( $row ) ) throw new InvalidArgumentException( __( 'ساختار قانون شهرها معتبر نیست.', 'notran-iran-shipping' ) );
            if ( empty( $row['state'] ) && empty( $row['cities'] ) ) continue;
            $state = Notran_IR_Catalog::state( $row['state'] ?? '' );
            if ( ! $state || ! is_array( $row['cities'] ?? null ) || ! count( $row['cities'] ) || count( $row['cities'] ) > 1000 ) throw new InvalidArgumentException( __( 'استان و دست‌کم یک شهر معتبر را برای هر قانون انتخاب کنید.', 'notran-iran-shipping' ) );
            $mode = $row['mode'] ?? '';
            if ( ! in_array( $mode, array( 'free', 'flat' ), true ) ) throw new InvalidArgumentException( __( 'نوع هزینهٔ قانون معتبر نیست.', 'notran-iran-shipping' ) );
            $cost = 'free' === $mode ? '0' : Notran_IR_Catalog::amount( $row['cost'] ?? '' );
            $cities = array();
            foreach ( $row['cities'] as $input ) {
                $city = Notran_IR_Catalog::city( $state, wp_unslash( $input ) );
                if ( ! $city ) throw new InvalidArgumentException( __( 'یکی از شهرها متعلق به استان انتخاب‌شده نیست.', 'notran-iran-shipping' ) );
                $key = $state . ':' . Notran_IR_Catalog::key( $city );
                if ( isset( $used[ $key ] ) ) throw new InvalidArgumentException( __( 'یک شهر در چند قانون تکرار شده است؛ برای هر شهر فقط یک قانون نگه دارید.', 'notran-iran-shipping' ) );
                $used[ $key ] = true; $cities[] = $city;
            }
            $out[] = array( 'state' => $state, 'cities' => $cities, 'mode' => $mode, 'cost' => $cost );
        }
        return $out;
    }

    public function process_admin_options() {
        // WooCommerce owns the settings nonce, capability check and instance ID.
        $saved = parent::process_admin_options();
        if ( $saved ) WC_Cache_Helper::get_transient_version( 'shipping', true );
        return $saved;
    }

    public function calculate_shipping( $package = array() ) {
        $destination = $package['destination'] ?? array();
        if ( ! $this->is_enabled() || 'IR' !== ( $destination['country'] ?? '' ) ) return;
        $state = Notran_IR_Catalog::state( $destination['state'] ?? '' );
        $city = Notran_IR_Catalog::city( $state, $destination['city'] ?? '' );
        if ( ! $state || ! $city ) return;
        if ( $this->postpaid ) {
            $label = $this->title;
            $this->add_rate( array( 'id' => $this->get_rate_id(), 'label' => $label, 'cost' => 0, 'taxes' => false, 'package' => $package, 'meta_data' => array( '_notran_postpaid' => 'yes' ) ) );
            return;
        }
        $cost = $this->get_option( 'cost', '' );
        foreach ( (array) $this->get_option( 'city_rules', array() ) as $rule ) {
            if ( $state !== ( $rule['state'] ?? '' ) ) continue;
            foreach ( (array) ( $rule['cities'] ?? array() ) as $match ) {
                if ( Notran_IR_Catalog::key( $match ) === Notran_IR_Catalog::key( $city ) ) {
                    $cost = 'free' === ( $rule['mode'] ?? '' ) ? '0' : ( $rule['cost'] ?? '' );
                    break 2;
                }
            }
        }
        // A missing/invalid setting must never turn into a zero-cost shipment.
        if ( '' === $cost || ! is_numeric( $cost ) || (float) $cost < 0 ) return;
        $label = $this->title;
        $this->add_rate( array( 'id' => $this->get_rate_id(), 'label' => $label, 'cost' => $cost, 'package' => $package ) );
    }

    public function generate_notran_rules_html( $key, $data ) {
        if ( ! class_exists( 'Notran_IR_Shipping_Admin' ) ) require_once NOTRAN_IR_SHIPPING_DIR . 'includes/class-admin.php';
        return Notran_IR_Shipping_Admin::rules_html( $this->get_field_key( $key ), (array) $this->get_option( $key, array() ) );
    }
}

final class Notran_IR_Postpaid_Method extends Notran_IR_Shipping_Method {
    protected $postpaid = true;
}
