<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Command;

use Survos\TuiExtrasBundle\Event\TreeNodeChangeEvent;
use Survos\TuiExtrasBundle\Highlighter\SyntaxHighlighter;
use Survos\TuiExtrasBundle\Input\MouseInput;
use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Widget\DetailPanelWidget;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Collapsible file tree (left) with instant syntax-highlighted preview (right).
 *
 * Works in any Symfony app that installs survos/tui-extras-bundle.
 *
 * Usage:
 *   php bin/console browse:files                  # current directory
 *   php bin/console browse:files /path/to/dir     # specific directory
 *   php bin/console browse:files . --pre-expand=2 # expand 2 levels
 *   php bin/console browse:files . --no-gitignore # include ignored files
 *   php bin/console browse:files . --raw          # no syntax highlighting
 *
 * Keybindings:
 *   ↑↓ / j k   navigate      → expand    ← collapse/parent
 *   Enter/Space toggle/preview            q/ctrl+c quit
 *
 * Preview updates instantly on cursor move. PHP, JSON, and Markdown
 * are formatted and syntax-highlighted; everything else uses bat/batcat
 * if installed, otherwise plain text.
 */
#[AsCommand('browse:files', 'Collapsible file tree with instant syntax-highlighted preview', aliases: ['files'])]
class BrowseFilesCommand
{
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Directory to browse')] string $directory = '.',
        #[Option('Respect .gitignore (use --no-gitignore to disable)')] bool $gitignore = true,
        #[Option('Levels to pre-expand on startup')] int $preExpand = 1,
        #[Option('Show raw file content without syntax highlighting')] bool $raw = false,
    ): int {
        $directory = realpath($directory) ?: $directory;

        if (!is_dir($directory)) {
            $output->writeln("<error>Not a directory: {$directory}</error>");

            return Command::FAILURE;
        }

        $highlighter = new SyntaxHighlighter();

        $stylesheet = new StyleSheet([
            TreeWidget::class.'::branch-expanded'  => new Style(bold: true, color: 'green'),
            TreeWidget::class.'::branch-collapsed' => new Style(color: 'green'),
            TreeWidget::class.'::leaf'             => new Style(dim: false),
            TreeWidget::class.'::selected'         => new Style(reverse: true, bold: true),
            DetailPanelWidget::class.'::title'     => new Style(bold: true, color: 'green'),
            DetailPanelWidget::class.'::separator' => new Style(dim: true),
        ]);

        $detail = new DetailPanelWidget();
        $detail->setContent(self::dirSummary($directory), basename($directory).'/');

        $tree = new TreeWidget();
        $tree->setRoots($this->buildTree($directory, $directory, 0, $preExpand, $gitignore));

        $tree->onCursorChange(static function (TreeNodeChangeEvent $event) use ($detail, $directory, $highlighter, $raw): void {
            $node = $event->node;

            if (!$node->isLeaf()) {
                $full = $directory.'/'.$node->data;
                $detail->setContent(self::dirSummary($full), (string) $node->data);

                return;
            }

            $rel  = (string) $node->data;
            $full = $directory.'/'.$rel;

            if (!is_readable($full)) {
                $detail->setContent('(not readable)', $rel);

                return;
            }

            $size = filesize($full);
            if ($size > 512_000) {
                $detail->setContent(\sprintf('(file too large: %s KB)', number_format($size / 1024, 1)), $rel);

                return;
            }

            $handle = fopen($full, 'rb');
            $sample = $handle ? fread($handle, 8192) : false;
            if ($handle) {
                fclose($handle);
            }
            if (false !== $sample && str_contains($sample, "\0")) {
                $detail->setContent(\sprintf('(binary file, %s bytes)', number_format($size)), $rel);

                return;
            }

            $ext = strtolower(pathinfo($full, \PATHINFO_EXTENSION));
            if (!$raw && 'md' === $ext) {
                $detail->setMarkdown((string) file_get_contents($full), $rel);
            } else {
                $lines = $highlighter->highlightFile($full, $raw);
                $detail->setContent(implode("\n", $lines), $rel);
            }
        });

        $split = (new ContainerWidget())
            ->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $split->add($tree->setStyle(new Style(maxColumns: 16)));
        $split->add($detail);

        $tui = new Tui($stylesheet);
        $tui->add($split);
        $tui->setFocus($tree);
        (new MouseInput())->run($tui);

        $selected = $tree->getCursorNode();
        if (null !== $selected && $selected->isLeaf()) {
            $output->writeln($directory.'/'.$selected->data);
        }

        return Command::SUCCESS;
    }

    public static function dirSummary(string $path): string
    {
        if (!is_dir($path)) {
            return '';
        }

        $items = @scandir($path, \SCANDIR_SORT_ASCENDING) ?: [];
        $dirs = $files = $byExt = [];

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $dirs[] = $item;
            } else {
                $files[] = $item;
                $ext = strtolower(pathinfo($item, \PATHINFO_EXTENSION)) ?: '(none)';
                $byExt[$ext] = ($byExt[$ext] ?? 0) + 1;
            }
        }

        arsort($byExt);

        $lines = [\sprintf('%d director%s · %d file%s',
            \count($dirs), 1 === \count($dirs) ? 'y' : 'ies',
            \count($files), 1 === \count($files) ? '' : 's',
        )];

        if ([] !== $byExt) {
            $lines[] = '';
            $lines[] = 'By extension:';
            foreach ($byExt as $ext => $count) {
                $lines[] = \sprintf('  .%-12s %d', $ext, $count);
            }
        }

        if ([] !== $dirs) {
            $lines[] = '';
            $lines[] = 'Directories:';
            foreach ($dirs as $d) {
                $lines[] = '  '.$d.'/';
            }
        }

        return implode("\n", $lines);
    }

    /** @return TreeNode[] */
    private function buildTree(string $root, string $dir, int $depth, int $preExpand, bool $gitignore): array
    {
        $items = @scandir($dir, \SCANDIR_SORT_ASCENDING);
        if (false === $items) {
            return [];
        }

        $dirs = $files = [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            if ($gitignore && '.' === $item[0]) {
                continue;
            }
            $full = $dir.'/'.$item;
            is_dir($full) ? $dirs[] = $item : $files[] = $item;
        }

        $nodes = [];
        foreach ($dirs as $name) {
            $full   = $dir.'/'.$name;
            $rel    = ltrim(substr($full, \strlen($root)), '/');
            $branch = TreeNode::branch($name.'/', $rel, $depth < $preExpand);
            foreach ($this->buildTree($root, $full, $depth + 1, $preExpand, $gitignore) as $child) {
                $branch->addChild($child);
            }
            $nodes[] = $branch;
        }

        foreach ($files as $name) {
            $full    = $dir.'/'.$name;
            $rel     = ltrim(substr($full, \strlen($root)), '/');
            $nodes[] = TreeNode::leaf($name, $rel);
        }

        return $nodes;
    }
}
