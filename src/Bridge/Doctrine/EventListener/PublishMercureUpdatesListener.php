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

namespace ApiPlatform\Core\Bridge\Doctrine\EventListener;

use ApiPlatform\Core\Api\IriConverterInterface;
use ApiPlatform\Core\Api\ResourceClassResolverInterface;
use ApiPlatform\Core\Api\UrlGeneratorInterface;
use ApiPlatform\Core\Bridge\Symfony\Messenger\DispatchTrait;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use ApiPlatform\Core\Exception\RuntimeException;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Util\ResourceClassInfoTrait;
use Doctrine\Common\EventArgs;
use Doctrine\ODM\MongoDB\Event\OnFlushEventArgs as MongoDbOdmOnFlushEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs as OrmOnFlushEventArgs;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Publishes resources updates to the Mercure hub.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 *
 * @experimental
 */
final class PublishMercureUpdatesListener
{
    use DispatchTrait;
    use ResourceClassInfoTrait;

    private $iriConverter;
    private $serializer;
    private $publisher;
    private $expressionLanguage;
    private $createdObjects;
    private $updatedObjects;
    private $deletedObjects;
    private $formats;

    /**
     * @param array<string, string[]|string> $formats
     */
    public function __construct(ResourceClassResolverInterface $resourceClassResolver, IriConverterInterface $iriConverter, ResourceMetadataFactoryInterface $resourceMetadataFactory, SerializerInterface $serializer, array $formats, MessageBusInterface $messageBus = null, callable $publisher = null, ExpressionLanguage $expressionLanguage = null)
    {
        if (null === $messageBus && null === $publisher) {
            throw new InvalidArgumentException('A message bus or a publisher must be provided.');
        }

        $this->resourceClassResolver = $resourceClassResolver;
        $this->iriConverter = $iriConverter;
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->serializer = $serializer;
        $this->formats = $formats;
        $this->messageBus = $messageBus;
        $this->publisher = $publisher;
        $this->expressionLanguage = $expressionLanguage ?? class_exists(ExpressionLanguage::class) ? new ExpressionLanguage() : null;
        $this->reset();
    }

    /**
     * Collects created, updated and deleted objects.
     */
    public function onFlush(EventArgs $eventArgs): void
    {
        if ($eventArgs instanceof OrmOnFlushEventArgs) {
            $uow = $eventArgs->getEntityManager()->getUnitOfWork();
        } elseif ($eventArgs instanceof MongoDbOdmOnFlushEventArgs) {
            $uow = $eventArgs->getDocumentManager()->getUnitOfWork();
        } else {
            return;
        }

        $methodName = $eventArgs instanceof OrmOnFlushEventArgs ? 'getScheduledEntityInsertions' : 'getScheduledDocumentInsertions';
        foreach ($uow->{$methodName}() as $object) {
            $this->storeObjectToPublish($object, 'createdObjects');
        }

        $methodName = $eventArgs instanceof OrmOnFlushEventArgs ? 'getScheduledEntityUpdates' : 'getScheduledDocumentUpdates';
        foreach ($uow->{$methodName}() as $object) {
            $this->storeObjectToPublish($object, 'updatedObjects');
        }

        $methodName = $eventArgs instanceof OrmOnFlushEventArgs ? 'getScheduledEntityDeletions' : 'getScheduledDocumentDeletions';
        foreach ($uow->{$methodName}() as $object) {
            $this->storeObjectToPublish($object, 'deletedObjects');
        }
    }

    /**
     * Publishes updates for changes collected on flush, and resets the store.
     */
    public function postFlush(): void
    {
        try {
            foreach ($this->createdObjects as $object) {
                $this->publishUpdate($object, $this->createdObjects[$object]);
            }

            foreach ($this->updatedObjects as $object) {
                $this->publishUpdate($object, $this->updatedObjects[$object]);
            }

            foreach ($this->deletedObjects as $object) {
                $this->publishUpdate($object, $this->deletedObjects[$object]);
            }
        } finally {
            $this->reset();
        }
    }

    private function reset(): void
    {
        $this->createdObjects = new \SplObjectStorage();
        $this->updatedObjects = new \SplObjectStorage();
        $this->deletedObjects = new \SplObjectStorage();
    }

    /**
     * @param object $object
     */
    private function storeObjectToPublish($object, string $property): void
    {
        if (null === $resourceClass = $this->getResourceClass($object)) {
            return;
        }

        $value = $this->resourceMetadataFactory->create($resourceClass)->getAttribute('mercure', false);
        if (false === $value) {
            return;
        }

        if (\is_string($value)) {
            if (null === $this->expressionLanguage) {
                throw new RuntimeException('The Expression Language component is not installed. Try running "composer require symfony/expression-language".');
            }

            $value = $this->expressionLanguage->evaluate($value, ['object' => $object]);
        }

        if (true === $value) {
            $value = [];
        }

        if (!\is_array($value)) {
            throw new InvalidArgumentException(sprintf('The value of the "mercure" attribute of the "%s" resource class must be a boolean, an array of targets or a valid expression, "%s" given.', $resourceClass, \gettype($value)));
        }

        if ('deletedObjects' === $property) {
            $this->deletedObjects[(object) [
                'id' => $this->iriConverter->getIriFromItem($object),
                'iri' => $this->iriConverter->getIriFromItem($object, UrlGeneratorInterface::ABS_URL),
            ]] = $value;

            return;
        }

        $this->{$property}[$object] = $value;
    }

    /**
     * @param object $object
     */
    private function publishUpdate($object, array $targets): void
    {
        if ($object instanceof \stdClass) {
            // By convention, if the object has been deleted, we send only its IRI.
            // This may change in the feature, because it's not JSON Merge Patch compliant,
            // and I'm not a fond of this approach.
            $iri = $object->iri;
            /** @var string $data */
            $data = json_encode(['@id' => $object->id]);
        } else {
            $resourceClass = $this->getObjectClass($object);
            $context = $this->resourceMetadataFactory->create($resourceClass)->getAttribute('normalization_context', []);

            $iri = $this->iriConverter->getIriFromItem($object, UrlGeneratorInterface::ABS_URL);
            $data = $this->serializer->serialize($object, key($this->formats), $context);
        }

        $update = new Update($iri, $data, $targets);
        $this->messageBus ? $this->dispatch($update) : ($this->publisher)($update);
    }
}
