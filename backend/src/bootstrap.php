<?php
declare(strict_types=1);

// Minimal PSR-4-style autoloader: NutriPlan\Foo => src/Foo.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'NutriPlan\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
