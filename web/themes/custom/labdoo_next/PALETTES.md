# Learning palette

Learning uses red page titles, green section headings, blue controls and warm
orange subheadings, with yellow decorative accents.
Select **Learning** under **Appearance → Labdoo Next palettes**
(`/admin/appearance/labdoo-next/palettes`), then save to apply it to all visitors.
Adding the preset does not change the site's saved active palette.

| Role | Color | Use |
| --- | --- | --- |
| Title | `#c5221f` | Main page titles |
| Primary | `#1967d2` | Links, buttons and uniform metric values |
| Supporting | `#188038` | Section headings, sidebar headings, filter labels and quote authors |
| Highlight | `#fbbc04` | Title underline and filter top border; never small text on white |
| Accent | `#b45309` | Subheadings, quote rule and link hover |
| Text | `#202124` | Main content |
| Secondary text | `#5f6368` | Labels and supporting information |
| Canvas | `#f8fafd` | Light page background |
| Paper | `#ffffff` | Cards and forms |

Impact metrics use a uniform primary color without multicolor marks. Green
has a stable role in section and sidebar headings, filter groups and quote
authors; orange marks subheadings. Yellow accents the page title and filter
panels. Semaphore artwork, map markers, charts, logos and existing artwork keep
their own colors. Saved palettes can edit supporting and highlight colors;
older palettes fall back to primary/accent without configuration migration.
The title color is independently editable using the existing Title label. Saved
Learning presets without a title color inherit its red; other saved palettes
fall back to their primary color.
The first palette save also invalidates pages that cached fallback Forest.

The stylesheet is maintained directly in `css/labdoo-next.css`. Republish its
immutable copy with `python3 build/build-static.py` from this theme directory
and keep the generated manifest and content-addressed file with the source.

Regression coverage: `tests/phpunit/PaletteManagerTest.php` checks compatibility,
unsafe color rejection and normal-text contrast. `tests/e2e/theme-palette.spec.ts`
checks selecting Learning, colored headings, uniform metrics, English/Spanish
desktop/mobile rendering and persistence of custom supporting/highlight colors. Existing palette
permission, no-JavaScript editing and theme-preview cases remain applicable.
