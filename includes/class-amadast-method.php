<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class Notran_Amadast_Method extends WC_Shipping_Method {
    public function __construct( $instance_id = 0 ) {
        $this->id = 'notran_amadast'; $this->instance_id = absint( $instance_id );
        $this->method_title = __( 'ارسال آنلاین نوتران', 'notran-iran-shipping' );
        $this->method_description = __( 'یک یا چند شرکت و سرویس را انتخاب کنید؛ هر سرویس با قیمت خودش نمایش داده می‌شود. تخفیف و هزینه‌های این روش برای همهٔ انتخاب‌ها یکسان است. برای شرایط متفاوت، روش جداگانه بسازید. ابتدا اتصال و مبدأ را در «نرخ آنلاین نوتران» تنظیم کنید.', 'notran-iran-shipping' );
        $this->method_description .= ' <a href="' . esc_url( Notran_Amadast::settings_url() ) . '">' . esc_html__( 'تنظیم اتصال، مبدأ و بسته‌بندی', 'notran-iran-shipping' ) . '</a>';
        $this->supports = array( 'shipping-zones', 'instance-settings' );
        $services = array();
        foreach ( Notran_Amadast_API::services() as $id => $service ) $services[ $id ] = $service['title'];
        $this->instance_form_fields = array(
            'enabled' => array( 'title' => __( 'فعال‌سازی این روش', 'notran-iran-shipping' ), 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'در این منطقه نمایش داده شود', 'notran-iran-shipping' ) ),
            'service' => array( 'title' => __( 'شرکت‌ها و سرویس‌های حمل', 'notran-iran-shipping' ), 'type' => 'multiselect', 'class' => 'wc-enhanced-select', 'default' => array(), 'options' => $services, 'description' => __( 'می‌توانید چند مورد را هم‌زمان انتخاب کنید. فقط همین سرویس‌ها نمایش داده می‌شوند؛ سرویس‌های دیگر همان شرکت خودکار فعال نمی‌شوند.', 'notran-iran-shipping' ) ),
            'title' => array( 'title' => __( 'عنوان دلخواه', 'notran-iran-shipping' ), 'type' => 'text', 'default' => '', 'description' => __( 'خالی باشد، نام شرکت و سرویس استفاده می‌شود. در انتخاب چند سرویس، نام هر سرویس به عنوان دلخواه افزوده می‌شود.', 'notran-iran-shipping' ) ),
            'price_basis' => array( 'title' => __( 'مبنای نرخ آنلاین', 'notran-iran-shipping' ), 'type' => 'select', 'default' => 'discounted', 'options' => array( 'discounted' => __( 'نرخ تخفیف‌خوردهٔ سرویس؛ در نبود آن نرخ عادی', 'notran-iran-shipping' ), 'regular' => __( 'نرخ عادی سرویس', 'notran-iran-shipping' ) ) ),
            'discount_type' => array( 'title' => __( 'نوع تخفیف فروشگاه', 'notran-iran-shipping' ), 'type' => 'select', 'default' => 'none', 'options' => array( 'none' => __( 'بدون تخفیف', 'notran-iran-shipping' ), 'fixed' => __( 'مبلغ ثابت', 'notran-iran-shipping' ), 'percent' => __( 'درصد', 'notran-iran-shipping' ) ) ),
            'discount_value' => array( 'title' => __( 'مقدار تخفیف', 'notran-iran-shipping' ), 'type' => 'text', 'default' => '0', 'description' => __( 'درصد بین صفر تا ۱۰۰؛ مبلغ ثابت در واحد پول فروشگاه است. تخفیف فقط از کرایه کم می‌شود.', 'notran-iran-shipping' ), 'custom_attributes' => array( 'inputmode' => 'decimal' ) ),
            'extra_costs' => array( 'title' => __( 'هزینه‌های اضافی', 'notran-iran-shipping' ), 'type' => 'notran_fees', 'default' => array() ),
            'show_details' => array( 'title' => __( 'شرح محاسبه', 'notran-iran-shipping' ), 'type' => 'checkbox', 'default' => 'yes', 'label' => __( 'تخفیف و هزینه‌های نام‌دار زیر روش ارسال و در سفارش نمایش داده شوند', 'notran-iran-shipping' ) ),
            'fallback' => array( 'title' => sprintf( __( 'نرخ نهایی جایگزین (%s)', 'notran-iran-shipping' ), get_woocommerce_currency() ), 'type' => 'text', 'default' => '', 'description' => __( 'فقط هنگام خطای ارتباط: خالی یعنی مخفی‌کردن؛ مبلغ مثبت یعنی نرخ نهایی جایگزین. تخفیف و هزینهٔ اضافی دوباره روی این مبلغ اعمال نمی‌شوند. برای مقصد بدون پوشش، روش نمایش داده نمی‌شود.', 'notran-iran-shipping' ), 'custom_attributes' => array( 'inputmode' => 'decimal' ) ),
        );
        $ids = $this->service_ids();
        // Native Woo multiselect compares strings strictly; retain old scalar settings.
        $this->instance_settings['service'] = array_map( 'strval', $ids );
        $this->title = $this->get_option( 'title', '' ) ?: ( 1 === count( $ids ) ? $services[ $ids[0] ] : $this->method_title );
        $this->enabled = $this->get_option( 'enabled', 'yes' );
        $this->tax_status = 'none'; // Calculator returns the shipping total; do not add the same tax twice.
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public static function number( $value ) {
        if ( ! is_scalar( $value ) ) throw new InvalidArgumentException( __( 'مقدار عددی معتبر وارد کنید.', 'notran-iran-shipping' ) );
        $value = strtr( trim( (string) $value ), array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
        $value = str_replace( array( ',', '٬', '٫' ), array( '', '', '.' ), $value );
        if ( ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $value ) ) throw new InvalidArgumentException( __( 'مقدار باید عدد صفر یا مثبت باشد.', 'notran-iran-shipping' ) );
        return wc_format_decimal( $value, 6 );
    }

    public function service_ids() {
        $ids = array(); $catalog = Notran_Amadast_API::services();
        foreach ( (array) $this->get_option( 'service', array() ) as $value ) {
            if ( is_scalar( $value ) && ctype_digit( (string) $value ) && isset( $catalog[ (int) $value ] ) ) $ids[] = (int) $value;
        }
        return array_values( array_unique( $ids ) );
    }
    public function validate_service_field( $key, $value ) {
        $ids = array(); $catalog = Notran_Amadast_API::services();
        foreach ( (array) $value as $id ) {
            if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || ! isset( $catalog[ (int) $id ] ) ) throw new InvalidArgumentException( __( 'شرکت و سرویس معتبر انتخاب کنید.', 'notran-iran-shipping' ) );
            $ids[] = (string) (int) $id;
        }
        return array_values( array_unique( $ids ) );
    }
    public function validate_discount_value_field( $key, $value ) {
        $value = self::number( $value );
        $data = $this->get_post_data();
        if ( 'percent' === ( $data[ $this->get_field_key( 'discount_type' ) ] ?? $this->get_option( 'discount_type', 'none' ) ) && (float) $value > 100 ) throw new InvalidArgumentException( __( 'درصد تخفیف بیشتر از ۱۰۰ مجاز نیست.', 'notran-iran-shipping' ) );
        return $value;
    }
    public function validate_fallback_field( $key, $value ) {
        if ( is_scalar( $value ) && '' === trim( (string) $value ) ) return '';
        $value = self::number( $value );
        if ( (float) $value <= 0 ) throw new InvalidArgumentException( __( 'نرخ جایگزین باید مثبت باشد؛ برای مخفی‌کردن، آن را خالی بگذارید.', 'notran-iran-shipping' ) );
        return $value;
    }
    public function validate_extra_costs_field( $key, $value ) {
        if ( null === $value ) return array();
        if ( ! is_array( $value ) || count( $value ) > 20 ) throw new InvalidArgumentException( __( 'حداکثر ۲۰ هزینهٔ اضافی مجاز است.', 'notran-iran-shipping' ) );
        $fees = array();
        foreach ( $value as $row ) {
            if ( ! is_array( $row ) ) throw new InvalidArgumentException( __( 'ساختار هزینه معتبر نیست.', 'notran-iran-shipping' ) );
            if ( empty( $row['label'] ) && '' === ( $row['amount'] ?? '' ) ) continue;
            $label = is_scalar( $row['label'] ?? null ) ? sanitize_text_field( $row['label'] ) : '';
            if ( '' === $label || ! in_array( $row['type'] ?? '', array( 'fixed', 'percent' ), true ) ) throw new InvalidArgumentException( __( 'عنوان و نوع هر هزینه را کامل کنید.', 'notran-iran-shipping' ) );
            $fees[] = array( 'label' => $label, 'type' => $row['type'], 'amount' => self::number( $row['amount'] ?? '' ) );
        }
        return $fees;
    }
    public function process_admin_options() {
        $saved = parent::process_admin_options();
        if ( $saved ) WC_Cache_Helper::get_transient_version( 'shipping', true );
        return $saved;
    }

    public function is_available( $package ) {
        return Notran_Amadast::enabled() && 'yes' === $this->get_option( 'enabled', 'yes' ) && $this->is_enabled() && 'IR' === ( $package['destination']['country'] ?? '' ) && parent::is_available( $package );
    }

    /** All adjustments use the Woo store currency, after converting the API's IRR. */
    public static function adjusted( $base, $settings ) {
        $base = max( 0, (float) $base ); $discount = 0; $notes = array(); $fees = array();
        $amount = max( 0, (float) ( $settings['discount_value'] ?? 0 ) );
        if ( 'fixed' === ( $settings['discount_type'] ?? '' ) ) $discount = min( $base, $amount );
        if ( 'percent' === ( $settings['discount_type'] ?? '' ) ) $discount = $base * min( 100, $amount ) / 100;
        $after = max( 0, $base - $discount ); $cost = $after;
        if ( $discount > 0 ) $notes[] = __( 'تخفیف ارسال', 'notran-iran-shipping' ) . ': −' . self::price_text( $discount );
        foreach ( (array) ( $settings['extra_costs'] ?? array() ) as $fee ) {
            if ( ! is_array( $fee ) || ! is_scalar( $fee['label'] ?? null ) ) continue;
            $amount = max( 0, (float) ( $fee['amount'] ?? 0 ) );
            $value = 'percent' === ( $fee['type'] ?? '' ) ? $after * $amount / 100 : $amount;
            if ( ! is_finite( $value ) || $value <= 0 ) continue;
            $cost += $value; $fees[] = array( 'label' => sanitize_text_field( $fee['label'] ), 'amount' => $value );
            $notes[] = sanitize_text_field( $fee['label'] ) . ': ' . self::price_text( $value );
        }
        return array( 'cost' => round( $cost, wc_get_price_decimals() ), 'base' => $base, 'discount' => $discount, 'fees' => $fees, 'notes' => $notes );
    }
    private static function price_text( $value ) { return trim( html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' ) ); }

    public function calculate_shipping( $package = array() ) {
        if ( ! $this->is_available( $package ) ) return;
        $payload = Notran_Amadast::context( $package );
        if ( ! $payload ) return;
        $services = $this->service_ids();
        $catalog = Notran_Amadast_API::services();
        if ( ! $services ) return;
        $state = Notran_Amadast_API::quote( $payload );
        foreach ( $services as $service ) $this->service_rate( $service, $catalog[ $service ], $state, $package, count( $services ) > 1 );
    }

    private function service_rate( $service, $entry, $state, $package, $multiple ) {
        $custom = $this->get_option( 'title', '' );
        $label = $custom ? $custom . ( $multiple ? ' — ' . $entry['title'] : '' ) : $entry['title'];
        $rate_id = $this->get_rate_id( $multiple ? (string) $service : '' );
        if ( 'error' === ( $state['status'] ?? '' ) ) {
            $fallback = $this->get_option( 'fallback', '' );
            if ( ! is_numeric( $fallback ) || (float) $fallback <= 0 ) return;
            $this->add_rate( array( 'id' => $rate_id, 'label' => $label, 'cost' => $fallback, 'taxes' => false, 'package' => $package,
                'meta_data' => array( '_notran_amadast_service' => $service, '_notran_amadast_courier' => $entry['courier'], '_notran_amadast_fallback' => 'yes', '_notran_amadast_notes' => array( __( 'نرخ جایگزین؛ استعلام آنلاین در دسترس نبود.', 'notran-iran-shipping' ) ) ) ) );
            return;
        }
        if ( 'ready' !== ( $state['status'] ?? '' ) ) return;
        $base = null;
        foreach ( $state['items'] as $item ) {
            if ( $service !== $item['service'] || ( $item['courier'] && (int) $entry['courier'] !== $item['courier'] ) ) continue;
            $price = 'regular' === $this->get_option( 'price_basis', 'discounted' ) ? $item['price'] : ( $item['discounted_price'] ?? $item['price'] );
            if ( null !== $price && ( null === $base || $price < $base ) ) $base = $price;
        }
        if ( null === $base || $base <= 0 ) return;
        $base /= 'IRT' === get_woocommerce_currency() ? 10 : 1;
        $calculation = self::adjusted( $base, $this->instance_settings );
        if ( ! is_finite( $calculation['cost'] ) || $calculation['cost'] < 0 || $calculation['cost'] > 1e12 ) return;
        $meta = array( '_notran_amadast_service' => $service, '_notran_amadast_courier' => $entry['courier'], '_notran_amadast_calculation' => $calculation, '_notran_amadast_currency' => get_woocommerce_currency() );
        if ( 'yes' === $this->get_option( 'show_details', 'yes' ) ) $meta['_notran_amadast_notes'] = $calculation['notes'];
        $this->add_rate( array( 'id' => $rate_id, 'label' => $label, 'cost' => $calculation['cost'], 'taxes' => false, 'package' => $package, 'meta_data' => $meta ) );
    }

    public function generate_notran_fees_html( $key, $data ) {
        $rows = (array) $this->get_option( $key, array() );
        if ( ! $rows ) $rows = array( array() );
        $name = $this->get_field_key( $key );
        ob_start(); ?>
        <tr><th scope="row"><?php esc_html_e( 'هزینه‌های اضافی', 'notran-iran-shipping' ); ?></th><td>
        <div class="notran-fees" data-next="<?php echo count( $rows ); ?>">
        <p class="description"><?php esc_html_e( 'ابتدا تخفیف از کرایه کم می‌شود؛ سپس هزینه‌های ثابت و درصدی اضافه می‌شوند. درصد هر ردیف از کرایهٔ پس از تخفیف محاسبه می‌شود. مبالغ ثابت در واحد پول فروشگاه و برای هر بسته هستند.', 'notran-iran-shipping' ); ?></p>
        <div class="notran-fee-rows"><?php foreach ( $rows as $i => $row ) self::fee_row( $name, $i, $row ); ?></div>
        <template><?php self::fee_row( $name, '__ROW__', array() ); ?></template>
        <button class="button notran-fee-add" type="button"><?php esc_html_e( 'افزودن هزینه', 'notran-iran-shipping' ); ?></button></div></td></tr>
        <?php return ob_get_clean();
    }
    private static function fee_row( $name, $i, $row ) {
        $name .= '[' . $i . ']'; ?>
        <fieldset class="notran-fee-row"><legend class="screen-reader-text"><?php esc_html_e( 'هزینهٔ اضافی', 'notran-iran-shipping' ); ?></legend>
        <label><?php esc_html_e( 'عنوان هزینه', 'notran-iran-shipping' ); ?><input type="text" name="<?php echo esc_attr( $name . '[label]' ); ?>" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>"></label>
        <label><?php esc_html_e( 'نوع', 'notran-iran-shipping' ); ?><select name="<?php echo esc_attr( $name . '[type]' ); ?>"><option value="fixed"><?php esc_html_e( 'مبلغ ثابت', 'notran-iran-shipping' ); ?></option><option value="percent" <?php selected( $row['type'] ?? '', 'percent' ); ?>><?php esc_html_e( 'درصد', 'notran-iran-shipping' ); ?></option></select></label>
        <label><?php esc_html_e( 'مقدار', 'notran-iran-shipping' ); ?><input type="text" inputmode="decimal" name="<?php echo esc_attr( $name . '[amount]' ); ?>" value="<?php echo esc_attr( $row['amount'] ?? '' ); ?>"></label>
        <button type="button" class="button notran-fee-remove" aria-label="<?php esc_attr_e( 'حذف این هزینه', 'notran-iran-shipping' ); ?>"><?php esc_html_e( 'حذف', 'notran-iran-shipping' ); ?></button></fieldset>
        <?php
    }
}
