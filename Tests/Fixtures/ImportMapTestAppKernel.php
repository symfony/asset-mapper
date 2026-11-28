<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Tests\Fixtures;

use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

class ImportMapTestAppKernel extends Kernel
{
    private static ?string $projectDir = null;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return self::$projectDir ??= sys_get_temp_dir().'/sf_importmap_test_app_'.bin2hex(random_bytes(4));
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container) {
            $container->loadFromExtension('framework', [
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'http_client' => true,
                'assets' => null,
                'asset_mapper' => [
                    'paths' => [__DIR__.'/assets'],
                    'importmap_path' => __DIR__.'/importmap.php',
                    'vendor_dir' => __DIR__.'/assets/vendor',
                ],
                'test' => true,
            ]);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->register('logger', NullLogger::class);
    }
}
