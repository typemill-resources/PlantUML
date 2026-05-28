# PlantUML Plugin for Typemill

This plugin allows you to generate PlantUML diagrams directly within your [Typemill CMS](https://typemill.net) content. It uses an external PlantUML server (defaulting to the public one) to render the diagrams within the robust flat-file architecture of Typemill.

## Features

- Renders PlantUML diagrams defined in your markdown files.
- Configurable PlantUML server URL (supports self-hosted instances).
- Configurable output format (`SVG` or `PNG`).
- Uses standard fenced code blocks for security and compatibility.

## Requirements

- Typemill v2.21.3 or higher.
- PHP 7.4 or higher.

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

## Ebook Support

This plugin supports Ebook generation (PDF/ePub) via the **Ebooks** plugin.

### Automatic Rendering

In standard Typemill pages, diagrams are rendered automatically via Markdown hooks.

### Ebook Layouts

For PDF/ePub generation, the plugin provides a Twig filter `plantuml` to render diagrams within your book layouts.

Example usage in a layout template (e.g., `index.twig`):

    {{ chapter.content | plantuml }}

This will:
1.  Find PlantUML diagram wrappers in the HTML content.
2.  Download the rendered image from the PlantUML server.
3.  Save the image locally (e.g., `plugins/plantuml/temp/plantuml_hash.svg`) to ensure maximum compatibility with PDF/ePub generators. The image is only downloaded if it doesn't exist yet (smart offline caching based on the underlying diagram content hash).
4.  Wrap the local image tag in a `<figure>` tag for automatic centering (if supported by the layout CSS).

### Browser Rendering

In the standard browser view, diagrams are no longer converted into static Markdown images. Instead, they are rendered dynamically by JavaScript injected by the plugin. The original code remains available in the DOM as a hidden `<pre>` tag. This allows the diagrams to be visually constructed via the external server while preserving the capability for future syntax adjustments.

## Version Changelog

- **v1.5.0** (2026-04-07): Introduced visual markdown block parameters (`align`, `padding`, `size`). Added intelligent offline caching (`temp/`) utilizing MD5 hashing to eliminate redundant remote server queries during eBook generation. Refactored HTML capturing arrays.
- **v1.4.0** (2026-04-07): Refactored rendering logic: Browser viewing now uses injected JavaScript to render diagrams, preserving original syntax within the DOM for future modifications. Ebook (PDF/epub) generation now fetches and embeds graphics as transient local files prior to document assembly to maximize compatibility.
- **v1.3.0** (2025-12-31): Added Ebook support via Twig filter `plantuml`. Implemented automatic centering using `<figure>` tags.
- **v1.2.0** (2025-12-31): Added changelog/history to documentation.
- **v1.1.0** (2025-12-31): Added configuration for transparent background (SVG) and custom border color.
- **v1.0.1** (2025-12-30): Changed syntax to fenced code blocks (` ```plantuml-diagram `) for security and better theme compatibility. Added CSP whitelist support.
- **v1.0.0** (2025-12-30): Initial release.

---
v1.5.0 | © 2026  by M. Klein
