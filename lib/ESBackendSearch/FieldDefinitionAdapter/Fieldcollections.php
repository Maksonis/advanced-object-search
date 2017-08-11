<?php

namespace ESBackendSearch\FieldDefinitionAdapter;

use ESBackendSearch\FieldSelectionInformation;
use ESBackendSearch\FilterEntry;
use ONGR\ElasticsearchDSL\BuilderInterface;
use ONGR\ElasticsearchDSL\Query\BoolQuery;
use ONGR\ElasticsearchDSL\Query\NestedQuery;
use Pimcore\Model\Object\AbstractObject;
use Pimcore\Model\Object\ClassDefinition\Data;
use Pimcore\Model\Object\Concrete;
use Pimcore\Model\Object\Fieldcollection;

class Fieldcollections extends DefaultAdapter implements IFieldDefinitionAdapter {

    /**
     * field type for search frontend
     *
     * @var string
     */
    protected $fieldType = "fieldcollections";

    /**
     * @var Data\Fieldcollections
     */
    protected $fieldDefinition;

    /**
     * @return array
     */
    public function getESMapping() {

        $allowedTypes = $this->fieldDefinition->getAllowedTypes();
        if(empty($allowedTypes)) {
            $allFieldCollectionTypes = new Fieldcollection\Definition\Listing();
            foreach($allFieldCollectionTypes->load() as $type) {
                $allowedTypes[] = $type->getKey();
            }
        }

        $mappingProperties = [];

        foreach($allowedTypes as $fieldCollectionKey) {
            /**
             * @var $fieldCollectionDefinition Fieldcollection\Definition
             */
            $fieldCollectionDefinition = Fieldcollection\Definition::getByKey($fieldCollectionKey);

            $childMappingProperties = [];
            foreach($fieldCollectionDefinition->getFieldDefinitions() as $field) {
                $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($field, false);
                list($key, $mappingEntry) = $fieldDefinitionAdapter->getESMapping();
                $childMappingProperties[$key] = $mappingEntry;
            }

            $mappingProperties[$fieldCollectionKey] = [
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
     *          'type' => 'FIELD_COLLECTION_TYPE'
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
        $fieldCollectionType = $fieldFilter['type'];

        $innerBoolQuery = new BoolQuery();

        $innerPath = $path . $this->fieldDefinition->getName() . "." . $fieldCollectionType;


        if($filterEntryObject->getFilterEntryData() instanceof BuilderInterface) {

            // add given builder interface without any further processing
            $innerBoolQuery->add($filterEntryObject->getFilterEntryData(), $filterEntryObject->getOuterOperator());

        } else {

            $definition = Fieldcollection\Definition::getByKey($fieldCollectionType);
            $fieldDefinition = $definition->getFielddefinition($filterEntryObject->getFieldname());
            $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($fieldDefinition, false);

            if($filterEntryObject->getOperator() == FilterEntry::EXISTS || $filterEntryObject->getOperator() == FilterEntry::NOT_EXISTS) {

                //add exists filter generated by filter definition adapter
                $innerBoolQuery->add(
                    $fieldDefinitionAdapter->getExistsFilter($filterEntryObject->getFilterEntryData(), true, $innerPath . "."),
                    $filterEntryObject->getOuterOperator()
                );

            } else {

                //add query part generated by filter definition adapter
                $innerBoolQuery->add(
                    $fieldDefinitionAdapter->getQueryPart($filterEntryObject->getFilterEntryData(), true, $innerPath . "."),
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
        $fieldCollectionItems = $object->$getter();

        if($fieldCollectionItems) {

            //deactivate inheritance since within field collections there is no inheritance
            $inheritanceBackup = AbstractObject::getGetInheritedValues();
            AbstractObject::setGetInheritedValues(false);

            foreach($fieldCollectionItems->getItems() as $item) {
                /**
                 * @var $item \Pimcore\Model\Object\Fieldcollection\Data\AbstractData
                 */
                $definition = Fieldcollection\Definition::getByKey($item->getType());

                $fieldCollectionData = [];

                foreach($definition->getFieldDefinitions() as $key => $field) {
                    $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($field, false);
                    $fieldCollectionData[$key] = $fieldDefinitionAdapter->getIndexData($item);
                }

                $data[$item->getType()][] = $fieldCollectionData;

            }

            //reset inheritance
            AbstractObject::setGetInheritedValues($inheritanceBackup);

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