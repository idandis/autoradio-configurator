<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyService
{
    protected string $store;
    protected string $token;
    protected string $version;

    public function __construct()
    {
        $this->store = (string) (config('services.shopify.store') ?? '');
        $this->token = (string) config('services.shopify.token');
        $this->version = (string) (config('services.shopify.version') ?? '2026-04');
    }

    public function isConfigured(): bool
    {
        return $this->store !== '' && $this->token !== '';
    }

    /**
     * Esegue una query GraphQL verso Shopify.
     */
    public function graphql(string $query, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Shopify API is not configured.');
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
        ])->post(
            "https://{$this->store}/admin/api/{$this->version}/graphql.json",
            [
                'query' => $query,
                'variables' => $variables,
            ]
        );

        if (! $response->successful()) {
            throw new \Exception(
                'Shopify API Error: ' . $response->body()
            );
        }

        return $response->json();
    }

    /**
     * Recupera una pagina di prodotti.
     */
    public function getProducts(?string $cursor = null): array
    {
        $query = <<<'GRAPHQL'
        query GetProducts($cursor: String) {
          products(first: 250, after: $cursor) {
            edges {
              cursor
              node {
                id
                title
                handle
                status
                vendor
                productType

                featuredImage {
                  url
                }

                variants(first: 100) {
                  edges {
                    node {
                      id
                      title
                      sku
                      price
                    }
                  }
                }
              }
            }

            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        GRAPHQL;

        return $this->graphql($query, [
            'cursor' => $cursor,
        ]);
    }

    /**
     * Recupera tutti i prodotti Shopify.
     */
    public function getAllProducts(): array
    {
        $products = [];

        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {

            $response = $this->getProducts($cursor);

            $data = $response['data']['products'];

            foreach ($data['edges'] as $edge) {
                $products[] = $edge['node'];
            }

            $hasNextPage = $data['pageInfo']['hasNextPage'];
            $cursor = $data['pageInfo']['endCursor'];
        }

        return $products;
    }

    public function getVariantsByIds(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $variantIds
        ))));

        if ($variantIds === []) {
            return [];
        }

        $query = <<<'GRAPHQL'
        query GetVariantNodes($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on ProductVariant {
              id
              title
              sku
              price
              image {
                url
              }
              product {
                id
                title
                handle
                productType
                tags
                featuredImage {
                  url
                }
              }
            }
          }
        }
        GRAPHQL;

        $result = [];

        foreach (array_chunk($variantIds, 100) as $chunk) {
            $gids = array_map(
                fn (string $id) => str_starts_with($id, 'gid://') ? $id : "gid://shopify/ProductVariant/{$id}",
                $chunk
            );

            $response = $this->graphql($query, ['ids' => $gids]);

            foreach ($response['data']['nodes'] ?? [] as $node) {
                if (! is_array($node) || ! isset($node['id'], $node['product']['handle'])) {
                    continue;
                }

                $numericId = preg_match('/(\d+)$/', $node['id'], $matches) === 1
                    ? $matches[1]
                    : null;

                if ($numericId === null) {
                    continue;
                }

                $result[$numericId] = [
                    'variant_id' => $numericId,
                    'variant_title' => $node['title'] ?? null,
                    'sku' => $node['sku'] ?? null,
                    'price' => $node['price'] ?? null,
                    'image_url' => $node['image']['url'] ?? null,
                    'product_title' => $node['product']['title'] ?? null,
                    'product_handle' => $node['product']['handle'] ?? null,
                    'product_type' => $node['product']['productType'] ?? null,
                    'product_tags' => $node['product']['tags'] ?? [],
                    'featured_image' => $node['product']['featuredImage']['url'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Recupera le opzioni variante dalla scheda pubblica Shopify, utile quando
     * l'export non include le colonne Option2/Option3.
     */
    public function getPublicVariantOptions(string $handle): array
    {
        $storefrontUrl = rtrim((string) config(
            'services.shopify.storefront_url',
            'https://www.autoradiocanario.com',
        ), '/');

        if ($storefrontUrl === '') {
            $storefrontUrl = 'https://www.autoradiocanario.com';
        }

        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->retry(2, 200, throw: false)
            ->get($storefrontUrl.'/products/'.rawurlencode($handle).'.js');

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('variants', []))
            ->filter(fn ($variant) => is_array($variant) && filled($variant['id'] ?? null))
            ->mapWithKeys(fn (array $variant) => [(string) $variant['id'] => [
                'option2' => filled($variant['option2'] ?? null) ? trim((string) $variant['option2']) : null,
                'option3' => filled($variant['option3'] ?? null) ? trim((string) $variant['option3']) : null,
            ]])
            ->all();
    }
}
