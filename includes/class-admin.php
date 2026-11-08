<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Notran_IR_Shipping_Admin {
    public static function init() {
        add_filter( 'woocommerce_get_sections_shipping', static function( $sections ) { $sections['notran-locations'] = __( 'استان و شهر نوتران', 'notran-iran-shipping' ); return $sections; } );
        add_filter( 'woocommerce_get_settings_shipping', array( __CLASS__, 'settings' ), 20, 2 );
        add_filter( 'plugin_action_links_' . plugin_basename( NOTRAN_IR_SHIPPING_FILE ), static function( $links ) {
            array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=notran-locations' ) ) . '">' . esc_html__( 'تنظیمات', 'notran-iran-shipping' ) . '</a>' ); return $links;
        } );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function settings( $settings, $section = '' ) {
        if ( 'notran-locations' !== $section ) return $settings;
        $fields = array(
            array( 'title' => __( 'استان و شهر نوتران', 'notran-iran-shipping' ), 'type' => 'title', 'id' => 'notran_ir_locations', 'desc' => __( 'فهرست محلی ۳۱ استان و ۲۶۳۹ شهر و مقصد پستی ایران، مستقل از قالب سایت.', 'notran-iran-shipping' ) ),
            array( 'title' => __( 'فهرست شهرها', 'notran-iran-shipping' ), 'desc' => __( 'انتخاب شهر بر اساس استان در صورتحساب کلاسیک، ویرایش نشانی و محاسبه‌گر سبد خرید', 'notran-iran-shipping' ), 'id' => 'notran_ir_cities_enabled', 'type' => 'checkbox', 'default' => 'yes' ),
            array( 'type' => 'sectionend', 'id' => 'notran_ir_locations' ),
        );
        if ( function_exists( str_replace( '-', '_', get_template() ) . '_cart_free_shipping_enabled' ) ) {
            $fields = array_merge( $fields, array(
                array( 'title' => __( 'هماهنگی با قالب فعلی', 'notran-iran-shipping' ), 'type' => 'title', 'id' => 'notran_ir_theme_compat' ),
                array( 'title' => __( 'ارسال رایگان حداقل مبلغِ قالب', 'notran-iran-shipping' ), 'desc' => __( 'ارسال رایگانِ خودکار قالب کنار گذاشته شود و فقط قوانین روش‌های ارسال اعمال شوند. پس‌کرایه به‌تنهایی مشمول ارسال رایگانِ قالب نمی‌شود.', 'notran-iran-shipping' ), 'id' => 'notran_ir_only_shipping_rules', 'type' => 'checkbox', 'default' => 'no' ),
                array( 'title' => __( 'توضیح ارسال رایگانِ قالب', 'notran-iran-shipping' ), 'desc' => __( 'توضیح گزینهٔ ارسال رایگانِ حداقل مبلغِ قالب، در صورت واجدشرایط‌بودن مقصد و روش تحویل.', 'notran-iran-shipping' ), 'id' => 'notran_ir_promotion_description', 'type' => 'textarea', 'default' => '' ),
                array( 'type' => 'sectionend', 'id' => 'notran_ir_theme_compat' ),
            ) );
        }
        return array_merge( $fields, array(
            array( 'title' => __( 'تنظیم روش‌های ارسال', 'notran-iran-shipping' ), 'type' => 'title', 'id' => 'notran_ir_shipping_help', 'desc' => __( 'از «مناطق حمل‌ونقل»، روش «ارسال نوتران» یا «ارسال پس‌کرایه نوتران» را اضافه کنید و عنوان، توضیح، هزینه و شهرهای موردنظر را تنظیم کنید. عنوان‌ها و توضیحات واردشدهٔ شما حفظ می‌شوند.', 'notran-iran-shipping' ) ),
            array( 'type' => 'sectionend', 'id' => 'notran_ir_shipping_help' ),
        ) );
    }

    public static function assets() {
        if ( ! current_user_can( 'manage_woocommerce' ) || 'wc-settings' !== ( $_GET['page'] ?? '' ) || 'shipping' !== ( $_GET['tab'] ?? '' ) || empty( $_GET['instance_id'] ) ) return;
        $method = WC_Shipping_Zones::get_shipping_method( absint( $_GET['instance_id'] ) );
        if ( ! $method || 'notran_iran' !== $method->id ) return;
        wp_enqueue_style( 'notran-ir-admin', plugins_url( 'assets/admin.css', NOTRAN_IR_SHIPPING_FILE ), array(), NOTRAN_IR_SHIPPING_VERSION );
        wp_enqueue_script( 'notran-ir-cities', plugins_url( 'assets/cities.min.js', NOTRAN_IR_SHIPPING_FILE ), array( 'jquery' ), NOTRAN_IR_SHIPPING_VERSION, true );
        wp_localize_script( 'notran-ir-cities', 'notranIRCities', Notran_IR_Catalog::config() );
        wp_enqueue_script( 'notran-ir-admin', plugins_url( 'assets/admin.min.js', NOTRAN_IR_SHIPPING_FILE ), array( 'notran-ir-cities', 'wc-enhanced-select' ), NOTRAN_IR_SHIPPING_VERSION, true );
    }

    public static function rules_html( $key, $rows ) {
        if ( ! $rows ) $rows = array( array() );
        ob_start(); ?>
        <tr valign="top"><th scope="row"><?php esc_html_e( 'قوانین شهرها', 'notran-iran-shipping' ); ?></th><td>
        <div class="nt-ir-rules" data-next="<?php echo count( $rows ); ?>">
            <p class="description"><?php esc_html_e( 'در هر ردیف، شهرهای یک استان و نوع هزینه را انتخاب کنید. شهر تکراری در چند قانون پذیرفته نمی‌شود. شهرهای دیگر از هزینهٔ ثابت پیش‌فرض استفاده می‌کنند.', 'notran-iran-shipping' ); ?></p>
            <div class="nt-ir-rows"><?php foreach ( $rows as $i => $row ) self::row( $key, $i, is_array( $row ) ? $row : array() ); ?></div>
            <template class="nt-ir-template"><?php self::row( $key, '__ROW__', array() ); ?></template>
            <button type="button" class="button nt-ir-add"><?php esc_html_e( 'افزودن قانون شهر', 'notran-iran-shipping' ); ?></button>
            <p class="description"><?php echo esc_html( sprintf( __( 'مبالغ در واحد پول فروشگاه (%s) و برای هر بستهٔ ارسال هستند.', 'notran-iran-shipping' ), get_woocommerce_currency() ) ); ?></p>
        </div>
        </td></tr>
        <?php return ob_get_clean();
    }

    private static function row( $key, $i, $row ) {
        $base = $key . '[' . $i . ']';
        $state = Notran_IR_Catalog::state( $row['state'] ?? '' );
        $mode = 'flat' === ( $row['mode'] ?? '' ) ? 'flat' : 'free';
        $selected = (array) ( $row['cities'] ?? array() ); ?>
        <fieldset class="nt-ir-row"><legend class="screen-reader-text"><?php esc_html_e( 'قانون شهر', 'notran-iran-shipping' ); ?></legend>
          <label><?php esc_html_e( 'استان', 'notran-iran-shipping' ); ?><select class="nt-ir-state" name="<?php echo esc_attr( $base . '[state]' ); ?>"><option value=""><?php esc_html_e( 'انتخاب استان', 'notran-iran-shipping' ); ?></option><?php foreach ( Notran_IR_Catalog::provinces() as $code => $province ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $state, $code ); ?>><?php echo esc_html( $province['name'] ); ?></option><?php endforeach; ?></select></label>
          <label class="nt-ir-city-label"><?php esc_html_e( 'شهرها', 'notran-iran-shipping' ); ?><select class="nt-ir-cities" name="<?php echo esc_attr( $base . '[cities][]' ); ?>" multiple size="5" data-state="<?php echo esc_attr( $state ); ?>"><?php foreach ( Notran_IR_Catalog::cities( $state ) as $city ) : ?><option value="<?php echo esc_attr( $city ); ?>" <?php selected( in_array( $city, $selected, true ) ); ?>><?php echo esc_html( $city ); ?></option><?php endforeach; ?></select></label>
          <label><?php esc_html_e( 'نوع هزینه', 'notran-iran-shipping' ); ?><select class="nt-ir-mode" name="<?php echo esc_attr( $base . '[mode]' ); ?>"><option value="free" <?php selected( $mode, 'free' ); ?>><?php esc_html_e( 'رایگان', 'notran-iran-shipping' ); ?></option><option value="flat" <?php selected( $mode, 'flat' ); ?>><?php esc_html_e( 'مبلغ ثابت', 'notran-iran-shipping' ); ?></option></select></label>
          <label><?php esc_html_e( 'مبلغ', 'notran-iran-shipping' ); ?><input class="nt-ir-cost" type="text" inputmode="decimal" name="<?php echo esc_attr( $base . '[cost]' ); ?>" value="<?php echo esc_attr( $row['cost'] ?? '' ); ?>"></label>
          <button type="button" class="button nt-ir-remove" aria-label="<?php esc_attr_e( 'حذف این قانون', 'notran-iran-shipping' ); ?>"><?php esc_html_e( 'حذف', 'notran-iran-shipping' ); ?></button>
        </fieldset>
        <?php
    }
}
