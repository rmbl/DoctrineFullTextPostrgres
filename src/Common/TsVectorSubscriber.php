<?php
/**
 * @author: James Murray <jaimz@vertigolabs.org>
 * @copyright:
 * @date: 9/15/2015
 * @time: 5:18 PM
 */

namespace VertigoLabs\DoctrineFullTextPostgres\Common;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\MappingException;
use VertigoLabs\DoctrineFullTextPostgres\DBAL\Types\TsVector as TsVectorType;
use VertigoLabs\DoctrineFullTextPostgres\ORM\Mapping\TsVector;

/**
 * Class TsVectorSubscriber.
 */
class TsVectorSubscriber implements EventSubscriber
{
    const ANNOTATION_NS = 'VertigoLabs\\DoctrineFullTextPostgres\\ORM\\Mapping\\';
    const ANNOTATION_TSVECTOR = 'TsVector';

    private static $supportedTypes = [
        'string',
        'text',
        'array',
        'simple_array',
        'json',
        'json_array',
    ];

    public function __construct()
    {
        if (!Type::hasType(strtolower(self::ANNOTATION_TSVECTOR))) {
            Type::addType(strtolower(self::ANNOTATION_TSVECTOR), TsVectorType::class);
        }
    }

    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return string[]
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::loadClassMetadata,
            Events::preFlush,
            Events::preUpdate,
        ];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        /** @var ClassMetadata $metaData */
        $metaData = $eventArgs->getClassMetadata();

        $class = $metaData->getReflectionClass();
        foreach ($class->getProperties() as $prop) {
            $attributes = $prop->getAttributes(TsVector::class, \ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $reflAttribute) {
                $attribute = $reflAttribute->newInstance();
                $this->checkWatchFields($class, $prop, $attribute);
                $metaData->mapField([
                    'fieldName' => $prop->getName(),
                    'columnName' => $this->getColumnName($prop, $attribute),
                    'type' => 'tsvector',
                    'weight' => strtoupper($attribute->weight),
                    'language' => strtolower($attribute->language),
                    'nullable' => $this->isWatchFieldNullable($class, $attribute)
                ]);
            }
        }
    }

    public function preFlush(PreFlushEventArgs $eventArgs): void
    {
        $uow = $eventArgs->getEntityManager()->getUnitOfWork();
        $insertions = $uow->getScheduledEntityInsertions();
        $this->setTsVector($insertions);
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs): void
    {
        $uow = $eventArgs->getEntityManager()->getUnitOfWork();
        $updates = $uow->getScheduledEntityUpdates();
        $this->setTsVector($updates);
    }

    private function setTsVector($entities): void
    {
        foreach ($entities as $entity) {
            $refl = new \ReflectionObject($entity);
            foreach ($refl->getProperties() as $prop) {
                $attributes = $prop->getAttributes(TsVector::class, \ReflectionAttribute::IS_INSTANCEOF);
                foreach ($attributes as $reflAttribute) {
                    $attribute = $reflAttribute->newInstance();
                    $fields = $attribute->fields;
                    $tsVectorVal = [];
                    foreach ($fields as $field) {
                        if ($refl->hasMethod($field)) {
                            $method = $refl->getMethod($field);
                            $method->setAccessible(true);
                            $methodValue = $method->invoke($entity);
                            if (is_array($methodValue)) {
                                $methodValue = implode(' ', $methodValue);
                            }
                            $tsVectorVal[] = $methodValue;
                        }
                        if ($refl->hasProperty($field)) {
                            $field = $refl->getProperty($field);
                            $field->setAccessible(true);
                            $fieldValue = $field->getValue($entity);
                            if (is_array($fieldValue)) {
                                $fieldValue = implode(' ', $fieldValue);
                            }
                            $tsVectorVal[] = $fieldValue;
                        }
                    }
                    $prop->setAccessible(true);
                    $value = [
                        'data' => join(' ', $tsVectorVal),
                        'language' => $attribute->language,
                        'weight' => $attribute->weight,
                    ];
                    $prop->setValue($entity, $value);
                }
            }
        }
    }

    private function getColumnName(\ReflectionProperty $property, TsVector $attribute): string
    {
        return $attribute->name ?? $property->getName();
    }

    private function checkWatchFields(\ReflectionClass $class, \ReflectionProperty $targetProperty, TsVector $attribute): void
    {
        foreach ($attribute->fields as $fieldName) {
            if ($class->hasMethod($fieldName)) {
                continue;
            }

            if (!$class->hasProperty($fieldName)) {
                throw new MappingException(sprintf('Class does not contain %s property or getter', $fieldName));
            }

            $property = $class->getProperty($fieldName);
            $attributes = $property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $reflAttribute) {
                $propAttribute = $reflAttribute->newInstance();
                if (!in_array($propAttribute->type, self::$supportedTypes)) {
                    throw new AnnotationException(sprintf(
                        '%s::%s TsVector field can only be assigned to ( "%s" ) columns. %1$s::%s has the type %s',
                        $class->getName(),
                        $targetProperty->getName(),
                        implode('" | "', self::$supportedTypes),
                        $fieldName,
                        $propAttribute->type
                    ));
                }
            }
        }
    }

    private function isWatchFieldNullable(\ReflectionClass $class, TsVector $attribute)
    {
        foreach ($attribute->fields as $fieldName) {
            if ($class->hasMethod($fieldName)) {
                continue;
            }

            $property = $class->getProperty($fieldName);
            $attributes = $property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $reflAttribute) {
                $propAttribute = $reflAttribute->newInstance();
                if (false === $propAttribute->nullable) {
                    return false;
                }
            }
        }

        return true;
    }
}
