<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

class ApiInformationObjectsBrowseAction extends QubitApiAction
{
    // Facet fields returned when "facet=true" is requested without "facet.field"
    public const DEFAULT_FACET_FIELDS = ['place', 'name', 'subject', 'level', 'format'];

    // Solr-style facet.limit defaults and hard cap (terms aggregation size)
    public const FACET_LIMIT_DEFAULT = 100;
    public const FACET_LIMIT_MAX = 1000;

    // Map Solr-style facet field names to the term aggregations defined in
    // InformationObjectBrowseAction::$AGGS. The aggregation names themselves
    // (places, subjects, ...) are accepted as well.
    // We have chosen to align with Solr-style as that is what we are familiar with, and will make sense if AtoM swaps out to using SOLR.
    public static $FACET_FIELDS = [
        'place' => 'places',
        'name' => 'names',
        'creator' => 'creators',
        'subject' => 'subjects',
        'level' => 'levels',
        'format' => 'mediatypes',
        'genre' => 'genres',
        'repository' => 'repos',
        'collection' => 'collection',
        'language' => 'languages',
    ];

    protected function get($request)
    {
        $getParameters = $request->getGetParameters();

        // Get actual information object template to check archival history
        // visibility in _advancedSearch partial and in parseQuery function
        $archivalStandard = 'isad';
        if (null !== $infoObjectTemplate = QubitSetting::getByNameAndScope('informationobject', 'default_template')) {
            $archivalStandard = $infoObjectTemplate->getValue(['sourceCulture' => true]);
        }

        $limit = sfConfig::get('app_hits_per_page');
        if (isset($request->limit) && ctype_digit($request->limit)) {
            $limit = $request->limit;
        }

        $skip = 0;
        if (isset($request->skip) && ctype_digit($request->skip)) {
            $skip = $request->skip;
        }

        // Avoid pagination over ES' max result window config (default: 10000)
        $maxResultWindow = arElasticSearchPluginConfiguration::getMaxResultWindow();

        if ((int) $limit + (int) $skip > $maxResultWindow) {
            // Return 400 response with error message
            $message = $this->context->i18n->__(
                'Pagination limit reached. To avoid using vast amounts of memory,'
                .' AtoM limits pagination to %1% records. Please, narrow down your results.',
                ['%1%' => $maxResultWindow]
            );

            throw new QubitApiBadRequestException($message);
        }

        // Default to show all level descriptions
        if (!isset($request->topLod) || !filter_var($request->topLod, FILTER_VALIDATE_BOOLEAN)) {
            $getParameters['topLod'] = 0;
        }

        $this->search = new arElasticSearchPluginQuery($limit, $skip);
        $this->search->addAggFilters(InformationObjectBrowseAction::$AGGS, $getParameters);
        $this->search->addAdvancedSearchFilters(InformationObjectBrowseAction::$NAMES, $getParameters, $archivalStandard);

        // Optionally request facet counts (Solr-style parameters, opt-in).
        // Aggregations are computed over the whole matching set, regardless
        // of limit/skip, and respect the filters added above.
        $facetOptions = $this->getFacetOptions();
        if (null !== $facetOptions) {
            $this->search->addAggs($facetOptions['aggs']);
        }

        // Determin sort field and default order
        switch ($request->sort) {
            case 'identifier':
                $field = 'referenceCode.untouched';
                $order = 'asc';

                break;

            // I don't think that this is going to scale, but let's leave it for now
            case 'alphabetic':
                $field = sprintf('i18n.%s.title.alphasort', sfContext::getInstance()->user->getCulture());
                $order = 'asc';

                break;

            case 'date':
                $field = 'startDateSort';
                $order = 'asc';

                break;

            case 'lastUpdated':
            default:
                $field = 'updatedAt';
                $order = 'desc';
        }

        // Optionally reverse sort order
        if (isset($request->reverse) && !empty($request->reverse)) {
            $order = ('asc' == $order) ? 'desc' : 'asc';
        }

        $this->search->query->setSort([$field => $order]);

        $resultSet = QubitSearch::getInstance()->index->getIndex('QubitInformationObject')->search($this->search->getQuery(false, true));

        // Build array from results
        $results = $lodMapping = [];
        foreach ($resultSet as $hit) {
            $doc = $hit->getData();
            $result = [];

            if ('1' == sfConfig::get('app_inherit_code_informationobject', 1)) {
                $this->addItemToArray($result, 'reference_code', $doc['referenceCode']);
            } else {
                $this->addItemToArray($result, 'reference_code', $doc['identifier']);
            }

            $this->addItemToArray($result, 'slug', $doc['slug']);
            $this->addItemToArray($result, 'title', get_search_i18n($doc, 'title'));
            $this->addItemToArray($result, 'physical_characteristics', get_search_i18n($doc, 'physicalCharacteristics'));

            if (isset($doc['repository'])) {
                $this->addItemToArray($result, 'repository', get_search_i18n($doc['repository'], 'authorizedFormOfName'));
            }

            // Get LOD name, creating a mapping for other results
            if (isset($doc['levelOfDescriptionId'])) {
                if (isset($lodMapping[$doc['levelOfDescriptionId']])) {
                    $lodName = $lodMapping[$doc['levelOfDescriptionId']];
                } else {
                    if (null !== $lod = QubitTerm::getById($doc['levelOfDescriptionId'])) {
                        $lodMapping[$doc['levelOfDescriptionId']] = $lod->name;
                        $lodName = $lod->name;
                    }
                }

                $this->addItemToArray($result, 'level_of_description', $lodName);
            }

            // Create array with creator names
            if (isset($doc['creators']) && count($doc['creators']) > 0) {
                $creators = [];
                foreach ($doc['creators'] as $creator) {
                    $creatorName = get_search_i18n($creator, 'authorizedFormOfName');
                    if (!empty($creatorName)) {
                        $creators[] = $creatorName;
                    }
                }

                $this->addItemToArray($result, 'creators', $creators);
            }

            // Create array with creation dates
            if (isset($doc['dates']) && count($doc['dates']) > 0) {
                $dates = [];
                foreach ($doc['dates'] as $event) {
                    if (isset($event['typeId']) && QubitTerm::CREATION_ID == $event['typeId']) {
                        $date = get_search_i18n($event, 'date');
                        if (!empty($date)) {
                            $dates[] = $date;
                        }
                    }
                }

                $this->addItemToArray($result, 'creation_dates', $dates);
            }

            // Create array with place names
            if (isset($doc['places']) && count($doc['places']) > 0) {
                $places = [];
                foreach ($doc['places'] as $place) {
                    $placeName = get_search_i18n($place, 'name');
                    if (!empty($placeName)) {
                        $places[] = $placeName;
                    }
                }

                $this->addItemToArray($result, 'place_access_points', $places);
            }

            // Add thumbnail URL
            if (isset($doc['digitalObject']['thumbnailPath'])) {
                $this->addItemToArray($result, 'thumbnail_url', $this->siteBaseUrl.$doc['digitalObject']['thumbnailPath']);
            }

            $results[] = $result;
        }

        $response = [
            'total' => $resultSet->getTotalHits(),
            'results' => $results,
        ];

        // Only include facet counts when they were explicitly requested,
        // keeping the response unchanged for existing API consumers
        if (null !== $facetOptions) {
            $response['facet_counts'] = $this->getFacetCounts($resultSet, $facetOptions);
        }

        return $response;
    }

    /**
     * Parse Solr-style facet parameters:
     *
     *   facet=true
     *   facet.field=place&facet.field=subject   (repeatable or comma separated)
     *   facet.limit=100                          (max buckets per field)
     *   facet.mincount=1                         (drop buckets below this count)
     *
     * @return null|array null when faceting has not been requested, otherwise
     *                    ['fields' => [requested => aggName], 'aggs' => [...], 'mincount' => int]
     */
    private function getFacetOptions()
    {
        $params = $this->getRawQueryParameters();

        if (
            !isset($params['facet'])
            || !filter_var($this->firstValue($params['facet']), FILTER_VALIDATE_BOOLEAN)
        ) {
            return null;
        }

        // Collect requested fields (repeated params and/or comma separated)
        $fields = [];
        foreach ((array) ($params['facet.field'] ?? []) as $value) {
            foreach (explode(',', $value) as $field) {
                $field = trim($field);
                if ('' !== $field) {
                    $fields[] = $field;
                }
            }
        }

        if (empty($fields)) {
            $fields = self::DEFAULT_FACET_FIELDS;
        }

        $limit = self::FACET_LIMIT_DEFAULT;
        if (isset($params['facet.limit']) && ctype_digit((string) $this->firstValue($params['facet.limit']))) {
            $limit = (int) $this->firstValue($params['facet.limit']);
        }
        $limit = max(1, min($limit, self::FACET_LIMIT_MAX));

        $minCount = 1;
        if (isset($params['facet.mincount']) && ctype_digit((string) $this->firstValue($params['facet.mincount']))) {
            $minCount = (int) $this->firstValue($params['facet.mincount']);
        }

        $fieldMap = $aggs = [];
        foreach (array_unique($fields) as $field) {
            $aggName = self::$FACET_FIELDS[$field] ?? $field;

            if (
                !isset(InformationObjectBrowseAction::$AGGS[$aggName])
                || 'term' !== InformationObjectBrowseAction::$AGGS[$aggName]['type']
            ) {
                throw new QubitApiBadRequestException(sprintf(
                    'Unknown facet field "%s". Allowed values: %s',
                    $field,
                    implode(', ', array_keys(self::$FACET_FIELDS))
                ));
            }

            // Copy the site aggregation definition, overriding its bucket size
            $agg = InformationObjectBrowseAction::$AGGS[$aggName];
            $agg['size'] = $limit;

            $aggs[$aggName] = $agg;
            $fieldMap[$field] = $aggName;
        }

        return [
            'fields' => $fieldMap,
            'aggs' => $aggs,
            'mincount' => $minCount,
        ];
    }

    /**
     * Build the facet_counts structure from the result set aggregations.
     *
     * @param \Elastica\ResultSet $resultSet
     */
    private function getFacetCounts($resultSet, array $facetOptions)
    {
        $facetFields = [];
        $aggregations = $resultSet->hasAggregations() ? $resultSet->getAggregations() : [];

        foreach ($facetOptions['fields'] as $field => $aggName) {
            $buckets = $aggregations[$aggName]['buckets'] ?? [];

            // Apply facet.mincount
            $buckets = array_values(array_filter(
                $buckets,
                fn ($bucket) => $bucket['doc_count'] >= $facetOptions['mincount']
            ));

            // Aggregation keys are ids; resolve them to display names
            $labels = $this->getFacetLabels($aggName, array_column($buckets, 'key'));

            $facetFields[$field] = [];
            foreach ($buckets as $bucket) {
                $facetFields[$field][] = [
                    'id' => $bucket['key'],
                    'name' => $labels[$bucket['key']] ?? (string) $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }
        }

        return ['facet_fields' => $facetFields];
    }

    /**
     * Resolve aggregation bucket keys (ids) to display names, mirroring
     * InformationObjectBrowseAction::populateAgg().
     *
     * @return array id => name
     */
    private function getFacetLabels($aggName, array $ids)
    {
        $labels = [];

        if (empty($ids)) {
            return $labels;
        }

        switch ($aggName) {
            case 'levels':
            case 'mediatypes':
            case 'places':
            case 'subjects':
            case 'genres':
                $criteria = new Criteria();
                $criteria->add(QubitTerm::ID, $ids, Criteria::IN);

                foreach (QubitTerm::get($criteria) as $item) {
                    $labels[$item->id] = $item->getName(['cultureFallback' => true]);
                }

                break;

            case 'creators':
            case 'names':
                $criteria = new Criteria();
                $criteria->add(QubitActor::ID, $ids, Criteria::IN);

                foreach (QubitActor::get($criteria) as $item) {
                    $labels[$item->id] = $item->getAuthorizedFormOfName(['cultureFallback' => true]);
                }

                break;

            case 'repos':
                $criteria = new Criteria();
                $criteria->add(QubitRepository::ID, $ids, Criteria::IN);

                foreach (QubitRepository::get($criteria) as $item) {
                    $labels[$item->id] = $item->getAuthorizedFormOfName(['cultureFallback' => true]);
                }

                break;

            case 'collection':
                $criteria = new Criteria();
                $criteria->add(QubitInformationObject::ID, $ids, Criteria::IN);

                foreach (QubitInformationObject::get($criteria) as $item) {
                    $labels[$item->id] = $item->getTitle(['cultureFallback' => true]);
                }

                break;

            case 'languages':
                $cultureInfo = sfCultureInfo::getInstance($this->context->user->getCulture());

                foreach ($ids as $id) {
                    $labels[$id] = ucfirst($cultureInfo->getLanguage($id));
                }

                break;
        }

        return $labels;
    }

    /**
     * Parse the raw query string ourselves. PHP converts dots in parameter
     * names to underscores ("facet.field" becomes "facet_field") and keeps
     * only the last of repeated keys, which breaks Solr-style facet params.
     *
     * Repeated keys are returned as arrays; "facet_*" keys are normalised
     * to their dotted form so both spellings are accepted.
     */
    private function getRawQueryParameters()
    {
        $params = [];
        $queryString = $this->request->getPathInfoArray()['QUERY_STRING'] ?? '';

        foreach (explode('&', $queryString) as $pair) {
            if ('' === $pair) {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : '';

            if (0 === strpos($key, 'facet_')) {
                $key = str_replace('_', '.', $key);
            }

            if (isset($params[$key])) {
                $params[$key] = (array) $params[$key];
                $params[$key][] = $value;
            } else {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function firstValue($value)
    {
        return is_array($value) ? reset($value) : $value;
    }
}