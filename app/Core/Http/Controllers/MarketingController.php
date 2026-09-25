<?php

namespace App\Core\Http\Controllers;

use Illuminate\Contracts\View\View;

final class MarketingController
{
    public function home(): View
    {
        return view('core::marketing.home', [
            'products' => config('marketing.products', []),
            'workspaceCapabilities' => config('marketing.workspace_capabilities', []),
            'connections' => config('marketing.connections', []),
        ]);
    }

    public function showProduct(string $product): View
    {
        $products = config('marketing.products', []);

        abort_unless(array_key_exists($product, $products), 404);

        return view('core::marketing.product', [
            'productKey' => $product,
            'product' => $products[$product],
            'products' => $products,
            'connections' => collect(config('marketing.connections', []))
                ->filter(fn (array $connection): bool => in_array($product, [$connection['source'], $connection['target']], true))
                ->values()
                ->all(),
        ]);
    }
}
