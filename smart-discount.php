<?php
/**
 * Plugin Name: Discount Pro for WooCommerce
 * Description: افزونه پیشرفته مدیریت کوپن و تخفیف های خودکار برای ووکامرس.
 * Version: 3.2.0
 * Author: Alireza Fatemi
 * Author URI: https://alirezafatemi.ir/
 * Plugin URI: https://github.com/deveguru/
 * Text Domain: discount-pro-wc
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DPWC_VERSION', '3.2.0');
define('DPWC_DB_VERSION', '1.2');

if (!class_exists('DiscountProWooCommerce')) {

    class DiscountProWooCommerce {
        
        private $table_name_coupons;
        private $table_name_usage;
        private $table_name_sales;
        private $table_name_price_backup;
        
        public function __construct() {
            global $wpdb;
            $this->table_name_coupons = $wpdb->prefix . 'discount_pro_coupons';
            $this->table_name_usage = $wpdb->prefix . 'discount_pro_usage';
            $this->table_name_sales = $wpdb->prefix . 'discount_pro_sales';
            $this->table_name_price_backup = $wpdb->prefix . 'discount_pro_price_backup';
            
            add_action('plugins_loaded', array($this, 'init'));
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        }
        
        public function init() {
            date_default_timezone_set('Asia/Tehran');
            
            $this->check_database_version();
            
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
                return;
            }
            
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
            add_action('wp_ajax_dpwc_save_coupon', array($this, 'ajax_save_coupon'));
            add_action('wp_ajax_dpwc_delete_coupon', array($this, 'ajax_delete_coupon'));
            add_action('wp_ajax_dpwc_toggle_coupon', array($this, 'ajax_toggle_coupon'));
            add_action('wp_ajax_dpwc_save_sale', array($this, 'ajax_save_sale'));
            add_action('wp_ajax_dpwc_delete_sale', array($this, 'ajax_delete_sale'));
            add_action('dpwc_end_sale_event', array($this, 'handle_end_sale'), 10, 1);
            add_filter('woocommerce_get_shop_coupon_data', array($this, 'get_coupon_data'), 10, 2);
            add_filter('woocommerce_coupon_is_valid', array($this, 'validate_custom_coupon'), 10, 2);
            add_action('woocommerce_applied_coupon', array($this, 'track_coupon_usage'));
            add_shortcode('discount_pro_form', array($this, 'coupon_form_shortcode'));
            add_action('wp_head', array($this, 'frontend_styles'));
        }
        
        public function activate() {
            $this->create_tables();
            update_option('dpwc_db_version', DPWC_DB_VERSION);
            wp_cache_flush();
        }
        
        public function deactivate() {
            wp_clear_scheduled_hook('dpwc_end_sale_event');
            wp_cache_flush();
        }
        
        private function check_database_version() {
            $current_db_version = get_option('dpwc_db_version', '1.0');
            if (version_compare($current_db_version, DPWC_DB_VERSION, '<')) {
                $this->create_tables();
                update_option('dpwc_db_version', DPWC_DB_VERSION);
            }
        }
        
        public function woocommerce_missing_notice() {
            echo '<div class="notice notice-error"><p><strong>Discount Pro for WooCommerce:</strong> این افزونه نیاز به فعال بودن افزونه ووکامرس دارد.</p></div>';
        }
        
        private function create_tables() {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $sql_coupons = "CREATE TABLE {$this->table_name_coupons} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                coupon_code varchar(50) NOT NULL,
                coupon_name varchar(100) NOT NULL,
                discount_type varchar(20) NOT NULL,
                discount_amount decimal(10,2) NOT NULL,
                product_ids text,
                product_categories text,
                product_tags text,
                user_ids text,
                usage_limit int(11) DEFAULT 0,
                usage_count int(11) DEFAULT 0,
                start_date datetime DEFAULT NULL,
                end_date datetime DEFAULT NULL,
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY coupon_code (coupon_code)
            ) $charset_collate;";
            
            $sql_usage = "CREATE TABLE {$this->table_name_usage} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                coupon_id mediumint(9) NOT NULL,
                user_id bigint(20) NOT NULL,
                order_id bigint(20) NOT NULL,
                discount_amount decimal(10,2) NOT NULL,
                used_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY coupon_id (coupon_id)
            ) $charset_collate;";
            
            $sql_sales = "CREATE TABLE {$this->table_name_sales} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                sale_name varchar(100) NOT NULL,
                discount_type varchar(20) NOT NULL,
                discount_amount decimal(10,2) NOT NULL,
                product_ids text,
                product_categories text,
                product_tags text,
                start_date datetime NOT NULL,
                end_date datetime DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status)
            ) $charset_collate;";
            
            $sql_price_backup = "CREATE TABLE {$this->table_name_price_backup} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                product_id bigint(20) NOT NULL,
                sale_id mediumint(9) NOT NULL,
                original_regular_price decimal(10,2) DEFAULT NULL,
                original_sale_price decimal(10,2) DEFAULT NULL,
                new_sale_price decimal(10,2) DEFAULT NULL,
                backed_up_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY product_sale (product_id, sale_id)
            ) $charset_collate;";
            
            dbDelta($sql_coupons);
            dbDelta($sql_usage);
            dbDelta($sql_sales);
            dbDelta($sql_price_backup);
        }
        
        public function add_admin_menu() {
            add_menu_page('کوپن و تخفیف پیشرفته', 'کوپن پیشرفته', 'manage_options', 'discount-pro-wc', array($this, 'admin_page'), 'dashicons-tickets-alt', 56);
            add_submenu_page('discount-pro-wc', 'همه کوپن ها', 'همه کوپن ها', 'manage_options', 'discount-pro-wc', array($this, 'admin_page'));
            add_submenu_page('discount-pro-wc', 'افزودن کوپن جدید', 'افزودن کوپن', 'manage_options', 'discount-pro-wc-add', array($this, 'add_coupon_page'));
            add_submenu_page('discount-pro-wc', 'تخفیف های خودکار', 'تخفیف های خودکار', 'manage_options', 'discount-pro-wc-sales', array($this, 'automatic_sales_page'));
            add_submenu_page('discount-pro-wc', 'افزودن تخفیف خودکار', 'افزودن تخفیف خودکار', 'manage_options', 'discount-pro-wc-add-sale', array($this, 'add_automatic_sale_page'));
            add_submenu_page('discount-pro-wc', 'آمار و گزارشات', 'آمار', 'manage_options', 'discount-pro-wc-stats', array($this, 'stats_page'));
        }
        
        public function enqueue_admin_scripts($hook) {
            if (strpos($hook, 'discount-pro-wc') === false) return;
            wp_enqueue_script('jquery');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
            wp_localize_script('jquery', 'dpwc_ajax', array('ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('dpwc_nonce')));
        }
        
        public function admin_page() {
            $coupons = $this->get_all_coupons();
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">کوپن های تخفیف پیشرفته</h1>
                <a href="<?php echo admin_url('admin.php?page=discount-pro-wc-add'); ?>" class="page-title-action">افزودن جدید</a>
                <hr class="wp-header-end">
                <?php if (empty($coupons)): ?>
                    <div class="notice notice-info"><p>هیچ کوپنی یافت نشد. <a href="<?php echo admin_url('admin.php?page=discount-pro-wc-add'); ?>">اولین کوپن خود را بسازید</a></p></div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>کد کوپن</th><th>نام</th><th>نوع</th><th>مقدار</th><th>استفاده</th><th>وضعیت</th><th>انقضا</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php foreach ($coupons as $coupon): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($coupon->coupon_code); ?></strong></td>
                                    <td><?php echo esc_html($coupon->coupon_name); ?></td>
                                    <td><?php echo $coupon->discount_type === 'percent' ? 'درصدی' : 'مقدار ثابت'; ?></td>
                                    <td><?php echo $coupon->discount_type === 'percent' ? $coupon->discount_amount . '%' : number_format($coupon->discount_amount) . ' تومان'; ?></td>
                                    <td><?php echo $coupon->usage_limit > 0 ? $coupon->usage_count . '/' . $coupon->usage_limit : $coupon->usage_count . '/نامحدود'; ?></td>
                                    <td><span class="status-<?php echo $coupon->is_active ? 'active' : 'inactive'; ?>"><?php echo $coupon->is_active ? 'فعال' : 'غیرفعال'; ?></span></td>
                                    <td><?php echo $coupon->end_date ? $this->format_persian_date($coupon->end_date, false) : 'هرگز'; ?></td>
                                    <td><button class="button button-small dpwc-toggle" data-id="<?php echo $coupon->id; ?>"><?php echo $coupon->is_active ? 'غیرفعال کردن' : 'فعال کردن'; ?></button> <button class="button button-small button-link-delete dpwc-delete" data-id="<?php echo $coupon->id; ?>">حذف</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php $this->admin_scripts();
        }
        
        public function add_coupon_page() {
            $products = wc_get_products(array('limit' => -1, 'status' => 'publish'));
            $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            $tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
            $users = get_users(array('fields' => array('ID', 'display_name', 'user_email')));
            ?>
            <div class="wrap">
                <h1>افزودن کوپن تخفیف جدید</h1>
                <form id="dpwc-coupon-form" method="post">
                    <table class="form-table">
                        <tr><th scope="row"><label for="coupon_name">نام کوپن <span class="required">*</span></label></th><td><input type="text" id="coupon_name" name="coupon_name" class="regular-text" required /></td></tr>
                        <tr><th scope="row"><label for="coupon_code">کد کوپن <span class="required">*</span></label></th><td><input type="text" id="coupon_code" name="coupon_code" class="regular-text" required /><button type="button" id="generate-code" class="button">تولید کد خودکار</button></td></tr>
                        <tr><th scope="row"><label for="discount_type">نوع تخفیف <span class="required">*</span></label></th><td><select id="discount_type" name="discount_type" required><option value="percent">درصدی</option><option value="fixed">مقدار ثابت (تومان)</option></select></td></tr>
                        <tr><th scope="row"><label for="discount_amount">مقدار تخفیف <span class="required">*</span></label></th><td><input type="number" id="discount_amount" name="discount_amount" step="0.01" min="0" class="regular-text" required /></td></tr>
                        <tr><th scope="row"><label for="product_ids">محصولات</label></th><td><select id="product_ids" name="product_ids[]" multiple="multiple" style="width: 100%;"><?php foreach ($products as $product) { echo '<option value="' . $product->get_id() . '">' . $product->get_name() . '</option>'; } ?></select><p class="description">برای اعمال روی همه محصولات، فیلدهای محصولات، دسته‌بندی‌ها و برچسب‌ها را خالی بگذارید.</p></td></tr>
                        <tr><th scope="row"><label for="product_categories">دسته‌بندی محصولات</label></th><td><select id="product_categories" name="product_categories[]" multiple="multiple" style="width: 100%;"><?php foreach ($categories as $category) { echo '<option value="' . $category->term_id . '">' . $category->name . '</option>'; } ?></select></td></tr>
                        <tr><th scope="row"><label for="product_tags">برچسب محصولات</label></th><td><select id="product_tags" name="product_tags[]" multiple="multiple" style="width: 100%;"><?php foreach ($tags as $tag) { echo '<option value="' . $tag->term_id . '">' . $tag->name . '</option>'; } ?></select></td></tr>
                        <tr><th scope="row"><label for="user_ids">کاربران مخصوص</label></th><td><select id="user_ids" name="user_ids[]" multiple="multiple" style="width: 100%;"><?php foreach ($users as $user) { echo '<option value="' . $user->ID . '">' . $user->display_name . ' (' . $user->user_email . ')</option>'; } ?></select><p class="description">برای همه کاربران خالی بگذارید</p></td></tr>
                        <tr><th scope="row"><label for="usage_limit">محدودیت استفاده</label></th><td><input type="number" id="usage_limit" name="usage_limit" min="0" class="regular-text" /><p class="description">برای استفاده نامحدود خالی بگذارید یا 0 وارد کنید</p></td></tr>
                    </table>
                    <p class="submit"><input type="submit" class="button button-primary" value="ایجاد کوپن" /><a href="<?php echo admin_url('admin.php?page=discount-pro-wc'); ?>" class="button">انصراف</a></p>
                </form>
            </div>
            <?php $this->form_scripts();
        }
        
        public function automatic_sales_page() {
            $sales = $this->get_all_sales();
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">تخفیف های خودکار</h1>
                <a href="<?php echo admin_url('admin.php?page=discount-pro-wc-add-sale'); ?>" class="page-title-action">افزودن جدید</a>
                <hr class="wp-header-end">
                <?php if (empty($sales)): ?>
                    <div class="notice notice-info"><p>هیچ تخفیف خودکاری یافت نشد. <a href="<?php echo admin_url('admin.php?page=discount-pro-wc-add-sale'); ?>">اولین تخفیف خودکار خود را بسازید</a></p></div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>نام تخفیف</th><th>نوع</th><th>مقدار</th><th>تعداد محصولات</th><th>وضعیت</th><th>تاریخ شروع</th><th>تاریخ پایان</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php foreach ($sales as $sale):
                                $product_count = $this->count_affected_products($sale->id);
                                $status_map = array('active' => 'فعال', 'expired' => 'منقضی شده');
                                $status_text = isset($status_map[$sale->status]) ? $status_map[$sale->status] : ucfirst($sale->status);
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html($sale->sale_name); ?></strong></td>
                                    <td><?php echo $sale->discount_type === 'percent' ? 'درصدی' : 'مقدار ثابت'; ?></td>
                                    <td><?php echo $sale->discount_type === 'percent' ? $sale->discount_amount . '%' : number_format($sale->discount_amount) . ' تومان'; ?></td>
                                    <td><?php echo number_format($product_count); ?> محصول</td>
                                    <td><span class="status-<?php echo esc_attr($sale->status); ?>"><?php echo $status_text; ?></span></td>
                                    <td><?php echo $this->format_persian_date($sale->start_date); ?></td>
                                    <td><?php echo $sale->end_date ? $this->format_persian_date($sale->end_date) : 'نامحدود'; ?></td>
                                    <td><button class="button button-small button-link-delete dpwc-delete-sale" data-id="<?php echo $sale->id; ?>">حذف</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php $this->sales_admin_scripts();
        }
        
        public function add_automatic_sale_page() {
            $products = wc_get_products(array('limit' => -1, 'status' => 'publish'));
            $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            $tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
            ?>
            <div class="wrap">
                <h1>افزودن تخفیف خودکار جدید</h1>
                <form id="dpwc-sale-form" method="post">
                    <table class="form-table">
                        <tr><th scope="row"><label for="sale_name">نام تخفیف <span class="required">*</span></label></th><td><input type="text" id="sale_name" name="sale_name" class="regular-text" required /></td></tr>
                        <tr><th scope="row"><label for="discount_type">نوع تخفیف <span class="required">*</span></label></th><td><select id="discount_type" name="discount_type" required><option value="percent">درصدی</option><option value="fixed">مقدار ثابت (تومان)</option></select></td></tr>
                        <tr><th scope="row"><label for="discount_amount">مقدار تخفیف <span class="required">*</span></label></th><td><input type="number" id="discount_amount" name="discount_amount" step="0.01" min="0" class="regular-text" required /></td></tr>
                        <tr><th scope="row"><label for="product_ids">محصولات</label></th><td><select id="product_ids" name="product_ids[]" multiple="multiple" style="width: 100%;"><?php foreach ($products as $product) { echo '<option value="' . $product->get_id() . '">' . $product->get_name() . '</option>'; } ?></select><p class="description"><strong>حداقل یکی از فیلدها باید انتخاب شود.</strong></p></td></tr>
                        <tr><th scope="row"><label for="product_categories">دسته‌بندی محصولات</label></th><td><select id="product_categories" name="product_categories[]" multiple="multiple" style="width: 100%;"><?php foreach ($categories as $category) { echo '<option value="' . $category->term_id . '">' . $category->name . '</option>'; } ?></select></td></tr>
                        <tr><th scope="row"><label for="product_tags">برچسب محصولات</label></th><td><select id="product_tags" name="product_tags[]" multiple="multiple" style="width: 100%;"><?php foreach ($tags as $tag) { echo '<option value="' . $tag->term_id . '">' . $tag->name . '</option>'; } ?></select></td></tr>
                        <tr><th scope="row"><label for="sale_duration">مدت زمان تخفیف <span class="required">*</span></label></th><td><select id="sale_duration" name="sale_duration" required><option value="unlimited">همیشه فعال (نامحدود)</option><option value="1_week">1 هفته</option><option value="1_month">1 ماه</option><option value="custom">مدت زمان سفارشی (روز)</option></select><div id="custom_days_wrapper" style="display:none; margin-top:10px;"><input type="number" name="custom_days" id="custom_days" class="small-text" min="1" step="1" placeholder="تعداد روز" /><span class="description">روز</span></div></td></tr>
                    </table>
                    <p class="submit"><input type="submit" class="button button-primary button-large" value="ایجاد و فعالسازی تخفیف" /><a href="<?php echo admin_url('admin.php?page=discount-pro-wc-sales'); ?>" class="button">انصراف</a></p>
                </form>
            </div>
            <?php $this->form_scripts();
        }
        
        public function stats_page() {
            global $wpdb;
            $total_coupons = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name_coupons}");
            $active_coupons = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name_coupons} WHERE is_active = 1");
            $total_usage = $wpdb->get_var("SELECT SUM(usage_count) FROM {$this->table_name_coupons}");
            $total_discount = $wpdb->get_var("SELECT SUM(discount_amount) FROM {$this->table_name_usage}");
            $total_sales = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name_sales}");
            $active_sales = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name_sales} WHERE status = 'active'");
            $top_coupons = $wpdb->get_results("SELECT coupon_code, coupon_name, usage_count, discount_amount, discount_type FROM {$this->table_name_coupons} ORDER BY usage_count DESC LIMIT 10");
            ?>
            <div class="wrap">
                <h1>آمار و گزارشات</h1>
                <h2>آمار کلی کوپن ها</h2>
                <div class="dpwc-stats-grid">
                    <div class="stat-box"><h3>کل کوپن ها</h3><span class="stat-number"><?php echo number_format($total_coupons); ?></span></div>
                    <div class="stat-box"><h3>کوپن های فعال</h3><span class="stat-number"><?php echo number_format($active_coupons); ?></span></div>
                    <div class="stat-box"><h3>کل استفاده</h3><span class="stat-number"><?php echo number_format($total_usage ?: 0); ?></span></div>
                    <div class="stat-box"><h3>کل تخفیف داده شده</h3><span class="stat-number"><?php echo number_format($total_discount ?: 0); ?> تومان</span></div>
                </div>
                <h2>آمار کلی تخفیف های خودکار</h2>
                <div class="dpwc-stats-grid">
                    <div class="stat-box"><h3>کل تخفیف های خودکار</h3><span class="stat-number"><?php echo number_format($total_sales); ?></span></div>
                    <div class="stat-box"><h3>تخفیف های فعال</h3><span class="stat-number"><?php echo number_format($active_sales); ?></span></div>
                </div>
                <?php if (!empty($top_coupons)): ?>
                    <h2>پراستفاده ترین کوپن ها</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>کد</th><th>نام</th><th>نوع</th><th>مقدار</th><th>تعداد استفاده</th></tr></thead>
                        <tbody><?php foreach ($top_coupons as $coupon): ?><tr><td><strong><?php echo esc_html($coupon->coupon_code); ?></strong></td><td><?php echo esc_html($coupon->coupon_name); ?></td><td><?php echo $coupon->discount_type === 'percent' ? 'درصدی' : 'مقدار ثابت'; ?></td><td><?php echo $coupon->discount_type === 'percent' ? $coupon->discount_amount . '%' : number_format($coupon->discount_amount) . ' تومان'; ?></td><td><?php echo number_format($coupon->usage_count); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php $this->stats_styles();
        }
        
        public function ajax_save_coupon() {
            check_ajax_referer('dpwc_nonce', 'nonce');
            if (!current_user_can('manage_options')) { wp_send_json_error('دسترسی غیرمجاز'); }
            global $wpdb;
            $data = array('coupon_name' => sanitize_text_field($_POST['coupon_name']), 'coupon_code' => strtoupper(sanitize_text_field($_POST['coupon_code'])), 'discount_type' => sanitize_text_field($_POST['discount_type']), 'discount_amount' => floatval($_POST['discount_amount']), 'product_ids' => isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? implode(',', array_map('intval', $_POST['product_ids'])) : '', 'product_categories' => isset($_POST['product_categories']) && is_array($_POST['product_categories']) ? implode(',', array_map('intval', $_POST['product_categories'])) : '', 'product_tags' => isset($_POST['product_tags']) && is_array($_POST['product_tags']) ? implode(',', array_map('intval', $_POST['product_tags'])) : '', 'user_ids' => isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? implode(',', array_map('intval', $_POST['user_ids'])) : '', 'usage_limit' => intval($_POST['usage_limit']) ?: 0);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name_coupons} WHERE coupon_code = %s", $data['coupon_code']));
            if ($exists > 0) { wp_send_json_error('کد کوپن قبلاً وجود دارد.'); }
            if ($wpdb->insert($this->table_name_coupons, $data)) { wp_send_json_success('کوپن با موفقیت ایجاد شد'); } else { wp_send_json_error('خطا در ایجاد کوپن: ' . $wpdb->last_error); }
        }
        
        public function ajax_delete_coupon() {
            check_ajax_referer('dpwc_nonce', 'nonce');
            if (!current_user_can('manage_options')) { wp_send_json_error('دسترسی غیرمجاز'); }
            global $wpdb;
            $id = intval($_POST['coupon_id']);
            $wpdb->delete($this->table_name_usage, array('coupon_id' => $id));
            if ($wpdb->delete($this->table_name_coupons, array('id' => $id))) { wp_send_json_success('کوپن با موفقیت حذف شد'); } else { wp_send_json_error('خطا در حذف کوپن'); }
        }
        
        public function ajax_toggle_coupon() {
            check_ajax_referer('dpwc_nonce', 'nonce');
            if (!current_user_can('manage_options')) { wp_send_json_error('دسترسی غیرمجاز'); }
            global $wpdb;
            $id = intval($_POST['coupon_id']);
            $current = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$this->table_name_coupons} WHERE id = %d", $id));
            $new_status = $current ? 0 : 1;
            if ($wpdb->update($this->table_name_coupons, array('is_active' => $new_status), array('id' => $id)) !== false) { wp_send_json_success(array('new_status' => $new_status)); } else { wp_send_json_error('خطا در تغییر وضعیت کوپن'); }
        }
        
        public function ajax_save_sale() {
            check_ajax_referer('dpwc_nonce', 'nonce');
            if (!current_user_can('manage_options')) { wp_send_json_error('دسترسی غیرمجاز'); }
            global $wpdb;
            $duration = sanitize_text_field($_POST['sale_duration']);
            $end_date = null;
            if ($duration !== 'unlimited') {
                $interval = '';
                switch ($duration) {
                    case '1_week': $interval = '+1 week'; break;
                    case '1_month': $interval = '+1 month'; break;
                    case 'custom': $days = intval($_POST['custom_days']); if ($days > 0) { $interval = "+{$days} days"; } break;
                }
                if ($interval) { $end_date = date('Y-m-d H:i:s', strtotime($interval)); }
            }
            $sale_data = array('sale_name' => sanitize_text_field($_POST['sale_name']), 'discount_type' => sanitize_text_field($_POST['discount_type']), 'discount_amount' => floatval($_POST['discount_amount']), 'product_ids' => isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? implode(',', array_map('intval', $_POST['product_ids'])) : '', 'product_categories' => isset($_POST['product_categories']) && is_array($_POST['product_categories']) ? implode(',', array_map('intval', $_POST['product_categories'])) : '', 'product_tags' => isset($_POST['product_tags']) && is_array($_POST['product_tags']) ? implode(',', array_map('intval', $_POST['product_tags'])) : '', 'start_date' => current_time('mysql'), 'end_date' => $end_date, 'status' => 'active');
            if (empty($sale_data['product_ids']) && empty($sale_data['product_categories']) && empty($sale_data['product_tags'])) { wp_send_json_error('حداقل یک محصول، دسته‌بندی یا برچسب باید انتخاب شود.'); }
            if ($wpdb->insert($this->table_name_sales, $sale_data) === false) { wp_send_json_error('خطا در ذخیره تخفیف خودکار در دیتابیس: ' . $wpdb->last_error); }
            $sale_id = $wpdb->insert_id;
            $affected_count = $this->apply_sale_to_products($sale_id, $sale_data);
            if ($affected_count === 0) { $wpdb->delete($this->table_name_sales, array('id' => $sale_id)); wp_send_json_error('هیچ محصولی با معیارهای انتخابی شما یافت نشد.'); }
            if ($end_date) { wp_schedule_single_event(strtotime(get_gmt_from_date($end_date)), 'dpwc_end_sale_event', array($sale_id)); }
            wp_send_json_success("تخفیف خودکار با موفقیت ایجاد شد و روی {$affected_count} محصول اعمال گردید.");
        }
        
        public function ajax_delete_sale() {
            check_ajax_referer('dpwc_nonce', 'nonce');
            if (!current_user_can('manage_options')) { wp_send_json_error('دسترسی غیرمجاز'); }
            global $wpdb;
            $sale_id = intval($_POST['sale_id']);
            $sale = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name_sales} WHERE id = %d", $sale_id), ARRAY_A);
            if (!$sale) { wp_send_json_error('تخفیف مورد نظر یافت نشد.'); }
            wp_clear_scheduled_hook('dpwc_end_sale_event', array($sale_id));
            if ($sale['status'] === 'active') { $this->revert_sale_prices($sale_id); }
            if ($wpdb->delete($this->table_name_sales, array('id' => $sale_id))) { wp_send_json_success('تخفیف خودکار با موفقیت حذف شد و قیمت محصولات به حالت اولیه بازگشت.'); } else { wp_send_json_error('خطا در حذف تخفیف خودکار.'); }
        }
        
        private function apply_sale_to_products($sale_id, $sale_data) {
            $product_ids = $this->get_product_ids_for_sale($sale_data);
            if (empty($product_ids)) return 0;
            global $wpdb;
            $affected_count = 0;
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product || !$product->exists()) continue;
                $regular_price = $product->get_regular_price();
                if (empty($regular_price) || $regular_price <= 0) continue;
                $new_sale_price = ($sale_data['discount_type'] === 'percent') ? ($regular_price - ($regular_price * floatval($sale_data['discount_amount']) / 100)) : ($regular_price - floatval($sale_data['discount_amount']));
                $new_sale_price = max(0, $new_sale_price);
                if ($new_sale_price >= $regular_price) continue;
                $wpdb->replace($this->table_name_price_backup, array('product_id' => $product_id, 'sale_id' => $sale_id, 'original_regular_price' => $regular_price, 'original_sale_price' => $product->get_sale_price() ?: null, 'backed_up_at' => current_time('mysql')), array('%d', '%d', '%f', '%f', '%s'));
                $product->set_sale_price($new_sale_price);
                $product->save();
                wc_delete_product_transients($product_id);
                $affected_count++;
            }
            return $affected_count;
        }
        
        private function revert_sale_prices($sale_id) {
            global $wpdb;
            $backups = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name_price_backup} WHERE sale_id = %d", $sale_id));
            foreach ($backups as $backup) {
                $product = wc_get_product($backup->product_id);
                if (!$product || !$product->exists()) continue;
                $product->set_sale_price($backup->original_sale_price !== null ? $backup->original_sale_price : '');
                $product->save();
                wc_delete_product_transients($backup->product_id);
            }
            $wpdb->delete($this->table_name_price_backup, array('sale_id' => $sale_id));
        }
        
        public function handle_end_sale($sale_id) {
            global $wpdb;
            $sale = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name_sales} WHERE id = %d", $sale_id), ARRAY_A);
            if (!$sale || $sale['status'] !== 'active') return;
            $this->revert_sale_prices($sale_id);
            $wpdb->update($this->table_name_sales, array('status' => 'expired'), array('id' => $sale_id));
        }
        
        private function get_product_ids_for_sale($sale_data) {
            $product_ids = array();
            if (!empty($sale_data['product_ids'])) { $product_ids = array_merge($product_ids, array_map('intval', explode(',', $sale_data['product_ids']))); }
            $tax_query = array('relation' => 'OR');
            if (!empty($sale_data['product_categories'])) { $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => explode(',', $sale_data['product_categories'])); }
            if (!empty($sale_data['product_tags'])) { $tax_query[] = array('taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => explode(',', $sale_data['product_tags'])); }
            if (count($tax_query) > 1) { $term_products = wc_get_products(array('limit' => -1, 'status' => 'publish', 'return' => 'ids', 'tax_query' => $tax_query)); $product_ids = array_merge($product_ids, $term_products); }
            return array_unique($product_ids);
        }
        
        private function count_affected_products($sale_id) {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name_price_backup} WHERE sale_id = %d", $sale_id));
        }
        
        public function get_coupon_data($data, $code) {
            if ($data) return $data;
            $coupon = $this->get_coupon_by_code($code);
            if (!$coupon || !$this->is_coupon_valid($coupon)) return false;
            return array('discount_type' => $coupon->discount_type === 'percent' ? 'percent' : 'fixed_cart', 'amount' => floatval($coupon->discount_amount), 'individual_use' => false, 'product_ids' => !empty($coupon->product_ids) ? array_map('intval', explode(',', $coupon->product_ids)) : array(), 'exclude_product_ids' => array(), 'usage_limit' => $coupon->usage_limit > 0 ? $coupon->usage_limit : '', 'usage_limit_per_user' => '', 'limit_usage_to_x_items' => '', 'free_shipping' => false, 'product_categories' => !empty($coupon->product_categories) ? array_map('intval', explode(',', $coupon->product_categories)) : array(), 'exclude_product_categories' => array(), 'exclude_sale_items' => false, 'minimum_amount' => '', 'maximum_amount' => '', 'email_restrictions' => array(), 'used_by' => array());
        }
        
        public function validate_custom_coupon($is_valid, $wc_coupon) {
            if (!$is_valid) return false;
            $coupon = $this->get_coupon_by_code($wc_coupon->get_code());
            return $coupon ? $this->is_coupon_valid($coupon) : $is_valid;
        }
        
        public function track_coupon_usage($coupon_code) {
            global $wpdb;
            $coupon = $this->get_coupon_by_code($coupon_code);
            if (!$coupon) return;
            $wpdb->query($wpdb->prepare("UPDATE {$this->table_name_coupons} SET usage_count = usage_count + 1 WHERE id = %d", $coupon->id));
            if (is_user_logged_in()) { $wpdb->insert($this->table_name_usage, array('coupon_id' => $coupon->id, 'user_id' => get_current_user_id(), 'order_id' => 0, 'discount_amount' => 0)); }
        }
        
        private function get_all_coupons() { global $wpdb; return $wpdb->get_results("SELECT * FROM {$this->table_name_coupons} ORDER BY created_at DESC"); }
        private function get_all_sales() { global $wpdb; return $wpdb->get_results("SELECT * FROM {$this->table_name_sales} ORDER BY created_at DESC"); }
        private function get_coupon_by_code($code) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name_coupons} WHERE coupon_code = %s", strtoupper($code))); }
        
        private function is_coupon_valid($coupon) {
            if (!$coupon->is_active) return false;
            $now = current_time('mysql');
            if ($coupon->start_date && $now < $coupon->start_date) return false;
            if ($coupon->end_date && $now > $coupon->end_date) return false;
            if ($coupon->usage_limit > 0 && $coupon->usage_count >= $coupon->usage_limit) return false;
            if (!empty($coupon->user_ids) && is_user_logged_in()) { if (!in_array(get_current_user_id(), array_map('intval', explode(',', $coupon->user_ids)))) return false; }
            $p_ids = !empty($coupon->product_ids) ? array_map('intval', explode(',', $coupon->product_ids)) : [];
            $c_ids = !empty($coupon->product_categories) ? array_map('intval', explode(',', $coupon->product_categories)) : [];
            $t_ids = !empty($coupon->product_tags) ? array_map('intval', explode(',', $coupon->product_tags)) : [];
            if (empty($p_ids) && empty($c_ids) && empty($t_ids)) return true;
            if (function_exists('WC') && WC()->cart) { foreach (WC()->cart->get_cart() as $item) { $id = $item['product_id']; if (in_array($id, $p_ids) || has_term($c_ids, 'product_cat', $id) || has_term($t_ids, 'product_tag', $id)) return true; } }
            return false;
        }
        
        private function format_persian_date($date, $with_time = true) { if (empty($date)) return ''; $ts = strtotime($date); list($y, $m, $d) = $this->gregorian_to_jalali(date('Y', $ts), date('n', $ts), date('j', $ts)); $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند']; $f = $d . ' ' . $months[$m - 1] . ' ' . $y; if ($with_time) $f .= ' ساعت ' . date('H:i', $ts); return $f; }
        private function gregorian_to_jalali($gy, $gm, $gd) { $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]; $gy2 = ($gm > 2) ? ($gy + 1) : $gy; $days = 355666 + (365 * $gy) + floor(($gy2 + 3) / 4) - floor(($gy2 + 99) / 100) + floor(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1]; $jy = -1595 + (33 * floor($days / 12053)); $days %= 12053; $jy += 4 * floor($days / 1461); $days %= 1461; if ($days > 365) { $jy += floor(($days - 1) / 365); $days = ($days - 1) % 365; } $jm = ($days < 186) ? 1 + floor($days / 31) : 7 + floor(($days - 186) / 30); $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30)); return array($jy, $jm, $jd); }
        
        public function coupon_form_shortcode($atts) { $atts = shortcode_atts(['title' => 'اعمال کد تخفیف', 'placeholder' => 'کد تخفیف را وارد کنید', 'button_text' => 'اعمال'], $atts); ob_start(); ?> <div class="dpwc-form"><h3><?php echo esc_html($atts['title']); ?></h3><form class="coupon-form" method="post" action="<?php echo esc_url(wc_get_cart_url()); ?>"><input type="text" name="coupon_code" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" required><button type="submit" name="apply_coupon" value="اعمال تخفیف"><?php echo esc_html($atts['button_text']); ?></button><?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?></form></div> <?php return ob_get_clean(); }
        public function frontend_styles() { ?> <style>.dpwc-form{background:linear-gradient(135deg,#f9f9f9 0%,#ffffff 100%);padding:25px;border-radius:12px;margin:25px 0;border:2px solid #61CE70;box-shadow:0 4px 12px rgba(97,206,112,0.1)}.dpwc-form h3{color:#081035;margin-bottom:15px;font-size:1.2em}.dpwc-form .coupon-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.dpwc-form input[type="text"]{flex:1;min-width:200px;padding:12px 16px;border:2px solid #61CE70;border-radius:8px;font-size:16px;transition:all 0.3s ease}.dpwc-form input[type="text"]:focus{outline:none;border-color:#4fb85d;box-shadow:0 0 0 3px rgba(97,206,112,0.2)}.dpwc-form button{padding:12px 24px;background:linear-gradient(135deg,#61CE70,#4fb85d);color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;transition:all 0.3s ease}.dpwc-form button:hover{background:linear-gradient(135deg,#4fb85d,#4a7c54);transform:translateY(-2px);box-shadow:0 4px 12px rgba(97,206,112,0.3)}@media (max-width:768px){.dpwc-form .coupon-form{flex-direction:column}.dpwc-form input[type="text"]{width:100%}}</style> <?php }
        private function admin_scripts() { ?> <script>jQuery(document).ready(function($){$('.dpwc-toggle').on('click',function(){var t=$(this);$.post(ajaxurl,{action:'dpwc_toggle_coupon',coupon_id:t.data('id'),nonce:dpwc_ajax.nonce},function(e){e.success&&location.reload()})});$('.dpwc-delete').on('click',function(){if(confirm('آیا از حذف این کوپن اطمینان دارید؟')){var t=$(this);$.post(ajaxurl,{action:'dpwc_delete_coupon',coupon_id:t.data('id'),nonce:dpwc_ajax.nonce},function(e){e.success&&location.reload()})}})});</script> <style>.status-active{color:#46b450;font-weight:700}.status-inactive,.status-expired{color:#dc3232;font-weight:700}.button-link-delete{color:#a00;text-decoration:none;border:1px solid #a00;background:#fff;border-radius:3px}.button-link-delete:hover{color:#fff;background:#dc3232;border-color:#dc3232}</style> <?php }
        private function sales_admin_scripts() { ?> <script>jQuery(document).ready(function($){$('.dpwc-delete-sale').on('click',function(e){e.preventDefault();if(confirm('آیا از حذف این تخفیف خودکار اطمینان دارید؟ قیمت محصولات به حالت اولیه بازخواهد گشت.')){var t=$(this),o=t.data('id');t.prop('disabled',!0).text('در حال حذف...');$.post(ajaxurl,{action:'dpwc_delete_sale',sale_id:o,nonce:dpwc_ajax.nonce},function(e){e.success?(alert(e.data),location.reload()):(alert('خطا: '+e.data),t.prop('disabled',!1).text('حذف'))})}})});</script> <?php }
        private function form_scripts() { ?> <script>jQuery(document).ready(function($){$('#product_ids, #user_ids, #product_categories, #product_tags').select2({placeholder:'انتخاب کنید...',dir:'rtl',width:'100%'});$('#generate-code').on('click',function(){$('#coupon_code').val('DISCOUNT'+Math.random().toString(36).substr(2,6).toUpperCase())});$('#sale_duration').on('change',function(){'custom'===$(this).val()?$('#custom_days_wrapper').show():$('#custom_days_wrapper').hide()});function handleFormSubmit(e,form,action,redirectUrl){e.preventDefault();var submitBtn=form.find('input[type="submit"]'),originalBtnText=submitBtn.val();submitBtn.val('در حال پردازش...').prop('disabled',!0);var formData=form.serialize()+'&action='+action+'&nonce='+dpwc_ajax.nonce;$.post(dpwc_ajax.ajax_url,formData,function(response){if(response.success){alert(response.data||'عملیات با موفقیت انجام شد.');window.location.href=redirectUrl}else{alert('خطا: '+response.data);submitBtn.val(originalBtnText).prop('disabled',!1)}}).fail(function(){alert('خطای سرور.');submitBtn.val(originalBtnText).prop('disabled',!1)})} $('#dpwc-coupon-form').on('submit',function(e){handleFormSubmit(e,$(this),'dpwc_save_coupon','<?php echo admin_url('admin.php?page=discount-pro-wc'); ?>')});$('#dpwc-sale-form').on('submit',function(e){handleFormSubmit(e,$(this),'dpwc_save_sale','<?php echo admin_url('admin.php?page=discount-pro-wc-sales'); ?>')})});</script> <?php }
        private function stats_styles() { ?> <style>.dpwc-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:25px;margin:30px 0}.stat-box{background:linear-gradient(135deg,#fff 0%,#f9fafb 100%);padding:30px;border-radius:16px;box-shadow:0 8px 25px rgba(0,0,0,.1);text-align:center;border:2px solid #61CE70;transition:transform .3s ease}.stat-box:hover{transform:translateY(-5px);box-shadow:0 12px 30px rgba(97,206,112,.2)}.stat-box h3{margin:0 0 15px;color:#081035;font-size:16px;font-weight:600}.stat-number{font-size:36px;font-weight:700;color:#61CE70;display:block}</style> <?php }
    }
    
    new DiscountProWooCommerce();
}
