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
 * Enforces the code rules in AGENTS.md by reading source files, so the
 * rules hold from the first commit without depending on runtime wiring.
 */
final class ArchitectureTest extends TestCase
{
    /**
     * Areas of app/ written before every member had to be documented. The backfill removes each one as it lands;
     * once the list is empty, this constant goes too.
     */
    private const AREAS_AWAITING_DOCUMENTATION = [
        'Actions', 'Data', 'Enums', 'Events', 'Exceptions', 'Http', 'Jobs', 'Listeners', 'Models',
        'Notifications', 'Platform', 'Policies', 'Queries', 'Services', 'Support',
    ];

    public function test_business_code_does_not_depend_on_the_http_layer(): void
    {
        $files = [];
        foreach (['app/Actions', 'app/Queries', 'app/Models', 'app/Data', 'app/Events', 'app/Policies', 'app/Enums'] as $directory) {
            $files = [...$files, ...$this->phpFiles($directory)];
        }

        $this->assertNoImports($files, [
            'App\\Http\\',
            'Illuminate\\Http\\Request',
            'Livewire\\',
        ]);
    }

    /** Authorisation lives in policies: routes declare abilities (`can`, `account.can`) and actions check the record's policy. */
    public function test_controllers_leave_authorisation_to_policies(): void
    {
        $this->assertNoImports($this->phpFiles('app/Http/Controllers'), ['Illuminate\\Support\\Facades\\Gate']);
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

    /** @param class-string $class */
    #[DataProvider('controllerClasses')]
    public function test_controllers_handle_exactly_one_route(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $public = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->class === $class && ! $method->isConstructor(),
        ));

        $this->assertTrue($reflection->isFinal(), "{$class} must be final.");
        $this->assertSame(['__invoke'], array_map(fn (ReflectionMethod $method): string => $method->name, $public), "{$class} must be a single-action controller with only __invoke().");
    }

    /**
     * Every method and property in one top-level area of app/ carries a docblock that explains it, and
     * promoted constructor properties are described by `@param` lines on the constructor. A docblock made
     * only of tags (`@return list<string>`) doesn't count: it types the member without saying what it's for.
     */
    #[DataProvider('applicationAreas')]
    public function test_every_method_and_property_is_documented(string $area): void
    {
        if (in_array($area, self::AREAS_AWAITING_DOCUMENTATION, true)) {
            $this->markTestIncomplete("app/{$area} is still being documented.");
        }

        $missing = [];
        foreach ($this->phpFiles('app/'.$area) as $file) {
            $name = 'App\\'.str_replace('/', '\\', substr($this->relative($file), 4, -4));
            if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name)) {
                continue;
            }
            $reflection = new ReflectionClass($name);

            foreach ($reflection->getProperties() as $property) {
                if ($property->class === $name && ! $property->isPromoted() && $property->getDeclaringClass()->getFileName() === $file
                    && ! $this->explains($property->getDocComment())) {
                    $missing[] = "{$name}::\${$property->name}";
                }
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->class !== $name || $method->getFileName() !== $file) {
                    continue;
                }
                $docblock = $method->getDocComment();
                if (! $this->explains($docblock)) {
                    $missing[] = "{$name}::{$method->name}()";
                }
                foreach ($method->isConstructor() ? $method->getParameters() : [] as $parameter) {
                    if ($parameter->isPromoted() && ($docblock === false || preg_match('/@param\s+.*?\$'.$parameter->name.'\s+\S/', $docblock) !== 1)) {
                        $missing[] = "{$name}::__construct(\${$parameter->name}) (promoted, needs an @param description)";
                    }
                }
            }
        }

        $this->assertSame([], $missing, "These members in app/{$area} need a docblock that explains them:\n".implode("\n", $missing));
    }

    /** @return iterable<string, array{class-string}> */
    public static function dataClasses(): iterable
    {
        yield from self::classesIn('Data');
    }

    /** @return iterable<string, array{class-string}> */
    public static function actionClasses(): iterable
    {
        // Fortify's adapters implement its contracts (create, update, reset) instead of handle().
        yield from self::classesIn('Actions', except: 'Actions/Fortify/');
    }

    /** @return iterable<string, array{class-string}> */
    public static function controllerClasses(): iterable
    {
        yield from self::classesIn('Http/Controllers');
    }

    /**
     * Each directory directly under app/ (and the loose files at its root, as "."), so a failure names the
     * area that needs attention instead of one list for the whole application.
     *
     * @return iterable<string, array{string}>
     */
    public static function applicationAreas(): iterable
    {
        foreach (glob(dirname(__DIR__, 2).'/app/*', GLOB_ONLYDIR) ?: [] as $directory) {
            yield basename($directory) => [basename($directory)];
        }
    }

    /** @return iterable<string, array{class-string}> */
    private static function classesIn(string $directory, ?string $except = null): iterable
    {
        $root = dirname(__DIR__, 2).'/app/';
        $found = false;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root));
            if ($file->getExtension() !== 'php' || ($except !== null && str_starts_with($relative, $except))) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
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

    /**
     * Whether a docblock says something in prose: at least one line that isn't a tag or blank.
     */
    private function explains(string|false $docblock): bool
    {
        if ($docblock === false) {
            return false;
        }
        foreach (preg_split('/\R/', $docblock) ?: [] as $line) {
            $text = trim((string) preg_replace('#^\s*/?\*+/?|\*/\s*$#', '', $line));
            if ($text !== '' && ! str_starts_with($text, '@')) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $file): string
    {
        return ltrim(substr($file, strlen(dirname(__DIR__, 2))), '/');
    }
}
