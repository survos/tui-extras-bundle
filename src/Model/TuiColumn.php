<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Model;

/**
 * Describes one column in a TUI data table.
 *
 * width=0 means auto-size (fill remaining space equally with other auto columns).
 * format hints: 'date', 'datetime', 'boolean', 'bytes', 'percent' — matched in DataTableWidget::formatCell().
 *
 * When survos/field-bundle is available, use TuiColumn::fromFieldDescriptor() instead of constructing directly.
 * Once field-bundle adds YAML-based column definitions, FieldDescriptor (the compiled registry artifact)
 * will still be the canonical source — this class remains the TUI-layer DTO.
 */
final class TuiColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $width = 0,
        public readonly bool $sortable = false,
        public readonly bool $searchable = false,
        public readonly bool $visible = true,
        public readonly ?string $format = null,
        public readonly int $order = 100,
    ) {}

    public static function make(string $key, ?string $label = null): self
    {
        return new self(
            key: $key,
            label: $label ?? ucwords(str_replace(['_', '-'], ' ', $key)),
        );
    }

    /**
     * Convert a survos/field-bundle FieldDescriptor to a TuiColumn.
     * Accepts any object with the matching public properties to avoid a hard dependency.
     */
    public static function fromFieldDescriptor(object $descriptor): self
    {
        return new self(
            key: $descriptor->name,
            label: method_exists($descriptor, 'getFallbackLabel')
                ? $descriptor->getFallbackLabel()
                : ucwords(str_replace(['_', '-'], ' ', $descriptor->name)),
            width: isset($descriptor->width) && $descriptor->width
                ? (int) filter_var($descriptor->width, \FILTER_SANITIZE_NUMBER_INT)
                : 0,
            sortable: $descriptor->sortable ?? false,
            searchable: $descriptor->searchable ?? false,
            visible: $descriptor->visible ?? true,
            format: $descriptor->format ?? null,
            order: $descriptor->order ?? 100,
        );
    }
}
