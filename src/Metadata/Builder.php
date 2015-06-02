<?php namespace Wetzel\Datamapper\Metadata;

use ReflectionClass;
use InvalidArgumentException;
use DomainException;
use Illuminate\Console\AppNamespaceDetectorTrait;
use Illuminate\Filesystem\ClassFinder;

use Doctrine\Common\Annotations\AnnotationReader;

use Wetzel\Datamapper\Metadata\Definitions\Entity as EntityDefinition;
use Wetzel\Datamapper\Metadata\Definitions\Attribute as AttributeDefinition;
use Wetzel\Datamapper\Metadata\Definitions\Column as ColumnDefinition;
use Wetzel\Datamapper\Metadata\Definitions\EmbeddedClass as EmbeddedClassDefinition;
use Wetzel\Datamapper\Metadata\Definitions\Relation as RelationDefinition;
use Wetzel\Datamapper\Metadata\Definitions\Table as TableDefinition;

class Builder {

    use AppNamespaceDetectorTrait;

    /**
     * The annotation reader instance.
     *
     * @var \Doctrine\Common\Annotations\AnnotationReader
     */
    protected $reader;

    /**
     * The class finder instance.
     *
     * @var \Illuminate\Filesystem\ClassFinder
     */
    protected $finder;

    /**
     * Create a new Eloquent query builder instance.
     *
     * @param  \Doctrine\Common\Annotations\AnnotationReader  $reader
     * @param  \Illuminate\Filesystem\ClassFinder  $finder
     * @return void
     */
    public function __construct(AnnotationReader $reader, ClassFinder $finder)
    {
        $this->reader = $reader;
        $this->finder = $finder;
    }

    /**
     * Build metadata from all entity classes.
     *
     * @param array $classes
     * @return array
     */
    public function build($classes)
    {
        $metadataArray = [];

        foreach($classes as $class) {
            // check if class is in app namespace
            if ($this->stripNamespace($class, $this->getAppNamespace())) {
                $metadata = $this->parseClass($class);

                if ($metadata) {
                    $metadataArray[$class] = $metadata;
                }
            }
        }

        $this->validate($metadataArray);

        return $metadataArray;
    }

    /**
     * Validate generated metadata.
     *
     * @param array $metadataArray
     * @return void
     */
    protected function validate($metadataArray)
    {
        // check if all tables have exactly one primary key
        foreach($metadataArray as $metadata) {
            $countPrimaryKeys = $this->countPrimaryKeys($metadata['table']['columns']);
            if ($countPrimaryKeys == 0) {
                throw new DomainException('No primary key defined in class ' . $metadata['class'] . '.');
            } elseif ($countPrimaryKeys > 1) {
                throw new DomainException('No composite primary keys allowed for class ' . $metadata['class'] . '.');
            }
        }
    }

    /**
     * Count primary keys in metadata columns.
     *
     * @param array $columns column metadata
     * @return array
     */
    protected function countPrimaryKeys($columns)
    {
        $count = 0;

        foreach($columns as $column) {
            if ( ! empty($column['primary'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all classes for a namespace.
     *
     * @param string namespace
     * @return array
     */
    public function getClassesFromNamespace($namespace=null)
    {
        if ( ! $namespace) {
            $namespace = $this->getAppNamespace();
        }

        $path = str_replace('\\', '/', $this->stripNamespace($namespace, $this->getAppNamespace()));

        $directory = app_path() . $path;

        return $this->finder->findClasses($directory);
    }

    /**
     * Parses a class.
     *
     * @param array $annotations
     * @return array|null
     */
    public function parseClass($class)
    {
        $reflectionClass = new ReflectionClass($class);

        // check if class is entity
        if ($this->reader->getClassAnnotation($reflectionClass, '\Wetzel\Datamapper\Annotations\Entity')) {
            return $this->parseEntity($class);
        } else {
            return null;
        }
    }

    /**
     * Parse an entity class.
     *
     * @param array $class
     * @return array
     */
    public function parseEntity($class)
    {
        $reflectionClass = new ReflectionClass($class);

        // scan class annotations
        $classAnnotations = $this->reader->getClassAnnotations($reflectionClass);

        // init class metadata
        $metadata = new EntityDefinition([
            'class' => $class,
            'table' => new TableDefinition([
                'name' => $this->getTablenameFromClass($class),
            ]),
        ]);

        foreach($classAnnotations as $annotation) {
            // softdeletes
            if ($annotation instanceof \Wetzel\Datamapper\Annotations\SoftDeletes) {
                $metadata['softDeletes'] = true;
            }

            // table name
            elseif ($annotation instanceof \Wetzel\Datamapper\Annotations\Table) {
                $metadata['table']['name'] = $annotation->name;
            }

            // timestamps
            elseif ($annotation instanceof \Wetzel\Datamapper\Annotations\Timestamps) {
                $metadata['timestamps'] = true;
            }

            // versioned
            elseif ($annotation instanceof \Wetzel\Datamapper\Annotations\Versionable) {
                $metadata['versionable'] = true;
            }

            // hidden
            elseif ($annotation instanceof \Wetzel\Datamapper\Annotations\Hidden) {
                $metadata['hidden'] = $annotation->attributes;
            }

            // visible
            elseif ($annotation instanceof \Wetzel\Datamapper\Annotations\Visible) {
                $metadata['visible'] = $annotation->attributes;
            }

            // touches
            elseif ($annotation instanceof \Wetzel\Datamapper\Annotations\Touches) {
                $metadata['touches'] = $annotation->relations;
            }
        }

        // scan property annotations
        foreach($reflectionClass->getProperties() as $reflectionProperty) {
            $name = $reflectionProperty->getName();
            $propertyAnnotations = $this->reader->getPropertyAnnotations($reflectionProperty);

            foreach($propertyAnnotations as $annotation) {
                // property is embedded class
                if ($annotation instanceof \Wetzel\Datamapper\Annotations\Embedded) {
                    $metadata['embeddeds'][$name] = $this->parseEmbeddedClass($name, $annotation, $metadata);
                }

                // property is attribute
                elseif ($this->stripNamespace($annotation, 'Wetzel\Datamapper\Annotations\Attribute')) {
                    $metadata['attributes'][$name] = $this->parseAttribute($name, $annotation);
                    $metadata['table']['columns'][$name] = $this->parseColumn($name, $annotation);
                }

                // property is relationship
                elseif ($this->stripNamespace($annotation, 'Wetzel\Datamapper\Annotations\Relation')) {
                    $metadata['relations'][$name] = $this->parseRelation($name, $annotation, $metadata);
                }
            }
        }

        return $metadata;
    }

    /**
     * Parse an embedded class.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @param \Wetzel\Datamapper\Metadata\Definitions\Class $metadata
     * @return \Wetzel\Datamapper\Metadata\Definitions\EmbeddedClass
     */
    protected function parseEmbeddedClass($name, $annotation, &$metadata)
    {
        $embeddedClass = $annotation->class;
        $embeddedName = $name;
        $reflectionClass = new ReflectionClass($embeddedClass);

        // scan class annotations
        $classAnnotations = $this->reader->getClassAnnotations($reflectionClass);

        // check if class is embedded class
        if ( ! $this->reader->getClassAnnotation($reflectionClass, 'Wetzel\Datamapper\Annotations\Embeddable')) {
            throw new InvalidArgumentException('Embedded class '.$embeddedClass.' has no @Embeddable annotation.');
        }

        // scan property annotations
        foreach($reflectionClass->getProperties() as $reflectionProperty) {
            $name = $reflectionProperty->getName();
            $propertyAnnotations = $this->reader->getPropertyAnnotations($reflectionProperty);
            
            $attributes = [];

            foreach($propertyAnnotations as $annotation) {
                // property is attribute
                if ($this->stripNamespace($annotation, 'Wetzel\Datamapper\Annotations\Attribute')) {
                    $attributes[$name] = $this->parseAttribute($name, $annotation);
                    $metadata['table']['columns'][$name] = $this->parseColumn($name, $annotation);
                }
            }

            return new EmbeddedClassDefinition([
                'name' => $embeddedName,
                'class' => $embeddedClass,
                'attributes' => $attributes,
            ]);
        }
    }

    /**
     * Parse an attribute.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @return \Wetzel\Datamapper\Metadata\Definitions\Attribute
     */
    protected function parseAttribute($name, $annotation)
    {
        // add attribute
        return new AttributeDefinition([
            'name' => $name
        ]);
    }

    /**
     * Parse a column.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @return \Wetzel\Datamapper\Metadata\Definitions\Column
     */
    protected function parseColumn($name, $annotation)
    {
        $type = $this->getClassWithoutNamespace($annotation, true);

        // add column
        return new ColumnDefinition([
            'name' => $name,
            'type' => $type,
            'nullable' => $annotation->nullable,
            'default' => $annotation->default,
            'primary' => $annotation->primary,
            'unique' => $annotation->unique,
            'index' => $annotation->index,
            'options' => $this->generateOptionsArray(['scale','precision','length','unsigned','autoIncrement'], $annotation)
        ]);
    }

    /**
     * Parse a relationship.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @param \Wetzel\Datamapper\Metadata\Definitions\Class $metadata
     * @return \Wetzel\Datamapper\Metadata\Definitions\Relation
     */
    protected function parseRelation($name, $annotation, &$metadata)
    {
        $type = $this->getClassWithoutNamespace($annotation, true);

        if ($type == 'belongsTo') {
            // create extra columns for belongsTo
            $this->generateBelongsToColumns($name, $annotation, $metadata);
        } elseif ($type == 'morphTo') {
            // create extra columns for morphTo
            $this->generateMorphToColumns($name, $annotation, $metadata);
        }

        if ($type == 'belongsToMany') {
            // create pivot table for belongsToMany
            $pivotTable = $this->generateBelongsToManyPivotTable($name, $annotation, $metadata);
        } elseif ($type == 'morphToMany') {
            // create pivot table for morphToMany
            $pivotTable = $this->generateMorphToManyPivotTable($name, $annotation, $metadata);
        } else {
            $pivotTable = null;
        }

        // add relation
        return new RelationDefinition([
            'name' => $name,
            'type' => $type,
            'relatedClass' => $annotation->related,
            'pivotTable' => $pivotTable,
            'options' => $this->generateOptionsArray(['name','type','table','through','foreignKey','otherKey','localKey','firstKey','secondKey','inverse','id','relation'], $annotation)
        ]);
    }

    /**
     * Generate extra columns for a belongsTo relation.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @param \Wetzel\Datamapper\Metadata\Definitions\Class $metadata
     * @return void
     */
    protected function generateBelongsToColumns($name, $annotation, &$metadata)
    {
        $name = ( ! empty($annotation->otherKey))
            ? $annotation->otherKey
            : $this->getClassWithoutNamespace($annotation->related, true).'_id';

        $metadata['table']['columns'][$name] = new ColumnDefinition([
            'name' => $name,
            'type' => 'integer',
            'unsigned' => true,
        ]);
    }

    /**
     * Generate extra columns for a morphTo relation.
     *
     * @param array $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @param \Wetzel\Datamapper\Metadata\Definitions\Class $metadata
     * @return void
     */
    protected function generateMorphToColumns($name, $annotation, &$metadata)
    {
        $morphName = ( ! empty($annotation->name))
            ? $annotation->name
            : $name;

        $morphId = ( ! empty($annotation->id))
            ? $annotation->id
            : $morphName.'_id';

        $morphType = ( ! empty($annotation->type))
            ? $annotation->type
            : $morphName.'_type';

        $metadata['table']['columns'][$morphId] = new ColumnDefinition([
            'name' => $morphId,
            'type' => 'integer',
            'unsigned' => true,
        ]);

        $metadata['table']['columns'][$morphType] = new ColumnDefinition([
            'name' => $morphType,
            'type' => 'string',
        ]);
    }
    
    /**
     * Generate pivot table for a belongsToMany relation.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @param \Wetzel\Datamapper\Metadata\Definitions\Class $metadata
     * @return \Wetzel\Datamapper\Metadata\Definitions\Table
     */
    protected function generateBelongsToManyPivotTable($name, $annotation, &$metadata)
    {
        $tableName = ( ! empty($annotation->table))
            ? $annotation->table
            : $this->metadata['table'].'_'.$this->getClassWithoutNamespace($annotation->related, true).'_pivot';

        $foreignKey = ( ! empty($annotation->foreignKey))
            ? $annotation->foreignKey
            : $this->getClassWithoutNamespace($metadata['class'], true).'_id';

        $otherKey = ( ! empty($annotation->otherKey))
            ? $annotation->otherKey
            : $this->getClassWithoutNamespace($annotation->related, true).'_id';

        return new TableDefinition([
            'name' => $tableName,
            'columns' => [
                $foreignKey => new ColumnDefinition([
                    'name' => $foreignKey,
                    'type' => 'integer',
                    'unsigned' => true,
                ]),
                $otherKey => new ColumnDefinition([
                    'name' => $otherKey,
                    'type' => 'integer',
                    'unsigned' => true,
                ]),
            ]
        ]);
    }
    
    /**
     * Generate pivot table for a morphToMany relation.
     *
     * @param string $name
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @param \Wetzel\Datamapper\Metadata\Definitions\Class $metadata
     * @return \Wetzel\Datamapper\Metadata\Definitions\Table
     */
    protected function generateMorphToManyPivotTable($name, $annotation, &$metadata)
    {
        $morphName = ( ! empty($annotation->name))
            ? $annotation->name
            : $name;

        $tableName = ( ! empty($annotation->table))
            ? $annotation->table
            : $this->metadata['table'].'_'.$morphName.'_pivot';

        $foreignKey = ( ! empty($annotation->foreignKey))
            ? $annotation->foreignKey
            : $this->getClassWithoutNamespace($metadata['class'], true).'_id';

        $morphId = ( ! empty($annotation->otherKey))
            ? $annotation->otherKey
            : $morphName.'_id';

        $morphType = $morphName.'_type';

        return new TableDefinition([
            'name' => $tableName,
            'columns' => [
                $foreignKey => new ColumnDefinition([
                    'name' => $foreignKey,
                    'type' => 'integer',
                    'unsigned' => true,
                ]),
                $morphId => new ColumnDefinition([
                    'name' => $morphId,
                    'type' => 'integer',
                    'unsigned' => true,
                ]),
                $morphType => new ColumnDefinition([
                    'name' => $morphType,
                    'type' => 'string',
                ]),
            ]
        ]);
    }

    /**
     * Generate an options array.
     *
     * @param array $keys
     * @param \Wetzel\Datamapper\Annotations\Annotation $annotation
     * @return array
     */
    protected function generateOptionsArray($keys, $annotation)
    {
        $options = [];

        foreach($keys as $key) {
            if (isset($annotation->{$key})) $options[$key] = $annotation->{$key};
        }

        return $options;
    }

    /**
     * Strip given namespace from class.
     *
     * @param string|object $class
     * @param string $namespace
     * @return string|null
     */
    protected function stripNamespace($class, $namespace)
    {
        $class = (is_object($class)) ? get_class($class) : $class;

        if (substr($class, 0, strlen($namespace)) == $namespace) {
            return substr($class, strlen($namespace));
        } else {
            return null;
        }
    }

    /**
     * Get table name.
     *
     * @param string $class
     * @return string
     */
    protected function getTablenameFromClass($class)
    {
        $className = array_slice(explode('/',str_replace('\\', '/', $class)), 2);

        // delete last entry if entry is equal to the next to last entry
        if (count($className) >= 2 && end($className) == prev($className)) {
            array_pop($className);
        }

        $classBasename = array_pop($className);

        return strtolower(implode('_',array_merge($className, preg_split('/(?<=\\w)(?=[A-Z])/', $classBasename))));
    }

    /**
     * Get class name.
     *
     * @param string|object $class
     * @param boolean $lcfirst
     * @return string
     */
    protected function getClassWithoutNamespace($class, $lcfirst=false)
    {
        $class = (is_object($class)) ? get_class($class) : $class;
        
        $items = explode('\\', $class);

        $class = array_pop($items);

        if ($lcfirst) {
            return lcfirst($class);
        } else {
            return $class;
        }
    }

}