<?php

namespace Plugins\plantuml;

use Slim\Views\Twig;
use Typemill\Events\OnExportHtmlLoaded;

class PlantUmlExportView extends Twig
{
    private $exporter;

    public function __construct(Twig $view, callable $exporter)
    {
        $this->loader = $view->getLoader();
        $this->environment = $view->getEnvironment();
        $this->defaultVariables =& $view->defaultVariables;
        $this->exporter = $exporter;
    }

    public function fetch(string $template, array $data = []): string
    {
        $data = array_merge($this->defaultVariables, $data);
        if (str_starts_with($template, '@booklayouts/') && isset($data['book']) && is_array($data['book']))
        {
            foreach ($data['book'] as $key => $chapter)
            {
                if (!is_array($chapter) || !isset($chapter['content']) || !is_string($chapter['content']))
                {
                    continue;
                }

                $event = new OnExportHtmlLoaded([
                    'html' => $chapter['content'],
                    'item' => $chapter['item'] ?? [],
                    'metadata' => $chapter['metadata'] ?? [],
                    'base_url' => $data['base_url'] ?? '',
                ]);
                ($this->exporter)($event);
                $data['book'][$key]['content'] = $event->getData()['html'];
            }
        }

        return parent::fetch($template, $data);
    }
}