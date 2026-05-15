<?php

declare(strict_types=1);

namespace App\Command;

use Survos\TuiExtrasBundle\Event\TableRowChangeEvent;
use Survos\TuiExtrasBundle\Model\TuiColumn;
use Survos\TuiExtrasBundle\Source\SqliteTableSource;
use Survos\TuiExtrasBundle\Widget\DataTableWidget;
use Survos\TuiExtrasBundle\Widget\DetailPanelWidget;
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
 * Demonstrates DataTableWidget + DetailPanelWidget in a split layout.
 *
 * Data: all PHP internal functions — ~1300+ rows, FTS5 search.
 *
 * Try:  / str_   → all string functions
 *       / array  → all array functions
 *
 * Keybindings: ↑↓/j/k navigate · Space/Enter select · PgDn/PgUp page · / search · s sort · q quit
 */
#[AsCommand('browse:functions', 'Browse PHP built-in functions with FTS5 search + detail pane')]
final class DemoBrowseCommand
{
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $pdo = $this->buildDatabase();
        $source = new SqliteTableSource(
            pdo: $pdo,
            table: 'functions',
            ftsTable: 'fts_functions',
            columns: [
                new TuiColumn(key: 'name', label: 'Function', sortable: true, searchable: true, order: 10),
                new TuiColumn(key: 'extension', label: 'Extension', width: 16, sortable: true, searchable: true, order: 20),
                new TuiColumn(key: 'params', label: 'Params', width: 7, sortable: true, order: 30),
                new TuiColumn(key: 'required', label: 'Req\'d', width: 6, sortable: true, order: 40),
            ],
        );

        $stylesheet = new StyleSheet([
            DataTableWidget::class.'::header'    => new Style(bold: true, color: 'cyan'),
            DataTableWidget::class.'::separator' => new Style(dim: true),
            DataTableWidget::class.'::selected'  => new Style(reverse: true, bold: true),
            DataTableWidget::class.'::statusbar' => new Style(dim: true),
            DataTableWidget::class.'::search'    => new Style(color: 'yellow'),
            DataTableWidget::class.'::no-match'  => new Style(dim: true, italic: true),
            DetailPanelWidget::class.'::title'   => new Style(bold: true, color: 'cyan'),
            DetailPanelWidget::class.'::separator' => new Style(dim: true),
        ]);

        $detail = new DetailPanelWidget();
        $detail->setContent('Navigate to a function to see its signature.', 'Detail');

        $table = new DataTableWidget($source);
        $table->onRowChange(static function (TableRowChangeEvent $event) use ($detail): void {
            $row = $event->row;
            $content = "Extension: {$row['extension']}\n\n";
            try {
                $rf = new \ReflectionFunction($row['name']);
                $params = $rf->getParameters();
                if ([] === $params) {
                    $content .= "Parameters: (none)\n";
                } else {
                    $content .= "Parameters:\n";
                    foreach ($params as $p) {
                        $type = $p->getType()?->getName() ?? 'mixed';
                        $opt = $p->isOptional() ? ' [optional]' : '';
                        $content .= "  \${$p->name}: {$type}{$opt}\n";
                    }
                }
                $ret = $rf->getReturnType();
                $content .= "\nReturns: ".($ret ? $ret->getName() : 'mixed');
            } catch (\Throwable) {
                $content .= "(reflection unavailable)";
            }
            $detail->setContent($content, $row['name'].'()');
        });

        $split = (new ContainerWidget())
            ->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $split->add($table->setStyle(new Style(maxColumns: 55)));
        $split->add($detail);

        $tui = new Tui($stylesheet);
        $tui->add($split);
        $tui->setFocus($table);
        $tui->run();

        $selected = $table->getSelectedRow();
        if (null !== $selected) {
            $output->writeln(\sprintf('<info>Selected:</info> %s (%s)', $selected['name'], $selected['extension']));
        }

        return Command::SUCCESS;
    }

    private function buildDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE functions (
                id       INTEGER PRIMARY KEY,
                name     TEXT NOT NULL,
                extension TEXT NOT NULL,
                params   INTEGER NOT NULL DEFAULT 0,
                required INTEGER NOT NULL DEFAULT 0
            )
        ');

        $insert = $pdo->prepare('INSERT INTO functions (name, extension, params, required) VALUES (?, ?, ?, ?)');

        $pdo->beginTransaction();
        foreach ($this->collectFunctions() as $row) {
            $insert->execute($row);
        }
        $pdo->commit();

        $pdo->exec('
            CREATE VIRTUAL TABLE fts_functions USING fts5(
                name, extension, content=functions, content_rowid=id
            )
        ');
        $pdo->exec("INSERT INTO fts_functions(fts_functions) VALUES('rebuild')");

        return $pdo;
    }

    /** @return iterable<array{string, string, int, int}> */
    private function collectFunctions(): iterable
    {
        $names = get_defined_functions()['internal'];
        sort($names);

        foreach ($names as $name) {
            try {
                $rf = new \ReflectionFunction($name);
                yield [$name, $rf->getExtensionName() ?: 'core', $rf->getNumberOfParameters(), $rf->getNumberOfRequiredParameters()];
            } catch (\Throwable) {
                yield [$name, 'core', 0, 0];
            }
        }
    }
}
