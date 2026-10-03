<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

final readonly class AddOn
{
    /**
     * Create a new AddOn instance.
     *
     * Something bought in units on top of a tier, such as extra seats or events.
     *
     * @param  string  $key  Stable identifier stored on billing items.
     * @param  string  $name  Shown on the billing page.
     * @param  ?int  $monthlyCentsPerUnit  Price per unit per month; null until it's priced, which keeps it off sale.
     * @param  string  $description  One line on the billing page explaining what a unit adds.
     * @param  array<string, int>  $grants  per unit bought: entitlement key => extra amount
     * @param  int  $maxQuantity  The most units one account may buy.
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $monthlyCentsPerUnit,
        public string $description,
        public array $grants,
        public int $maxQuantity = 100,
    ) {}
}
