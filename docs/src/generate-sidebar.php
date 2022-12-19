<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

require '../vendor/autoload.php';

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

$config = (require 'config.php')();

foreach ($config['sidebar']['directories'] as $title => $directories) {
    fwrite(\STDOUT, '## '.$title.\PHP_EOL);

    $namespaces = ['' => []];

    foreach ((new Finder())->files()->in($directories)->sortByName() as $file) {
        $path = Path::makeRelative($file->getPathName(), $config['sidebar']['basePath']);
        $parts = explode(\DIRECTORY_SEPARATOR, $path);
        $n = count($parts);
        $namespace = '';

        if ($n > 2) {
            array_shift($parts);
            array_pop($parts);
            // array_unshift($parts, "ApiPlaform");
            $namespace = implode('\\', $parts);
            if (!isset($namespaces[$namespace])) {
                $namespaces[$namespace] = [];
            }
        }

        $basename = basename($path, '.'.$file->getExtension());
        $namespaces[$namespace][] = sprintf('- [%s](/%s/%s)', $basename, Path::getDirectory($path), $basename);
    }

    foreach ($namespaces as $namespace => $files) {
        if ($namespace) {
            fwrite(\STDOUT, '### '.$namespace.\PHP_EOL);
        }

        fwrite(\STDOUT, implode(\PHP_EOL, $files).\PHP_EOL);
    }

    fwrite(\STDOUT, \PHP_EOL);
}
