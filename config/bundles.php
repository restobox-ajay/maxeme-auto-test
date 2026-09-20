<?php

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
];

// Each first-party module under modules/<Name>/src/<Name>.php declares class <Name>\<Name>
// extends Bundle. Discovered here by convention instead of being listed by name, so deleting
// a module folder just means one fewer glob() match — not a fatal "class not found" boot
// crash the moment Symfony reads this file.
$moduleDirs = glob(__DIR__ . '/../modules/*', \GLOB_ONLYDIR) ?: [];
sort($moduleDirs);

foreach ($moduleDirs as $moduleDir) {
    $name = basename($moduleDir);
    $class = $name . '\\' . $name;

    if (is_file($moduleDir . '/src/' . $name . '.php') && class_exists($class)) {
        $bundles[$class] = ['all' => true];
    }
}

return $bundles;
