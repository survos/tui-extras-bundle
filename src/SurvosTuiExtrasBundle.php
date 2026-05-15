<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle;

use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

/**
 * Extra TUI widgets and data-source abstractions for symfony/tui.
 *
 * The primary entry point for browse commands is vendor/bin/browse — a standalone
 * script that runs its own lightweight kernel. Commands are NOT auto-registered in
 * the host application's bin/console by default.
 *
 * survos/field-bundle is an optional enhancement: when present,
 * TuiColumn::fromFieldDescriptor() bridges FieldDescriptor to TUI column metadata.
 */
#[RequiredBundle('Survos\FieldBundle\SurvosFieldBundle', ignoreOnInvalid: true)]
class SurvosTuiExtrasBundle extends AbstractBundle
{
}
