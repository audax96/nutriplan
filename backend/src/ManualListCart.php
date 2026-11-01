<?php
declare(strict_types=1);

namespace NutriPlan;

/** Default: no retailer automation. The app shows the list for the user to copy and shop. */
final class ManualListCart implements RetailerCart
{
    public function addToBasket(array $lines): array
    {
        return [
            'status'  => 'manual',
            'message' => 'Copy this list into the Checkers Sixty60 app. Automatic basket-filling is not enabled yet.',
        ];
    }
}
