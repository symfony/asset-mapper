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
use Symfony\Component\AssetMapper\Event\PreAssetsCompileEvent;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

class AssetMapperTestAppKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/sf_asset-mapper_'.hash('xxh32', __FILE__);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container) {
            $assetMapper = [
                'paths' => [__DIR__.'/dir1', __DIR__.'/dir2', __DIR__.'/non_ascii', __DIR__.'/assets'],
                'importmap_path' => __DIR__.'/importmap.php',
                'vendor_dir' => __DIR__.'/assets/vendor',
                'public_prefix' => 'assets',
                'metadata_dir' => '%kernel.project_dir%/public/assets',
            ];

            if ('reachable_entries' === $this->getEnvironment()) {
                $assetMapper['importmap_entries'] = 'reachable';
            }

            $container->loadFromExtension('framework', [
                'http_client' => true,
                'assets' => null,
                'asset_mapper' => $assetMapper,
                'test' => true,
                'profiler' => ['enabled' => 'profiler' === $this->getEnvironment()],
            ]);

            $container->setAlias('public.assets.packages', new Alias('assets.packages', true));
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->register('logger', NullLogger::class);
        $container->register('pre_assets_compile_listener', PreAssetsCompileListener::class)
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => PreAssetsCompileEvent::class]);

        if ('profiler' === $this->getEnvironment()) {
            $container->register('instantiation_counting_data_collector', InstantiationCountingDataCollector::class)
                ->addTag('data_collector');
        }
    }
}
