<?php
/**
 * Plugin Name: WooCommerce Stock Sync
 * Description: Synchronice WooCommerce stock info with a simple API call
 * Version: 1.0
 * Author: Code Sharp Kft
 * WC requires at least: 3.0
 * WC tested up to: 6.0
 */

// Register a new namespace and route for our API endpoint.
add_action('rest_api_init', function () {
    register_rest_route('wc-custom/v1', '/stock-sync', array(
        'methods' => 'POST',
        'callback' => 'wc_custom_handle_post_request',
        'permission_callback' => 'wc_custom_check_api_permission'
    ));
        register_rest_route('wc-custom/v1', '/stock-sync', array(
        'methods' => 'GET',
        'callback' => 'wc_custom_handle_get_request',
        'permission_callback' => 'wc_custom_check_api_permission'
    ));
});


/**
 * Handle the POST request to our custom endpoint.
 */
function wc_custom_handle_post_request(WP_REST_Request $request) {
        $csv = $request->get_body();
        log_received_csv($csv);
        $data = str_replace("\r\n", "\n", $csv);
        $data = explode("\n", $data);
        array_shift($data);

        global $wpdb;
        //$wpdb->query('START TRANSACTION');

        $valid = 0;
        $invalid = 0;

        foreach ($data as $item) {
                if ($item == null || trim($item) == '')
                        continue;
                $data_parts = explode(";", rtrim($item, ";"));
                if (count($data_parts) == 3 && is_numeric($data_parts[1]) && is_numeric($data_parts[2])) {
                        $sku = $data_parts[0];
                        $stock_quantity = intval($data_parts[1]);
                        $regular_price = floatval($data_parts[2]);
                        add_updateable_product($sku, $stock_quantity, $regular_price);
                        $valid += 1;
                } else {
                        log_message('Invalid data format: ' . $item);
                        $invalid += 1;
                }
        }
        //$wpdb->query('COMMIT');

        if (!wp_next_scheduled('product_update_event')) {
                wp_schedule_event(time(), 'every_minute', 'product_update_event');
        }

        log_message("Csv received, valid: $valid, invalid: $invalid.");

        return new WP_REST_Response(['valid' => $valid, 'invalid' => $invalid], 200);
}

function wc_custom_handle_get_request(WP_REST_Request $request) {
        $products = wc_get_products(array(
                'limit' => -1,
        ));

        $csv = "Termekkod;Mennyiseg;Br.Elad.Ar\n";
        foreach ($products as $product) {
                $sku = $product->get_sku();
                if (empty($sku))
                        continue;
                $csv .= $sku . ';' . $product->get_stock_quantity() . ';' . intval($product->get_regular_price()) . "\n";

        }
        header("Content-type: text/csv");
        header("Content-Disposition: attachment; filename=web_sku.csv");

        echo $csv;
        exit;
}

function wc_custom_check_api_permission($request) {
        return current_user_can('read') && current_user_can('edit_posts');
}

function log_message($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "$timestamp: $message";
        $log_file = __DIR__ . '/logfile.log';

        error_log($log_message . PHP_EOL, 3, $log_file);
}

function log_received_csv($csv) {
        $timestamp = date('Y-m-d H:i:s');
        $log_file = __DIR__ . '/' . $timestamp . '.csv';
        error_log($csv, 3, $log_file);
}

register_activation_hook(__FILE__, 'create_updateable_products_table');
function create_updateable_products_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'updateable_products';

        $sql = "CREATE TABLE $table_name (
                id INT NOT NULL AUTO_INCREMENT,
                sku VARCHAR(255),
                stock_quantity INT,
                regular_price DECIMAL(10,2),
                PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
}

function add_updateable_product($sku, $stock_quantity, $regular_price) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'updateable_products';
        $wpdb->insert($table_name, array(
                'sku' => $sku,
                'stock_quantity' => $stock_quantity,
                'regular_price' => $regular_price
        ));
}

register_deactivation_hook(__FILE__, 'delete_updateable_products_table');
function delete_updateable_products_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'updateable_products';
        $wpdb->query("DROP TABLE IF EXISTS $table_name;");
}

function update_products($time_limit) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'updateable_products';
        $end_time = time() + $time_limit;

        $updateable = 0;
        $unchanged = 0;
        $failed = 0;

        $max_id = -1;
        while (time() < $end_time) {
                $products = $wpdb->get_results("SELECT * FROM $table_name WHERE id > $max_id ORDER BY id LIMIT 100;");

                log_message("Processing " . count($products) . " product(s)");
                foreach ($products as $row) {
                        if(time() > $end_time) {
                                break;
                        }
                        $product_id = wc_get_product_id_by_sku($row->sku);
                        //log_message("Processing $product_id with sku: " . $row->sku);
                        if ($product_id == 0){
                                $failed += 1;
                                log_message("Product not found with sku: " . $row->sku);
                        } else {
                                $product = wc_get_product($product_id);
                                if($product) {
                                        $sq = intval($product->get_stock_quantity());
                                        $sp = floatval($product->get_regular_price());
                                        if ($sq != $row->stock_quantity || $sp != $row->regular_price) {
                                                log_message("Updating product with sku $row->sku quantity: $sq -> $row->stock_quantity price: $sp -> $row->regular_price");
                                                $updateable += 1;
                                                $product->set_stock_quantity($row->stock_quantity);
                                                $product->set_regular_price($row->regular_price);
                                                $product->save();
                                        } else {
                                                $unchanged += 1;
                                        }
                                } else {
                                        $failed += 1;
                                        log_message("Product not found with id: " . $product_id);
                                }
                        }
                        if ($max_id < $row->id)
                                $max_id = $row->id;
                        //This does not work!
                        //$sql = $wpdb->prepare("DELETE FROM $table_name WHERE id = %d ;", $row->id);
                        //$wpdb->query($sql);
                }


                if (count($products) < 100) {
                        wp_clear_scheduled_hook('product_update_event');
                        log_message('CRON job cleared.');
                        break;
                }
        }
        //This works!
        log_message("Deleting rows wit id lower than $max_id");
        $sql = $wpdb->prepare("DELETE FROM $table_name WHERE id < %d ;", $max_id);
        $wpdb->query($sql);
        log_message("updated: $updateable, unchanged: $unchanged, failed: $failed");
}

// Custom cron schedule
add_filter('cron_schedules', 'product_update_task_custom_schedule');
function product_update_task_custom_schedule($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60, // 60 seconds
        'display' => __('Every Minute')
    );
    return $schedules;
}

// The function to execute on the cron job
add_action('product_update_event', 'product_update_task');
function product_update_task() {
        $start = microtime(true);
        update_products(20);
        $time_elapsed_secs = microtime(true) - $start;
        log_message("Cron job executed with duration: $time_elapsed_secs");
}
