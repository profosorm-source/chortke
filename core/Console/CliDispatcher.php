<?php

declare(strict_types=1);

namespace Core\Console;

class CliDispatcher
{
    private array $commands = [];

    public function register(string $name, string $commandClass, string $description = ''): void
    {
        $this->commands[$name] = [
            'class' => $commandClass,
            'description' => $description
        ];
    }

    public function run(array $argv): void
    {
        $action = $argv[1] ?? 'help';

        if ($action === 'help') {
            $this->showHelp();
            return;
        }

        // جستجوی دستور دقیق یا انطباق نامگذاری (مثل feature:*)
        $matchedCommand = null;
        foreach ($this->commands as $name => $config) {
            // اگر دستور مستقیماً ثبت شده باشد یا به صورت prefix: مثل feature:
            if ($action === $name || (str_ends_with($name, ':*') && str_starts_with($action, rtrim($name, '*')))) {
                $matchedCommand = $config;
                break;
            }
        }

        if (!$matchedCommand) {
            echo "❌ Command '{$action}' not found.\n";
            $this->showHelp();
            exit(1);
        }

        try {
            $container = Container::getInstance();
            $instance = $container->make($matchedCommand['class']);
            
            // بررسی اینکه آیا این دستور از متد run یا هندلر داینامیک پشتیبانی می‌کند
            if (method_exists($instance, 'run')) {
                $instance->run($argv);
            } else {
                 throw new \RuntimeException("Command class " . $matchedCommand['class'] . " must implement run() method.");
            }
        } catch (\Throwable $e) {
            echo "❌ CLI execution failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    private function showHelp(): void
    {
        echo "\nChortke CLI Framework\n";
        echo "====================\n";
        echo "Usage: php cli.php <command> [options]\n\n";
        echo "Available Commands:\n";
        
        foreach ($this->commands as $name => $config) {
            echo "  " . str_pad($name, 20) . " - " . $config['description'] . "\n";
        }
        echo "\n";
    }
}
