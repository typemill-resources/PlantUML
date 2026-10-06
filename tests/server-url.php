<?php

namespace Typemill {
    class Plugin
    {
        public array $settings = [];

        public function getPluginSettings($name)
        {
            return $this->settings;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/PlantUmlEncoder.php';
    require dirname(__DIR__) . '/plantuml.php';

    $plugin = new \Plugins\plantuml\plantuml();
    $generateUrl = new \ReflectionMethod($plugin, 'generatePlantUmlUrl');
    $code = "@startuml\nAlice -> Bob: Hello\n@enduml";
    $encoded = (new \Plugins\plantuml\PlantUmlEncoder())->encode("\nAlice -> Bob: Hello\n");
    $cases = [
        ['https://www.plantuml.com', 'https://www.plantuml.com/plantuml'],
        ['https://plantuml.com/', 'https://plantuml.com/plantuml'],
        ['http://www.plantuml.com/', 'http://www.plantuml.com/plantuml'],
        ['https://WWW.PLANTUML.COM', 'https://WWW.PLANTUML.COM/plantuml'],
        [' https://www.plantuml.com/ ', 'https://www.plantuml.com/plantuml'],
        ['https://www.plantuml.com/plantuml/', 'https://www.plantuml.com/plantuml'],
        ['https://www.plantuml.com/custom/', 'https://www.plantuml.com/custom'],
        ['http://localhost:8080/', 'http://localhost:8080'],
        ['https://diagrams.example.com/custom/plantuml/', 'https://diagrams.example.com/custom/plantuml'],
    ];

    $checks = 0;
    foreach ($cases as [$configured, $expected]) {
        foreach (['svg', 'png'] as $format) {
            $plugin->settings = ['server_url' => $configured, 'output_format' => $format];
            $params = [
                'server_url' => $configured,
                'format' => $format,
                'transparent_background' => false,
                'border_color' => '',
            ];
            foreach ([null, $params] as $input) {
                $actual = $generateUrl->invoke($plugin, $code, $input);
                if ($actual !== $expected . '/' . $format . '/' . $encoded) {
                    throw new \RuntimeException('Unexpected diagram URL: ' . $actual);
                }
                $checks++;
            }
        }
    }

    $plugin->settings = [];
    if ($generateUrl->invoke($plugin, $code) !== 'https://www.plantuml.com/plantuml/svg/' . $encoded) {
        throw new \RuntimeException('Default server URL changed.');
    }
    $checks++;

    echo "Passed {$checks} server URL checks.\n";
}