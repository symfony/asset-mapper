<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Tests\Path;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\Path\LocalPublicAssetsFilesystem;
use Symfony\Component\Filesystem\Filesystem;

class LocalPublicAssetsFilesystemTest extends TestCase
{
    private Filesystem $filesystem;
    private static string $writableRoot = __DIR__.'/../Fixtures/importmaps_for_writing';

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        if (!file_exists(__DIR__.'/../Fixtures/importmaps_for_writing')) {
            $this->filesystem->mkdir(self::$writableRoot);
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove(self::$writableRoot);
    }

    public function testWrite()
    {
        $filesystem = new LocalPublicAssetsFilesystem(self::$writableRoot);
        $filesystem->write('foo/bar.js', 'foobar');
        $this->assertFileExists(self::$writableRoot.'/foo/bar.js');
        $this->assertSame('foobar', file_get_contents(self::$writableRoot.'/foo/bar.js'));

        // with a directory
        $filesystem->write('foo/baz/bar.js', 'foobar');
        $this->assertFileExists(self::$writableRoot.'/foo/baz/bar.js');
    }

    public function testCopy()
    {
        $filesystem = new LocalPublicAssetsFilesystem(self::$writableRoot);
        $filesystem->copy(__DIR__.'/../Fixtures/importmaps/assets/pizza/index.js', 'foo/bar.js');
        $this->assertFileExists(self::$writableRoot.'/foo/bar.js');
        $this->assertSame("console.log('pizza/index.js');", trim(file_get_contents(self::$writableRoot.'/foo/bar.js')));
    }
}
