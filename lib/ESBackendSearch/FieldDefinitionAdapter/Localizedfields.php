<?php

namespace ESBackendSearch\FieldDefinitionAdapter;

use ESBackendSearch\FieldSelectionInformation;
use ESBackendSearch\FilterEntry;
use ONGR\ElasticsearchDSL\BuilderInterface;
use ONGR\ElasticsearchDSL\Query\BoolQuery;
use ONGR\ElasticsearchDSL\Query\NestedQuery;
use Pimcore\Model\Object\ClassDefinition\Data;
use Pimcore\Model\Object\Concrete;
use Pimcore\Tool;

class Localizedfields extends DefaultAdapter implements IFieldDefinitionAdapter {

    /**
     * @var Data\Localizedfields
     */
    protected $fieldDefinition;


    /**
     * @return array
     */
    public function getESMapping() {

        $children = $this->fieldDefinition->getFieldDefinitions();
        $childMappingProperties = [];
        foreach($children as $child) {
            $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($child);
            list($key, $mappingEntry) = $fieldDefinitionAdapter->getESMapping();
            $childMappingProperties[$key] = $mappingEntry;
        }

        $mappingProperties = [];
        $languages = Tool::getValidLanguages();
        foreach($languages as $language) {
            $mappingProperties[$language] = [
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
     * @param Concrete $object
     * @return array
     */
    public function getIndexData($object)
    {
        $webserviceData = $this->fieldDefinition->getForWebserviceExport($object);
        $data = [];

        foreach($webserviceData as $entry) {
            $data[$entry->language][$entry->name] = $entry->value;
        }
        return $data;
    }


    /**
     * @param $fieldFilter
     *
     * filter field format as follows:
     *  stdObject with language as key and languageFilter array as values like
     *    [
     *      'en' => FilterEntry[]  - FULL FEATURES FILTER ENTRY ARRAY
     *    ]
     *   e.g.
     *      'en' => [
     *          ["fieldnme" => "locname", "filterEntryData" => "englname"]
     *       ]
     *
     * @param string $path
     * @return BoolQuery
     */
    public function getQueryPart($fieldFilter, $path = "") {

        $languageQueries = [];

        foreach($fieldFilter as $language => $languageFilters) {
            $path = $this->fieldDefinition->getName() . "." . $language;
            $languageBoolQuery = new BoolQuery();

            foreach($languageFilters as $localizedFieldFilter) {
                $filterEntryObject = $this->service->buildFilterEntryObject($localizedFieldFilter);

                if($filterEntryObject->getFilterEntryData() instanceof BuilderInterface) {

                    // add given builder interface without any further processing
                    $languageBoolQuery->add($filterEntryObject->getFilterEntryData(), $filterEntryObject->getOuterOperator());

                } else {
                    $fieldDefinition = $this->fieldDefinition->getFielddefinition($filterEntryObject->getFieldname());
                    $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($fieldDefinition);

                    if($filterEntryObject->getOperator() == FilterEntry::EXISTS || $filterEntryObject->getOperator() == FilterEntry::NOT_EXISTS) {

                        //add exists filter generated by filter definition adapter
                        $languageBoolQuery->add(
                            $fieldDefinitionAdapter->getExistsFilter($filterEntryObject->getFilterEntryData(), $path . "."),
                            $filterEntryObject->getOuterOperator()
                        );

                    } else {

                        //add query part generated by filter definition adapter
                        $languageBoolQuery->add(
                            $fieldDefinitionAdapter->getQueryPart($filterEntryObject->getFilterEntryData(), $path . "."),
                            $filterEntryObject->getOuterOperator()
                        );

                    }


                }
            }
            $languageQueries[] = new NestedQuery($path, $languageBoolQuery);
        }

        if(count($languageQueries) == 1) {
            return $languageQueries[0];
        } else {
            $boolQuery = new BoolQuery();
            foreach($languageQueries as $query) {
                 $boolQuery->add($query);
            }
            return $boolQuery;
        }
    }


    /**
     * returns selectable fields with their type information for search frontend
     *
     * @return FieldSelectionInformation[]
     */
    public function getFieldSelectionInformation()
    {
        $fieldSelectionInformationEntries = [];

        $children = $this->fieldDefinition->getChilds();
        foreach($children as $child) {
            $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($child);

            $subEntries = $fieldDefinitionAdapter->getFieldSelectionInformation();
            foreach($subEntries as $subEntry) {
                $context = $subEntry->getContext();
                $context['subType'] = $subEntry->getFieldType();
                $context['languages'] = Tool::getValidLanguages();
                $subEntry->setContext($context);

                $subEntry->setFieldType("localizedfields");
            }

            $fieldSelectionInformationEntries = array_merge($fieldSelectionInformationEntries, $subEntries);
        }

        return $fieldSelectionInformationEntries;
    }

}