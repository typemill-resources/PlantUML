<?php
// SPDX-License-Identifier: MIT
// Copyright (c) 2026 Mark Klein

namespace plugins\plantuml;

use \typemill\plugin;
use plugins\plantuml\PlantUmlEncoder;

/**
 * Class plantuml
 * 
 * Provides integration between Typemill and an external PlantUML server. 
 * This plugin hooks into the Typemill event system to intercept PlantUML 
 * syntax blocks in markdown and dynamically replaces them with rendered graphics.
 */
class plantuml extends plugin
{
    /**
     * Subscribe to specific Typemill events.
     *
     * @return array Array mapping event names to plugin methods.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onHtmlLoaded'     => 'onHtmlLoaded', // Intercept HTML after markdown translation
            'onCspLoaded'      => 'onCspLoaded',  // Content Security Policy for external images
            'onTwigLoaded'     => 'onTwigLoaded'  // Inject JS to frontend & add Twig filter for Ebooks
        ];
    }

    /**
     * Dynamically whitelist the PlantUML external server in Typemill's Content Security Policy (CSP).
     * This ensures the browser doesn't block the dynamically injected SVGs from the external provider.
     *
     * @param object $csp The CSP event data object.
     */
    public function onCspLoaded($csp)
    {
        $settings = $this->getPluginSettings('plantuml');
        $serverUrl = isset($settings['server_url']) ? $settings['server_url'] : 'https://www.plantuml.com/plantuml';

        // Extract host from URL
        $parsedUrl = parse_url($serverUrl);
        if (isset($parsedUrl['host']))
        {
            $data = $csp->getData();
            $data[] = $parsedUrl['host'];
            $csp->setData($data);
        }
    }

    /**
     * Replaces standard pre-formatted PlantUML code blocks inside the final HTML 
     * with customized divs suitable for JavaScript-driven browser rendering.
     * We do this in onHtmlLoaded instead of onMarkdownLoaded to prevent altering the RAW content
     * presented inside the Typemill Author editor.
     *
     * @param object $plugindata Event data payload containing the parsed HTML.
     */
    public function onHtmlLoaded($plugindata)
    {
        $html = $plugindata->getData();

        // Regex to capture pre/code blocks in HTML with language 'plantuml-diagram' and optional attributes
        // Matches <pre><code class="language-plantuml-diagram align=center size=500">...</code></pre>
        $regex = '/<pre><code class="language-plantuml-diagram(.*?)"(?:.*?)?>\s*(.*?)\s*<\/code><\/pre>/ms';

        $newHtml = preg_replace_callback($regex, array($this, 'processHtmlForBrowser'), $html);

        $plugindata->setData($newHtml);
    }

    /**
     * Executes when Twig initializes. We use this to register:
     * 1. A Twig filter "plantuml" for Ebook/PDF generation algorithms.
     * 2. An inline JavaScript snippet to the actual Web-Frontend to dynamically fetch the server image.
     *
     * @param object $event
     */
    public function onTwigLoaded($event)
    {

/* This throws an error if twig has already been initialized by another plugin.

        // Filter for e-book plugins to grab the rendered HTML structure
        $this->addTwigFilter('plantuml', function($content) {
            return $this->processHtmlContent($content);
        });
*/

        // Inject JS script into front-end to render the PlantUML diagrams within the browser.
        // Bypassed during admin/editor routes to avoid manipulating the editor's live preview.
        if (!$this->adminroute && !$this->editorroute) {
            $this->addInlineJS('
                document.addEventListener("DOMContentLoaded", function() {
                    var items = document.querySelectorAll(".plantuml-browser-render");
                    items.forEach(function(item) {
                        var url = item.getAttribute("data-plantuml-url");
                        if (url) {
                            var img = document.createElement("img");
                            img.src = url;
                            img.alt = "PlantUML diagram";
                            img.className = "plantuml-diagram-image";
                            
                            // Retrieve and apply dynamic CSS sizes
                            var size = item.getAttribute("data-plantuml-size");
                            if (size && size !== "") {
                                img.style.maxWidth = size;
                                img.style.width = "100%";
                            } else {
                                img.style.maxWidth = "100%";
                            }

                            // Apply formatting alignment padding inherited from attributes
                            var align = item.getAttribute("data-plantuml-align");
                            if (align) item.style.textAlign = align;

                            var padding = item.getAttribute("data-plantuml-padding");
                            if (padding) item.style.padding = padding;

                            item.appendChild(img);
                        }
                    });
                });
            ');
        }
    }

    /**
     * Interceptor used by the {{ content | plantuml }} Twig filter (specifically during Ebook/PDF runs).
     * Finds PlantUML blocks and triggers local caching algorithms.
     */
    private function processHtmlContent($content)
    {
        // For Ebook/PDF, the Twig filter might encounter the original <pre> code block (if Ebook plugin skips onHtmlLoaded)
        // OR it might encounter the div wrapper (if onHtmlLoaded fired first). We handle both via distinct regexes!

        // Regex 1: Match raw pre/code blocks with optional attributes
        $regex1 = '/<pre><code class="language-plantuml-diagram(.*?)"(?:.*?)?>\s*(.*?)\s*<\/code><\/pre>/ms';
        $content = preg_replace_callback($regex1, array($this, 'processRawMatchesLocalHtml'), $content);

        // Regex 2: Match the browser-rendered div structure we inject via onHtmlLoaded
        $regex2 = '/<div class="plantuml-browser-render"\s+data-plantuml-url="(.*?)"\s*(.*?)>(.*?)<\/div>/ms';
        $content = preg_replace_callback($regex2, array($this, 'processHtmlMatches'), $content);

        return $content;
    }

    /**
     * Parses inline pseudo-attributes embedded inside the markdown code block initialization line.
     * e.g., ```plantuml-diagram align="left" padding="2rem"
     * 
     * @param string $attrString Extracted attribute substring
     * @return array Extracted key-pair configuration settings.
     */
    private function parseAttributes($attrString)
    {
        $defaults = ['align' => 'center', 'padding' => '', 'size' => ''];
        if (empty(trim($attrString))) return $defaults;

        $rules = ['align', 'padding', 'size'];
        foreach ($rules as $rule) {
            // Regex match specific rules surrounded by empty spacing ignoring case.
            if (preg_match('/(?:^|\s)' . $rule . '=["\']?([^"\'\s]+)["\']?(?:\s|$)/i', $attrString, $m)) {
                $defaults[$rule] = $m[1];
            }
        }
        return $defaults;
    }

    /**
     * Handler for the Twig Filter targeting Raw Markdown.
     * Takes standard Parsedown code blocks and replaces them directly with the cached PDF image structure.
     */
    private function processRawMatchesLocalHtml($matches)
    {
        if (!empty($matches[2])) {
            $attrs = $this->parseAttributes($matches[1]);
            $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
            $imageUrl = $this->generatePlantUmlUrl($code);
            return $this->generateLocalPlantUmlHtml($imageUrl, $attrs);
        }
        return $matches[0];
    }

    /**
     * Handler for the Twig Filter targeting pre-modified DOM blocks.
     * Overrides browser-based JS structures with a direct static image format during eBook generation.
     */
    private function processHtmlMatches($matches)
    {
        if (!empty($matches[1])) {
            $imageUrl = $matches[1];
            // Decode any entities from URL
            $imageUrl = htmlspecialchars_decode($imageUrl, ENT_QUOTES);
            
            // Extract the attributes from the div tags we stored earlier
            $attrs = $this->parseAttributes($matches[2]);
            return $this->generateLocalPlantUmlHtml($imageUrl, $attrs);
        }

        return $matches[0];
    }

    /**
     * Parses the HTML payload replacing standard PlantUML tags with `div.plantuml-browser-render`.
     * Caches the original text into a hidden `pre` item inside the structure to not lose data for the editor.
     */
    private function processHtmlForBrowser($matches)
    {
        if (!empty($matches[2])) {
            $attrs = $this->parseAttributes($matches[1]);
            // Because Parsedown encodes the HTML, we must decode it first to generate the correct plantuml code URL.
            $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
            $imageUrl = $this->generatePlantUmlUrl($code);
            
            // Return HTML container with generated URL and attributes.
            $alignStr = ' data-plantuml-align="' . htmlspecialchars($attrs['align']) . '"';
            $paddingStr = !empty($attrs['padding']) ? (' data-plantuml-padding="' . htmlspecialchars($attrs['padding']) . '"') : '';
            $sizeStr = !empty($attrs['size']) ? (' data-plantuml-size="' . htmlspecialchars($attrs['size']) . '"') : '';
            
            // Apply a default text-align strictly to the div as well, inline, to avoid FOUC before JS loads.
            $styleStr = ' style="text-align: ' . htmlspecialchars($attrs['align']) . ';"';

            $html = '<div class="plantuml-browser-render" data-plantuml-url="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '"' . $alignStr . $paddingStr . $sizeStr . $styleStr . '>' . "\n";
            $html .= '<pre class="plantuml-original-code" style="display:none;"><code>' . htmlspecialchars($code, ENT_QUOTES) . '</code></pre>' . "\n";
            $html .= '</div>';
            
            return $html;
        }

        return $matches[0];
    }

    /**
     * Generates an external URI payload reflecting the PlantUML architecture.
     * Evaluates transparent background and border settings.
     * Uses `PlantUmlEncoder` to base64 translate the data using DEFALTE algorithms.
     */
    private function generatePlantUmlUrl($code)
    {
         // Get settings
        $settings = $this->getPluginSettings('plantuml');
        
        $serverUrl = isset($settings['server_url']) ? rtrim($settings['server_url'], '/') : 'https://www.plantuml.com/plantuml';
        $format = isset($settings['output_format']) ? $settings['output_format'] : 'svg';
        $transparent = isset($settings['transparent_background']) ? $settings['transparent_background'] : false;
        $borderColor = isset($settings['border_color']) ? trim($settings['border_color']) : '';

        // Trim whitespace
        $code = trim($code);

        // Remove @startuml and @enduml if present (though not strictly required inside the block, nice to have)
        $code = str_replace(['@startuml', '@enduml'], '', $code);

        // Add transparent background for SVG if requested AND enabled
        if ($format === 'svg' && $transparent) {
            $code = "skinparam backgroundcolor transparent\n" . $code;
        }

        // Add border if configured
        if (!empty($borderColor)) {
            $code = "skinparam DiagramBorderColor $borderColor\nskinparam DiagramBorderThickness 1\nskinparam pageBorderColor $borderColor\nskinparam pageMargin 10\n" . $code;
        }
        
        // Encode
        require_once __DIR__ . '/PlantUmlEncoder.php';
        $encoder = new PlantUmlEncoder();
        $encoded = $encoder->encode($code);

        // Build URL
        return $serverUrl . '/' . $format . '/' . $encoded;
    }

    private function generatePlantUmlImage($code)
    {
        $imageUrl = $this->generatePlantUmlUrl($code);
        return "![PlantUML diagram]($imageUrl)";
    }

    /**
     * Downloads and permanently caches the PlantUML graphic into a local disk structure. 
     * Necessary to supply eBooks (mPDF, DomPDF) with raw embedded objects rather than Cross-Origin 3rd-party domains.
     * The `temp` directory avoids internal Typemill Download wrappers.
     */
    private function generateLocalPlantUmlHtml($imageUrl, $attributes = [])
    {
        // Pre-create the image locally for Ebook PDF/ePub generation.
        $targetDir = __DIR__ . '/temp/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        $extension = (strpos($imageUrl, '/png/') !== false) ? 'png' : 'svg';
        // Unique file name based on URL, prevents infinite accumulation while updating the image contents.
        $fileName = 'plantuml_' . md5($imageUrl) . '.' . $extension;
        $localPath = $targetDir . $fileName;
        
        // Cache the image download. Only download if the file doesn't exist.
        if (!file_exists($localPath)) {
            $context = stream_context_create([
                "http" => [
                    "header" => "User-Agent: Typemill-PlantUML-Plugin\r\n"
                ]
            ]);
            
            $imageContent = file_get_contents($imageUrl, false, $context);
            if ($imageContent !== false) {
                file_put_contents($localPath, $imageContent);
            }
        }
        
        // Output local file path for PDF rendering. Must include baseurl so it doesn't break on subroutes.
        $baseUrl = isset($this->urlinfo['baseurl']) ? rtrim($this->urlinfo['baseurl'], '/') : '';
        $localUrl = $baseUrl . '/plugins/plantuml/temp/' . $fileName;

        // Apply attributes
        $align = $attributes['align'] ?? 'center';
        $padding = $attributes['padding'] ?? '';
        $size = $attributes['size'] ?? '';
        
        $figureStyle = "text-align: {$align};" . (!empty($padding) ? " padding: {$padding};" : "") . " margin: 0;";
        $imgStyle = !empty($size) ? "max-width: {$size}; width: 100%;" : "max-width: 100%;";
        
        return '<figure style="' . $figureStyle . '"><img src="' . $localUrl . '" alt="PlantUML diagram" class="plantuml-diagram" style="' . $imgStyle . '" /></figure>';
    }
}
