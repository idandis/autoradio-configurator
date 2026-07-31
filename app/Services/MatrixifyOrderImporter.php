<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOrder;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class MatrixifyOrderImporter
{
    public function import(string|UploadedFile $source): array
    {
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        $extension = mb_strtolower($source instanceof UploadedFile
            ? $source->getClientOriginalExtension()
            : pathinfo($source, PATHINFO_EXTENSION));

        if (! $path || ! is_file($path)) {
            throw new RuntimeException('File ordini non trovato.');
        }

        [$headers, $rows] = in_array($extension, ['xls', 'xlsx'], true)
            ? $this->readSpreadsheet($path)
            : $this->readCsv($path);

        $headers = array_map(function ($header): string {
            $header = trim((string) $header);
            $header = str_replace(["\xEF\xBB\xBF", "\u{FEFF}", 'ï»¿'], '', $header);
            $header = trim($header, " \t\n\r\0\x0B\"'");

            return $header;
        }, $headers);
        $this->validateHeaders($headers);

        $groups = [];
        foreach ($rows as $row) {
            $mapped = $this->mapRow($headers, $row);
            $orderId = $this->identifier($this->value($mapped, 'ID'));
            if ($orderId !== '') {
                $groups[$orderId][] = $mapped;
            }
        }

        $stats = [
            'orders_imported' => 0,
            'orders_updated' => 0,
            'customers_imported' => 0,
            'customers_updated' => 0,
            'customers_skipped' => 0,
            'lines' => 0,
        ];

        DB::transaction(function () use ($groups, &$stats): void {
            foreach ($groups as $shopifyId => $rows) {
                $customer = $this->customer($rows, $stats);
                $existing = CustomerOrder::where('shopify_id', $shopifyId)->first();
                $order = $existing ?? new CustomerOrder;

                $order->fill([
                    'customer_id' => $customer?->id,
                    'shopify_id' => $shopifyId,
                    'name' => $this->firstValue($rows, 'Name') ?: '#'.$shopifyId,
                    'number' => $this->nullable($this->firstValue($rows, 'Number')),
                    'processed_at' => $this->date($this->firstValue($rows, 'Processed At')),
                    'shopify_created_at' => $this->date($this->firstValue($rows, 'Created At')),
                    'shopify_updated_at' => $this->date($this->firstValue($rows, 'Updated At')),
                    'cancelled_at' => $this->date($this->firstValue($rows, 'Cancelled At')),
                    'cancel_reason' => $this->nullable($this->firstValue($rows, 'Cancel: Reason')),
                    'currency' => $this->firstValue($rows, 'Currency') ?: 'EUR',
                    'total_line_items' => $this->money($this->firstValue($rows, 'Price: Total Line Items')),
                    'total_discount' => $this->money($this->firstValue($rows, 'Price: Total Discount')),
                    'total_shipping' => $this->money($this->firstValue($rows, 'Price: Total Shipping')),
                    'total_refund' => $this->money($this->firstValue($rows, 'Price: Total Refund')),
                    'total_outstanding' => $this->money($this->firstValue($rows, 'Price: Total Outstanding')),
                    'current_total' => $this->money($this->firstValue($rows, 'Price: Current Total')),
                    'total' => $this->money($this->firstValue($rows, 'Price: Total')),
                    'payment_status' => $this->nullable($this->firstValue($rows, 'Payment: Status')),
                    'fulfillment_status' => $this->nullable($this->firstValue($rows, 'Order Fulfillment Status')),
                    'additional_details' => $this->nullable($this->firstValue($rows, 'Additional Details')),
                    'billing_address' => $this->address($rows, 'Billing'),
                    'shipping_address' => $this->address($rows, 'Shipping'),
                ]);
                $order->save();

                $order->lines()->delete();
                $order->transactions()->delete();
                $order->refunds()->delete();
                $order->fulfillments()->delete();

                foreach ($rows as $row) {
                    if (mb_strtolower($this->value($row, 'Line: Type')) === 'line item') {
                        $order->lines()->create([
                            'shopify_id' => $this->nullable($this->identifier($this->value($row, 'Line: ID'))),
                            'product_id' => $this->nullable($this->identifier($this->value($row, 'Line: Product ID'))),
                            'product_handle' => $this->nullable($this->value($row, 'Line: Product Handle')),
                            'variant_id' => $this->nullable($this->identifier($this->value($row, 'Line: Variant ID'))),
                            'title' => $this->value($row, 'Line: Title') ?: 'Prodotto',
                            'name' => $this->nullable($this->value($row, 'Line: Name')),
                            'variant_title' => $this->nullable($this->value($row, 'Line: Variant Title')),
                            'sku' => $this->nullable($this->value($row, 'Line: SKU')),
                            'quantity' => (int) $this->money($this->value($row, 'Line: Quantity')),
                            'price' => $this->money($this->value($row, 'Line: Price')),
                            'discount' => $this->money($this->value($row, 'Line: Discount')),
                            'total' => $this->money($this->value($row, 'Line: Total')),
                            'vendor' => $this->nullable($this->value($row, 'Line: Vendor')),
                            'properties' => $this->nullable($this->value($row, 'Line: Properties')),
                            'fulfillment_service' => $this->nullable($this->value($row, 'Line: Fulfillment Service')),
                            'fulfillment_status' => $this->nullable($this->value($row, 'Line: Fulfillment Status')),
                        ]);
                        $stats['lines']++;
                    }
                }

                $this->importTransactions($order, $rows);
                $this->importRefunds($order, $rows);
                $this->importFulfillments($order, $rows);
                $stats[$existing ? 'orders_updated' : 'orders_imported']++;
            }

            $this->refreshCustomerOrderData();
        });

        return $stats;
    }

    private function customer(array $rows, array &$stats): ?Customer
    {
        $shopifyId = $this->identifier($this->firstValue($rows, 'Customer: ID'));
        $email = mb_strtolower($this->firstValue($rows, 'Customer: Email') ?: $this->firstValue($rows, 'Email'));
        $spent = $this->money($this->firstValue($rows, 'Customer: Total Spent'));
        $orderTotal = $this->money($this->firstValue($rows, 'Price: Total'));

        if ($spent <= 0 && $orderTotal <= 0) {
            $stats['customers_skipped']++;
            return null;
        }

        $customer = Customer::query()
            ->when($shopifyId !== '', fn ($query) => $query->where('shopify_id', $shopifyId))
            ->when($shopifyId === '' && $email !== '', fn ($query) => $query->where('email', $email))
            ->first();
        $existing = $customer !== null;
        $customer ??= new Customer;

        $values = [
            'shopify_id' => $shopifyId ?: null,
            'email' => $email ?: null,
            'phone' => $this->nullable($this->firstValue($rows, 'Customer: Phone') ?: $this->firstValue($rows, 'Phone')),
            'first_name' => $this->nullable($this->firstValue($rows, 'Customer: First Name')),
            'last_name' => $this->nullable($this->firstValue($rows, 'Customer: Last Name')),
            'note' => $this->nullable($this->firstValue($rows, 'Customer: Note')),
            'state' => $this->nullable($this->firstValue($rows, 'Customer: State')),
            'tags' => $this->nullable($this->firstValue($rows, 'Customer: Tags')),
            'total_orders' => (int) $this->money($this->firstValue($rows, 'Customer: Orders Count')),
            'total_spent' => $spent,
        ];

        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $customer->{$key} = $value;
            }
        }
        $customer->save();
        $stats[$existing ? 'customers_updated' : 'customers_imported']++;

        if (! $customer->addresses()->exists()) {
            $address = $this->address($rows, 'Shipping') ?: $this->address($rows, 'Billing');
            if ($address) {
                $customer->addresses()->create([
                    'first_name' => $address['first_name'],
                    'last_name' => $address['last_name'],
                    'company' => $address['company'],
                    'phone' => $address['phone'],
                    'line_1' => $address['line_1'],
                    'line_2' => $address['line_2'],
                    'city' => $address['city'],
                    'province' => $address['province'],
                    'province_code' => $address['province_code'],
                    'country' => $address['country'],
                    'country_code' => $address['country_code'],
                    'zip' => $address['zip'],
                    'is_default' => true,
                ]);
            }
        }

        return $customer;
    }

    private function importTransactions(CustomerOrder $order, array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $id = $this->identifier($this->value($row, 'Transaction: ID'));
            $kind = $this->value($row, 'Transaction: Kind');
            if ($id === '' && $kind === '') {
                continue;
            }
            $key = $id ?: md5(implode('|', [$kind, $this->value($row, 'Transaction: Processed At'), $this->value($row, 'Transaction: Amount')]));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $order->transactions()->create([
                'shopify_id' => $id ?: null,
                'kind' => $this->nullable($kind),
                'processed_at' => $this->date($this->value($row, 'Transaction: Processed At')),
                'amount' => $this->money($this->value($row, 'Transaction: Amount')),
                'currency' => $this->nullable($this->value($row, 'Transaction: Currency')),
                'status' => $this->nullable($this->value($row, 'Transaction: Status')),
                'message' => $this->nullable($this->value($row, 'Transaction: Message')),
                'gateway' => $this->nullable($this->value($row, 'Transaction: Gateway')),
                'payment_method' => $this->nullable($this->value($row, 'Transaction: Payment Method')),
                'wallet' => $this->nullable($this->value($row, 'Transaction: Wallet')),
                'is_test' => $this->boolean($this->value($row, 'Transaction: Test')),
                'error_code' => $this->nullable($this->value($row, 'Transaction: Error Code')),
            ]);
        }
    }

    private function importRefunds(CustomerOrder $order, array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $id = $this->identifier($this->value($row, 'Refund: ID'));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $order->refunds()->create([
                'shopify_id' => $id,
                'created_at_shopify' => $this->date($this->value($row, 'Refund: Created At')),
                'note' => $this->nullable($this->value($row, 'Refund: Note')),
                'restock' => $this->boolean($this->value($row, 'Refund: Restock')),
                'restock_type' => $this->nullable($this->value($row, 'Refund: Restock Type')),
                'restock_location' => $this->nullable($this->value($row, 'Refund: Restock Location')),
            ]);
        }
    }

    private function importFulfillments(CustomerOrder $order, array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $id = $this->identifier($this->value($row, 'Fulfillment: ID'));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $order->fulfillments()->create([
                'shopify_id' => $id,
                'status' => $this->nullable($this->value($row, 'Fulfillment: Status')),
                'created_at_shopify' => $this->date($this->value($row, 'Fulfillment: Created At')),
                'updated_at_shopify' => $this->date($this->value($row, 'Fulfillment: Updated At')),
                'tracking_company' => $this->nullable($this->value($row, 'Fulfillment: Tracking Company')),
                'location' => $this->nullable($this->value($row, 'Fulfillment: Location')),
                'shipment_status' => $this->nullable($this->value($row, 'Fulfillment: Shipment Status')),
                'tracking_number' => $this->nullable($this->value($row, 'Fulfillment: Tracking Number')),
                'tracking_url' => $this->nullable($this->value($row, 'Fulfillment: Tracking URL')),
            ]);
        }
    }

    private function refreshCustomerOrderData(): void
    {
        Customer::query()->whereHas('orders')->each(function (Customer $customer): void {
            $customer->first_order_at = $customer->orders()->min('processed_at');
            $customer->last_order_at = $customer->orders()->max('processed_at');
            $customer->save();
        });
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('Impossibile aprire il file ordini.');
        }
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\') ?: [];
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return [$headers, $rows];
    }

    private function readSpreadsheet(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getSheetByName('Orders') ?? $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Impossibile aprire il file Excel degli ordini.', previous: $exception);
        }

        return [array_shift($rows) ?? [], $rows];
    }

    private function validateHeaders(array $headers): void
    {
        $normalized = array_map('mb_strtolower', $headers);
        $missing = [];
        foreach (['ID', 'Name', 'Processed At', 'Customer: ID', 'Line: Type'] as $required) {
            if (! in_array(mb_strtolower($required), $normalized, true)) {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Questo non sembra un export Orders di Matrixify. Mancano le colonne: '.implode(', ', $missing).'.');
        }
    }

    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $mapped[$header] = trim((string) ($row[$index] ?? ''));
            }
        }

        return $mapped;
    }

    private function firstValue(array $rows, string $key): string
    {
        foreach ($rows as $row) {
            $value = $this->value($row, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function value(array $row, string $key): string
    {
        foreach ($row as $header => $value) {
            if (mb_strtolower($header) === mb_strtolower($key)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function address(array $rows, string $prefix): ?array
    {
        $address = [];
        foreach ([
            'first_name' => 'First Name', 'last_name' => 'Last Name', 'company' => 'Company',
            'phone' => 'Phone', 'line_1' => 'Address 1', 'line_2' => 'Address 2',
            'zip' => 'Zip', 'city' => 'City', 'province' => 'Province',
            'province_code' => 'Province Code', 'country' => 'Country', 'country_code' => 'Country Code',
        ] as $key => $suffix) {
            $address[$key] = $this->nullable($this->firstValue($rows, "{$prefix}: {$suffix}"));
        }

        return array_filter($address) === [] ? null : $address;
    }

    private function identifier(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value !== '' && is_numeric($value)) {
            return number_format((float) $value, 0, '', '');
        }

        return $value;
    }

    private function money(string $value): float
    {
        $value = preg_replace('/[^\d,.-]/u', '', $value) ?? '';
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0;
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function boolean(string $value): bool
    {
        return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'sì', 'si'], true);
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
