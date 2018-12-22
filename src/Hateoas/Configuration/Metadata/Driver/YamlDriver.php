<?php

namespace Hateoas\Configuration\Metadata\Driver;

use Hateoas\Configuration\Embedded;
use Hateoas\Configuration\Exclusion;
use Hateoas\Configuration\Metadata\ClassMetadata;
use Hateoas\Configuration\Provider\RelationProviderInterface;
use JMS\Serializer\Expression\CompilableExpressionEvaluatorInterface;
use Metadata\ClassMetadata as JMSClassMetadata;
use Hateoas\Configuration\Relation;
use Hateoas\Configuration\RelationProvider;
use Hateoas\Configuration\Route;
use Metadata\Driver\AbstractFileDriver;
use Metadata\Driver\FileLocatorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
class YamlDriver extends AbstractFileDriver
{
    use CheckExpressionTrait;

    /**
     * @var RelationProviderInterface
     */
    private $relationProvider;

    public function __construct(FileLocatorInterface $locator, CompilableExpressionEvaluatorInterface $expressionLanguage, RelationProviderInterface $relationProvider)
    {
        parent::__construct($locator);
        $this->relationProvider = $relationProvider;
        $this->expressionLanguage = $expressionLanguage;
    }

    /**
     * {@inheritdoc}
     */
    protected function loadMetadataFromFile(\ReflectionClass $class, string $file): ?JMSClassMetadata
    {
        $config = Yaml::parse(file_get_contents($file));

        if (!isset($config[$name = $class->getName()])) {
            throw new \RuntimeException(sprintf('Expected metadata for class %s to be defined in %s.', $name, $file));
        }

        $config        = $config[$name];
        $classMetadata = new ClassMetadata($name);
        $classMetadata->fileResources[] = $file;
        $classMetadata->fileResources[] = $class->getFileName();

        if (isset($config['relations'])) {
            foreach ($config['relations'] as $relation) {
                $classMetadata->addRelation(new Relation(
                    $relation['rel'],
                    $this->createHref($relation),
                    $this->createEmbedded($relation),
                    isset($relation['attributes']) ? $relation['attributes'] : array(),
                    $this->createExclusion($relation)
                ));
            }
        }

        if (isset($config['relation_providers'])) {
            foreach ($config['relation_providers'] as $relationProvider) {
                $relations = $this->relationProvider->getRelations(new RelationProvider($relationProvider), $class->getName());
                foreach ($relations as $relation) {
                    $classMetadata->addRelation($relation);
                }
            }
        }

        return $classMetadata;
    }

    /**
     * {@inheritdoc}
     */
    protected function getExtension(): string
    {
        return 'yml';
    }

    private function parseExclusion(array $exclusion)
    {
        return new Exclusion(
            isset($exclusion['groups']) ? $exclusion['groups'] : null,
            isset($exclusion['since_version']) ? (string)$exclusion['since_version'] : null,
            isset($exclusion['until_version']) ? (string)$exclusion['until_version'] : null,
            isset($exclusion['max_depth']) ? (int)$exclusion['max_depth'] : null,
            isset($exclusion['exclude_if']) ? $this->checkExpression((string)$exclusion['exclude_if']) : null
        );
    }

    private function createHref($relation)
    {
        $href = null;
        if (isset($relation['href']) && is_array($href = $relation['href']) && isset($href['route'])) {

            $absolute = false;
            if (isset($href['absolute'])  && is_bool($href['absolute'])) {
                $absolute = $href['absolute'];
            } elseif (isset($href['absolute'])) {
                $absolute = isset($href['absolute']) ? $this->checkExpression($href['absolute']) : false;
            }

            $href = new Route(
                $this->checkExpression($href['route']),
                isset($href['parameters']) ? $this->checkExpressionArray((array)$href['parameters']) : array(),
                $absolute,
                isset($href['generator'])  ? $href['generator'] : null
            );
        }

        return $this->checkExpression($href);
    }

    private function createEmbedded($relation)
    {
        $embedded = null;
        if (isset($relation['embedded'])) {
            $embedded = $this->checkExpression($relation['embedded']);

            if (is_array($embedded)) {
                $embeddedExclusion = null;
                if (isset($embedded['exclusion'])) {
                    $embeddedExclusion = $this->parseExclusion($embedded['exclusion']);
                }

                $xmlElementName = isset($embedded['xmlElementName']) ? $this->checkExpression((string)$embedded['xmlElementName']) : null;
                $embedded       = new Embedded($this->checkExpression($embedded['content']), $xmlElementName, $embeddedExclusion);
            }
        }

        return $embedded;
    }

    private function createExclusion($relation)
    {
        $exclusion = null;
        if (isset($relation['exclusion'])) {
            $exclusion = $this->parseExclusion($relation['exclusion']);
        }

        return $exclusion;
    }
}
