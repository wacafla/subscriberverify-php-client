<?php
declare(strict_types=1);

// Optional loader for projects that do not use Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SubscriberVerify\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
