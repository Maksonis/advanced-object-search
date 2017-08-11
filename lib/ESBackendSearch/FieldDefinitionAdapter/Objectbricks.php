<?php

namespace ESBackendSearch\FieldDefinitionAdapter;

use ESBackendSearch\FieldSelectionInformation;
use ESBackendSearch\FilterEntry;
use ONGR\ElasticsearchDSL\BuilderInterface;
use ONGR\ElasticsearchDSL\Query\BoolQuery;
use ONGR\ElasticsearchDSL\Query\NestedQuery;
use Pimcore\Model\Object\ClassDefinition\Data;
use Pimcore\Model\Object\Concrete;
use Pimcore\Model\Object\Objectbrick\Definition;

class Objectbricks extends DefaultAdapter implements IFieldDefinitionAdapter {

    /**
     * field type for search frontend
     *
     * @var string
     */
    protected $fieldType = "objectbricks";

    /**
     * @var Data\Objectbricks
     */
    protected $fieldDefinition;

    /**
     * @return array
     */
    public function getESMapping() {

        $allowedTypes = $this->fieldDefinition->getAllowedTypes();

        $mappingProperties = [];

        foreach($allowedTypes as $objectBrickDefinitionKey) {
            $objectBrickDefinition = Definition::getByKey($objectBrickDefinitionKey);

            $childMappingProperties = [];
            foreach($objectBrickDefinition->getFieldDefinitions() as $field) {
                $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($field, $this->considerInheritance);
                list($key, $mappingEntry) = $fieldDefinitionAdapter->getESMapping();
                $childMappingProperties[$key] = $mappingEntry;
            }

            $mappingProperties[$objectBrickDefinitionKey] = [
                'type' => 'nested',
                'properties' => $childMappingProperties
            ];

        }

        return [
            $this->fieldDefinition->getName(),
            [
                'type' => 'nested',
                'properties' => $mappingProperties
            ]
        ];
    }


    /**
     * @param $fieldFilter
     *
     * filter field format as follows:
     *      [
     *          'type' => 'OBJECT_BRICK_TYPE'
     *          'filterCondition' => FilterEntry[]  - FULL FEATURES FILTER ENTRY ARRAY
     *      ]
     *
     * @param bool $ignoreInheritance
     * @param string $path
     * @return BuilderInterface
     */
    public function getQueryPart($fieldFilter, $ignoreInheritance = false, $path = "")
    {
        $filterEntryObject = $this->service->buildFilterEntryObject($fieldFilter['filterCondition']);
        $objectBrickType = $fieldFilter['type'];

        $innerBoolQuery = new BoolQuery();

        $innerPath = $path . $this->fieldDefinition->getName() . "." . $objectBrickType;


        if($filterEntryObject->getFilterEntryData() instanceof BuilderInterface) {

            // add given builder interface without any further processing
            $innerBoolQuery->add($filterEntryObject->getFilterEntryData(), $filterEntryObject->getOuterOperator());

        } else {

            $definition = Definition::getByKey($objectBrickType);
            $fieldDefinition = $definition->getFielddefinition($filterEntryObject->getFieldname());
            $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($fieldDefinition, $this->considerInheritance);

            if($filterEntryObject->getOperator() == FilterEntry::EXISTS || $filterEntryObject->getOperator() == FilterEntry::NOT_EXISTS) {

                //add exists filter generated by filter definition adapter
                $innerBoolQuery->add(
                    $fieldDefinitionAdapter->getExistsFilter($filterEntryObject->getFilterEntryData(), $filterEntryObject->getIgnoreInheritance(), $innerPath . "."),
                    $filterEntryObject->getOuterOperator()
                );

            } else {

                //add query part generated by filter definition adapter
                $innerBoolQuery->add(
                    $fieldDefinitionAdapter->getQueryPart($filterEntryObject->getFilterEntryData(), $filterEntryObject->getIgnoreInheritance(), $innerPath . "."),
                    $filterEntryObject->getOuterOperator()
                );

            }


        }

        return new NestedQuery(
            $path . $this->fieldDefinition->getName(),
            new NestedQuery($innerPath, $innerBoolQuery)
        );

    }

    /**
     * @param Concrete $object
     * @return array
     */
    public function getIndexData($object) {

        $data = [];

        $getter = "get" . ucfirst($this->fieldDefinition->getName());
        $objectBrickContainer = $object->$getter();

        if($objectBrickContainer) {


            foreach($objectBrickContainer->getItems() as $item) {
                $definition = Definition::getByKey($item->getType());

                $brickData = [];

                foreach($definition->getFieldDefinitions() as $key => $field) {
                    $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($field, $this->considerInheritance);
                    $brickData[$key] = $fieldDefinitionAdapter->getIndexData($item);
                }

                $data[$item->getType()][] = $brickData;

            }

        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function getFieldSelectionInformation()
    {

        $allowedTypes = [];
        foreach($this->fieldDefinition->getAllowedTypes() as $allowedType) {
            $allowedTypes[] = [$allowedType];
        }

        return [new FieldSelectionInformation(
            $this->fieldDefinition->getName(),
            $this->fieldDefinition->getTitle(),
            $this->fieldType,
            [
                'allowedTypes' => $allowedTypes,
            ]
        )];

    }

}