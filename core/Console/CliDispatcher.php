<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Container;

class CliDispatcher
{
    private Container $container;
    private array $commands = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function register(string $name, string $commandClass, string $description = ''): void
    {
        $this->commands[$name] = [
            'class' => $commandClass,
            'description' => $description
        ];
    }

    public function run(array $argv): void
    {
        // M28 Fix: بررسی هوشمند سوییچ‌های راهنما در کل طول آرگومان‌های ورودی
        if (count($argv) < 2 || in_array('--help', $argv) || in_array('-h', $argv) || in_array('help', $argv)) {
            $this->showHelp();
            return;
        }

        $action = $argv[1];

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
            echo "\n\033[1;31m❌ Error:\033[0m Command '\033[1;37m{$action}\033[0m' not found.\n";
            $this->showHelp();
            exit(1);
        }

        try {
            $instance = $this->container->make($matchedCommand['class']);
            
            // بررسی اینکه آیا این دستور از متد run پشتیبانی می‌کند
            if (method_exists($instance, 'run')) {
                $instance->run($argv);
            } else {
                 throw new \RuntimeException("Command class " . $matchedCommand['class'] . " must implement run() method.");
            }
        } catch (\Throwable $e) {
            echo "\n\033[1;31m❌ CLI execution failed:\033[0m " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * M28 Fix: ژنراتور راهنمای داینامیک و حرفه‌ای، گروه‌بندی دستورات و نمایش رنگی (ANSI Colored)
     */
    private function showHelp(): void
    {
        echo "\n\033[1;34mChortke Enterprise CLI\033[0m\n";
        echo "========================\n";
        echo "\033[1;33mUsage:\033[0m\n";
        echo "  php cli.php <command> [options] [--help|-h]\n\n";
        echo "\033[1;33mAvailable Commands:\033[0m\n";
        
        $groups = [];
        foreach ($this->commands as $name => $config) {
            $parts = explode(':', $name, 2);
            $prefix = count($parts) > 1 ? $parts[0] : 'system';
            $groups[$prefix][$name] = $config['description'];
        }

        // مرتب‌سازی گروه‌ها به ترتیب حروف الفبا
        ksort($groups);

        foreach ($groups as $prefix => $commands) {
            echo "\n  \033[1;32m" . ucfirst($prefix) . "\033[0m\n";
            foreach ($commands as $name => $desc) {
                echo "    \033[1;37m" . str_pad($name, 25) . "\033[0m " . $desc . "\n";
            }
        }
        echo "\n";
    }
}
