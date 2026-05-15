# survos/tui-extras-bundle — Demo App

A minimal HTTP-less Symfony 8.1 application demonstrating the TUI extra widgets.
No web stack, no Doctrine, no Twig — just `ConsoleBundle` + `SurvosTuiExtrasBundle`.

## Quick start

```bash
composer install
php bin/console browse:files          # file tree of current directory
php bin/console browse:files ../src   # bundle source with syntax highlighting
php bin/console browse:functions      # PHP functions with FTS5 search
```

## Commands

### `browse:files [directory]`

Collapsible file tree (left) with instant syntax-highlighted preview (right).

| Key | Action |
|-----|--------|
| `↑` `↓` `j` `k` | Navigate |
| `→` | Expand directory |
| `←` | Collapse / jump to parent |
| `Enter` `Space` | Expand dir or preview file |
| `q` `ctrl+c` | Quit |

**Options:**

```bash
--pre-expand=N     # expand N levels deep on start (default: 1)
--no-gitignore     # include git-ignored files
--raw              # no syntax highlighting
```

**Supported file types:**

- `.php` — token-based syntax highlighting (`token_get_all`)
- `.json` — pretty-printed and highlighted
- `.md` — full Markdown rendering (headers, bold, lists, code blocks)
- Everything else — `bat`/`batcat` if installed, otherwise plain text

### `browse:functions`

~1,300 PHP internal functions in a paged, searchable table backed by an
in-memory SQLite database with an **FTS5 virtual table**.

| Key | Action |
|-----|--------|
| `↑` `↓` `j` `k` | Navigate rows |
| `/` | Enter live search (FTS5) |
| `Esc` | Clear search |
| `s` | Toggle sort direction |
| `PgDn` `→` | Next page |
| `PgUp` `←` | Previous page |
| `Space` `Enter` | Select (prints function name on exit) |
| `q` `ctrl+c` | Quit |

Try typing `str_` to see all string functions, `array_` for array functions.

## Architecture

This demo uses Symfony 8.1's **HTTP-less application** pattern:

```php
// src/Kernel.php — no FrameworkBundle, no HttpKernel
class Kernel extends AbstractKernel {
    use KernelTrait;

    private function getBundlesDefinition(): array {
        return [
            ConsoleBundle::class => ['all' => true],
            SurvosTuiExtrasBundle::class => ['all' => true],
        ];
    }
}
```

No `config/` directory, no YAML files, no routes. Everything is inline.

## Widgets used

- `TreeWidget` — collapsible tree with `▼`/`►` branches and `──` leaves
- `DataTableWidget` — paged, searchable table with FTS5-aware `SqliteTableSource`
- `DetailPanelWidget` — right-pane preview with title, separator, and markdown support

## Adding your own data source

Implement `TuiTableSourceInterface` and pass it to `DataTableWidget`:

```php
class MySource implements TuiTableSourceInterface {
    public function columns(): array {
        return [
            new TuiColumn(key: 'name', label: 'Name', sortable: true, searchable: true),
            new TuiColumn(key: 'count', label: 'Count', width: 8, format: 'bytes'),
        ];
    }

    public function count(?string $search = null): int { ... }

    public function rows(int $offset = 0, int $limit = 25, ...): iterable { ... }
}

$table = new DataTableWidget(new MySource());
$tui = new Tui(new StyleSheet());
$tui->add($table);
$tui->setFocus($table);
$tui->run();
```
