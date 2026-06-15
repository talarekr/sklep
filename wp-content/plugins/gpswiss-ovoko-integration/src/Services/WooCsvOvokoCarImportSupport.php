<?php

namespace GPSwiss\Ovoko\Services;

class WooCsvOvokoCarImportSupport
{
    private const CAR_ID_ALIASES = ['ovoko_car_id', 'car_id', 'donor_car_id', 'vehicle_id', 'ovoko_vehicle_id'];
    private const VEHICLE_FIELDS = ['ovoko_car_id', 'donor_car_id', 'car_id', 'vehicle_id', 'ovoko_vehicle_id', 'car_make', 'car_model', 'car_generation', 'car_year', 'car_engine', 'car_engine_code', 'car_vin', 'car_fuel', 'car_body_type', 'car_gearbox', 'car_mileage'];

    public function hooks(): void
    {
        add_filter('woocommerce_csv_product_import_mapping_options', [$this, 'mapping_options']);
        add_filter('woocommerce_csv_product_import_mapping_default_columns', [$this, 'mapping_default_columns']);
        add_filter('woocommerce_product_import_pre_insert_product_object', [$this, 'capture_pre_insert_data'], 10, 2);
        add_action('woocommerce_product_import_inserted_product_object', [$this, 'persist_ovoko_vehicle_data'], 10, 2);
    }

    public function mapping_options(array $options): array
    {
        foreach (self::VEHICLE_FIELDS as $field) {
            $options[$field] = 'Ovoko donor vehicle: ' . $field;
        }
        return $options;
    }

    public function mapping_default_columns(array $columns): array
    {
        foreach (self::VEHICLE_FIELDS as $field) {
            $columns[$field] = $field;
        }
        return $columns;
    }

    public function capture_pre_insert_data($product, array $data)
    {
        $ovokoData = $this->extract_vehicle_data($data);
        if ($ovokoData === [] || !is_object($product) || !method_exists($product, 'update_meta_data')) {
            return $product;
        }

        foreach ($this->meta_values_for_import_data($ovokoData) as $key => $value) {
            $product->update_meta_data($key, $value);
        }

        return $product;
    }

    public function persist_ovoko_vehicle_data($product, array $data): void
    {
        $productId = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        if ($productId <= 0) {
            return;
        }

        $ovokoData = $this->extract_vehicle_data($data);
        if ($ovokoData === []) {
            return;
        }

        foreach ($this->meta_values_for_import_data($ovokoData) as $key => $value) {
            update_post_meta($productId, $key, $value);
        }

        $ovokoCarId = (string) ($ovokoData['ovoko_car_id'] ?? '');
        if ($ovokoCarId === '') {
            return;
        }

        $car = $this->find_existing_car_by_ovoko_id($ovokoCarId);
        if ($car !== []) {
            update_post_meta($productId, '_ovoko_linked_car_table', (string) $car['table']);
            update_post_meta($productId, '_ovoko_linked_car_id', (string) $car['id']);
            update_post_meta($productId, '_gps_ovoko_import_warnings', []);
            return;
        }

        update_post_meta($productId, '_gps_ovoko_import_warnings', ['ovoko_car_not_found']);
    }

    private function extract_vehicle_data(array $data): array
    {
        $found = [];
        foreach (self::VEHICLE_FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                $found[$field] = sanitize_text_field($value);
            }
        }

        foreach (self::CAR_ID_ALIASES as $alias) {
            if (!empty($found[$alias])) {
                $found['ovoko_car_id'] = (string) $found[$alias];
                break;
            }
        }

        return $found;
    }

    private function meta_values_for_import_data(array $ovokoData): array
    {
        $legacyPayload = [
            'source' => 'woo_csv_import',
            'ovoko_vehicle' => $ovokoData,
        ];

        $meta = [
            '_gps_ovoko_import_legacy_payload' => wp_json_encode($legacyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (!empty($ovokoData['ovoko_car_id'])) {
            $meta['_ovoko_car_id'] = (string) $ovokoData['ovoko_car_id'];
        }

        return $meta;
    }

    private function find_existing_car_by_ovoko_id(string $ovokoCarId): array
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return [];
        }

        $tables = array_unique([$wpdb->prefix . 'cars', $wpdb->prefix . 'gpswiss_ovoko_cars', $wpdb->prefix . 'ovoko_cars']);
        $columns = ['ovoko_car_id', 'external_id', 'source_id', 'legacy_id', 'car_id', 'vehicle_id'];
        foreach ($tables as $table) {
            $existingColumns = $this->table_columns($table);
            if ($existingColumns === []) {
                continue;
            }
            $idColumn = in_array('id', $existingColumns, true) ? 'id' : $existingColumns[0];
            foreach ($columns as $column) {
                if (!in_array($column, $existingColumns, true)) {
                    continue;
                }
                $id = $wpdb->get_var($wpdb->prepare("SELECT `{$idColumn}` FROM `{$table}` WHERE `{$column}` = %s LIMIT 1", $ovokoCarId));
                if ($id !== null && $id !== '') {
                    return ['table' => $table, 'id' => (string) $id, 'matched_column' => $column];
                }
            }
        }

        return [];
    }

    private function table_columns(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($table) . '`', 0);
        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }
}
