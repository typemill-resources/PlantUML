# PlantUML Plugin for Typemill

This plugin allows you to generate PlantUML diagrams directly within your [Typemill CMS](https://typemill.net) content. It uses an external PlantUML server (defaulting to the public one) to render the diagrams within the robust flat-file architecture of Typemill.

## Features

- Renders PlantUML diagrams defined in your markdown files.
- Configurable PlantUML server URL (supports self-hosted instances).
- Configurable output format (`SVG` or `PNG`).
- Uses standard fenced code blocks for security and compatibility.

## Requirements

- Typemill v2.21.3 or higher.
- PHP 8.1 or higher.

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
5.  **Server URL**: Enter the URL of the PlantUML server. Default is `http://www.plantuml.com/plantuml`.
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

No Twig filters or manual template changes are required. The export plugin simply dispatches `onExportHtmlLoaded` and receives fully static, self-contained HTML.

### Browser Rendering

In the standard browser view, diagrams are rendered dynamically by JavaScript injected by the plugin. The original code remains available in the DOM as a hidden `<pre>` tag. This allows the diagrams to be visually constructed via the external server while preserving the capability for future syntax adjustments.

### Requirements for Export

- The export plugin must dispatch the `onExportHtmlLoaded` event before generating the final output.
- The PlantUML server must be reachable from the host (the plugin checks `allow_url_fopen` and falls back to `curl`).
- Cached assets are stored in `/cache/generated/plantuml/` and can be safely deleted at any time.

## Version Changelog
- **v1.6.0** (15.07.2026): Added `onExportHtmlLoaded` support for export-safe HTML generation (EPUB/PDF/static). Removed broken Twig filter. Migrated cache from `plugins/plantuml/temp/` to `/cache/generated/plantuml/` via core `generateStaticAsset()` helper. Added composite cache key with all rendering parameters. Added `fetchRemoteImage()` with `allow_url_fopen` check, curl fallback, and 10-second timeout. Fixed CSP port bug for non-standard ports. Simplified regex noise.
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
v1.6.0 | © 2026  by M. Klein
