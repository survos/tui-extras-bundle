<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle;

use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

/**
 * Extra TUI widgets and data-source abstractions for symfony/tui.
 *
 * survos/field-bundle is an optional enhancement: when present, TuiColumn::fromFieldDescriptor()
 * bridges FieldDescriptor (including future YAML-defined columns) to TUI column metadata.
 * The #[RequiredBundle] below ensures field-bundle is initialized before this bundle
 * in any app that has it installed, without failing when it is absent.
 */
#[RequiredBundle('Survos\FieldBundle\SurvosFieldBundle', ignoreOnInvalid: true)]
class SurvosTuiExtrasBundle extends AbstractBundle
{
}
