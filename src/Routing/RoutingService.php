<?php

declare(strict_types=1);

namespace YtdPhp\Routing;

use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Routing\RouteDecision;
use YtdPhp\Routing\RoutingConfig;
use YtdPhp\Routing\RoutingRule;
use YtdPhp\Routing\RoutingConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class RoutingService
{
    public const string ROUTE_DIRECT = 'direct';
    public const string ROUTE_LOCAL = 'local';
    public const string ROUTE_REMOTE = 'remote';
    public const string ROUTE_CUSTOM = 'custom';

    /** @var list<string> */
    private const array ROUTING_SECTIONS = [
        self::ROUTE_DIRECT,
        self::ROUTE_LOCAL,
        self::ROUTE_REMOTE,
    ];

    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function getVideoHostname(string $videoUrl): string
    {
        $parts = \parse_url($videoUrl);
        $hostname = $parts['host'] ?? null;
        if (\is_string($hostname) && $hostname !== '') {
            return \strtolower($hostname);
        }

        $parts = \parse_url('//' . $videoUrl);
        $hostname = $parts['host'] ?? null;

        return \is_string($hostname) ? \strtolower($hostname) : '';
    }

    public function loadRoutingConfig(?string $path = null): RoutingConfig
    {
        $path ??= $this->bootstrap->getProxyRulesPath();
        if (!is_file($path)) {
            throw new RoutingConfigException('Файл правил маршрутизации не найден: ' . $path);
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $error) {
            throw new RoutingConfigException('Не удалось разобрать YAML: ' . $error->getMessage(), 0, $error);
        }

        $routingRaw = $parsed['routing'] ?? null;
        if (!\is_array($routingRaw)) {
            throw new RoutingConfigException("В конфиге должен быть раздел 'routing'.");
        }

        $unknownSections = \array_diff(array_keys($routingRaw), self::ROUTING_SECTIONS);
        if ($unknownSections !== []) {
            throw new RoutingConfigException('Неизвестные разделы маршрутизации: ' . implode(', ', $unknownSections));
        }

        $routing = [];
        $priorityRules = [];
        $regularRules = [];
        foreach (self::ROUTING_SECTIONS as $section) {
            $patterns = $routingRaw[$section] ?? [];
            if (!\is_array($patterns)) {
                throw new RoutingConfigException(sprintf('Раздел routing.%s должен быть списком строк.', $section));
            }

            $routing[$section] = [];
            foreach ($patterns as $pattern) {
                if (!\is_string($pattern)) {
                    throw new RoutingConfigException(sprintf('Раздел routing.%s должен содержать только строки.', $section));
                }

                $strippedPattern = \trim($pattern);
                $normalizedPattern = $this->validatePattern($pattern, $path);
                $routing[$section][] = $normalizedPattern;
                $rule = new RoutingRule(
                    $section,
                    $normalizedPattern,
                    str_ends_with($strippedPattern, '!'),
                );
                if ($rule->priority) {
                    $priorityRules[] = $rule;
                } else {
                    $regularRules[] = $rule;
                }
            }
        }

        return new RoutingConfig($path, $routing, [...$priorityRules, ...$regularRules]);
    }

    public function matchHostPattern(string $hostname, string $pattern): bool
    {
        $normalizedHost = \strtolower(\trim($hostname));
        $normalizedPattern = \strtolower(\trim($pattern));
        if ($normalizedHost === '') {
            return false;
        }

        if ($normalizedPattern === '*') {
            return true;
        }

        if (str_starts_with($normalizedPattern, '*.')) {
            $rootDomain = substr($normalizedPattern, 2);
            if (!\str_contains($rootDomain, '.')) {
                return str_ends_with($normalizedHost, '.' . $rootDomain);
            }

            return $normalizedHost === $rootDomain || str_ends_with($normalizedHost, '.' . $rootDomain);
        }

        return $normalizedHost === $normalizedPattern;
    }

    public function resolveRoute(string $videoUrl, ?string $explicitProxy, bool $noProxy, bool $remote): RouteDecision
    {
        $hostname = $this->getVideoHostname($videoUrl);
        if ($hostname === '') {
            throw new RoutingConfigException('Не удалось определить домен загрузки из URL: ' . $videoUrl);
        }

        if (\is_string($explicitProxy) && $explicitProxy !== '') {
            return new RouteDecision(
                self::ROUTE_CUSTOM,
                $explicitProxy,
                'cli',
                '--proxy',
                $hostname,
            );
        }

        if ($noProxy) {
            return new RouteDecision(
                self::ROUTE_DIRECT,
                null,
                'cli',
                '--no-proxy',
                $hostname,
            );
        }

        if ($remote) {
            return new RouteDecision(
                self::ROUTE_REMOTE,
                $this->resolveProxyUrl(self::ROUTE_REMOTE),
                'cli',
                '--remote',
                $hostname,
            );
        }

        $config = $this->loadRoutingConfig();
        foreach ($config->orderedRules as $rule) {
            if ($this->matchHostPattern($hostname, $rule->pattern)) {
                return new RouteDecision(
                    $rule->section,
                    $this->resolveProxyUrl($rule->section),
                    $rule->section,
                    $rule->priority ? $rule->pattern . '!' : $rule->pattern,
                    $hostname,
                );
            }
        }

        throw new RoutingConfigException(
            sprintf(
                "Для домена '%s' не найден маршрут в %s. Добавь шаблон в routing.direct, routing.local или routing.remote.",
                $hostname,
                $config->path,
            ),
        );
    }

    private function validatePattern(string $pattern, string $path): string
    {
        $normalized = \strtolower(\trim($pattern));
        if (str_ends_with($normalized, '!')) {
            $normalized = \trim(substr($normalized, 0, -1));
        }

        if ($normalized === '') {
            throw new RoutingConfigException('Пустой шаблон в ' . $path);
        }

        if ($normalized === '*') {
            return $normalized;
        }

        if (str_starts_with($normalized, '*.')) {
            if (\str_contains(substr($normalized, 1), '*')) {
                throw new RoutingConfigException(sprintf("Поддерживаются только точные хосты, шаблоны '*.' и глобальный '*' (%s)", $pattern));
            }

            return $normalized;
        }

        if (\str_contains($normalized, '*')) {
            throw new RoutingConfigException(sprintf("Поддерживаются только точные хосты, шаблоны '*.' и глобальный '*' (%s)", $pattern));
        }

        return $normalized;
    }

    private function resolveProxyUrl(string $mode): ?string
    {
        return match ($mode) {
            self::ROUTE_DIRECT => null,
            self::ROUTE_LOCAL => $this->requiredEnv('PROXY_LOCAL'),
            self::ROUTE_REMOTE => $this->requiredEnv('PROXY_REMOTE'),
            default => throw new RoutingConfigException('Неизвестный режим маршрутизации: ' . $mode),
        };
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || $value === '') {
            throw new RoutingConfigException(sprintf('Маршрут требует %s, но он не задан.', $name));
        }

        return $value;
    }
}
