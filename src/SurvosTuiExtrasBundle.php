<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle;

use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;

/**
 * Extra TUI widgets and data-source abstractions for symfony/tui.
 *
 * The primary entry point for browse commands is vendor/bin/browse — a standalone
 * script that runs its own lightweight kernel. Commands are NOT auto-registered in
 * the host application's bin/console by default.
 *
 * survos/field-bundle is an optional enhancement: when present,
 * TuiColumn::fromFieldDescriptor() bridges FieldDescriptor to TUI column metadata.
 * Note: do NOT use #[RequiredBundle] for optional deps — it auto-registers any
 * installed bundle class into every kernel that loads this bundle, even with
 * ignoreOnInvalid: true (that flag only skips non-installed classes).
 */
class SurvosTuiExtrasBundle extends AbstractBundle
{
}
