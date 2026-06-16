<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Extra TUI widgets and data-source abstractions for symfony/tui.
 *
 * survos/field-bundle is an optional enhancement: when present,
 * TuiColumn::fromFieldDescriptor() bridges FieldDescriptor to TUI column metadata.
 * Note: do NOT use #[RequiredBundle] for optional deps — it auto-registers any
 * installed bundle class into every kernel that loads this bundle, even with
 * ignoreOnInvalid: true (that flag only skips non-installed classes).
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- required for Flex auto-registering in config/bundles.php!!
final class SurvosTuiExtrasBundle extends AbstractSurvosBundle
{
}
