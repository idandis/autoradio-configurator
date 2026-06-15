<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyService
{
    protected string $store;
    protected string $token;
    protected string $version;

    public function __construct()
    {
        $this->store = config('services.shopify.store');
        $this->token = config('services.shopify.token');
        $this->version = config('services.shopify.version');
    }

    /**
     * Esegue una query GraphQL verso Shopify.
     */
    public function graphql(string $query, array $variables = []): array
    {
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
}
