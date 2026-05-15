<?php

declare(strict_types=1);

namespace App;

use Survos\TuiExtrasBundle\SurvosTuiExtrasBundle;
use Symfony\Component\Console\ConsoleBundle;
use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class Kernel extends AbstractKernel
{
    use KernelTrait;

    private function getBundlesDefinition(): array
    {
        return [
            ConsoleBundle::class => ['all' => true],
            SurvosTuiExtrasBundle::class => ['all' => true],
        ];
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->services()
            ->load('App\\Command\\', __DIR__.'/../src/Command/')
            ->autoconfigure()
            ->autowire();
    }
}
