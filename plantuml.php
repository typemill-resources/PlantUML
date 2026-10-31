<?php
// SPDX-License-Identifier: MIT
// Copyright (c) 2026 Mark Klein

namespace Plugins\plantuml;

use \Typemill\Plugin;
use Plugins\plantuml\PlantUmlEncoder;

/**
 * Class plantuml
 * 
 * Provides integration between Typemill and an external PlantUML server. 
 * This plugin hooks into the Typemill event system to intercept PlantUML 
 * syntax blocks in markdown and dynamically replaces them with rendered graphics.
 */
class plantuml extends Plugin
{
    private array $markdownAttributes = [];

    /**
     * Subscribe to specific Typemill events.
     *
     * @return array Array mapping event names to plugin methods.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onMarkdownLoaded'   => 'onMarkdownLoaded',
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

    public function onMarkdownLoaded($event)
    {
        $markdown = $event->getData();
        $this->markdownAttributes = is_string($markdown) ? $this->collectMarkdownAttributes($markdown) : [];
    }

    private function collectMarkdownAttributes(string $markdown): array
    {
        require_once __DIR__ . '/PlantUmlMarkdown.php';

        $parser = new PlantUmlMarkdown();
        $parser->markup($parser->text($markdown));
        $attributes = [];
        foreach ($parser->diagrams as $diagram)
        {
            $attributes[$this->diagramKey($diagram['code'])][] = $this->parseAttributes($diagram['parameters']);
        }

        return $attributes;
    }

    private function diagramKey(string $code): string
    {
        return hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", $code)));
    }

    private function resolveAttributes(string $code, string $parameters, array &$attributes): array
    {
        $key = $this->diagramKey($code);
        if (!empty($attributes[$key]))
        {
            return array_shift($attributes[$key]);
        }

        return $this->parseAttributes($parameters);
    }

    public function onHtmlLoaded($plugindata)
    {
        $html = $plugindata->getData();

        $regex = '/<pre><code class="language-plantuml-diagram(?=\s|")(.*?)"[^>]*>\s*(.*?)\s*<\/code><\/pre>/ms';

        $attributes = $this->markdownAttributes;
        $newHtml = preg_replace_callback($regex, function ($matches) use (&$attributes) {
            return $this->processHtmlForBrowser($matches, $attributes);
        }, $html);

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

        $html = $this->renderExportHtml($html, is_array($data) ? $data : []);

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

    private function getChapterMarkdown(array $chapter): ?string
    {
        $item = $chapter['item'] ?? [];
        if (empty($item['path']))
        {
            return null;
        }

        $settings = $this->getSettings();
        $path = $item['path'];
        $meta = $chapter['metadata']['meta'] ?? [];
        if (($meta['referencetype'] ?? '') === 'copy' && !empty($meta['reference']))
        {
            $navigation = new \Typemill\Models\Navigation();
            $language = $settings['langattr'];
            $page = $navigation->getPageInfoForUrl(trim($meta['reference'], '/'), $this->urlinfo, $language);
            if ($page)
            {
                $tree = $navigation->getFullDraftNavigation($this->urlinfo, $language);
                $reference = $navigation->getItemWithKeyPath($tree, explode('.', $page['keyPath']));
                if ($reference)
                {
                    $path = $reference->path;
                }
            }
        }

        if (($item['elementType'] ?? '') === 'folder')
        {
            $path = rtrim($path, '/\\') . '/index.md';
        }

        $root = realpath($settings['rootPath'] . '/content');
        $file = $root ? realpath($root . '/' . ltrim($path, '/\\')) : false;
        if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_readable($file))
        {
            return null;
        }

        $markdown = file_get_contents($file);
        return $markdown === false ? null : $markdown;
    }

    public function renderExportHtml(string $html, array $chapter = []): string
    {
        if (!str_contains($html, 'language-plantuml-diagram') && !str_contains($html, 'plantuml-browser-render'))
        {
            return $html;
        }

        $markdown = $this->getChapterMarkdown($chapter);
        $attributes = $markdown !== null ? $this->collectMarkdownAttributes($markdown) : $this->markdownAttributes;

        // Handle raw <pre><code> blocks (if export plugin skipped onHtmlLoaded)
        $regex1 = '/<pre><code class="language-plantuml-diagram(?=\s|")(.*?)"[^>]*>\s*(.*?)\s*<\/code><\/pre>/ms';
        $html = preg_replace_callback($regex1, function ($matches) use (&$attributes) {
            $code  = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
            $attrs = $this->resolveAttributes($code, $matches[1], $attributes);
            return $this->renderStaticPlantUml($code, $attrs);
        }, $html);

        // Handle already-transformed browser-render divs
        $regex2 = '/<div class="plantuml-browser-render"[^>]*>.*?<pre class="plantuml-original-code"[^>]*><code>(.*?)<\/code><\/pre>.*?<\/div>/ms';
        $html = preg_replace_callback($regex2, function ($matches) {
            $code = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

            $attrs = [];
            if (preg_match('/data-plantuml-align="([^"]*)"/', $matches[0], $m)) $attrs['align'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            if (preg_match('/data-plantuml-padding="([^"]*)"/', $matches[0], $m)) $attrs['padding'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            if (preg_match('/data-plantuml-size="([^"]*)"/', $matches[0], $m)) $attrs['size'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            $attrs += ['align' => 'center', 'padding' => '', 'size' => ''];

            return $this->renderStaticPlantUml($code, $attrs);
        }, $html);

        return $html;
    }

    /**
     * Executes when Twig initializes. Injects an inline JavaScript snippet
     * to the Web-Frontend to dynamically fetch and display PlantUML server images.
     *
     * @param object $event
     */
    public function onTwigLoaded($event)
    {
        if (trim($this->route, '/') === 'tm/ebooks/preview')
        {
            require_once __DIR__ . '/PlantUmlExportView.php';

            $view = $this->getTwig();
            if (!$view instanceof PlantUmlExportView)
            {
                $this->container->set('view', new PlantUmlExportView($view, [$this, 'onExportHtmlLoaded']));
            }
        }

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
        $attrString = html_entity_decode($attrString, ENT_QUOTES | ENT_HTML5);
        if (empty(trim($attrString))) return $defaults;

        $rules = ['align', 'padding', 'size'];
        foreach ($rules as $rule) {
            if (preg_match('/(?:^|\s)' . $rule . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))(?=\s|$)/i', $attrString, $m, PREG_UNMATCHED_AS_NULL)) {
                $defaults[$rule] = $m[1] ?? $m[2] ?? $m[3];
            }
        }
        return $defaults;
    }

    /**
     * Parses the HTML payload replacing standard PlantUML tags with `div.plantuml-browser-render`.
     * Caches the original text into a hidden `pre` item inside the structure to not lose data for the editor.
     */
    private function processHtmlForBrowser($matches, array &$attributes)
    {
        if (!empty($matches[2])) {
            $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
            $attrs = $this->resolveAttributes($code, $matches[1], $attributes);
            $imageUrl = $this->generatePlantUmlUrl($code);
            
            $alignStr = ' data-plantuml-align="' . htmlspecialchars($attrs['align']) . '"';
            $paddingStr = $attrs['padding'] !== '' ? (' data-plantuml-padding="' . htmlspecialchars($attrs['padding']) . '"') : '';
            $sizeStr = $attrs['size'] !== '' ? (' data-plantuml-size="' . htmlspecialchars($attrs['size']) . '"') : '';
            
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
    private function generatePlantUmlUrl($code, ?array $params = null)
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

        $serverUrl   = $this->normalizeServerUrl($params['server_url']);
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

    private function normalizeServerUrl(string $serverUrl): string
    {
        $serverUrl = rtrim(trim($serverUrl), '/');
        $parts = parse_url($serverUrl);

        if (is_array($parts)
            && in_array(strtolower($parts['host'] ?? ''), ['plantuml.com', 'www.plantuml.com'], true)
            && empty($parts['path'])
            && !isset($parts['query'])
            && !isset($parts['fragment']))
        {
            $serverUrl .= '/plantuml';
        }

        return $serverUrl;
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
        
        $figureStyle = "display: block; width: 100%; box-sizing: border-box; text-align: {$align};" . ($padding !== '' ? " padding: {$padding};" : "") . " margin: 0;";
        $imgStyle    = 'display: inline-block; vertical-align: middle; margin: 0; ' . ($size !== '' ? "max-width: {$size}; width: 100%;" : "max-width: 100%;");
        
        return '<figure style="' . htmlspecialchars($figureStyle, ENT_QUOTES) . '"><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '" alt="PlantUML diagram" class="plantuml-diagram" style="' . htmlspecialchars($imgStyle, ENT_QUOTES) . '" /></figure>';
    }
}
