<?php

declare(strict_types=1);

// Router script for PHP's built-in web server, started by SharpPhp\Cli\DevServer ("sharp serve",
// or WebApp.Run() from the command line). Not a class: PHP runs it for every request.

$public = getenv('SHARP_PUBLIC');
if (is_string($public) && $public !== '') {
    $path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    $file = realpath($public . rawurldecode(is_string($path) ? $path : '/'));
    $root = realpath($public);
    if ($file !== false && $root !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        return false; // a file in wwwroot/: the built-in server sends it as it is
    }
}

$autoload = getenv('SHARP_AUTOLOAD');
$project = getenv('SHARP_PROJECT');
if (!is_string($autoload) || !is_file($autoload) || !is_string($project)) {
    http_response_code(500);
    echo 'SharpPHP: start this server with "sharp serve" or WebApp.Run().';

    return true;
}

require $autoload;

SharpPhp\Project::fromDirectory($project)->run();

return true;
