<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArchitectureNamespacesTest extends TestCase
{
    /** @var list<string> */
    private const array LEGACY_BUCKETS = ['Bootstrap', 'Dto', 'Exception', 'Service'];

    /** @var list<string> */
    private const array CONTEXT_DIRECTORIES = [
        'Command',
        'Diagnostics',
        'Download',
        'NativeHost',
        'Playlist',
        'Routing',
        'Runtime',
        'Shared',
    ];

    /** @var array<string, list<string>> */
    private const array FORBIDDEN_CONTEXT_DEPENDENCIES = [
        'Shared' => ['Command', 'Diagnostics', 'Download', 'NativeHost', 'Playlist', 'Routing', 'Runtime'],
        'Runtime' => ['Command', 'Diagnostics', 'Download', 'NativeHost', 'Playlist', 'Routing', 'Shared'],
        'Routing' => ['Command', 'Diagnostics', 'Download', 'NativeHost', 'Playlist'],
        'Download' => ['Command', 'Diagnostics', 'NativeHost', 'Playlist', 'Routing'],
        'Playlist' => ['Command', 'Diagnostics', 'NativeHost', 'Routing'],
        'NativeHost' => ['Command', 'Diagnostics', 'Download', 'Playlist', 'Routing'],
        'Diagnostics' => ['Command', 'Download', 'NativeHost', 'Playlist'],
    ];

    public function testSourceFilesDeclareNamespaceMatchingTheirContextDirectory(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $relativePath = $this->relativePath($file->getPathname(), $this->srcRoot());
            $namespace = $this->declaredNamespace($file->getPathname());

            self::assertNotNull($namespace, $relativePath . ' must declare a namespace.');

            $pathParts = \explode(DIRECTORY_SEPARATOR, $relativePath);
            $context = $pathParts[0];
            if (\count($pathParts) === 1) {
                self::assertSame('YtdPhp', $namespace, $relativePath . ' must stay in the root application namespace.');

                continue;
            }

            self::assertContains($context, self::CONTEXT_DIRECTORIES, $relativePath . ' lives in an unknown context directory.');
            self::assertStringStartsWith('YtdPhp\\' . $context, $namespace, $relativePath . ' must use its context namespace.');
        }
    }

    public function testLegacyGenericBucketsAreNotUsedAnymore(): void
    {
        foreach (self::LEGACY_BUCKETS as $bucket) {
            self::assertDirectoryDoesNotExist($this->srcRoot() . DIRECTORY_SEPARATOR . $bucket);
        }

        foreach ($this->projectPhpFiles() as $file) {
            $contents = \file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach (self::LEGACY_BUCKETS as $bucket) {
                $legacyNamespace = 'YtdPhp\\' . $bucket;
                self::assertStringNotContainsString('namespace ' . $legacyNamespace, $contents, $file->getPathname());
                self::assertStringNotContainsString('use ' . $legacyNamespace . '\\', $contents, $file->getPathname());
            }
        }
    }

    public function testDomainDependenciesPointInAllowedDirections(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $relativePath = $this->relativePath($file->getPathname(), $this->srcRoot());
            $context = \explode(DIRECTORY_SEPARATOR, $relativePath)[0];
            if (!isset(self::FORBIDDEN_CONTEXT_DEPENDENCIES[$context])) {
                continue;
            }

            $dependencies = $this->internalContextDependencies($file->getPathname());
            foreach (self::FORBIDDEN_CONTEXT_DEPENDENCIES[$context] as $forbiddenContext) {
                self::assertNotContains(
                    $forbiddenContext,
                    $dependencies,
                    \sprintf('%s must not depend on YtdPhp\\%s.', $relativePath, $forbiddenContext),
                );
            }
        }
    }

    /**
     * @return list<SplFileInfo>
     */
    private function sourceFiles(): array
    {
        return $this->phpFilesInDirectory($this->srcRoot());
    }

    /**
     * @return list<SplFileInfo>
     */
    private function projectPhpFiles(): array
    {
        $files = $this->sourceFiles();
        $files = \array_merge($files, $this->phpFilesInDirectory($this->projectRoot() . '/tests'));

        foreach ($this->regularFilesInDirectory($this->projectRoot() . '/bin') as $file) {
            $files[] = $file;
        }

        foreach ([$this->projectRoot() . '/ytd.php'] as $path) {
            if (\is_file($path)) {
                $files[] = new SplFileInfo($path);
            }
        }

        return $files;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesInDirectory(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files[] = $file;
        }

        \usort(
            $files,
            static fn(SplFileInfo $left, SplFileInfo $right): int => $left->getPathname() <=> $right->getPathname(),
        );

        return $files;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function regularFilesInDirectory(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            $files[] = $file;
        }

        \usort(
            $files,
            static fn(SplFileInfo $left, SplFileInfo $right): int => $left->getPathname() <=> $right->getPathname(),
        );

        return $files;
    }

    private function declaredNamespace(string $path): ?string
    {
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        if (\preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) !== 1) {
            return null;
        }

        return \trim($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function internalContextDependencies(string $path): array
    {
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        \preg_match_all('/(?:use\s+|\\\\)YtdPhp\\\\([A-Za-z]+)\\\\/', $contents, $matches);
        if ($matches[1] === []) {
            return [];
        }

        return \array_values(\array_unique($matches[1]));
    }

    private function relativePath(string $path, string $root): string
    {
        return \ltrim(\substr($path, \strlen($root)), DIRECTORY_SEPARATOR);
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function srcRoot(): string
    {
        return $this->projectRoot() . '/src';
    }
}
