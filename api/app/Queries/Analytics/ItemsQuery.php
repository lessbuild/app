<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Illuminate\Support\Facades\DB;

/** E-commerce item reports: what was bought, how many, and for how much, from events that list their items. */
final class ItemsQuery
{
    /**
     * Get the 25 items with the most revenue in the period: each item's name (or id), category, units sold, orders it
     * appeared in and revenue (price × quantity), per currency.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @return list<array{item: string, category: string|null, quantity: int, orders: int, revenue: float, currency: string}>
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period): array
    {
        $ids = AnalyticsEvent::query()->where('site_id', $site->id)->countable()->where('type', 'event')
            ->whereBetween('occurred_at', [$period->start->utc(), $period->end->utc()])->select('id');
        $pgsql = DB::getDriverName() === 'pgsql';
        $field = $pgsql
            ? ['name' => "item->>'name'", 'id' => "item->>'id'", 'category' => "item->>'category'"]
            : ['name' => "json_extract(item.value, '$.name')", 'id' => "json_extract(item.value, '$.id')", 'category' => "json_extract(item.value, '$.category')"];
        $number = $pgsql
            ? ['quantity' => "CAST(item->>'quantity' AS NUMERIC)", 'price' => "CAST(item->>'price' AS NUMERIC)"]
            : ['quantity' => "json_extract(item.value, '$.quantity')", 'price' => "json_extract(item.value, '$.price')"];
        $currency = $pgsql ? "COALESCE(e.properties->>'currency', 'USD')" : "COALESCE(json_extract(e.properties, '$.currency'), 'USD')";
        $rows = DB::table('analytics_events as e')
            ->crossJoin(DB::raw($pgsql ? "LATERAL json_array_elements(e.properties->'items') AS item" : "json_each(e.properties, '$.items') AS item"))
            ->whereIn('e.id', $ids)
            ->selectRaw("COALESCE({$field['name']}, {$field['id']}) AS item_label, {$field['category']} AS category, {$currency} AS currency")
            ->selectRaw("SUM(COALESCE({$number['quantity']}, 1)) AS quantity, COUNT(DISTINCT e.id) AS orders")
            ->selectRaw("SUM(COALESCE({$number['price']}, 0) * COALESCE({$number['quantity']}, 1)) AS revenue")
            ->groupBy('item_label', 'category', 'currency')
            ->orderByDesc('revenue')->orderByDesc('quantity')->orderBy('item_label')
            ->limit(25)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'item' => (string) $row->item_label,
                'category' => $row->category === null ? null : (string) $row->category,
                'quantity' => (int) $row->quantity,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'currency' => (string) $row->currency,
            ];
        }

        return $items;
    }
}
