<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\DnsRecordSet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DnsRecordSetTest extends TestCase
{
    use MonitoringHelpers;

    /**
     * @param  array<mixed>  $expected
     */
    #[DataProvider('validExpectations')]
    public function test_normalizes_record_sets_without_losing_txt_content(string $type, string $text, array $expected): void
    {
        $this->assertSame($expected, app(DnsRecordSet::class)->expected($type, $text));
    }

    /** @return array<string, list<mixed>> */
    public static function validExpectations(): array
    {
        return [
            'IPv4 duplicate and order' => ['A', "8.8.8.8\n1.1.1.1\n8.8.8.8", ['1.1.1.1', '8.8.8.8']],
            'canonical IPv6' => ['AAAA', '2606:4700:4700:0000:0000:0000:0000:1111', ['2606:4700:4700::1111']],
            'alias case and root dot' => ['CNAME', "CDN.Example.COM.\r\n", ['cdn.example.com']],
            'name server' => ['NS', 'NS1.Example.COM.', ['ns1.example.com']],
            'private-style name is record data, not a connection target' => ['CNAME', 'service.INTERNAL.', ['service.internal']],
            'MX priority' => ['MX', "010 MAIL.Example.com.\n20 backup.example.com", ['10 mail.example.com', '20 backup.example.com']],
            'null MX' => ['MX', '0 .', ['0 .']],
            'literal text' => ['TXT', " verification=AbC \nvalue=two", [' verification=AbC ', 'value=two']],
            'Unicode text' => ['TXT', 'description=café', ['description=café']],
        ];
    }

    #[DataProvider('invalidExpectations')]
    public function test_rejects_invalid_or_unbounded_expectations(string $type, string $text): void
    {
        $this->assertNull(app(DnsRecordSet::class)->expected($type, $text));
    }

    /** @return array<string, list<mixed>> */
    public static function invalidExpectations(): array
    {
        return [
            'unsupported type' => ['ANY', 'example.com'], 'empty' => ['TXT', ''],
            'too many' => ['A', implode("\n", array_fill(0, 21, '1.1.1.1'))],
            'too large' => ['TXT', str_repeat('x', 16385)],
            'TXT value too large' => ['TXT', str_repeat('x', 4097)],
            'TXT control' => ['TXT', "hidden\0text"], 'TXT binary' => ['TXT', "\xff"],
            'IPv6 not A' => ['A', '2606:4700::1111'], 'IPv4 not AAAA' => ['AAAA', '1.1.1.1'],
            'ambiguous IPv4' => ['A', '0177.0.0.1'], 'invalid IP' => ['A', '999.1.1.1'],
            'missing MX priority' => ['MX', 'mail.example.com'], 'excessive priority' => ['MX', '65536 mail.example.com'],
            'negative priority' => ['MX', '-1 mail.example.com'], 'invalid null MX' => ['MX', '10 .'],
            'alias URL' => ['CNAME', 'https://example.com'], 'non FQDN NS' => ['NS', 'localhost'],
        ];
    }

    #[DataProvider('hostnames')]
    public function test_validates_absolute_dns_names_and_service_labels(string $hostname, ?string $expected): void
    {
        $this->assertSame($expected, app(DnsRecordSet::class)->hostname($hostname));
    }

    /** @return array<string, list<mixed>> */
    public static function hostnames(): array
    {
        return [
            'root dot' => ['STATUS.Example.com.', 'status.example.com'],
            'domain key' => ['selector._domainkey.example.com', 'selector._domainkey.example.com'],
            'DMARC' => ['_dmarc.example.com', '_dmarc.example.com'],
            'single label' => ['localhost', null], 'internal suffix' => ['service.internal', null],
            'URL' => ['https://example.com', null], 'credentials' => ['user@example.com', null],
            'port' => ['example.com:443', null], 'spaces' => [' example.com', null],
            'label size' => [str_repeat('a', 64).'.com', null], 'empty label' => ['example..com', null],
            'multiple root dots' => ['example.com..', null], 'IP address' => ['127.0.0.1', null],
            'non ASCII' => ['éxample.com', null], 'wildcard' => ['*.example.com', null],
        ];
    }

    public function test_combines_txt_chunks_ignores_ttl_and_keeps_answer_types_separate(): void
    {
        $this->assertSame(['Verification=AbC'], app(DnsRecordSet::class)->observed('TXT', [
            ['type' => 'TXT', 'entries' => ['Verification=', 'AbC'], 'txt' => 'ignored duplicate', 'ttl' => 100],
            ['type' => 'CNAME', 'target' => 'elsewhere.example.com'],
        ]));
        $this->assertSame([], app(DnsRecordSet::class)->observed('A', []));
    }

    /**
     * @param  array<mixed>  $expected
     * @param  array<mixed>  $record
     */
    #[DataProvider('typedAnswers')]
    public function test_normalizes_record_type_specific_answers(string $type, array $record, ?array $expected): void
    {
        $this->assertSame($expected, app(DnsRecordSet::class)->observed($type, [$record]));
    }

    /** @return array<string, list<mixed>> */
    public static function typedAnswers(): array
    {
        return [
            'IPv4' => ['A', ['type' => 'A', 'ip' => '1.1.1.1'], ['1.1.1.1']],
            'IPv6' => ['AAAA', ['type' => 'AAAA', 'ipv6' => '2606:4700:4700:0:0:0:0:1111'], ['2606:4700:4700::1111']],
            'alias' => ['CNAME', ['type' => 'CNAME', 'target' => 'CDN.Example.com.'], ['cdn.example.com']],
            'name server' => ['NS', ['type' => 'NS', 'target' => 'NS1.Example.com.'], ['ns1.example.com']],
            'mail exchanger' => ['MX', ['type' => 'MX', 'pri' => 10, 'target' => 'MAIL.Example.com.'], ['10 mail.example.com']],
            'native null MX' => ['MX', ['type' => 'MX', 'pri' => 0, 'target' => ''], ['0 .']],
            'explicit root null MX' => ['MX', ['type' => 'MX', 'pri' => 0, 'target' => '.'], ['0 .']],
            'literal TXT' => ['TXT', ['type' => 'TXT', 'txt' => 'Verification=AbC '], ['Verification=AbC ']],
            'invalid null MX priority' => ['MX', ['type' => 'MX', 'pri' => 10, 'target' => ''], null],
            'missing MX target' => ['MX', ['type' => 'MX', 'pri' => 0], null],
            'malformed MX priority' => ['MX', ['type' => 'MX', 'pri' => [], 'target' => 'mail.example.com'], null],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    #[DataProvider('invalidAnswers')]
    public function test_invalid_and_oversized_answers_do_not_become_partial_successes(array $records): void
    {
        $this->assertNull(app(DnsRecordSet::class)->observed('TXT', $records));
    }

    /** @return array<string, list<mixed>> */
    public static function invalidAnswers(): array
    {
        return [
            'missing fields' => [['not an answer']],
            'invalid chunks' => [[['type' => 'TXT', 'entries' => ['a', ['nested']]]]],
            'missing value' => [[['type' => 'TXT']]],
            'oversized answer count' => [array_fill(0, 101, ['type' => 'TXT', 'txt' => 'a'])],
            'oversized payload' => [array_fill(0, 9, ['type' => 'TXT', 'txt' => str_repeat('x', 4000)])],
        ];
    }
}
