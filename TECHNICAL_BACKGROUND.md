# Technical Background & Architecture

This document provides an in-depth explanation of the technical architecture and underlying design decisions integrated into the **PlantUML for Typemill** plugin. Its purpose is to guide developers or maintainers understanding how the original static workflows were replaced with a hybrid browser/PDF/ePub rendering approach.

Architecture documented for **version 1.6.3 (2026-10-31)**. The complete release summary and verification status are listed in the README changelog.

## 1. The Typemill Markdown Lifecycle

Typemill uses `Parsedown` to interpret and compile Markdown `.md` text into standard HTML. Historically, plugins (including older versions of this PlantUML plugin) used the `onMarkdownLoaded` event.

### The Problem with `onMarkdownLoaded`
The `onMarkdownLoaded` event modifies the raw markdown text *before* any core systems process it. This had a critical flaw inside the Authoring Interface: If the plugin intercepted the diagram block (` ```plantuml-diagram `) and replaced it with a static `div` or image, this substitution was treated as the *new* document truth. 
When the Typemill Editor later accessed the "markdown source", it displayed the injected `div` container instead of the original code, effectively destroying the user's diagram code and making subsequent modifications impossible.

### The Solution: `onHtmlLoaded`
To fix the data loss issue, the execution hook was shifted to `onHtmlLoaded`. 
At this stage in the Typemill pipeline, Parsedown has already generated a raw HTML node corresponding to the markdown structure: `<pre><code class="language-plantuml-diagram">...</code></pre>`.

The plugin safely intercepts this parsed HTML block using a targeted Regular Expression (`preg_replace_callback`). It reads the `code` value, handles the API formatting, and replaces the block using an external `div` wrapper, while preserving the raw PlantUML text embedded via a *hidden* `<pre>` tag.

*Because this substitution occurs distinctly on the compiled HTML frontend layer, the underlying raw `.md` files on the user's disk file system remain 100% untouched. The Typemill editor loads the authentic code seamlessly.*

## 2. In-Browser Dynamic Rendering

By default, we want the Web performance to be optimized and seamless without unnecessary disk footprints. Therefore, normal Web views bypass file downloads entirely.

When `onHtmlLoaded` catches a diagram, it transforms it into an unpopulated structure:
```html
<div class="plantuml-browser-render" data-plantuml-url="https://www.plantuml.com/... ">
```

The plugin utilizes Typemill's `addInlineJS()` method to inject a small, lightweight client-side listener. 
Upon DOM load, the JavaScript scans for elements bearing the `plantuml-browser-render` class. It then extracts the `data-plantuml-url` attribute, dynamically generates a standard `<img>` node, passes any embedded optional alignment parameters (`padding`, `align`, `size`), and attaches it to the DOM.

This guarantees cross-origin independence while keeping browser operations lean. 

## 3. Static eBook Images

The `onExportHtmlLoaded` handler replaces diagram code blocks or browser-render containers with static `<figure><img ...></figure>` markup. Image downloads happen on the PHP host, using `file_get_contents()` with a curl fallback. The host must be able to reach the configured PlantUML server; no JavaScript is needed to construct the exported images.

### PDF Preview Without Twig Extensions

The Ebooks preview does not emit `onExportHtmlLoaded`. On the `tm/ebooks/preview` route only, `onTwigLoaded` installs a plugin-local Slim view adapter, `PlantUmlExportView`. Before rendering a book layout, the adapter sends each chapter's HTML and source context to the existing export handler. It then uses the original Twig environment and loader to render the prepared data.

No additional Twig extension, filter, function, node visitor, or runtime loader is registered. Default view variables remain shared with the original Slim view, existing compiled templates remain usable, and repeated events do not install nested adapters. The Ebooks plugin and layout files remain unchanged.

### Image Cache

Typemill's `generateStaticAsset()` helper stores images under `/cache/generated/plantuml/`. The SHA1 cache key includes the diagram source, server URL, output format, transparency, and border color. Existing images are reused without another download. Display-only parameters such as alignment and maximum width are applied to the HTML rather than the shared image bytes.

## 4. Parsedown Parameter Extraction

A core feature is that users can style diagrams directly from standard Fenced tags:
````markdown
```plantuml-diagram align="left" padding="2rem"
````
Typemill's Parsedown interpreter keeps only the language identifier in the code block's CSS class and discards the additional parameters. A plugin-local subclass of the installed parser collects the original fenced declarations without replacing or saving Markdown. For browser pages, the read-only `onMarkdownLoaded` hook supplies the source. For ebook chapters, the export handler reads the chapter's Markdown file using the supplied source context.

The plugin matches parameters to diagram text and occurrence order, so identical diagrams can retain different display settings. `parseAttributes()` handles `align`, `size`, and `padding`, including quoted CSS values containing spaces.

These configuration keys are actively transposed directly into the respective HTML node styles to maximize identical behavior between offline PDFs and active web-pages.

## 5. Third-Party Attributions

**Typemill CMS:**
The backbone architecture and event pipeline this plugin relies upon originates exclusively from [Typemill](https://typemill.net), the sophisticated open-source flat-file CMS originally created by Trendschau.

**PlantUML Encoded Processing:**
PlantUML uses a distinct compression algorithm via Deflate scaling and a custom 6-bit Base64-like character map to translate raw markdown diagram strings into scalable URL paths. To maintain a zero external PHP dependency footprint within Typemill plugins, this logic is natively mapped within `PlantUmlEncoder.php`.

The internal encoding script is directly adopted from the open-source community:
**Author:** Jawira Portugal (`jawira/plantuml-encoding`)
**License:** MIT License
**Repository:** [https://github.com/jawira/plantuml-encoding](https://github.com/jawira/plantuml-encoding)

## Conclusion
The resultant behavior is a zero-conflict bridge between Typemill's editor routing, Apache's file-stream strictness, and PDF/ePub compiling dependencies, using zero overrides to core files.
