<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class PdfReadMazba extends PdfClient
{
    /**
     * Identify if this PDF matches the format handled by this assistant.
     */
    public static function validateFormat(array $lines)
    {
        // For example: check if a certain keyword always appears in this format
        foreach ($lines as $line) {
            if (Str::contains($line, 'Example Transport Document')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Process the lines and create the structured order array.
     */
    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $data = [
            'order_number' => null,
            'shipper' => [
                'name'    => null,
                'address' => null,
                'country' => null,
            ],
            'consignee' => [
                'name'    => null,
                'address' => null,
                'country' => null,
            ],
            'pickup_date'   => null,
            'delivery_date' => null,
            'goods' => [],
        ];

        foreach ($lines as $line) {
            // Example: extract order number
            if (Str::contains($line, 'Order No')) {
                $data['order_number'] = trim(Str::after($line, 'Order No'));
            }

            // Example: pickup date
            if (Str::contains($line, 'Pickup Date')) {
                $data['pickup_date'] = Carbon::parse(
                    Str::after($line, 'Pickup Date')
                )->toDateString();
            }

            // Example: delivery date
            if (Str::contains($line, 'Delivery Date')) {
                $data['delivery_date'] = Carbon::parse(
                    Str::after($line, 'Delivery Date')
                )->toDateString();
            }

            // You’d continue with shipper/consignee parsing logic here…
        }

        // Example of adding goods
        $data['goods'][] = [
            'description' => 'Example goods',
            'quantity'    => 10,
            'weight'      => 1200.5,
        ];

        // Normalize country names to ISO
        if (!empty($data['shipper']['country'])) {
            $data['shipper']['country'] = \App\GeonamesCountry::getIso($data['shipper']['country']);
        }
        if (!empty($data['consignee']['country'])) {
            $data['consignee']['country'] = \App\GeonamesCountry::getIso($data['consignee']['country']);
        }

        // Validate & finalize
        return $this->createOrder($data);
    }
}
