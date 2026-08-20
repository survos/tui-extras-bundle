# survos/tui-extras-bundle

Extra TUI widgets and data-source abstractions for [`symfony/tui`](https://github.com/symfony/tui) — the terminal UI component shipping in Symfony 8.1.

> **Status:** 0.1 — beta. Tracks `symfony/tui ^8.1` which is itself experimental.
> Widgets are usable and the file browser works end-to-end. APIs may shift before 1.0.

---

## What's in the box

### Widgets

| Widget | What it does |
|---|---|
| `DataTableWidget` | Paged, searchable, sortable data table. Live search, FTS5-aware. |
| `TreeWidget` | Collapsible tree — `▼`/`►` branches, `──` leaves. Keyboard navigate. |
| `DetailPanelWidget` | Read-only text/code pane with title and separator. |

All bundled browsers opt into mouse reporting. The scroll wheel moves through
the focused `TreeWidget` or `DataTableWidget`; keyboard navigation remains
available. Mouse mode is disabled in a `finally` block when the TUI exits so
normal terminal scrollback and text selection are restored.

Applications can enable the same behavior for custom widgets by implementing
`MouseAwareInterface` and running the TUI through `MouseInput`:

```php
$tui = new Tui();
$tui->add($mouseAwareWidget);
$tui->setFocus($mouseAwareWidget);

(new MouseInput())->run($tui);
```

### Data sources (`TuiTableSourceInterface`)

| Source | Backed by |
|---|---|
| `ArrayTableSource` | Plain PHP array, in-memory filter/sort |
| `SqliteTableSource` | PDO + SQLite with optional FTS5 full-text search |
| `FileSource` | Symfony Finder — respects `.gitignore` by default |

### Syntax highlighting (`SyntaxHighlighter`)

- Uses `bat`/`batcat` if installed (200+ languages, themes)
- PHP fallback via `token_get_all()` — zero extra deps
- JSON fallback via stdlib pretty-print + hand-rolled tokenizer
- Markdown via `MarkdownWidget` (league/commonmark + tempest/highlight)

---

## Requirements

- PHP 8.1+
- `symfony/tui ^8.1` (currently `8.1.x-dev`)
- `league/commonmark ^2.0` (for Markdown rendering)
- `tempest/highlight ^2.0` (for code blocks within Markdown)

---

## Installation

```bash
composer require survos/tui-extras-bundle
```

Register in your `config/bundles.php` (or let Flex do it):

```php
Survos\TuiExtrasBundle\SurvosTuiExtrasBundle::class => ['all' => true],
```

> **HTTP-less apps** (`AbstractKernel` + `KernelTrait` + `ConsoleBundle`, no `FrameworkBundle`):
> the bundle works without an HTTP kernel. See the `demo/` directory for a minimal setup.

---

## The file browser

The fastest way to see the bundle in action:

```bash
cd demo/
composer install
php bin/console browse:files          # tree of current directory
php bin/console browse:files ../src   # browse the bundle source
php bin/console browse:files . --pre-expand=2   # open 2 levels on start
php bin/console browse:files . --raw            # no syntax highlighting
php bin/console browse:files . --no-gitignore   # include ignored files
```

```
▼ src/
── ► Event/
▼ Highlighter/
──── SyntaxHighlighter.php
▼ Model/
──── TreeNode.php
──── TuiColumn.php
▼ Widget/
──── DataTableWidget.php        │  <?php
──── DetailPanelWidget.php      │
──── TreeWidget.php             │  declare(strict_types=1);
▼ Source/                       │
──── ArrayTableSource.php       │  namespace Survos\TuiExtrasBundle\Highlighter;
──── FileSource.php             │
──── SqliteTableSource.php      │  /**
                                │   * Syntax highlighter that emits ANSI-colored
                                │   * lines compatible with DetailPanelWidget.
```

**Keybindings:**

| Key | Action |
|---|---|
| `↑` / `↓` / `j` / `k` | Navigate |
| `→` | Expand directory |
| `←` | Collapse (or jump to parent) |
| `Enter` / `Space` | Toggle directory / preview file |
| `q` / `ctrl+c` | Quit |

Preview updates **instantly** as you navigate — no Enter required for files.
PHP and JSON files are syntax-highlighted. Markdown files render with full formatting.

---

## FTS5 full-text search demo

```bash
php bin/console browse:functions    # ~1300 PHP internal functions, SQLite FTS5
```

Type `/` then `str_` to filter to all string functions instantly. Uses an in-memory
SQLite database with an FTS5 virtual table — the same pattern `FolioRowTableSource`
uses in `survos/folio-bundle`.

---

## Building a split-pane browser

```php
use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Widget\DetailPanelWidget;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

$tree = new TreeWidget();
$tree->addRoot(TreeNode::branch('src/', null, true)
    ->addChild(TreeNode::leaf('Kernel.php', 'src/Kernel.php'))
    ->addChild(TreeNode::branch('Command/')
        ->addChild(TreeNode::leaf('MyCommand.php', 'src/Command/MyCommand.php'))
    )
);

$detail = new DetailPanelWidget();
$tree->onCursorChange(fn($e) => $detail->setContent(
    file_get_contents($e->node->data ?? ''),
    (string) $e->node->data,
));

$split = (new ContainerWidget())->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
$split->add($tree->setStyle(new Style(maxColumns: 40)));
$split->add($detail);

$tui = new Tui(new StyleSheet());
$tui->add($split);
$tui->setFocus($tree);
$tui->run();
```

---

## Building a DataTable with SQLite FTS5

```php
use Survos\TuiExtrasBundle\Model\TuiColumn;
use Survos\TuiExtrasBundle\Source\SqliteTableSource;
use Survos\TuiExtrasBundle\Widget\DataTableWidget;

$pdo = new \PDO('sqlite:/path/to/data.sqlite');

// FTS5 virtual table: CREATE VIRTUAL TABLE fts_items USING fts5(name, description, content=items, content_rowid=id)
$source = new SqliteTableSource(
    pdo: $pdo,
    table: 'items',
    ftsTable: 'fts_items',
    columns: [
        new TuiColumn(key: 'name', label: 'Name', sortable: true, searchable: true),
        new TuiColumn(key: 'description', label: 'Description', searchable: true),
        new TuiColumn(key: 'created_at', label: 'Created', width: 12, format: 'date', sortable: true),
    ],
);

$table = new DataTableWidget($source);
// Press / to search with FTS5, ↑↓ navigate, s sort, q quit
```

---

## TuiTableSourceInterface

Implement this to make any data source browsable:

```php
interface TuiTableSourceInterface {
    /** @return TuiColumn[] */
    public function columns(): array;
    public function count(?string $search = null): int;
    /** @return iterable<array<string,mixed>> */
    public function rows(int $offset = 0, int $limit = 25, ?string $search = null, ?string $sortBy = null, string $sortDir = 'ASC'): iterable;
}
```

Built-in implementations: `ArrayTableSource`, `SqliteTableSource`, `FileSource`.
Adapters in other bundles: `FolioRowTableSource` (survos/folio-bundle), `JsonlTableSource` (survos/jsonl-bundle, planned).

---

## Roadmap

- [ ] `browse:commands` — `vendor/bin/browse` command browser (tree of namespaces + full `--help` in detail pane)
- [ ] `FolioRowTableSource` — FTS5-backed browser for survos/folio-bundle
- [ ] `MeilisearchTableSource` + `browse:meili` — live Meilisearch browser
- [ ] Generic SQLite schema browser (squall-style: table tree + row DataTable)
- [ ] PHAR packaging via humbug/box (`vendor/bin/browse`)

---

## License

MIT
