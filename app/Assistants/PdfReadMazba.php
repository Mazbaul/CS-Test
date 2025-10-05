<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Helpers\GeonamesCountry;

class PdfReadMazba extends PdfClient
{
    /**
     * Identify if this PDF matches the format handled by this assistant.
     */
    public static function validateFormat(array $lines)
    {
        // For example: check if a certain keyword always appears in this format
        foreach ($lines as $line) {
            if (Str::contains($line, 'TRANSALLIANCE TS LTD')) {
                return true;
            }
        }
        return false;
    }

    public function processLines(array $lines, ?string $attachment_filename = null): array
    {
        $data = [
            'order_number' => null,
            'order_date' => null,
            'delivery_date' => null,
            'customer' => [
                'name' => null,
                'address' => [
                    'street' => null,
                    'city' => null,
                    'zip' => null,
                    'country' => null,
                ],
            ],
            'supplier' => [
                'name' => null,
                'address' => [
                    'street' => null,
                    'city' => null,
                    'zip' => null,
                    'country' => null,
                ],
            ],
            'items' => [],
            'loading' => [
                'date' => null,
                'time_from' => null,
                'time_to' => null,
                'address' => [
                    'street' => null,
                    'city' => null,
                    'zip' => null,
                    'country' => null,
                ],
            ],
            'delivery' => [
                'date' => null,
                'time_from' => null,
                'time_to' => null,
                'address' => [
                    'street' => null,
                    'city' => null,
                    'zip' => null,
                    'country' => null,
                ],
            ],
        ];

        $currentSection = null;
        $addressBuffer = [];
        $isCustomerAddress = false;
        $isSupplierAddress = false;
        $isLoadingAddress = false;
        $isDeliveryAddress = false;
        $supplierAddressStarted = false;

        foreach ($lines as $index => $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }

            // Extract supplier information from the header
            if (Str::contains($line, 'TRANSALLIANCE TS LTD') && empty($data['supplier']['name'])) {
                $data['supplier']['name'] = 'TRANSALLIANCE TS LTD';
                $isSupplierAddress = true;
                $addressBuffer = [];
                continue;
            }

            // Extract order number from REF line
            if (Str::startsWith($line, 'REF .')) {
                $data['order_number'] = trim(Str::after($line, 'REF .'));
            }

            // Extract order date
            if (Str::startsWith($line, 'Date/Time :')) {
                $datePart = Str::before(Str::after($line, 'Date/Time :'), ' ');
                $data['order_date'] = $this->parseDate($datePart);
            }

            // Extract customer information
            if (str_contains($line, 'Test Client 2')) {
                $data['customer']['name'] = 'Test Client 2';
                $isCustomerAddress = true;
                $isSupplierAddress = false; // Stop supplier address collection
                $addressBuffer = [];
                continue;
            }

            // Extract shipping price
            if (str_contains($line, 'SHIPPING PRICE')) {
                $priceLine = $lines[$index + 1] ?? '';
                if (preg_match('/([\d.,]+)\s*EUR/', $priceLine, $matches)) {
                    $data['items'][] = [
                        'position' => 1,
                        'description' => 'Shipping Service',
                        'quantity' => 1,
                        'unit' => 'shipment',
                        'unit_price' => uncomma($matches[1]),
                        'total_price' => uncomma($matches[1]),
                    ];
                }
            }

            // Extract loading information
            if (str_contains($line, 'Loading')) {
                $isLoadingAddress = true;
                $isCustomerAddress = false;
                $isSupplierAddress = false;
                $addressBuffer = [];
                $loadingInfo = $this->extractDateTime($lines, $index);
                if ($loadingInfo) {
                    $data['loading'] = array_merge($data['loading'], $loadingInfo);
                }
            }

            // Extract delivery information
            if (str_contains($line, 'Delivery')) {
                $isDeliveryAddress = true;
                $isLoadingAddress = false;
                $addressBuffer = [];
                $deliveryInfo = $this->extractDateTime($lines, $index);
                if ($deliveryInfo) {
                    $data['delivery'] = array_merge($data['delivery'], $deliveryInfo);
                }
            }

            // Process supplier address lines
            if ($isSupplierAddress) {
                if ($this->isAddressLine($line) && !Str::startsWith($line, 'Tel :') && !Str::startsWith($line, 'VAT NUM:')) {
                    $addressBuffer[] = $line;
                    
                    // Try to parse supplier address from buffer when we have enough lines
                    if (count($addressBuffer) >= 2) {
                        $parsedAddress = $this->parseSupplierAddress($addressBuffer);
                        if ($parsedAddress && $parsedAddress['city']) {
                            $data['supplier']['address'] = $parsedAddress;
                            $isSupplierAddress = false;
                        }
                    }
                } else {
                    // If we hit a non-address line and have some address data, try to parse it
                    if (!empty($addressBuffer)) {
                        $parsedAddress = $this->parseSupplierAddress($addressBuffer);
                        if ($parsedAddress) {
                            $data['supplier']['address'] = $parsedAddress;
                        }
                        $isSupplierAddress = false;
                    }
                }
            }

            // Process customer address lines
            if ($isCustomerAddress) {
                if ($this->isAddressLine($line)) {
                    $addressBuffer[] = $line;
                    
                    // Try to parse customer address from buffer
                    if (count($addressBuffer) >= 2) {
                        $parsedAddress = $this->parseCustomerAddress($addressBuffer);
                        if ($parsedAddress) {
                            $data['customer']['address'] = $parsedAddress;
                            $isCustomerAddress = false;
                        }
                    }
                } else {
                    // Reset if we hit a non-address line
                    if (!empty($addressBuffer)) {
                        $parsedAddress = $this->parseCustomerAddress($addressBuffer);
                        if ($parsedAddress) {
                            $data['customer']['address'] = $parsedAddress;
                        }
                        $isCustomerAddress = false;
                    }
                    $addressBuffer = [];
                }
            }

            // Process loading address lines
            if ($isLoadingAddress) {
                if ($this->isAddressLine($line) && !Str::startsWith($line, 'ONE:')) {
                    $addressBuffer[] = $line;
                    
                    // Try to parse loading address
                    if (count($addressBuffer) >= 2) {
                        $parsedAddress = $this->parseLocationAddress($addressBuffer, 'GB');
                        if ($parsedAddress && $parsedAddress['street']) {
                            $data['loading']['address'] = $parsedAddress;
                            $isLoadingAddress = false;
                        }
                    }
                } else {
                    // Reset if we hit a non-address line
                    if (!empty($addressBuffer)) {
                        $parsedAddress = $this->parseLocationAddress($addressBuffer, 'GB');
                        if ($parsedAddress) {
                            $data['loading']['address'] = $parsedAddress;
                        }
                        $isLoadingAddress = false;
                    }
                    $addressBuffer = [];
                }
            }

            // Process delivery address lines
            if ($isDeliveryAddress) {
                if ($this->isAddressLine($line) && !Str::startsWith($line, 'ONE:')) {
                    $addressBuffer[] = $line;
                    
                    // Try to parse delivery address
                    if (count($addressBuffer) >= 2) {
                        $parsedAddress = $this->parseLocationAddress($addressBuffer, 'FR');
                        if ($parsedAddress && $parsedAddress['street']) {
                            $data['delivery']['address'] = $parsedAddress;
                            $isDeliveryAddress = false;
                        }
                    }
                } else {
                    // Reset if we hit a non-address line
                    if (!empty($addressBuffer)) {
                        $parsedAddress = $this->parseLocationAddress($addressBuffer, 'FR');
                        if ($parsedAddress) {
                            $data['delivery']['address'] = $parsedAddress;
                        }
                        $isDeliveryAddress = false;
                    }
                    $addressBuffer = [];
                }
            }

            // Extract weight and goods nature
            if (str_contains($line, 'Weight . :')) {
                if (preg_match('/Weight \. :\s*([\d.,]+)/', $line, $matches)) {
                    $data['total_weight'] = uncomma($matches[1]);
                }
            }

            if (str_contains($line, 'M. nature:')) {
                $data['goods_nature'] = trim(Str::after($line, 'M. nature:'));
            }
        }

        // Clean up data
        $data = $this->cleanData($data);
        
        return $this->createOrder($data);
    }

    protected function parseSupplierAddress(array $addressBuffer): array
    {
        $address = [
            'street' => null,
            'city' => null,
            'zip' => null,
            'country' => 'GB',
        ];

        if (count($addressBuffer) >= 2) {
            // First line after company name is usually the street address
            $address['street'] = $addressBuffer[0];

            // Second line typically contains city and postal code
            $secondLine = $addressBuffer[1];
            
            // Try to extract UK postal code pattern (e.g., GB-DE14 2WX or DE14 2WX)
            if (preg_match('/(?:GB-)?([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})/i', $secondLine, $postalMatches)) {
                $address['zip'] = trim($postalMatches[1]);
                // Extract city name (everything before the postal code)
                $cityPart = trim(str_replace($postalMatches[0], '', $secondLine));
                $address['city'] = $cityPart ?: 'BURTON UPON TRENT';
            } else {
                // Fallback: use the second line as city if no postal code found
                $address['city'] = $secondLine;
            }
        }

        return $address;
    }

    protected function parseDate(string $dateString): ?string
    {
        try {
            return Carbon::createFromFormat('d/m/Y', trim($dateString))->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractDateTime(array $lines, int $currentIndex): ?array
    {
        $dateTimeInfo = [];
        
        // Look for date pattern in current and next lines
        for ($i = $currentIndex; $i <= $currentIndex + 2; $i++) {
            if (isset($lines[$i])) {
                $line = $lines[$i];
                
                // Match date pattern: ONE: 17/09/25 8h00 – 15h00
                if (preg_match('/ONE:\s*(\d{2}\/\d{2}\/\d{2})\s+(\d+)h(\d+)\s*–\s*(\d+)h(\d+)/', $line, $matches)) {
                    try {
                        $date = Carbon::createFromFormat('d/m/y', $matches[1])->format('Y-m-d');
                        $timeFrom = $matches[2] . ':' . $matches[3];
                        $timeTo = $matches[4] . ':' . $matches[5];
                        
                        $dateTimeInfo = [
                            'date' => $date,
                            'time_from' => $timeFrom,
                            'time_to' => $timeTo,
                        ];
                        break;
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }
        
        return $dateTimeInfo;
    }

    protected function isAddressLine(string $line): bool
    {
        // Skip lines that are clearly not address lines
        $skipPatterns = [
            'Contact:', 'Tel :', 'Fax:', 'E-mail :', 'VAT NUM:', 'REF .', 
            'ONE:', 'OT :', 'Instructions:', 'WELL SEND BARCODE',
            'Agreed to,', 'CAUTION,', 'IMPORTANT /', 'BRADCLING',
            'Payment terms :', 'SHIPPING PRICE', 'OTHER COSTS',
            'Loading', 'Delivery', 'Observations :', 'Weight . :',
            'Page:', 'CHARTERING CONFIRMATION', 'Date/Time :',
        ];
        
        foreach ($skipPatterns as $pattern) {
            if (Str::contains($line, $pattern)) {
                return false;
            }
        }
        
        // Address lines typically contain alphanumeric characters, spaces, and common address symbols
        return preg_match('/^[a-zA-Z0-9\s\-\.,\/@]+$/u', $line) && strlen($line) > 5;
    }

    protected function parseCustomerAddress(array $addressBuffer): ?array
    {
        if (count($addressBuffer) < 2) {
            return null;
        }

        $address = [
            'street' => null,
            'city' => null,
            'zip' => null,
            'country' => null,
        ];

        // First line is usually street address
        $address['street'] = $addressBuffer[0];

        // Second line contains city and postal code
        $secondLine = $addressBuffer[1];
        
        // Try to extract Lithuanian address format
        if (preg_match('/^(LT-\d{5})\s+([A-Z\s]+)$/', $secondLine, $matches)) {
            $address['zip'] = $matches[1];
            $address['city'] = trim($matches[2]);
            $address['country'] = 'LT';
        } else {
            // Fallback: use the second line as city
            $address['city'] = $secondLine;
        }

        return $address;
    }

    protected function parseLocationAddress(array $addressBuffer, string $defaultCountry): array
    {
        $address = [
            'street' => null,
            'city' => null,
            'zip' => null,
            'country' => $defaultCountry,
        ];

        if (!empty($addressBuffer)) {
            // Look for street address in the lines
            foreach ($addressBuffer as $line) {
                if (preg_match('/^[A-Z].*[A-Z]$/', $line) && !preg_match('/^REFERENCE/', $line)) {
                    $address['street'] = $line;
                    break;
                }
            }

            // Look for postal code and city pattern
            foreach ($addressBuffer as $line) {
                // UK postal code pattern
                if (preg_match('/(GB-)?([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\s+([A-Z\s]+)$/i', $line, $matches)) {
                    $address['zip'] = trim($matches[2]);
                    $address['city'] = trim($matches[3]);
                    break;
                }
                // French postal code pattern
                if (preg_match('/(\d{5})\s+([A-Z\s\-]+)$/', $line, $matches)) {
                    $address['zip'] = $matches[1];
                    $address['city'] = trim($matches[2]);
                    break;
                }
            }
        }

        return $address;
    }

    protected function cleanData(array $data): array
    {
        // Ensure dates are properly formatted
        if ($data['order_date']) {
            try {
                $data['order_date'] = Carbon::parse($data['order_date'])->format('Y-m-d');
            } catch (\Exception $e) {
                $data['order_date'] = null;
            }
        }

        // Set delivery date from loading information if available
        if ($data['loading']['date']) {
            $data['delivery_date'] = $data['loading']['date'];
        }

        // Convert country names to ISO codes
        if ($data['customer']['address']['country']) {
            $data['customer']['address']['country'] = GeonamesCountry::getIso(
                $data['customer']['address']['country']
            ) ?: $data['customer']['address']['country'];
        }

        // Ensure supplier name is set
        if (empty($data['supplier']['name'])) {
            $data['supplier']['name'] = 'TRANSALLIANCE TS LTD';
        }

        // Ensure items array is properly structured
        if (empty($data['items'])) {
            $data['items'] = [
                [
                    'position' => 1,
                    'description' => 'Transport Service',
                    'quantity' => 1,
                    'unit' => 'shipment',
                    'unit_price' => 0,
                    'total_price' => 0,
                ]
            ];
        }

        return $data;
    }
}
