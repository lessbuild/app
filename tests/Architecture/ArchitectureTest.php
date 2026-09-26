<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Enforces the layering rules in CLAUDE.md by reading source files, so the
 * rules hold from the first commit without depending on runtime wiring.
 */
final class ArchitectureTest extends TestCase
{
    public function test_domain_code_does_not_depend_on_the_http_layer(): void
    {
        $this->assertNoImports($this->phpFiles('app/Domain'), [
            'App\\Http\\',
            'Illuminate\\Http\\Request',
            'Livewire\\',
        ]);
    }

    public function test_the_application_uses_a_single_database_connection(): void
    {
        foreach ($this->phpFiles('app') as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(DB::connection|Schema::connection|->setConnection)\(/',
                (string) file_get_contents($file),
                "{$file} selects a named connection; platform-v2 uses one database.",
            );
        }
    }

    public function test_external_system_clients_are_only_used_behind_service_interfaces(): void
    {
        $outsideServices = array_values(array_filter(
            $this->phpFiles('app'),
            fn (string $file): bool => ! str_starts_with($this->relative($file), 'app/Services/'),
        ));

        $this->assertNoImports($outsideServices, ['Stripe\\', 'phpseclib3\\', 'Spatie\\Ssh\\', 'GuzzleHttp\\']);
    }

    /** @param class-string $class */
    #[DataProvider('dataClasses')]
    public function test_data_objects_are_final_and_readonly(string $class): void
    {
        if ($class === self::class) {
            $this->markTestSkipped('No classes in this layer yet.');
        }

        $reflection = new ReflectionClass($class);

        $this->assertTrue($reflection->isFinal() && $reflection->isReadOnly(), "{$class} must be declared final readonly.");
    }

    /** @param class-string $class */
    #[DataProvider('actionClasses')]
    public function test_actions_expose_a_single_handle_method(string $class): void
    {
        if ($class === self::class) {
            $this->markTestSkipped('No classes in this layer yet.');
        }

        $public = array_values(array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->class === $class && ! $method->isConstructor(),
        ));

        $this->assertSame(['handle'], array_map(fn (ReflectionMethod $method): string => $method->name, $public), "{$class} must have exactly one public method, handle().");
    }

    /** @return iterable<string, array{class-string}> */
    public static function dataClasses(): iterable
    {
        yield from self::classesIn('Data');
    }

    /** @return iterable<string, array{class-string}> */
    public static function actionClasses(): iterable
    {
        yield from self::classesIn('Actions');
    }

    /** @return iterable<string, array{class-string}> */
    private static function classesIn(string $layer): iterable
    {
        $found = false;
        foreach (glob(dirname(__DIR__, 2)."/app/Domain/*/{$layer}/*.php") ?: [] as $file) {
            $class = 'App\\Domain\\'.basename(dirname($file, 2))."\\{$layer}\\".basename($file, '.php');
            if (class_exists($class)) {
                $found = true;
                yield $class => [$class];
            }
        }
        if (! $found) {
            // Keep the data provider non-empty before the first class of this layer exists.
            yield 'none yet' => [self::class];
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $forbiddenPrefixes
     */
    private function assertNoImports(array $files, array $forbiddenPrefixes): void
    {
        foreach ($files as $file) {
            foreach ($this->imports($file) as $import) {
                foreach ($forbiddenPrefixes as $prefix) {
                    $this->assertFalse(str_starts_with($import, $prefix), "{$this->relative($file)} imports {$import}.");
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    /** @return list<string> */
    private function imports(string $file): array
    {
        preg_match_all('/^use\s+([^;\s]+)/m', (string) file_get_contents($file), $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $path = dirname(__DIR__, 2).'/'.$directory;
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relative(string $file): string
    {
        return ltrim(substr($file, strlen(dirname(__DIR__, 2))), '/');
    }
}
