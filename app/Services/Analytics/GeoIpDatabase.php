<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use RuntimeException;
use Throwable;

/**
 * Downloads DB-IP's free country database (published monthly, licensed CC BY 4.0) and swaps it in only once it
 * opens and answers a lookup, so a bad download never replaces a good database.
 */
final class GeoIpDatabase
{
    /**
     * Download this month's database (or last month's, early in the month) and put it in place.
     *
     * @return string the month installed, as Y-m
     *
     * @throws RuntimeException when no usable database could be downloaded
     */
    public function update(): string
    {
        $target = (string) config('analytics.geoip_database');
        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('Could not create '.dirname($target).'.');
        }
        $failures = [];
        foreach ([CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->subMonthNoOverflow()] as $month) {
            try {
                $this->install($month->format('Y-m'), $target);

                return $month->format('Y-m');
            } catch (Throwable $exception) {
                $failures[] = $month->format('Y-m').': '.$exception->getMessage();
            }
        }

        throw new RuntimeException('No country database could be installed ('.implode('; ', $failures).').');
    }

    /**
     * Download one month's database, check it, and move it into place.
     *
     * @param  string  $month  Y-m
     * @param  string  $target
     * @return void
     */
    private function install(string $month, string $target): void
    {
        $url = str_replace('{month}', $month, (string) config('analytics.geoip_url'));
        $compressed = $target.'.download.gz';
        $unpacked = $target.'.download';
        try {
            Http::timeout(120)->sink($compressed)->get($url)->throw();
            $this->gunzip($compressed, $unpacked);
            $reader = new Reader($unpacked);
            $reader->get('8.8.8.8');
            $reader->close();
            if (! rename($unpacked, $target)) {
                throw new RuntimeException('Could not move the database into place.');
            }
        } finally {
            @unlink($compressed);
            @unlink($unpacked);
        }
    }

    /**
     * Unpack a gzip file.
     *
     * @param  string  $from
     * @param  string  $to
     * @return void
     */
    private function gunzip(string $from, string $to): void
    {
        $in = @gzopen($from, 'rb');
        $out = @fopen($to, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Could not unpack the download.');
        }
        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1 << 20);
                if ($chunk === false) {
                    throw new RuntimeException('The download is not a valid gzip file.');
                }
                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }
}
