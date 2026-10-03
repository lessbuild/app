<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Exceptions\AccountRuleViolation;
use App\Models\AnalyticsSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class ImportAdSpend
{
    /**
     * The most rows one file can hold.
     *
     * @var int
     */
    public const MAX_ROWS = 20000;

    /**
     * The header names each column may have in ad platforms' exports, lower case.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'date' => ['date', 'day', 'reporting starts', 'reporting start', 'date start'],
        'campaign' => ['campaign', 'campaign name', 'utm_campaign'],
        'source' => ['source', 'utm_source', 'platform'],
        'cost' => ['cost', 'spend', 'amount spent', 'amount', 'amount spent (usd)', 'amount spent (eur)', 'amount spent (gbp)'],
        'currency' => ['currency', 'currency code'],
        'clicks' => ['clicks', 'link clicks', 'clicks (all)'],
        'impressions' => ['impressions', 'impr.'],
    ];

    /**
     * Import a CSV of ad spend (date, campaign and cost, with optional source, currency, clicks and impressions), as
     * exported from an ad platform. Rows without a source use the one chosen; rows without a currency use the one
     * chosen. A day and campaign already imported is replaced. Returns how many rows were imported.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $csv  the file's contents
     * @param  string  $source  utm_source for rows without one, such as google
     * @param  string  $currency  ISO code for rows without one
     * @return int
     */
    public function handle(User $actor, AnalyticsSite $site, string $csv, string $source, string $currency): int
    {
        Gate::forUser($actor)->authorize('update', $site);
        $lines = preg_split('/\r\n|\r|\n/', ltrim($csv, "\u{FEFF}")) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
        if (count($lines) < 2) {
            throw new AccountRuleViolation('file', __('The file needs a header row and at least one row of spend.'));
        }
        if (count($lines) > self::MAX_ROWS + 1) {
            throw new AccountRuleViolation('file', __('Import up to :count rows at a time.', ['count' => number_format(self::MAX_ROWS)]));
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $header = array_map(fn (?string $name): string => mb_strtolower(trim((string) $name)), str_getcsv($lines[0], $delimiter, '"', ''));
        $columns = [];
        foreach (self::COLUMNS as $key => $names) {
            foreach ($header as $index => $name) {
                if (in_array($name, $names, true)) {
                    $columns[$key] = $index;
                    break;
                }
            }
        }
        foreach (['date', 'campaign', 'cost'] as $required) {
            if (! isset($columns[$required])) {
                throw new AccountRuleViolation('file', __('The file needs date, campaign and cost columns (it has: :columns).', ['columns' => implode(', ', array_filter($header)) ?: __('none')]));
            }
        }

        $now = now();
        $rows = [];
        foreach (array_slice($lines, 1) as $number => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $cell = fn (string $key): string => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
            $campaign = mb_substr($cell('campaign'), 0, 150);
            $cost = $this->number($cell('cost'));
            try {
                $date = CarbonImmutable::parse($cell('date'))->toDateString();
            } catch (Throwable) {
                $date = null;
            }
            if ($campaign === '' || $cost === null || $date === null) {
                throw new AccountRuleViolation('file', __('Row :row needs a date, a campaign and a cost.', ['row' => $number + 2]));
            }
            $rowSource = mb_strtolower(mb_substr($cell('source') !== '' ? $cell('source') : $source, 0, 100));
            $rowCurrency = strtoupper($cell('currency') !== '' ? $cell('currency') : $currency);
            if (preg_match('/^[A-Z]{3}$/', $rowCurrency) !== 1) {
                throw new AccountRuleViolation('file', __('Row :row has a currency that isn’t a three-letter code.', ['row' => $number + 2]));
            }
            $clicks = $this->number($cell('clicks'));
            $impressions = $this->number($cell('impressions'));
            // The same day and campaign twice in one file (such as one row per ad) adds up.
            $key = $date.'|'.$rowSource.'|'.$campaign;
            $previous = $rows[$key] ?? ['cost_cents' => 0, 'clicks' => null, 'impressions' => null];
            $rows[$key] = [
                'site_id' => $site->id, 'date' => $date, 'source' => $rowSource, 'campaign' => $campaign, 'currency' => $rowCurrency,
                'cost_cents' => $previous['cost_cents'] + (int) round($cost * 100),
                'clicks' => $clicks === null ? $previous['clicks'] : (int) ($previous['clicks'] ?? 0) + (int) $clicks,
                'impressions' => $impressions === null ? $previous['impressions'] : (int) ($previous['impressions'] ?? 0) + (int) $impressions,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::transaction(function () use ($rows): void {
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('analytics_ad_spend')->upsert($chunk, ['site_id', 'date', 'source', 'campaign'], ['cost_cents', 'currency', 'clicks', 'impressions', 'updated_at']);
            }
        });

        return count($rows);
    }

    /**
     * Read a number as ad platforms write it ("1,234.50", "1.234,50", "$12", "12 €"), or null when there isn't one.
     *
     * @param  string  $value
     * @return float|null
     */
    private function number(string $value): ?float
    {
        $value = (string) preg_replace('/[^0-9.,\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && ($dot === false || $comma > $dot) && strlen($value) - $comma - 1 !== 3) {
            // A decimal comma: 1.234,50 or 12,5.
            $value = str_replace(['.', ','], ['', '.'], $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }
}
