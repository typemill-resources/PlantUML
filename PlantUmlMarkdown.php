<?php

namespace Plugins\plantuml;

use Typemill\Extensions\ParsedownExtension;

class PlantUmlMarkdown extends ParsedownExtension
{
    public array $diagrams = [];

    protected function blockFencedCode($Line)
    {
        $block = parent::blockFencedCode($Line);
        if ($block && ($block['element']['element']['attributes']['class'] ?? '') === 'language-plantuml-diagram')
        {
            $info = trim(substr($Line['text'], $block['openerLength']));
            $block['plantuml_parameters'] = substr($info, strlen('plantuml-diagram'));
        }

        return $block;
    }

    protected function blockFencedCodeComplete($Block)
    {
        $block = parent::blockFencedCodeComplete($Block);
        if (isset($block['plantuml_parameters']))
        {
            $this->diagrams[] = [
                'code' => $block['element']['element']['text'],
                'parameters' => $block['plantuml_parameters'],
            ];
        }

        return $block;
    }
}