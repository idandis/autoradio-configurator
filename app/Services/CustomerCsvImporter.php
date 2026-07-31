<?php

namespace App\Services;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class CustomerCsvImporter
{
    public function import(string|UploadedFile $source): array
    {
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        $extension = mb_strtolower($source instanceof UploadedFile
            ? $source->getClientOriginalExtension()
            : pathinfo($source, PATHINFO_EXTENSION));

        if (! $path || ! is_file($path)) {
            throw new RuntimeException('File clienti non trovato.');
        }

        [$headers, $rows] = in_array($extension, ['xls', 'xlsx'], true)
            ? $this->readSpreadsheet($path)
            : $this->readCsv($path);

        $headers = array_map(fn ($header) => $this->normalizeHeader($header), $headers ?? []);
        $this->validateHeaders($headers);

        $groups = [];
        $currentKey = null;

        foreach ($rows as $row) {
            $mapped = $this->mapRow($headers, $row);
            $shopifyId = $this->value($mapped, ['ID', 'Customer ID']);
            $email = mb_strtolower($this->value($mapped, ['Email']));

            if ($shopifyId !== '') {
                $currentKey = 'id:'.$shopifyId;
            } elseif ($email !== '') {
                $currentKey = 'email:'.$email;
            }

            if ($currentKey !== null) {
                $groups[$currentKey][] = $mapped;
            }
        }

        $stats = [
            'imported' => 0,
            'updated' => 0,
            'skipped_without_spending' => 0,
            'addresses' => 0,
        ];

        DB::transaction(function () use ($groups, &$stats): void {
            foreach ($groups as $rows) {
                $totalOrders = (int) $this->number($this->firstValue($rows, ['Total Orders']));
                $totalSpent = $this->number($this->firstValue($rows, ['Total Spent']));

                if ($totalSpent <= 0) {
                    $stats['skipped_without_spending']++;
                    continue;
                }

                $shopifyId = $this->firstValue($rows, ['ID', 'Customer ID']) ?: null;
                $email = mb_strtolower($this->firstValue($rows, ['Email'])) ?: null;
                $customer = Customer::query()
                    ->when(
                        $shopifyId,
                        fn ($query) => $query->where('shopify_id', $shopifyId),
                        fn ($query) => $query->where('email', $email),
                    )
                    ->first();
                $isNew = $customer === null;
                $customer ??= new Customer;

                $customer->fill([
                    'shopify_id' => $shopifyId,
                    'email' => $email,
                    'first_name' => $this->nullable($this->firstValue($rows, ['First Name'])),
                    'last_name' => $this->nullable($this->firstValue($rows, ['Last Name'])),
                    'phone' => $this->nullable($this->firstValue($rows, ['Phone'])),
                    'language' => $this->nullable($this->firstValue($rows, ['Language'])),
                    'state' => $this->nullable($this->firstValue($rows, ['State'])),
                    'note' => $this->nullable($this->firstValue($rows, ['Note'])),
                    'tags' => $this->nullable($this->firstValue($rows, ['Tags'])),
                    'total_orders' => $totalOrders,
                    'total_spent' => number_format($totalSpent, 2, '.', ''),
                    'first_order_at' => $this->date($this->firstValue($rows, ['First Order: Processed At'])),
                    'last_order_at' => $this->date($this->firstValue($rows, ['Last Order: Processed At'])),
                    'shopify_created_at' => $this->date($this->firstValue($rows, ['Created At'])),
                    'shopify_updated_at' => $this->date($this->firstValue($rows, ['Updated At'])),
                ]);
                $customer->save();

                $customer->addresses()->delete();
                $addresses = $this->addresses($rows);

                if ($addresses !== []) {
                    $customer->addresses()->createMany($addresses);
                    $stats['addresses'] += count($addresses);
                }

                $stats[$isNew ? 'imported' : 'updated']++;
            }
        });

        return $stats;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw new RuntimeException('Impossibile aprire il file CSV.');
        }

        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return [$headers ?: [], $rows];
    }

    private function readSpreadsheet(string $path): array
    {
        try {
            $rows = IOFactory::load($path)
                ->getActiveSheet()
                ->toArray(null, true, true, false);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Impossibile aprire il file Excel.', previous: $exception);
        }

        return [array_shift($rows) ?? [], $rows];
    }

    private function validateHeaders(array $headers): void
    {
        $normalized = array_map('mb_strtolower', $headers);
        $missing = [];

        if (! in_array('id', $normalized, true) && ! in_array('customer id', $normalized, true)) {
            $missing[] = 'ID';
        }

        if (! in_array('total orders', $normalized, true)) {
            $missing[] = 'Total Orders';
        }

        if (! in_array('total spent', $normalized, true)) {
            $missing[] = 'Total Spent';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Import non eseguito. Mancano le colonne: '.implode(', ', $missing).'.'
            );
        }
    }

    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $mapped[$header] = $this->sanitize($row[$index] ?? null);
            }
        }

        return $mapped;
    }

    private function addresses(array $rows): array
    {
        $addresses = [];

        foreach ($rows as $row) {
            $address = [
                'shopify_id' => $this->nullable($this->value($row, ['Address ID'])),
                'first_name' => $this->nullable($this->value($row, ['Address First Name'])),
                'last_name' => $this->nullable($this->value($row, ['Address Last Name'])),
                'company' => $this->nullable($this->value($row, ['Address Company'])),
                'phone' => $this->nullable($this->value($row, ['Address Phone'])),
                'line_1' => $this->nullable($this->value($row, ['Address Line 1', 'Address1'])),
                'line_2' => $this->nullable($this->value($row, ['Address Line 2', 'Address2'])),
                'city' => $this->nullable($this->value($row, ['Address City', 'City'])),
                'province' => $this->nullable($this->value($row, ['Address Province', 'Province'])),
                'province_code' => $this->nullable($this->value($row, ['Address Province Code'])),
                'country' => $this->nullable($this->value($row, ['Address Country', 'Country'])),
                'country_code' => $this->nullable($this->value($row, ['Address Country Code', 'Country Code'])),
                'zip' => $this->nullable($this->value($row, ['Address Zip', 'Zip'])),
                'is_default' => $this->boolean($this->value($row, ['Address Is Default'])),
            ];

            if (! collect($address)->except(['shopify_id', 'is_default'])->filter()->isNotEmpty()) {
                continue;
            }

            $key = $address['shopify_id'] ?: md5(implode('|', $address));
            $addresses[$key] = $address;
        }

        return array_values($addresses);
    }

    private function firstValue(array $rows, array $keys): string
    {
        foreach ($rows as $row) {
            $value = $this->value($row, $keys);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ($row as $header => $value) {
                if (mb_strtolower($header) === mb_strtolower($key)) {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }

    private function number(string $value): float
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
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function boolean(string $value): bool
    {
        return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'sì', 'si'], true);
    }

    private function normalizeHeader(mixed $value): string
    {
        return trim(str_replace("\u{FEFF}", '', (string) $this->sanitize($value)), " \t\n\r\0\x0B\"'");
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', ['UTF-8', 'Windows-1252', 'ISO-8859-1']);
    }

    private function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
