<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\ImportMap\Resolver;

use Symfony\Component\AssetMapper\Exception\RuntimeException;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\PackageRequireOptions;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class JsDelivrEsmResolver implements PackageResolverInterface
{
    public const URL_PATTERN_VERSION = 'https://data.jsdelivr.com/v1/packages/npm/%s/resolved';
    public const URL_PATTERN_DIST_CSS = 'https://cdn.jsdelivr.net/npm/%s@%s%s';
    public const URL_PATTERN_DIST = self::URL_PATTERN_DIST_CSS.'/+esm';
    public const URL_PATTERN_ENTRYPOINT = 'https://data.jsdelivr.com/v1/packages/npm/%s@%s/entrypoints';

    public const IMPORT_REGEX = '#(?:import\s*(?:(?:\{[^}]*\}|\w+|\*\s*as\s+\w+)\s*\bfrom\s*)?|export\s*(?:\{[^}]*\}|\*)\s*from\s*)("/npm/((?:@[^/]+/)?[^@]+?)(?:@([^/]+))?((?:/[^/]+)*?)/\+esm")#';

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function resolvePackages(array $packagesToRequire): array
    {
        $resolvedPackages = [];

        resolve_packages:

        // request the version of each package
        $requiredPackages = [];
        foreach ($packagesToRequire as $options) {
            $packageSpecifier = trim($options->packageModuleSpecifier, '/');

            // avoid resolving the same package twice
            if (isset($resolvedPackages[$packageSpecifier])) {
                continue;
            }

            [$packageName, $filePath] = ImportMapEntry::splitPackageNameAndFilePath($packageSpecifier);

            $versionUrl = sprintf(self::URL_PATTERN_VERSION, $packageName);
            if (null !== $options->versionConstraint) {
                $versionUrl .= '?specifier='.urlencode($options->versionConstraint);
            }
            $response = $this->httpClient->request('GET', $versionUrl);
            $requiredPackages[] = [$options, $response, $packageName, $filePath, /* resolved version */ null];
        }

        // use the version of each package to request the contents
        $findVersionErrors = [];
        $entrypointResponses = [];
        foreach ($requiredPackages as $i => [$options, $response, $packageName, $filePath]) {
            if (200 !== $response->getStatusCode()) {
                $findVersionErrors[] = [$packageName, $response];
                continue;
            }

            $version = $response->toArray()['version'];
            if (null === $version) {
                throw new RuntimeException(sprintf('Unable to find the latest version for package "%s" - try specifying the version manually.', $packageName));
            }

            $pattern = str_ends_with($filePath, '.css') ? self::URL_PATTERN_DIST_CSS : self::URL_PATTERN_DIST;
            $requiredPackages[$i][1] = $this->httpClient->request('GET', sprintf($pattern, $packageName, $version, $filePath));
            $requiredPackages[$i][4] = $version;

            if (!$filePath) {
                $entrypointResponses[$packageName] = [$this->httpClient->request('GET', sprintf(self::URL_PATTERN_ENTRYPOINT, $packageName, $version)), $version];
            }
        }

        try {
            ($findVersionErrors[0][1] ?? null)?->getHeaders();
        } catch (HttpExceptionInterface $e) {
            $response = $e->getResponse();
            $packages = implode('", "', array_column($findVersionErrors, 0));

            throw new RuntimeException(sprintf('Error %d finding version from jsDelivr for the following packages: "%s". Check your package names. Response: ', $response->getStatusCode(), $packages).$response->getContent(false), 0, $e);
        }

        // process the contents of each package & add the resolved package
        $packagesToRequire = [];
        $getContentErrors = [];
        foreach ($requiredPackages as [$options, $response, $packageName, $filePath, $version]) {
            if (200 !== $response->getStatusCode()) {
                $getContentErrors[] = [$options->packageModuleSpecifier, $response];
                continue;
            }

            $contentType = $response->getHeaders()['content-type'][0] ?? '';
            $type = str_starts_with($contentType, 'text/css') ? ImportMapType::CSS : ImportMapType::JS;
            $resolvedPackages[$options->packageModuleSpecifier] = new ResolvedImportMapPackage($options, $version, $type);

            $packagesToRequire = array_merge($packagesToRequire, $this->fetchPackageRequirementsFromImports($response->getContent()));
        }

        try {
            ($getContentErrors[0][1] ?? null)?->getHeaders();
        } catch (HttpExceptionInterface $e) {
            $response = $e->getResponse();
            $packages = implode('", "', array_column($getContentErrors, 0));

            throw new RuntimeException(sprintf('Error %d requiring packages from jsDelivr for "%s". Check your package names. Response: ', $response->getStatusCode(), $packages).$response->getContent(false), 0, $e);
        }

        // process any pending CSS entrypoints
        $entrypointErrors = [];
        foreach ($entrypointResponses as $package => [$cssEntrypointResponse, $version]) {
            if (200 !== $cssEntrypointResponse->getStatusCode()) {
                $entrypointErrors[] = [$package, $cssEntrypointResponse];
                continue;
            }

            $entrypoints = $cssEntrypointResponse->toArray()['entrypoints'] ?? [];
            $cssFile = $entrypoints['css']['file'] ?? null;
            $guessed = $entrypoints['css']['guessed'] ?? true;

            if (!$cssFile || $guessed) {
                continue;
            }

            $packagesToRequire[] = new PackageRequireOptions($package.$cssFile, $version);
        }

        try {
            ($entrypointErrors[0][1] ?? null)?->getHeaders();
        } catch (HttpExceptionInterface $e) {
            $response = $e->getResponse();
            $packages = implode('", "', array_column($entrypointErrors, 0));

            throw new RuntimeException(sprintf('Error %d checking for a CSS entrypoint for "%s". Response: ', $response->getStatusCode(), $packages).$response->getContent(false), 0, $e);
        }

        if ($packagesToRequire) {
            goto resolve_packages;
        }

        return array_values($resolvedPackages);
    }

    /**
     * @param ImportMapEntry[] $importMapEntries
     *
     * @return array<string, array{content: string, dependencies: string[]}>
     */
    public function downloadPackages(array $importMapEntries, callable $progressCallback = null): array
    {
        $responses = [];

        foreach ($importMapEntries as $package => $entry) {
            if (!$entry->isRemotePackage()) {
                throw new \InvalidArgumentException(sprintf('The entry "%s" is not a remote package.', $entry->importName));
            }

            $pattern = ImportMapType::CSS === $entry->type ? self::URL_PATTERN_DIST_CSS : self::URL_PATTERN_DIST;
            $url = sprintf($pattern, $entry->getPackageName(), $entry->version, $entry->getPackagePathString());

            $responses[$package] = $this->httpClient->request('GET', $url);
        }

        $errors = [];
        $contents = [];
        foreach ($responses as $package => $response) {
            if (200 !== $response->getStatusCode()) {
                $errors[] = [$package, $response];
                continue;
            }

            if ($progressCallback) {
                $progressCallback($package, 'started', $response, \count($responses));
            }

            $dependencies = [];
            $contents[$package] = [
                'content' => $this->makeImportsBare($response->getContent(), $dependencies),
                'dependencies' => $dependencies,
            ];
            if ($progressCallback) {
                $progressCallback($package, 'finished', $response, \count($responses));
            }
        }

        try {
            ($errors[0][1] ?? null)?->getHeaders();
        } catch (HttpExceptionInterface $e) {
            $response = $e->getResponse();
            $packages = implode('", "', array_column($errors, 0));

            throw new RuntimeException(sprintf('Error %d downloading packages from jsDelivr for "%s". Check your package names. Response: ', $response->getStatusCode(), $packages).$response->getContent(false), 0, $e);
        }

        return $contents;
    }

    /**
     * Parses the very specific import syntax used by jsDelivr.
     *
     * Replaces those with normal import "package/name" statements and
     * records the package as a dependency, so it can be downloaded and
     * added to the importmap.
     *
     * @return PackageRequireOptions[]
     */
    private function fetchPackageRequirementsFromImports(string $content): array
    {
        // imports from jsdelivr follow a predictable format
        preg_match_all(self::IMPORT_REGEX, $content, $matches);
        $dependencies = [];
        foreach ($matches[2] as $index => $packageName) {
            $version = $matches[3][$index] ?: null;
            $packageName .= $matches[4][$index]; // add the path if any

            $dependencies[] = new PackageRequireOptions($packageName, $version);
        }

        return $dependencies;
    }

    /**
     * Parses the very specific import syntax used by jsDelivr.
     *
     * Replaces those with normal import "package/name" statements.
     */
    private function makeImportsBare(string $content, array &$dependencies): string
    {
        $content = preg_replace_callback(self::IMPORT_REGEX, function ($matches) use (&$dependencies) {
            $packageName = $matches[2].$matches[4]; // add the path if any
            $dependencies[] = $packageName;

            // replace the "/npm/package@version/+esm" with "package@version"
            return str_replace($matches[1], sprintf('"%s"', $packageName), $matches[0]);
        }, $content);

        // source maps are not also downloaded - so remove the sourceMappingURL
        // remove the final one only (in case sourceMappingURL is used in the code)
        if (false !== $lastPos = strrpos($content, '//# sourceMappingURL=')) {
            $content = substr($content, 0, $lastPos).preg_replace('{//# sourceMappingURL=.*$}m', '', substr($content, $lastPos));
        }

        return preg_replace('{/\*# sourceMappingURL=[^ ]*+ \*/}', '', $content);
    }
}
