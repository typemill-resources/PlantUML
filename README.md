# PlantUML Plugin for Typemill

This plugin allows you to generate PlantUML diagrams directly within your [Typemill CMS](https://typemill.net) content. It uses an external PlantUML server (defaulting to the public one) to render the diagrams within the robust flat-file architecture of Typemill.

## Features

- Renders PlantUML diagrams defined in your markdown files.
- Configurable PlantUML server URL (supports self-hosted instances).
- Configurable output format (`SVG` or `PNG`).
- Uses standard fenced code blocks for security and compatibility.

## Requirements

Lates version has been tested with

- Typemill v2.28.0
- PHP 8.5.12 or higher.

## Installation

1.  Download the plugin.
2.  Unzip the content.
3.  Upload the folder `plantuml` to your Typemill plugins directory `plugins`.
4.  The final path should be `your-typemill-installation/plugins/plantuml`.

## Configuration

1.  Login to your Typemill administration dashboard.
2.  Go to **System** -> **Plugins**.
3.  Activate the **PlantUML** plugin.
4.  Click on **Settings** for the PlantUML plugin.
5.  **Server URL**: Enter the base URL of the PlantUML server, including its deployment path. Default is `https://www.plantuml.com/plantuml`. The public host URLs `https://www.plantuml.com` and `https://plantuml.com` are automatically completed with `/plantuml`. Self-hosted URLs retain their configured paths.
6.  **Output Format**: Select `SVG Vector` (recommended) or `PNG Image`.
7.  **Transparent Background**: Check this box to make the background transparent (works best with SVG).
8.  **Border Color**: Enter a color (e.g., `#393b41`, `black`, `red`) to draw a border around the entire image. Leave empty for no border.
9.  Save the settings.

## Usage

### Rendering Diagrams

1. Add a code block with the language identifier `plantuml-diagram`.
2. Write your standard PlantUML code inside the block.
3. You can optionaly provide visual parameters (`align`, `padding`, `size`).

Example:

    ```plantuml-diagram align="center" padding="10px" size="600px"
    @startuml
    Alice -> Bob: Authentication Request
    Bob --> Alice: Authentication Response
    @enduml
    ```

**Supported Parameters:**
* `align`: Alignment of the graphic (e.g. `center`, `left`, `right`). Default is `center`.
* `padding`: CSS padding to add around the graphic (e.g. `20px` or `2rem`).
* `size`: Maximum width (CSS `max-width`) of the graphic in pixels or percentages (e.g. `100%`, `500px`).

The plugin will process any `plantuml-diagram` block and dynamically render it as an image in your browser.

The same `align`, `padding`, and `size` parameters are applied to static ebook images. Quoted CSS values containing spaces, such as `padding="10px 20px"`, are supported. Identical diagrams can have different display parameters without sharing their alignment or size.

Static figures use a full-width block container and an inline-block image so alignment also works in layouts such as Damovo, which otherwise apply `display: table` to figures and `display: block` to images. Padding stays inside the figure width.

### Displaying Code

To display PlantUML code as a code block (without rendering it), use the standard `plantuml` identifier:

    ```plantuml
    @startuml
    Alice -> Bob: This will be displayed as code.
    @enduml
    ```

This allows you to document PlantUML syntax or show examples without the plugin intercepting them.

## License

This plugin is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Author & Attribution

**Typemill CMS:** This plugin is exclusively designed for the [Typemill](https://typemill.net) CMS, an open-source flat-file CMS for text-driven websites established by Trendschau.
**Typemill plantUML Plugin Wrapper:** Created by Mark Klein.
**PlantUML Encoder Engine:** The `PlantUmlEncoder.php` Deflate/Base64 logic is credited to Jawira Portugal (`jawira/plantuml-encoding`), licensed under the MIT License.

- GitHub Repository: [jawira/plantuml-encoding](https://github.com/jawira/plantuml-encoding)

## Export & Ebook Support

This plugin supports export formats like **EPUB**, **PDF**, and **static site exports** via Typemill's `onExportHtmlLoaded` event.

### How It Works

When an export plugin (e.g. Ebooks) requests export-safe HTML, the PlantUML plugin automatically:

1.  Finds all `plantuml-diagram` blocks in the HTML content.
2.  Downloads the rendered image from the configured PlantUML server.
3.  Caches the image under `/cache/generated/plantuml/` using a SHA1 content hash that includes all rendering parameters (server URL, format, transparency, border color).
4.  Replaces the dynamic browser markup with a static `<img>` tag wrapped in a `<figure>` element.

No manual template changes are required. The export plugin simply dispatches `onExportHtmlLoaded` and receives HTML referencing static, locally cached images.

### Ebooks PDF Preview

The Ebooks plugin's **Preview PDF** path does not dispatch `onExportHtmlLoaded`. Only on the `tm/ebooks/preview` route, PlantUML uses `onTwigLoaded` to wrap the existing Slim view in `PlantUmlExportView`. Before a book layout is rendered, this adapter passes each chapter's HTML, source item, metadata, and base URL to the PlantUML plugin's existing `onExportHtmlLoaded` handler through an `OnExportHtmlLoaded` event.

The adapter then renders the prepared book data using the original Twig environment, template loader, and shared default variables. It registers **no Twig extension, filter, function, node visitor, or runtime loader**, and it does not change compiled templates. Existing Twig caches can be used without recompilation. Repeated `onTwigLoaded` events do not wrap the view twice; other routes keep their original view.

The existing `plantuml-diagram` fenced code blocks remain unchanged in Markdown. No changes to the Ebooks plugin or book layouts are needed. Ordinary `plantuml` code blocks and other images are left untouched. Static image generation stays in `onExportHtmlLoaded`; the Slim view adapter only supplies the missing preview call and chapter context.

Typemill's Markdown parser removes additional parameters from fenced-code language declarations. To retain them, PlantUML collects the original declarations with a plugin-local subclass of the installed parser. In the browser, `onMarkdownLoaded` captures parameters without changing the Markdown event data. For ebook chapters, the renderer reads the corresponding Markdown file under the configured content directory, using the chapter context. Folder chapters use `index.md`; copied-page chapters use their referenced source. Parameters are matched to diagram text and occurrence order before generating image markup.

The Markdown hook also receives block arrays in the visual editor. Parameter extraction runs only for string payloads; block arrays and other non-text values are left unchanged and clear any previously captured parameters. This avoids type errors without rewriting editor content.

### Browser Rendering

In the standard browser view, diagrams are rendered dynamically by JavaScript injected by the plugin. The original code remains available in the DOM as a hidden `<pre>` tag. This allows the diagrams to be visually constructed via the external server while preserving the capability for future syntax adjustments.

### Requirements for Export

- The export plugin must dispatch `onExportHtmlLoaded`, or use the Ebooks preview path with `onTwigLoaded` and `chapter.content` output in `@booklayouts/` templates.
- The PlantUML server must be reachable from the host (the plugin checks `allow_url_fopen` and falls back to `curl`).
- Cached assets are stored in `/cache/generated/plantuml/` and can be safely deleted at any time.

### Regression Tests

```console
php plugins/plantuml/tests/server-url.php
php plugins/plantuml/tests/ebook-preview.php
```

The preview test uses the installed Markdown parser, Slim view, Twig, and the unchanged Damovo template. It verifies that no Twig extension or filter is added, the original renderer and defaults remain shared, unrelated routes are unchanged, and the preview calls `onExportHtmlLoaded` with the chapter context. It also checks the documented invocation and parameters from real Markdown, default values, repeated diagrams, SVG/PNG settings, transparency, border color, static image output, repeated events, existing compiled templates, and browser/export compatibility without downloading diagrams or writing production image caches. Temporary chapter fixtures and Twig cache files are created inside the plugin's test directory and removed afterward.

When served locally, `plugins/plantuml/tests/ebook-preview.php?alignment-fixture=1` provides nine layout cases using the original Damovo CSS: left/center/right alignment with intrinsic, pixel-limited, and percentage-limited image sizes. This fixture supports browser position checks in screen and print media without accessing production content or downloading diagrams.

## Version Changelog

### v1.6.3 (2026-10-31)

- Fixed missing PlantUML images in the Ebooks plugin's **Preview PDF**, including the Damovo layout. The plugin-local `PlantUmlExportView` adapter passes chapter HTML and source context to `onExportHtmlLoaded` before rendering, producing static images through the existing cache helper.
- Kept all changes inside the PlantUML plugin. No changes to Typemill core, the Ebooks plugin, book layouts, or stored Markdown are required. No additional Twig extension, filter, function, node visitor, or runtime loader is registered.
- Restricted the view adapter to `tm/ebooks/preview`. It reuses the original renderer, loader, compiled templates, and shared default variables; repeated events do not wrap the view twice. Other routes remain unchanged.
- Preserved the documented `plantuml-diagram` code-block syntax and restored `align`, `padding`, and `size` parameters after the standard Markdown parser discards them. Added plugin-local source extraction for browser and ebook output, including folder chapters and copied-page references, with matching by diagram text and occurrence order.
- Supported quoted CSS values containing spaces and explicit zero values. Identical diagrams can retain different alignment, padding, and size settings. Plain `plantuml` code blocks and fenced syntax examples remain code; language identifiers with additional suffixes are not treated as diagrams.
- Fixed actual image alignment under Damovo CSS: static figures override shrink-to-fit table layout with a full-width block, and their images override block layout with inline-block display and zero margins. Border-box sizing keeps padding inside the available width. Added a browser fixture and CSS regression assertions.
- Fixed a visual-editor `TypeError` in `onMarkdownLoaded`: the event can contain an array of editor blocks rather than a Markdown string. Non-text payloads now skip parameter extraction and clear stale parameter state without changing the event data. Added regression tests using parser-generated block arrays, JSON-decoded draft blocks, and empty values.
- Kept SVG/PNG output, transparency, border color, browser rendering, and the existing export event compatible. Escaped generated style attributes and decoded browser-container attributes when exporting.
- Updated the usage and architecture documentation. Added regression coverage for the unchanged Damovo template, existing Twig caches, export context, renderer defaults, route isolation, and documented parameters. Verification: **119 preview/compatibility checks**, **37 URL checks**, and PHP syntax checks passed. **36 browser position checks** passed with the original Damovo CSS across desktop/mobile widths and screen/print media.
- Manual validation (2026-10-31): The authenticated PlantUML visual-editor page loaded without errors. Following further manual testing, the user confirmed that no errors remained. This manual confirmation is separate from the automated checks above.

### Previous Versions

- **v1.6.2** (2026-10-06): Fixed broken diagram URLs when the public PlantUML server is configured without its `/plantuml` deployment path. Normalizes surrounding whitespace and trailing slashes while preserving self-hosted server paths. Added standalone URL regression tests (`php plugins/plantuml/tests/server-url.php`).
- **v1.6.1** (2026-08-10): Fixed PHP 8.4/8.5 deprecation by explicitly marking the `$params` parameter of `generatePlantUmlUrl()` as nullable (`?array`). Verified compatibility with PHP 8.2, 8.3, 8.4, and 8.5.
- **v1.6.0** (2026-07-15): Added `onExportHtmlLoaded` support for export-safe HTML generation (EPUB/PDF/static). Removed broken Twig filter. Migrated cache from `plugins/plantuml/temp/` to `/cache/generated/plantuml/` via core `generateStaticAsset()` helper. Added composite cache key with all rendering parameters. Added `fetchRemoteImage()` with `allow_url_fopen` check, curl fallback, and 10-second timeout. Fixed CSP port bug for non-standard ports. Simplified regex noise.
- **v1.5.3** (2026-05-30): removed twigFilter because it throws error if already registered.
- **v1.5.2** (2026-05-30): removed twigFilter because it throws error if already registered.
- **v1.5.1** (2026-05-30): Used checkboxlabel for configuration in plugin settings. 
- **v1.5.0** (2026-04-07): Introduced visual markdown block parameters (`align`, `padding`, `size`). Added intelligent offline caching (`temp/`) utilizing MD5 hashing to eliminate redundant remote server queries during eBook generation. Refactored HTML capturing arrays.
- **v1.4.0** (2026-04-07): Refactored rendering logic: Browser viewing now uses injected JavaScript to render diagrams, preserving original syntax within the DOM for future modifications. Ebook (PDF/epub) generation now fetches and embeds graphics as transient local files prior to document assembly to maximize compatibility.
- **v1.3.0** (2025-12-31): Added Ebook support via Twig filter `plantuml`. Implemented automatic centering using `<figure>` tags.
- **v1.2.0** (2025-12-31): Added changelog/history to documentation.
- **v1.1.0** (2025-12-31): Added configuration for transparent background (SVG) and custom border color.
- **v1.0.1** (2025-12-30): Changed syntax to fenced code blocks (` ```plantuml-diagram `) for security and better theme compatibility. Added CSP whitelist support.
- **v1.0.0** (2025-12-30): Initial release.

---
© 2026  by M. Klein
