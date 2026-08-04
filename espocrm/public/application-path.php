<?php

/**
 * Resolves the application mount point before the CRM container is booted.
 * This keeps the public landing page portable between a virtual-host root and
 * local subfolders such as http://localhost/espocrm_boye/.
 */
final class NexaApplicationPath
{
    public static function fromScriptName(string $scriptName): string
    {
        $scriptName = '/' . ltrim(str_replace('\\', '/', $scriptName), '/');
        $publicSuffix = '/public/index.php';

        if (preg_match('~^(.*?)/w/[a-z0-9](?:[a-z0-9-]{0,62})(?:/.*)?/?$~i', $scriptName, $matches) === 1) {
            $workspaceBase = '/' . trim($matches[1], '/.');

            return $workspaceBase === '/' ? '' : $workspaceBase;
        }
        if (str_ends_with($scriptName, $publicSuffix)) {
            $path = substr($scriptName, 0, -strlen($publicSuffix));
        } else {
            $path = str_replace('\\', '/', dirname($scriptName));
        }

        $path = '/' . trim($path, '/.');

        return $path === '/' ? '' : $path;
    }

    public static function isApplicationRoot(string $requestPath, string $basePath): bool
    {
        $requestPath = '/' . trim($requestPath, '/');
        $requestPath = $requestPath === '/' ? '' : $requestPath;

        return $requestPath === $basePath;
    }

    public static function isRoute(string $requestPath, string $basePath, string $route): bool
    {
        $routePath = ($basePath === '' ? '' : $basePath) . '/' . trim($route, '/');

        return rtrim('/' . trim($requestPath, '/'), '/') === $routePath;
    }

    /** @return null|array{slug: string, fragment: string} */
    public static function workspaceRoute(string $requestPath, string $basePath): ?array
    {
        $prefix = preg_quote(self::baseHref($basePath), '~');

        if (preg_match('~^' . $prefix . 'w/([a-z0-9](?:[a-z0-9-]{0,62}))(?:/(.*?))?/?$~i', $requestPath, $matches) !== 1) {
            return null;
        }

        return [
            'slug' => strtolower($matches[1]),
            'fragment' => rawurldecode(trim($matches[2] ?? '', '/')),
        ];
    }

    public static function baseHref(string $basePath): string
    {
        return ($basePath === '' ? '' : $basePath) . '/';
    }
}
