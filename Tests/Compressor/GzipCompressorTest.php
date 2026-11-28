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
use Symfony\Component\AssetMapper\Compressor\GzipCompressor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
class GzipCompressorTest extends TestCase
{
    private string $writableRoot;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->writableRoot = sys_get_temp_dir().'/sf_asset-mapper_'.hash('xxh32', __FILE__);
        $this->filesystem = new Filesystem();
        if (!file_exists($this->writableRoot)) {
            $this->filesystem->mkdir($this->writableRoot);
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->writableRoot);
    }

    public function testCompress()
    {
        $this->filesystem->dumpFile($this->writableRoot.'/foo/bar.js', 'foobar');

        (new GzipCompressor())->compress($this->writableRoot.'/foo/bar.js');

        $this->assertFileExists($this->writableRoot.'/foo/bar.js.gz');
    }
}
