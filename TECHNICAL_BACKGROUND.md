# Technical Background & Architecture

This document provides an in-depth explanation of the technical architecture and underlying design decisions integrated into the **PlantUML for Typemill** plugin. Its purpose is to guide developers or maintainers understanding how the original static workflows were replaced with a hybrid browser/PDF/ePub rendering approach.

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

## 3. The eBook (PDF/ePub) Conundrum

eBook layout generators (like mPDF or DOMPDF, which Typemill Ebook systems utilize internally) struggle substantially with:
1. JavaScript execution (rendering `div` based elements in realtime).
2. Complex Cross-Origin (CORS) or external URL policies.

If we exposed the raw JS algorithm or pure external urls to the eBook renderer, it would return blank spots.

### Twig Rendering and Transitory Storage (`plugins/plantuml/temp/`)

The plugin registers a standalone Twig Filter (`{{ content | plantuml }}`), designed especially for the static book generators.
When the Ebook plugin triggers this filter to compile its templates, our `processHtmlContent` pipeline parses everything looking for either pristine `pre/code` blocks or our manipulated `div` wrappers.

Once found, it uses PHP's `file_get_contents()` to secretly download the resulting binary from the external PlantUML server (mimicking what the browser handles locally).

**The Storage Location Strategy:**
It saves the file persistently under `plugins/plantuml/temp/`.
*Why `plugins/plantuml/temp/`?* Typemill routes intercept standard media configurations (like `media/files/`) using an `.htaccess` rule that forces file downloads (`application/octet-stream` & `attachment`) when processed through PHP. If we deployed our SVGs inside standard folders, the web-servers would stubbornly refuse to serve them to the internal web-engine as visual `<img>` payloads. By pushing the files strictly to a `temp/` folder inside the isolated `plugins` ecosystem, we ensure Apache/Nginx can serve them smoothly through the correct Native Mime-Types (`image/svg+xml`).

### Smart Cashing
We process the data through an offline cache. Each physical image operates using an MD5 hash calculation derived intimately from the internal base64 Diagram content (`$fileName = md5($imageUrl)`). 
If the file exists locally, the PHP system assumes the Markdown blueprint hasn't shifted and loads the previous asset instead of triggering remote downloads. The cache auto-busts the exact moment a user edits their Typemill syntax.

*Disclaimer: Transient image generations remain permanently cached to circumvent disk fragmentation. Periodic pruning of the `temp/` folder might be necessary depending on server capabilities.*

## 4. Parsedown Parameter Extraction

A core feature is that users can style diagrams directly from standard Fenced tags:
````markdown
```plantuml-diagram align="left" padding="2rem"
````
Typemill's Parsedown interpreter translates extra string parameters onto the CSS `class=""` element associated with the code block wrapper.
Using the internal parsing algorithm (`parseAttributes()`), the plugin identifies the strings (`align=`, `size=`, `padding=`) extracted strictly from the regex catch loops. 

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
