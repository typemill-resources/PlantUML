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
            'onHtmlLoaded'       => 'onHtmlLoaded',
            'onExportHtmlLoaded' => 'onExportHtmlLoaded',
            'onCspLoaded'        => 'onCspLoaded',
            'onTwigLoaded'       => 'onTwigLoaded',
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

        $parsedUrl = parse_url($serverUrl);
        if (isset($parsedUrl['host']))
        {
            $data = $csp->getData();
            $host = $parsedUrl['host'];
            if (isset($parsedUrl['port']))
            {
                $host .= ':' . $parsedUrl['port'];
            }
            $data[] = $host;
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

        $regex = '/<pre><code class="language-plantuml-diagram(.*?)"[^>]*>\s*(.*?)\s*<\/code><\/pre>/ms';

        $newHtml = preg_replace_callback($regex, array($this, 'processHtmlForBrowser'), $html);

        $plugindata->setData($newHtml);
    }

    /**
     * Generates export-safe HTML by replacing browser-render divs with static <img> tags.
     * Assets are cached under /cache/generated/plantuml/ via the core generateStaticAsset helper.
     *
     * @param object $event The export HTML event.
     */
    public function onExportHtmlLoaded($event)
    {
        $data = $event->getData();
        $html = is_array($data) ? $data['html'] : $data;

        // Handle raw <pre><code> blocks (if export plugin skipped onHtmlLoaded)
        $regex1 = '/<pre><code class="language-plantuml-diagram(.*?)"[^>]*>\s*(.*?)\s*<\/code><\/pre>/ms';
        $html = preg_replace_callback($regex1, function ($matches) {
            $attrs = $this->parseAttributes($matches[1]);
            $code  = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
            return $this->renderStaticPlantUml($code, $attrs);
        }, $html);

        // Handle already-transformed browser-render divs
        $regex2 = '/<div class="plantuml-browser-render"[^>]*>.*?<pre class="plantuml-original-code"[^>]*><code>(.*?)<\/code><\/pre>.*?<\/div>/ms';
        $html = preg_replace_callback($regex2, function ($matches) {
            $code = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

            $attrs = [];
            if (preg_match('/data-plantuml-align="([^"]*)"/', $matches[0], $m)) $attrs['align'] = $m[1];
            if (preg_match('/data-plantuml-padding="([^"]*)"/', $matches[0], $m)) $attrs['padding'] = $m[1];
            if (preg_match('/data-plantuml-size="([^"]*)"/', $matches[0], $m)) $attrs['size'] = $m[1];
            $attrs += ['align' => 'center', 'padding' => '', 'size' => ''];

            return $this->renderStaticPlantUml($code, $attrs);
        }, $html);

        if (is_array($data))
        {
            $data['html'] = $html;
            $event->setData($data);
        }
        else
        {
            $event->setData($html);
        }
    }

    /**
     * Executes when Twig initializes. Injects an inline JavaScript snippet
     * to the Web-Frontend to dynamically fetch and display PlantUML server images.
     *
     * @param object $event
     */
    public function onTwigLoaded($event)
    {
        if (!$this->adminroute && !$this->editorroute)
        {
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
                                
                            var size = item.getAttribute("data-plantuml-size");
                            if (size && size !== "") {
                                img.style.maxWidth = size;
                                img.style.width = "100%";
                            } else {
                                img.style.maxWidth = "100%";
                            }

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
            if (preg_match('/(?:^|\s)' . $rule . '=["\']?([^"\'\s]+)["\']?(?:\s|$)/i', $attrString, $m)) {
                $defaults[$rule] = $m[1];
            }
        }
        return $defaults;
    }

    /**
     * Parses the HTML payload replacing standard PlantUML tags with `div.plantuml-browser-render`.
     * Caches the original text into a hidden `pre` item inside the structure to not lose data for the editor.
     */
    private function processHtmlForBrowser($matches)
    {
        if (!empty($matches[2])) {
            $attrs = $this->parseAttributes($matches[1]);
            $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
            $imageUrl = $this->generatePlantUmlUrl($code);
            
            $alignStr = ' data-plantuml-align="' . htmlspecialchars($attrs['align']) . '"';
            $paddingStr = !empty($attrs['padding']) ? (' data-plantuml-padding="' . htmlspecialchars($attrs['padding']) . '"') : '';
            $sizeStr = !empty($attrs['size']) ? (' data-plantuml-size="' . htmlspecialchars($attrs['size']) . '"') : '';
            
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
     * Uses `PlantUmlEncoder` to base64 translate the data using DEFLATE algorithms.
     */
    private function generatePlantUmlUrl($code, array $params = null)
    {
        if ($params === null)
        {
            $settings = $this->getPluginSettings('plantuml');
            $params = [
                'server_url'             => isset($settings['server_url']) ? rtrim($settings['server_url'], '/') : 'https://www.plantuml.com/plantuml',
                'format'                 => isset($settings['output_format']) ? $settings['output_format'] : 'svg',
                'transparent_background' => isset($settings['transparent_background']) ? $settings['transparent_background'] : false,
                'border_color'           => isset($settings['border_color']) ? trim($settings['border_color']) : '',
            ];
        }

        $serverUrl   = $params['server_url'];
        $format      = $params['format'];
        $transparent = $params['transparent_background'];
        $borderColor = $params['border_color'];

        $code = trim($code);
        $code = str_replace(['@startuml', '@enduml'], '', $code);

        if ($format === 'svg' && $transparent) {
            $code = "skinparam backgroundcolor transparent\n" . $code;
        }

        if (!empty($borderColor)) {
            $code = "skinparam DiagramBorderColor $borderColor\nskinparam DiagramBorderThickness 1\nskinparam pageBorderColor $borderColor\nskinparam pageMargin 10\n" . $code;
        }
        
        $encoder = new PlantUmlEncoder();
        $encoded = $encoder->encode($code);

        return $serverUrl . '/' . $format . '/' . $encoded;
    }

    /**
     * Renders a PlantUML diagram to a static asset for export (EPUB/PDF/static).
     * Uses the core generateStaticAsset helper for deterministic caching under /cache/generated/.
     */
    private function renderStaticPlantUml(string $code, array $attrs): string
    {
        $settings = $this->getPluginSettings('plantuml');
        
        $cacheKey = json_encode([
            'code'                 => $code,
            'server_url'           => $settings['server_url']  ?? 'https://www.plantuml.com/plantuml',
            'format'               => $settings['output_format'] ?? 'svg',
            'transparent_background' => $settings['transparent_background'] ?? false,
            'border_color'         => $settings['border_color'] ?? '',
        ]);
        
        $extension = $settings['output_format'] ?? 'svg';

        $url = $this->generateStaticAsset(
            $cacheKey,
            function ($key) {
                $params = json_decode($key, true);
                $remoteUrl = $this->generatePlantUmlUrl($params['code'], $params);
                $content = $this->fetchRemoteImage($remoteUrl);
                
                if ($content === false) {
                    return '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="40"><text x="10" y="25">PlantUML render error</text></svg>';
                }
                
                return $content;
            },
            $extension
        );

        return $this->buildFigureHtml($url, $attrs);
    }

    /**
     * Fetches a remote image with fallback from file_get_contents to curl.
     * Respects allow_url_fopen and applies a 10-second timeout.
     */
    private function fetchRemoteImage(string $url): string|false
    {
        if (ini_get('allow_url_fopen'))
        {
            $context = stream_context_create([
                'http' => [
                    'header'  => "User-Agent: Typemill-PlantUML-Plugin\r\n",
                    'timeout' => 10,
                ]
            ]);
            $result = @file_get_contents($url, false, $context);
            if ($result !== false)
            {
                return $result;
            }
        }
        
        if (function_exists('curl_init'))
        {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Typemill-PlantUML-Plugin');
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($result !== false && $httpCode >= 200 && $httpCode < 300)
            {
                return $result;
            }
        }
        
        return false;
    }

    /**
     * Builds the final <figure> HTML for a static PlantUML image.
     */
    private function buildFigureHtml(string $imageUrl, array $attributes = []): string
    {
        $align   = $attributes['align'] ?? 'center';
        $padding = $attributes['padding'] ?? '';
        $size    = $attributes['size'] ?? '';
        
        $figureStyle = "text-align: {$align};" . (!empty($padding) ? " padding: {$padding};" : "") . " margin: 0;";
        $imgStyle    = !empty($size) ? "max-width: {$size}; width: 100%;" : "max-width: 100%;";
        
        return '<figure style="' . $figureStyle . '"><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '" alt="PlantUML diagram" class="plantuml-diagram" style="' . $imgStyle . '" /></figure>';
    }
}
