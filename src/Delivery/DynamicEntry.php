<?php
/**
 * @copyright 2015-2017 Contentful GmbH
 * @license   MIT
 */

namespace Contentful\Delivery;

use Contentful\Exception\NotFoundException;
use Contentful\Link;

class DynamicEntry extends LocalizedResource implements EntryInterface
{
    /**
     * @var array
     */
    private $fields;

    /**
     * @var array
     */
    private $resolvedLinks = [];

    /**
     * @var SystemProperties
     */
    protected $sys;

    /**
     * @var Client|null
     */
    protected $client;

    /**
     * Entry constructor.
     *
     * @param array           $fields
     * @param SystemProperties $sys
     * @param Client|null      $client
     */
    public function __construct(array $fields, SystemProperties $sys, Client $client = null)
    {
        parent::__construct($sys->getSpace()->getLocales());

        $this->fields = $fields;
        $this->sys = $sys;
        $this->client = $client;
        $this->resolvedLinks = [];
    }

    public function getId()
    {
        return $this->sys->getId();
    }

    public function getRevision()
    {
        return $this->sys->getRevision();
    }

    public function getUpdatedAt()
    {
        return $this->sys->getUpdatedAt();
    }

    public function getCreatedAt()
    {
        return $this->sys->getCreatedAt();
    }

    public function getSpace()
    {
        return $this->sys->getSpace();
    }

    public function getContentType()
    {
        return $this->sys->getContentType();
    }

    /**
     * @param  string $name
     * @param  array  $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (0 !== strpos($name, 'get')) {
            trigger_error('Call to undefined method ' . __CLASS__ . '::' . $name . '()', E_USER_ERROR);
        }
        $locale = $this->getLocaleFromInput(isset($arguments[0]) ? $arguments[0] : null);

        $fieldName = substr($name, 3);
        $getId = false;

        $fieldConfig = $this->getFieldConfigForName($fieldName);
        // If the field name doesn't exist, that might be because we're looking for the ID of reference, try that next.
        if ($fieldConfig === null && substr($fieldName, -2) === 'Id') {
            $fieldName = substr($fieldName, 0, -2);
            $fieldConfig = $this->getFieldConfigForName($fieldName);
            $getId = true;
        }

        if ($fieldConfig === null) {
            trigger_error('Call to undefined method ' . __CLASS__ . '::' . $name . '()', E_USER_ERROR);
        }

        // Since DynamicEntry::getFieldForName manipulates the field name let's make sure we got the correct one
        $fieldName = $fieldConfig->getId();

        // HACK: Don't attempt to deal with some empty fields
        if (!isset($this->fields[$fieldName]) || (in_array($fieldConfig->getType(),
                    ['Array', 'Link']) && empty($this->fields[$fieldName]))
        ) {
            if ($fieldConfig->getType() === 'Array') {
                return [];
            }

            return null;
        }

        if ($getId && !($fieldConfig->getType() === 'Link' || ($fieldConfig->getType() === 'Array' && $fieldConfig->getItemsType() === 'Link'))) {
            trigger_error('Call to undefined method ' . __CLASS__ . '::' . $name . '()', E_USER_ERROR);
        }

        $value = $this->fields[$fieldName];
        if (!$fieldConfig->isLocalized()) {
            if (!isset($value[$locale])) {
                $locale = $this->getSpace()->getDefaultLocale()->getCode();
            }
        } else {
            $locale = $this->loopThroughFallbackChain($value, $locale, $this->getSpace());

            // We've reach the end of the fallback chain and there's no value
            if ($locale === null) {
                return null;
            }
        }

        // HACK: Don't attempt to use the locale if value is empty
        if (array_key_exists($locale, $value)) {
            $result = $value[$locale];
        } else {
            $result = '';
        }

        if ($getId && $fieldConfig->getType() === 'Link') {
            return $result->getId();
        }

        // HACK: Don't attempt to resolve links, we don't need it
        if ($result instanceof Link) {
            return $result;
        }

        if ($fieldConfig->getType() === 'Array' && $fieldConfig->getItemsType() === 'Link') {
            if ($getId) {
                return array_map([$this, 'mapIdValues'], $result);
            }
        }

        return $result;
    }

    /**
     * @param  string $fieldName
     *
     * @return ContentTypeField|null
     */
    private function getFieldConfigForName($fieldName)
    {
        // Let's try the lower case version first, it's the more common one
        $field = $this->getContentType()->getField(lcfirst($fieldName));

        if ($field !== null) {
            return $field;
        }

        return $this->getContentType()->getField($fieldName);
    }

    /**
     * @param  Link|DynamicEntry|Asset $value
     *
     * @return string
     */
    private function mapIdValues($value)
    {
        return $value->getId();
    }

    /**
     * @param  mixed $value
     * @param  string $type
     * @param  string $linkType
     *
     * @return mixed
     */
    private function formatSimpleValueForJson($value, $type, $linkType)
    {
        switch ($type) {
            case 'Symbol':
            case 'Text':
            case 'Integer':
            case 'Number':
            case 'Boolean':
            case 'Location':
            case 'Object':
                return $value;
            case 'Date':
                return \Contentful\format_date_for_json($value);
            case 'Link':
                return $value ? (object) [
                    'sys' => (object) [
                        'type' => 'Link',
                        'linkType' => $linkType,
                        'id' => $value->getId()
                    ]
                ] : null;
            default:
                throw new \InvalidArgumentException('Unexpected field type "' . $type . '" encountered while trying to serialize to JSON.');
        }
    }

    /**
     * @param  mixed $value
     * @param  ContentTypeField $fieldConfig
     *
     * @return mixed
     */
    private function formatValueForJson($value, ContentTypeField $fieldConfig)
    {
        $type = $fieldConfig->getType();

        if ($type === 'Array') {
            return array_map(function ($value) use ($fieldConfig) {
                return $this->formatSimpleValueForJson($value, $fieldConfig->getItemsType(), $fieldConfig->getItemsLinkType());
            }, $value);
        }

        return $this->formatSimpleValueForJson($value, $type, $fieldConfig->getLinkType());
    }

    public function jsonSerialize()
    {
        $entryLocale = $this->sys->getLocale();

        $fields = new \stdClass;
        $contentType = $this->getContentType();
        foreach ($this->fields as $fieldName => $fieldData) {
            $fields->$fieldName = new \stdClass;
            $fieldConfig = $contentType->getField($fieldName);
            if ($entryLocale) {
                $fields->$fieldName = $this->formatValueForJson($fieldData[$entryLocale], $fieldConfig);
            } else {
                foreach ($fieldData as $locale => $data) {
                    $fields->$fieldName->$locale = $this->formatValueForJson($data, $fieldConfig);
                }
            }
        }

        return (object) [
            'sys' => $this->sys,
            'fields' => $fields
        ];
    }
}
