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
use Symfony\Component\AssetMapper\Compressor\ZstandardCompressor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
class ZstandardCompressorTest extends TestCase
{
    private string $writableRoot;

    private ?Filesystem $filesystem = null;

    protected function setUp(): void
    {
        if (null !== $reason = (new ZstandardCompressor())->getUnsupportedReason()) {
            $this->markTestSkipped($reason);
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
        $this->filesystem->dumpFile($this->writableRoot.'/foo/bar.js', 'foobar');

        (new ZstandardCompressor())->compress($this->writableRoot.'/foo/bar.js');

        $this->assertFileExists($this->writableRoot.'/foo/bar.js.zst');
    }
}
