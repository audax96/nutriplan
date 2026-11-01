<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * V2: where a finished grocery list goes. NOT wired into the v1 API or app.
 * Implementations: ManualListCart and CheckersSixty60Cart (needs a Shoprite partner integration).
 */
interface RetailerCart
{
    /**
     * @param array $lines the plan's shopping_list lines (ingredient, amount, unit, ...)
     * @return array ['status' => string, 'message' => string, ...]
     */
    public function addToBasket(array $lines): array;
}
