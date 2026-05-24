<?php

namespace GPSwiss\Ovoko\DTO;

class NormalizedOvokoPart
{
    public function __construct(private array $data)
    {
    }

    public static function from_array(array $data): self
    {
        $defaults = [
            'part_id' => '', 'title' => '', 'description' => '', 'status' => '', 'price' => null, 'currency' => '',
            'stock_quantity' => null, 'category' => '', 'images' => [], 'source_url' => '', 'vehicle_make' => '',
            'vehicle_model' => '', 'vehicle_generation' => '', 'year' => '', 'engine_code' => '', 'gearbox_code' => '',
            'oe_numbers' => [], 'raw_payload' => [], 'payload_hash' => '',
        ];

        $normalized = wp_parse_args($data, $defaults);
        $normalized['images'] = array_values(array_filter((array) $normalized['images']));
        $normalized['oe_numbers'] = array_values(array_filter((array) $normalized['oe_numbers']));
        $normalized['raw_payload'] = is_array($normalized['raw_payload']) ? $normalized['raw_payload'] : [];

        return new self($normalized);
    }

    public function to_array(): array
    {
        return $this->data;
    }

    public function get_part_id(): string
    {
        return (string) ($this->data['part_id'] ?? '');
    }
}
