<?php

namespace Typemill {
    class Plugin
    {
        protected $adminroute = true;
        protected $editorroute = false;
        public $route = 'tm/ebooks/preview';
        public $container;
        public array $assets = [];
        public array $settings = [];
        public array $systemSettings = [];

        public function getPluginSettings($name)
        {
            return $this->settings;
        }

        protected function getTwig()
        {
            return $this->container->get('view');
        }

        protected function getSettings()
        {
            return $this->systemSettings + ['rootPath' => dirname(__DIR__, 3)];
        }

        protected function generateStaticAsset(string $source, callable $generator, string $extension = 'svg'): string
        {
            $this->assets[] = json_decode($source, true);
            return '/cache/generated/plantuml/' . sha1($source) . '.' . $extension;
        }
    }
}

namespace {
    require dirname(__DIR__, 3) . '/system/vendor/autoload.php';
    require dirname(__DIR__) . '/PlantUmlEncoder.php';
    require dirname(__DIR__) . '/plantuml.php';

    if (($_GET['alignment-fixture'] ?? false) || in_array('--alignment-fixture', $argv ?? [], true)) {
        $plugin = new \Plugins\plantuml\plantuml();
        $figure = new \ReflectionMethod($plugin, 'buildFigureHtml');
        $image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="160" height="60"><rect width="160" height="60" fill="#167d8d"/><text x="12" y="36" fill="white">PlantUML test</text></svg>');
        echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/plugins/ebooks/booklayouts/damovo/layout.css"><style>body{padding:16px} .alignment-frame{width:640px;max-width:100%;border:1px solid #999;margin-bottom:12px}</style></head><body>';
        foreach (['left', 'center', 'right'] as $align) {
            foreach (['', '200px', '50%'] as $size) {
                echo '<div class="alignment-frame" data-align="' . $align . '" data-size="' . $size . '">';
                echo $figure->invoke($plugin, $image, ['align' => $align, 'padding' => '10px', 'size' => $size]);
                echo '</div>';
            }
        }
        echo '</body></html>';
        return;
    }

    $checks = 0;
    $assert = function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
        $checks++;
    };

    $loader = new \Twig\Loader\ArrayLoader([
        '@booklayouts/index.twig' => '{% for chapter in book %}{{ chapter.content }}{% endfor %}',
        'frontend.twig' => '{{ chapter.content }}',
        'defaults.twig' => '{{ shared }}',
    ]);
    $view = new \Slim\Views\Twig($loader, ['cache' => false, 'autoescape' => false]);
    $environment = $view->getEnvironment();
    $originalExtensions = array_keys($environment->getExtensions());
    $attachPlugin = function (\Slim\Views\Twig $view, string $route = 'tm/ebooks/preview') {
        $plugin = new class extends \Plugins\plantuml\plantuml {
            public array $exportEvents = [];

            public function onExportHtmlLoaded($event)
            {
                $this->exportEvents[] = $event->getData();
                parent::onExportHtmlLoaded($event);
            }
        };
        $plugin->route = $route;
        $plugin->container = new \DI\Container();
        $plugin->container->set('view', $view);
        $plugin->onTwigLoaded(null);
        return $plugin;
    };
    $plugin = $attachPlugin($view);
    $exportView = $plugin->container->get('view');

    $parsedown = new \Typemill\Extensions\ParsedownExtension();
    $markdown = <<<'MARKDOWN'
```plantuml-diagram align="right" padding="10px" size="600px"
@startuml
Alice -> Bob: Authentication Request
@enduml
```

```plantuml
Alice -> Bob: Keep this as code
```
MARKDOWN;
    $markdownEvent = new class($markdown) {
        public function __construct(private $data) {}
        public function getData() { return $this->data; }
        public function setData($data): void { $this->data = $data; }
    };
    $plugin->onMarkdownLoaded($markdownEvent);
    $assert($markdownEvent->getData() === $markdown, 'The Markdown hook must not modify source content.');
    $html = $parsedown->markup($parsedown->text($markdown));
    $book = [['content' => $html]];
    $rendered = $exportView->fetch('@booklayouts/index.twig', ['book' => $book]);
    $assert($environment->getFilter('plantuml_export') === null, 'No additional Twig filter may be registered.');
    $assert(array_keys($environment->getExtensions()) === $originalExtensions, 'No additional Twig extension may be registered.');
    $assert($exportView->getEnvironment() === $environment, 'The existing Twig environment must be reused.');
    $assert($exportView->getLoader() === $loader, 'The existing template loader must be reused.');
    $assert(count($plugin->exportEvents) === 1, 'The preview must call onExportHtmlLoaded.');
    $assert($plugin->exportEvents[0]['html'] === $html, 'The export handler must receive the original chapter HTML.');
    $assert(str_contains($rendered, '<img src="/cache/generated/plantuml/'), 'Preview must contain a local image.');
    $assert(!str_contains($rendered, 'language-plantuml-diagram'), 'Diagram code block must be replaced.');
    $assert(str_contains($rendered, 'class="language-plantuml"'), 'Plain PlantUML code must remain unchanged.');
    $assert(count($plugin->assets) === 1, 'Only the diagram block must generate an asset.');
    $assert(str_contains($plugin->assets[0]['code'], 'Alice -> Bob: Authentication Request'), 'Diagram source must be decoded.');
    $assert(!str_contains($rendered, '&lt;figure'), 'Generated HTML must not be escaped.');
    $assert(str_contains($rendered, 'text-align: right;'), 'Documented Markdown alignment must reach the preview.');
    $assert(str_contains($rendered, 'padding: 10px;'), 'Documented Markdown padding must reach the preview.');
    $assert(str_contains($rendered, 'max-width: 600px;'), 'Documented Markdown size must reach the preview.');
    $assert(str_contains($rendered, 'display: block; width: 100%;'), 'The figure must override shrink-to-fit table layout.');
    $assert(str_contains($rendered, 'box-sizing: border-box;'), 'Padding must fit inside the figure width.');
    $assert(str_contains($rendered, 'display: inline-block;'), 'The image must override block layout so text alignment applies.');
    $assert(str_contains($rendered, 'vertical-align: middle; margin: 0;'), 'Theme image margins must not displace the aligned image.');
    $attributedHtml = '<pre><code class="language-plantuml-diagram align=right padding=10px size=600px">Alice -&gt; Bob: Hello</code></pre>';
    $attributedExport = $plugin->renderExportHtml($attributedHtml);
    $assert(str_contains($attributedExport, 'text-align: right;'), 'Available alignment attributes must be preserved.');
    $assert(str_contains($attributedExport, 'padding: 10px;'), 'Available padding attributes must be preserved.');
    $assert(str_contains($attributedExport, 'max-width: 600px;'), 'Available size attributes must be preserved.');

    $plugin->onTwigLoaded(null);
    $assert($plugin->container->get('view') === $exportView, 'Repeated Twig events must not wrap the view twice.');
    $assert($exportView->fetch('@booklayouts/index.twig', ['book' => $book]) === $rendered, 'Repeated Twig events must work after rendering.');
    $assert($exportView->fetch('frontend.twig', ['chapter' => ['content' => $html]]) === $html, 'Frontend templates must remain untouched.');
    $view['shared'] = 'original default';
    $assert($exportView->fetch('defaults.twig') === 'original default', 'Original default variables must remain available.');
    $exportView['shared'] = 'updated default';
    $assert($view['shared'] === 'updated default', 'Default variables must remain shared with the original view.');
    $assert($exportView->fetch('defaults.twig', ['shared' => 'explicit']) === 'explicit', 'Explicit render data must still override defaults.');
    $otherPlugin = $attachPlugin($view, 'tm/ebooks');
    $assert($otherPlugin->container->get('view') === $view, 'Other routes must not replace the view.');
    $assert($plugin->renderExportHtml($rendered) === $rendered, 'The export event must not render static images twice.');

    $event = new class($html) {
        public function __construct(private $data) {}
        public function getData() { return $this->data; }
        public function setData($data): void { $this->data = $data; }
    };
    $plugin->onHtmlLoaded($event);
    $assert(str_contains($event->getData(), 'plantuml-browser-render'), 'Browser rendering must remain available.');
    $assert($plugin->renderExportHtml($event->getData()) === $rendered, 'Browser containers must use the same export renderer.');
    $event->setData(['html' => $html, 'item' => ['path' => 'example']]);
    $plugin->onExportHtmlLoaded($event);
    $assert($event->getData()['html'] === $rendered, 'The export event must still convert raw code blocks.');
    $assert($event->getData()['item']['path'] === 'example', 'Export metadata must be preserved.');

    $editorBlocks = $parsedown->markdownToArrayBlocks($markdown);
    foreach ([$editorBlocks, json_decode(json_encode($editorBlocks)), [], null, false] as $editorPayload) {
        $markdownEvent->setData($markdown);
        $plugin->onMarkdownLoaded($markdownEvent);
        $markdownEvent->setData($editorPayload);
        $plugin->onMarkdownLoaded($markdownEvent);
        $assert($markdownEvent->getData() === $editorPayload, 'Editor block payloads must remain unchanged.');
        $assert(str_contains($plugin->renderExportHtml($html), 'text-align: center;'), 'Non-text payloads must clear previously captured Markdown parameters.');
    }

    $cases = [
        ['', 'center', '', ''],
        [' align="center" padding="10px" size="600px"', 'center', '10px', '600px'],
        [' align="left" padding="20px" size="100%"', 'left', '20px', '100%'],
        [" align='right' padding='2rem' size='500px'", 'right', '2rem', '500px'],
        [' align=left padding="10px 20px" size=100%', 'left', '10px 20px', '100%'],
        [' padding="0" size="0"', 'center', '0', '0'],
    ];
    foreach ($cases as [$parameters, $align, $padding, $size]) {
        $source = "```plantuml-diagram{$parameters}\nAlice -> Bob: Parameters\n```";
        $markdownEvent->setData($source);
        $plugin->onMarkdownLoaded($markdownEvent);
        $caseHtml = $parsedown->markup($parsedown->text($source));
        $caseExport = $plugin->renderExportHtml($caseHtml);
        $assert(str_contains($caseExport, "text-align: {$align};"), 'Documented alignment must work from Markdown.');
        $assert($padding === '' ? !str_contains($caseExport, 'padding:') : str_contains($caseExport, "padding: {$padding};"), 'Documented padding must work from Markdown.');
        $assert(str_contains($caseExport, 'max-width: ' . ($size === '' ? '100%' : $size) . ';'), 'Documented size must work from Markdown.');
        $event->setData($caseHtml);
        $plugin->onHtmlLoaded($event);
        $assert(str_contains($event->getData(), 'data-plantuml-align="' . $align . '"'), 'Browser output must preserve alignment.');
        $assert($padding === '' || str_contains($event->getData(), 'data-plantuml-padding="' . $padding . '"'), 'Browser output must preserve padding.');
        $assert($size === '' || str_contains($event->getData(), 'data-plantuml-size="' . $size . '"'), 'Browser output must preserve size.');
        $assert($plugin->renderExportHtml($event->getData()) === $caseExport, 'Browser and static output must use the same parameters.');
    }

    $duplicateSource = "```plantuml-diagram align=left size=100%\nAlice -> Bob: Same\n```\n\n```plantuml-diagram align=right size=500px\nAlice -> Bob: Same\n```";
    $markdownEvent->setData($duplicateSource);
    $plugin->onMarkdownLoaded($markdownEvent);
    $duplicateHtml = $parsedown->markup($parsedown->text($duplicateSource));
    $duplicateExport = $plugin->renderExportHtml($duplicateHtml);
    $assert(strpos($duplicateExport, 'text-align: left;') < strpos($duplicateExport, 'text-align: right;'), 'Identical diagrams must retain their own parameter order.');
    $assert(str_contains($duplicateExport, 'max-width: 100%;') && str_contains($duplicateExport, 'max-width: 500px;'), 'Identical diagrams must retain different sizes.');

    $literalSource = "````text\n```plantuml-diagram align=left\nAlice -> Bob: Example\n```\n````";
    $markdownEvent->setData($literalSource);
    $plugin->onMarkdownLoaded($markdownEvent);
    $literalHtml = $parsedown->markup($parsedown->text($literalSource));
    $assert($plugin->renderExportHtml($literalHtml) === $literalHtml, 'A fenced syntax example must remain literal code.');
    $suffixHtml = '<pre><code class="language-plantuml-diagram-example">Alice -&gt; Bob: Example</code></pre>';
    $assert($plugin->renderExportHtml($suffixHtml) === $suffixHtml, 'Only the documented language identifier must render a diagram.');

    $markdownEvent->setData($markdown);
    $plugin->onMarkdownLoaded($markdownEvent);
    $plugin->settings = ['output_format' => 'png'];
    $assert(str_contains($plugin->renderExportHtml($html), '.png"'), 'PNG output must remain supported.');
    $plugin->settings = ['server_url' => 'https://diagrams.example.test/custom', 'output_format' => 'svg', 'transparent_background' => true, 'border_color' => '#393b41'];
    $configuredExport = $plugin->renderExportHtml($html);
    $config = $plugin->assets[array_key_last($plugin->assets)];
    $assert(str_contains($configuredExport, '.svg"'), 'SVG output must remain supported.');
    $assert($config['server_url'] === $plugin->settings['server_url'], 'The configured server must reach the image generator.');
    $assert($config['transparent_background'] === true && $config['border_color'] === '#393b41', 'Rendering settings must reach the image generator.');
    $generateUrl = new \ReflectionMethod($plugin, 'generatePlantUmlUrl');
    $expectedCode = "skinparam DiagramBorderColor #393b41\nskinparam DiagramBorderThickness 1\nskinparam pageBorderColor #393b41\nskinparam pageMargin 10\nskinparam backgroundcolor transparent\n\nAlice -> Bob: Authentication Request\n";
    $expectedUrl = 'https://diagrams.example.test/custom/svg/' . (new \Plugins\plantuml\PlantUmlEncoder())->encode($expectedCode);
    $assert($generateUrl->invoke($plugin, $config['code'], $config) === $expectedUrl, 'SVG generation must apply transparency and border settings.');

    $cachePath = __DIR__ . '/.twig-preview-' . bin2hex(random_bytes(6));
    try {
        mkdir($cachePath . '/content', 0755, true);
        file_put_contents($cachePath . '/content/example.md', $markdown);
        $layoutLoader = new \Twig\Loader\FilesystemLoader();
        $layoutLoader->addPath(dirname(__DIR__, 2) . '/ebooks/booklayouts/damovo', 'booklayouts');
        $layoutData = [
            'book' => [['content' => $html, 'level' => 1, 'item' => ['path' => '/example.md', 'elementType' => 'file']], ['content' => '<img src="/existing-qrcode.png" />', 'level' => 2]],
            'ebookdata' => ['title' => 'PlantUML Preview Test'],
            'settings' => ['langattr' => 'en'],
            'base_url' => 'https://example.test',
            'assets' => new class {
                public function renderCSS(): string { return ''; }
                public function renderJS(): string { return ''; }
            },
        ];
        foreach (['cold', 'warm'] as $cacheState) {
            $layoutView = new \Slim\Views\Twig($layoutLoader, ['cache' => $cachePath, 'autoescape' => false]);
            $layoutEnvironment = $layoutView->getEnvironment();
            $layoutEnvironment->addFunction(new \Twig\TwigFunction('markdown', function ($text) use ($parsedown) {
                return $parsedown->markup($parsedown->text($text));
            }));
            $layoutExtensions = array_keys($layoutEnvironment->getExtensions());
            $layoutEnvironment->load('@booklayouts/index.twig');
            $layoutPlugin = $attachPlugin($layoutView);
            $layoutPlugin->systemSettings = ['rootPath' => $cachePath];
            $response = new \Slim\Psr7\Response(200);
            $renderedResponse = $layoutPlugin->container->get('view')->render($response, '@booklayouts/index.twig', $layoutData);
            $layoutHtml = (string) $renderedResponse->getBody();
            $assert($renderedResponse === $response, "Damovo {$cacheState} cache must preserve the response object.");
            $assert(array_keys($layoutEnvironment->getExtensions()) === $layoutExtensions, "Damovo {$cacheState} cache must not add Twig extensions.");
            $assert(str_contains($layoutHtml, '<img src="/cache/generated/plantuml/'), "Damovo {$cacheState} cache must contain a local image.");
            $assert(!str_contains($layoutHtml, 'language-plantuml-diagram'), "Damovo {$cacheState} cache must replace diagram code blocks.");
            $assert(str_contains($layoutHtml, '/existing-qrcode.png'), "Damovo {$cacheState} cache must preserve other images.");
            $assert(count($layoutPlugin->assets) === 1, "Damovo {$cacheState} cache must use the current plugin instance.");
            $assert(str_contains($layoutHtml, 'text-align: right; padding: 10px;'), "Damovo {$cacheState} cache must preserve Markdown parameters.");
            $assert(str_contains($layoutHtml, 'max-width: 600px;'), "Damovo {$cacheState} cache must preserve Markdown size.");
            $assert(count($layoutPlugin->exportEvents) === 2, "Damovo {$cacheState} cache must invoke the export handler for each chapter.");
            $assert($layoutPlugin->exportEvents[0]['item']['path'] === '/example.md', "Damovo {$cacheState} cache must pass the source item to the export handler.");
            $assert($layoutPlugin->exportEvents[0]['base_url'] === 'https://example.test', "Damovo {$cacheState} cache must pass the base URL to the export handler.");
        }
        $assert(count(glob($cachePath . '/*/*.php')) > 0, 'Twig must write a compiled template to the plugin-local test cache.');
    } finally {
        if (is_dir($cachePath)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cachePath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($cachePath);
        }
    }

    echo "Passed {$checks} ebook preview checks.\n";
}