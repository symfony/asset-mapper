<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Factory;

use Symfony\Component\AssetMapper\AssetMapperCompiler;
use Symfony\Component\AssetMapper\Exception\CircularAssetsException;
use Symfony\Component\AssetMapper\Exception\RuntimeException;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolverInterface;

/**
 * Creates MappedAsset objects by reading their contents & passing it through compilers.
 */
class MappedAssetFactory implements MappedAssetFactoryInterface
{
    private const PREDIGESTED_REGEX = '/-([0-9a-zA-Z]{7,128}\.digested)/';

    private array $assetsCache = [];
    private array $assetsBeingCreated = [];
    private array $fileContentsCache = [];

    public function __construct(
        private readonly PublicAssetsPathResolverInterface $assetsPathResolver,
        private readonly AssetMapperCompiler $compiler,
        private readonly string $vendorDir,
    ) {
    }

    public function createMappedAsset(string $logicalPath, string $sourcePath): ?MappedAsset
    {
        if (isset($this->assetsBeingCreated[$logicalPath])) {
            throw new CircularAssetsException(sprintf('Circular reference detected while creating asset for "%s": "%s".', $logicalPath, implode(' -> ', $this->assetsBeingCreated).' -> '.$logicalPath));
        }
        $this->assetsBeingCreated[$logicalPath] = $logicalPath;

        if (!isset($this->assetsCache[$logicalPath])) {
            $isVendor = $this->isVendor($sourcePath);
            $asset = new MappedAsset($logicalPath, $sourcePath, $this->assetsPathResolver->resolvePublicPath($logicalPath), isVendor: $isVendor);

            [$digest, $isPredigested] = $this->getDigest($asset);

            $asset = new MappedAsset(
                $asset->logicalPath,
                $asset->sourcePath,
                $asset->publicPathWithoutDigest,
                $this->getPublicPath($asset),
                $this->compileContent($asset),
                $digest,
                $isPredigested,
                $isVendor,
                $asset->getDependencies(),
                $asset->getFileDependencies(),
                $asset->getJavaScriptImports(),
            );

            $this->assetsCache[$logicalPath] = $asset;
        }

        unset($this->assetsBeingCreated[$logicalPath]);

        return $this->assetsCache[$logicalPath];
    }

    /**
     * Returns an array of "string digest" and "bool predigested".
     *
     * @return array{0: string, 1: bool}
     */
    private function getDigest(MappedAsset $asset): array
    {
        // check for a pre-digested file
        if (preg_match(self::PREDIGESTED_REGEX, $asset->logicalPath, $matches)) {
            return [$matches[1], true];
        }

        // Use the compiled content if any
        if (null !== $content = $this->compileContent($asset)) {
            return [hash('xxh128', $content), false];
        }

        return [
            hash_file('xxh128', $asset->sourcePath),
            false,
        ];
    }

    private function compileContent(MappedAsset $asset): ?string
    {
        if (\array_key_exists($asset->logicalPath, $this->fileContentsCache)) {
            return $this->fileContentsCache[$asset->logicalPath];
        }

        if (!is_file($asset->sourcePath)) {
            throw new RuntimeException(sprintf('Asset source path "%s" could not be found.', $asset->sourcePath));
        }

        if (!$this->compiler->supports($asset)) {
            return $this->fileContentsCache[$asset->logicalPath] = null;
        }

        $content = file_get_contents($asset->sourcePath);
        $content = $this->compiler->compile($content, $asset);

        return $this->fileContentsCache[$asset->logicalPath] = $content;
    }

    private function getPublicPath(MappedAsset $asset): ?string
    {
        [$digest, $isPredigested] = $this->getDigest($asset);

        if ($isPredigested) {
            return $this->assetsPathResolver->resolvePublicPath($asset->logicalPath);
        }

        $digestedPath = preg_replace_callback('/\.(\w+)$/', fn ($matches) => "-{$digest}{$matches[0]}", $asset->logicalPath);

        return $this->assetsPathResolver->resolvePublicPath($digestedPath);
    }

    private function isVendor(string $sourcePath): bool
    {
        $sourcePath = realpath($sourcePath);
        $vendorDir = realpath($this->vendorDir);

        return $sourcePath && str_starts_with($sourcePath, $vendorDir);
    }
}
