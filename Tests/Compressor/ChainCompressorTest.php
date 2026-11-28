<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Tests\Compressor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\Compressor\BrotliCompressor;
use Symfony\Component\AssetMapper\Compressor\ChainCompressor;
use Symfony\Component\AssetMapper\Compressor\GzipCompressor;
use Symfony\Component\AssetMapper\Compressor\ZstandardCompressor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
class ChainCompressorTest extends TestCase
{
    private string $writableRoot;

    private ?Filesystem $filesystem = null;

    protected function setUp(): void
    {
        if (\PHP_VERSION_ID >= 80600 && \extension_loaded('brotli') && version_compare(phpversion('brotli'), '0.21.0', '<=')) {
            $this->markTestSkipped('ext-brotli up to 0.21.0 reports a failure when closing a compressing stream.');
        }

        if (\PHP_VERSION_ID >= 80600 && \extension_loaded('zstd') && version_compare(phpversion('zstd'), '0.18.0', '<=')) {
            $this->markTestSkipped('ext-zstd up to 0.18.0 reports a failure when closing a compressing stream.');
        }

        $this->writableRoot = sys_get_temp_dir().'/sf_asset-mapper_'.hash('xxh32', __FILE__);
        $this->filesystem = new Filesystem();
        if (!file_exists($this->writableRoot)) {
            $this->filesystem->mkdir($this->writableRoot);
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem?->remove($this->writableRoot);
    }

    public function testCompress()
    {
        $extensions = [];
        if (null === (new GzipCompressor())->getUnsupportedReason()) {
            $extensions[] = 'gz';
        }
        if (null === (new BrotliCompressor())->getUnsupportedReason()) {
            $extensions[] = 'br';
        }
        if (null === (new ZstandardCompressor())->getUnsupportedReason()) {
            $extensions[] = 'zst';
        }

        if (!$extensions) {
            $this->markTestSkipped('No supported compressors available.');
        }

        $this->filesystem->dumpFile($this->writableRoot.'/foo/bar.js', 'foobar');

        (new ChainCompressor())->compress($this->writableRoot.'/foo/bar.js');

        foreach ($extensions as $extension) {
            $this->assertFileExists($this->writableRoot.'/foo/bar.js.'.$extension);
        }
    }
}
