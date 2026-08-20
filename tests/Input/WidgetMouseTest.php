<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Tests\Input;

use PHPUnit\Framework\TestCase;
use Survos\TuiExtrasBundle\Enum\MouseAction;
use Survos\TuiExtrasBundle\Enum\MouseButton;
use Survos\TuiExtrasBundle\Event\MouseEvent;
use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Source\ArrayTableSource;
use Survos\TuiExtrasBundle\Widget\DataTableWidget;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class WidgetMouseTest extends TestCase
{
    public function testWheelNavigatesTreeRows(): void
    {
        $tree = new TreeWidget();
        $tree->setRoots([
            TreeNode::leaf('alpha', 'alpha'),
            TreeNode::leaf('beta', 'beta'),
        ]);
        $tree->render(new RenderContext(40, 5));

        $tree->handleMouse($this->wheelEvent($tree, MouseButton::WheelDown));
        self::assertSame('beta', $tree->getCursorNode()?->data);

        $tree->handleMouse($this->wheelEvent($tree, MouseButton::WheelUp));
        self::assertSame('alpha', $tree->getCursorNode()?->data);
    }

    public function testWheelNavigatesDataTableRows(): void
    {
        $table = new DataTableWidget(new ArrayTableSource([
            ['name' => 'alpha'],
            ['name' => 'beta'],
        ]));
        $table->render(new RenderContext(40, 8));

        $table->handleMouse($this->wheelEvent($table, MouseButton::WheelDown));
        self::assertSame('beta', $table->getSelectedRow()['name'] ?? null);

        $table->handleMouse($this->wheelEvent($table, MouseButton::WheelUp));
        self::assertSame('alpha', $table->getSelectedRow()['name'] ?? null);
    }

    private function wheelEvent(AbstractWidget $target, MouseButton $button): MouseEvent
    {
        return new MouseEvent($target, 0, 0, $button, MouseAction::Wheel);
    }
}
