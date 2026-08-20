<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Command;

use Survos\TuiExtrasBundle\Event\TreeNodeChangeEvent;
use Survos\TuiExtrasBundle\Event\TreeNodeSelectEvent;
use Survos\TuiExtrasBundle\Input\MouseInput;
use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Widget\DetailPanelWidget;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * TUI browser for all registered console commands.
 *
 * Left:  TreeWidget — commands grouped by namespace (ai/, app/, browse/, …)
 * Right: DetailPanelWidget — description on cursor, full --help on Enter
 *
 * Data strategy (Option B + twist):
 *   Startup  → shell_exec("bin/console list --format=json")  — builds tree, caches descriptions
 *   Cursor   → description from cache                         — instant, no subprocess
 *   Enter    → shell_exec("bin/console help <cmd> --no-ansi") — full formatted help
 *
 * Works in any Symfony app. Discovers the running bin/console path automatically
 * from $_SERVER['argv'][0] so it sees every command the app has registered.
 *
 * Keybindings:
 *   ↑↓ / j k   navigate    →  expand namespace    ←  collapse
 *   Enter       show full --help in detail pane
 *   q / ctrl+c  quit
 */
#[AsCommand('browse:commands', 'Browse all registered console commands with descriptions and full help', aliases: ['commands'])]
class BrowseCommandsCommand
{
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $consolePath = $this->findConsolePath();

        $data = $this->loadCommandList($consolePath);
        if (null === $data) {
            $output->writeln('<error>Could not load command list. Make sure bin/console is accessible.</error>');

            return Command::FAILURE;
        }

        [$tree, $descriptions] = $this->buildTree($data);

        $stylesheet = new StyleSheet([
            TreeWidget::class.'::branch-expanded'  => new Style(bold: true, color: 'cyan'),
            TreeWidget::class.'::branch-collapsed' => new Style(color: 'cyan'),
            TreeWidget::class.'::leaf'             => new Style(dim: false),
            TreeWidget::class.'::selected'         => new Style(reverse: true, bold: true),
            DetailPanelWidget::class.'::title'     => new Style(bold: true, color: 'cyan'),
            DetailPanelWidget::class.'::separator' => new Style(dim: true),
        ]);

        $detail = new DetailPanelWidget();
        $detail->setContent(
            \sprintf("  %d commands across %d namespaces\n\n  ↑↓ navigate  →← expand/collapse  Enter full help  q quit",
                \count($data['commands']),
                \count(array_filter($data['namespaces'] ?? [], fn ($ns) => '_global' !== $ns['id'])),
            ),
            $data['application']['name'] ?? 'Commands',
        );

        // Cursor move: show description from cache (no subprocess)
        $tree->onCursorChange(static function (TreeNodeChangeEvent $event) use ($detail, $descriptions): void {
            $node = $event->node;

            if (!$node->isLeaf()) {
                $ns = rtrim((string) $node->label, ':');
                $cmds = array_filter(array_keys($descriptions), fn (string $k) => str_starts_with($k, $ns.':'));
                $detail->setContent(
                    \sprintf("  %d command%s\n\n  → to expand  Enter on a command for full help", \count($cmds), 1 === \count($cmds) ? '' : 's'),
                    $node->label,
                );

                return;
            }

            $name = (string) $node->data;
            $desc = $descriptions[$name] ?? '';
            $detail->setContent(
                \sprintf("  %s\n\n  %s\n\n  Press Enter for full help text.",
                    $name,
                    '' !== $desc ? $desc : '(no description)',
                ),
                $name,
            );
        });

        // Enter: shell out for full formatted help (one subprocess, ~200ms)
        $tree->onSelect(static function (TreeNodeSelectEvent $event) use ($detail, $consolePath): void {
            $name = (string) $event->node->data;
            $help = shell_exec(\sprintf(
                '%s %s help %s --no-ansi 2>&1',
                \PHP_BINARY,
                escapeshellarg($consolePath),
                escapeshellarg($name),
            ));
            $detail->setContent($help ?? '(no help available)', $name.' --help');
        });

        $split = (new ContainerWidget())
            ->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $split->add($tree->setStyle(new Style(maxColumns: 35)));
        $split->add($detail);

        $tui = new Tui($stylesheet);
        $tui->add($split);
        $tui->setFocus($tree);
        (new MouseInput())->run($tui);

        return Command::SUCCESS;
    }

    /**
     * @return array{TreeWidget, array<string,string>}  [widget, name→description map]
     */
    private function buildTree(array $data): array
    {
        $tree = new TreeWidget();

        /** @var array<string,string> $descriptions name → description */
        $descriptions = [];
        /** @var array<string,array<array{name:string,description:string}>> $byNamespace */
        $byNamespace = ['_global' => []];

        foreach ($data['commands'] as $cmd) {
            $name = $cmd['name'];
            $desc = $cmd['description'] ?? '';
            $descriptions[$name] = $desc;

            $colon = strpos($name, ':');
            $ns    = false !== $colon ? substr($name, 0, $colon) : '_global';
            $byNamespace[$ns][] = ['name' => $name, 'description' => $desc];
        }

        // Global (no namespace) commands first
        foreach ($byNamespace['_global'] as $cmd) {
            $tree->addRoot(TreeNode::leaf($cmd['name'], $cmd['name']));
        }

        // Namespaced commands, sorted alphabetically
        ksort($byNamespace);
        foreach ($byNamespace as $ns => $commands) {
            if ('_global' === $ns || [] === $commands) {
                continue;
            }
            $branch = TreeNode::branch($ns.':', $ns);
            foreach ($commands as $cmd) {
                $branch->addChild(TreeNode::leaf($cmd['name'], $cmd['name']));
            }
            $tree->addRoot($branch);
        }

        return [$tree, $descriptions];
    }

    /** @return array<string,mixed>|null */
    private function loadCommandList(string $consolePath): ?array
    {
        $json = shell_exec(\sprintf(
            '%s %s list --format=json 2>/dev/null',
            \PHP_BINARY,
            escapeshellarg($consolePath),
        ));

        if (null === $json || '' === $json) {
            return null;
        }

        $data = json_decode($json, true);

        return \JSON_ERROR_NONE === json_last_error() ? $data : null;
    }

    private function findConsolePath(): string
    {
        // $_SERVER['argv'][0] is the console script path (bin/console) that launched this command
        $argv0 = $_SERVER['argv'][0] ?? 'bin/console';

        return realpath($argv0) ?: $argv0;
    }
}
