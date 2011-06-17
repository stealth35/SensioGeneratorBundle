<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\GeneratorBundle\Generator;

use Symfony\Component\HttpKernel\Util\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * Generates a CRUD controller.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class CrudGenerator extends Generator
{
    private $filesystem;
    private $skeletonDir;
    private $routePrefix;
    private $bundle;
    private $entity;
    private $metadata;
    private $format;
    private $actions = array('index', 'show');

    /**
     * Constructor.
     *
     * @param Filesystem $filesystem A Filesystem instance
     * @param string $skeletonDir Path to the skeleton directory
     * @param string $routePrefix The route name prefix
     * @param array $needWriteActions Wether or not to generate write actions
     */
    public function __construct(Filesystem $filesystem, $skeletonDir, $routePrefix, $needWriteActions = false)
    {
        parent::__construct();

        $this->filesystem  = $filesystem;
        $this->skeletonDir = $skeletonDir;
        $this->routePrefix = $routePrefix;
        $this->setWriteActions($needWriteActions);
    }

    /**
     * Sets the list of write actions to generate.
     *
     * @param Boolean $mode Wether or not to generate write actions
     */
    public function setWriteActions($boolean)
    {
        if ($boolean) {
            $this->actions = array_merge($this->actions, array('new', 'edit', 'delete'));
        }
    }

    /**
     * Generate the CRUD controller.
     *
     * @param BundleInterface $bundle A bundle object
     * @param string $entity The entity relative class name
     * @param ClassMetadataInfo $metadata The entity class metadata
     * @param string $format The configuration format (xml, yaml, annotation)
     * @throws \RuntimeException
     */
    public function generate(BundleInterface $bundle, $entity, ClassMetadataInfo $metadata, $format)
    {
        if (count($metadata->identifier) > 1) {
            throw new \RuntimeException('The CRUD generator does not support entity classes with multiple primary keys.');
        }

        if (!in_array('id', $metadata->identifier)) {
            throw new \RuntimeException('The CRUD generator expects the entity object has a primary key field named "id" with a getId() method.');
        }

        $this->entity   = $entity;
        $this->bundle   = $bundle;
        $this->metadata = $metadata;
        $this->setFormat($format);

        $this->generateControllerClass();

        $dir = sprintf('%s/Resources/views/%s', $this->bundle->getPath(), str_replace('\\', '/', $this->entity));

        if (!file_exists($dir)) {
            $this->filesystem->mkdir($dir, 0755);
        }

        $this->generateIndexView($dir);

        if (in_array('show', $this->actions)) {
            $this->generateShowView($dir);
        }

        if (in_array('new', $this->actions)) {
            $this->generateNewView($dir);
        }

        if (in_array('edit', $this->actions)) {
            $this->generateEditView($dir);
        }

        $this->generateTestClass();
        $this->generateConfiguration();
    }

    /**
     * Sets the configuration format.
     *
     * @param string $format The configuration format
     */
    private function setFormat($format)
    {
        switch ($format) {
            case 'yml':
            case 'xml':
            case 'annotation':
                $this->format = $format;
                break;
            default:
                $this->format = 'yml';
                break;
        }
    }

    /**
     * Generates the routing configuration.
     *
     */
    private function generateConfiguration()
    {
        if (!in_array($this->format, array('yml', 'xml'))) {
            return;
        }

        $target = sprintf(
            '%s/Resources/config/%s.routing.%s', 
            $this->bundle->getPath(),
            strtolower(str_replace('\\', '_', $this->entity)),
            $this->format
        );

        $this->filesystem->copy(
            $this->skeletonDir.'/config/routing.'.$this->format,
            $target
        );

        $this->renderFile($target, array(
            'actions'      => $this->actions,
            'route_prefix' => $this->routePrefix,
            'bundle'       => $this->bundle->getName(),
            'entity'       => $this->entity,
        ));
    }

    /**
     * Generates the controller class only.
     *
     */
    private function generateControllerClass()
    {
        $dir = $this->bundle->getPath();

        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);

        $target = sprintf(
            '%s/Controller/%s/%sController.php',
            $dir,
            str_replace('\\', '/', $entityNamespace),
            $entityClass
        );

        if (file_exists($target)) {
            throw new \RuntimeException('Unable to generate the controller as it already exists.');
        }

        $this->filesystem->copy($this->skeletonDir.'/controller.php', $target);

        $this->renderFile($target, array(
            'actions'          => $this->actions,
            'route_prefix'     => $this->routePrefix,
            'dir'              => $this->skeletonDir,
            'bundle'           => $this->bundle->getName(),
            'entity'           => $this->entity,
            'entity_class'     => $entityClass,
            'namespace'        => $this->bundle->getNamespace(),
            'entity_namespace' => $entityNamespace,
            'format'           => $this->format,
        ));
    }

    /**
     * Generates the functional test class only.
     *
     */
    private function generateTestClass()
    {
        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);

        $dir    = $this->bundle->getPath() .'/Tests/Controller';
        $target = $dir .'/'. str_replace('\\', '/', $entityNamespace).'/'. $entityClass .'ControllerTest.php';

        $this->filesystem->copy($this->skeletonDir.'/tests/test.php', $target);

        $this->renderFile($target, array(
            'route_prefix'     => $this->routePrefix, 
            'entity'           => $this->entity,
            'entity_class'     => $entityClass,
            'namespace'        => $this->bundle->getNamespace(),
            'entity_namespace' => $entityNamespace,
            'actions'          => $this->actions,
            'dir'              => $this->skeletonDir,
        ));
    }

    /**
     * Generates the index.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateIndexView($dir)
    {
        $target = $dir.'/index.html.twig';
        $this->filesystem->copy($this->skeletonDir.'/views/index.html.twig', $target);

        $this->renderFile($target, array(
            'dir'            => $this->skeletonDir,
            'entity'         => $this->entity,
            'fields'         => $this->metadata->fieldNames,
            'actions'        => $this->actions,
            'record_actions' => $this->getRecordActions(),
            'route_prefix'   => $this->routePrefix,
        ));
    }

    /**
     * Generates the show.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateShowView($dir)
    {
        $target = $dir.'/show.html.twig';
        $this->filesystem->copy($this->skeletonDir.'/views/show.html.twig', $target);

        $this->renderFile($target, array(
            'dir'          => $this->skeletonDir,
            'entity'       => $this->entity,
            'fields'       => $this->metadata->fieldNames,
            'actions'      => $this->actions,
            'route_prefix' => $this->routePrefix,
        ));
    }

    /**
     * Generates the new.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateNewView($dir)
    {
        $target = $dir.'/new.html.twig';
        $this->filesystem->copy($this->skeletonDir.'/views/new.html.twig', $target);

        $this->renderFile($target, array(
            'dir'          => $this->skeletonDir,
            'route_prefix' => $this->routePrefix,
            'entity'       => $this->entity,
            'actions'      => $this->actions,
        ));
    }

    /**
     * Generates the edit.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateEditView($dir)
    {
        $target = $dir.'/edit.html.twig';
        $this->filesystem->copy($this->skeletonDir.'/views/edit.html.twig', $target);

        $this->renderFile($target, array(
            'dir'          => $this->skeletonDir,
            'route_prefix' => $this->routePrefix,
            'entity'       => $this->entity,
            'actions'      => $this->actions,
        ));
    }

    /**
     * Returns an array of record actions to generate (edit, show, delete).
     *
     * @return array
     */
    private function getRecordActions()
    {
        return array_filter($this->actions, function($item) {
            return in_array($item, array('show', 'edit', 'delete'));
        });
    }
}