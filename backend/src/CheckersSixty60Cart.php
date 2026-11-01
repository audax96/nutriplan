<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * PLACEHOLDER. As far as we know Checkers Sixty60 has no public API for filling a
 * customer's basket (verify this with Shoprite). The supported route is a partner or
 * affiliate agreement. Do NOT scrape or automate the consumer app: it breaks their
 * terms of service and will be fragile.
 *
 * NOT wired into the v1 API or app (v1 has no prices or retailer products).
 *
 * When Shoprite gives you API access (v2):
 *   1. Fill the `products` table: map each ingredient to a Checkers product (sku, pack size, price).
 *   2. Convert each shopping_list amount into a number of packs for that product.
 *   3. Create/append to the customer's cart and return ['status' => 'added', 'checkout_url' => '...'].
 *   4. Add a route and an "Add to Checkers" button; a budget limit can be re-introduced then.
 */
final class CheckersSixty60Cart implements RetailerCart
{
    public function addToBasket(array $lines): array
    {
        throw new ApiException(
            501,
            'not_available',
            'Adding items straight to Checkers Sixty60 is not available yet. You can copy your grocery list instead.'
        );
    }
}
