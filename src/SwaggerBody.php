<?php

namespace ByJG\Swagger;

use ByJG\Swagger\Exception\GenericSwaggerException;
use ByJG\Swagger\Exception\InvalidRequestException;
use ByJG\Swagger\Exception\NotMatchedException;

abstract class SwaggerBody
{
    const SWAGGER_PROPERTIES="properties";
    const SWAGGER_REQUIRED="required";
    
    /**
     * @var \ByJG\Swagger\SwaggerSchema
     */
    protected $swaggerSchema;

    protected $structure;

    protected $name;

    /**
     * OpenApi 2.0 does not describe null values, so this flag defines,
     * if match is ok when one of property, which has type, is null
     *
     * @var bool
     */
    protected $allowNullValues;

    /**
     * SwaggerRequestBody constructor.
     *
     * @param \ByJG\Swagger\SwaggerSchema $swaggerSchema
     * @param string $name
     * @param array $structure
     * @param bool $allowNullValues
     */
    public function __construct(SwaggerSchema $swaggerSchema, $name, $structure, $allowNullValues = false)
    {
        $this->swaggerSchema = $swaggerSchema;
        $this->name = $name;
        if (!is_array($structure)) {
            throw new \InvalidArgumentException('I expected the structure to be an array');
        }
        $this->structure = $structure;
        $this->allowNullValues = $allowNullValues;
    }

    abstract public function match($body);

    /**
     * @param $name
     * @param $schema
     * @param $body
     * @return bool
     * @throws \ByJG\Swagger\Exception\NotMatchedException
     */
    protected function matchString($name, $schema, $body)
    {
        if (isset($schema['enum']) && !in_array($body, $schema['enum'])) {
            throw new NotMatchedException("Value '$body' in '$name' not matched in ENUM. ", $this->structure);
        }

        return true;
    }

    /**
     * @param $name
     * @param $body
     * @return bool
     * @throws \ByJG\Swagger\Exception\NotMatchedException
     */
    protected function matchNumber($name, $body)
    {
        if (!is_numeric($body)) {
            throw new NotMatchedException("Expected '$name' to be numeric, but found '$body'. ", $this->structure);
        }

        return true;
    }

    /**
     * @param $name
     * @param $body
     * @return bool
     * @throws \ByJG\Swagger\Exception\NotMatchedException
     */
    protected function matchBool($name, $body)
    {
        if (!is_bool($body)) {
            throw new NotMatchedException("Expected '$name' to be boolean, but found '$body'. ", $this->structure);
        }

        return true;
    }

    /**
     * @param $name
     * @param $schema
     * @param $body
     * @return bool
     * @throws Exception\DefinitionNotFoundException
     * @throws Exception\InvalidDefinitionException
     * @throws GenericSwaggerException
     * @throws InvalidRequestException
     * @throws NotMatchedException
     */
    protected function matchArray($name, $schema, $body)
    {
        foreach ((array)$body as $item) {
            if (!isset($schema['items'])) {  // If there is no type , there is no test.
                continue;
            }
            $this->matchSchema($name, $schema['items'], $item);
        }
        return true;
    }

    /**
     * @param string $name
     * @param $schema
     * @param array $body
     * @return bool
     * @throws Exception\DefinitionNotFoundException
     * @throws Exception\InvalidDefinitionException
     * @throws GenericSwaggerException
     * @throws InvalidRequestException
     * @throws NotMatchedException
     */
    protected function matchSchema($name, $schema, $body)
    {
        if (isset($schema['type'])) {

            $type = $schema['type'];
            if (is_null($body)) {
                return $this->matchNull($name, $type);
            }

            if ($type == 'string') {
                return $this->matchString($name, $schema, $body);
            }

            if ($type == 'integer' || $type == 'float' || $schema['type'] == 'number') {
                return $this->matchNumber($name, $body);
            }

            if ($type == 'bool' || $schema['type'] == 'boolean') {
                return $this->matchBool($name, $body);
            }

            if ($type == 'array') {
                return $this->matchArray($name, $schema, $body);
            }
        }

        if (isset($schema['$ref'])) {
            $defintion = $this->swaggerSchema->getDefintion($schema['$ref']);
            return $this->matchSchema($schema['$ref'], $defintion, $body);
        }

        if (isset($schema[self::SWAGGER_PROPERTIES])) {
            if (!is_array($body)) {
                throw new InvalidRequestException(
                    "I expected an array here, but I got an string. Maybe you did wrong request?",
                    $body
                );
            }

            if (!isset($schema[self::SWAGGER_REQUIRED])) {
                $schema[self::SWAGGER_REQUIRED] = [];
            }
            foreach ($schema[self::SWAGGER_PROPERTIES] as $prop => $def) {
                $required = array_search($prop, $schema[self::SWAGGER_REQUIRED]);

                if (!array_key_exists($prop, $body)) {
                    if ($required !== false) {
                         throw new NotMatchedException("Required property '$prop' in '$name' not found in object");
                    }
                    unset($body[$prop]);
                    continue;
                }

                $this->matchSchema($prop, $def, $body[$prop]);
                unset($schema[self::SWAGGER_PROPERTIES][$prop]);
                if ($required !== false) {
                    unset($schema[self::SWAGGER_REQUIRED][$required]);
                }
                unset($body[$prop]);
            }

            if (count($schema[self::SWAGGER_REQUIRED]) > 0) {
                throw new NotMatchedException(
                    "The required property(ies) '"
                    . implode(', ', $schema[self::SWAGGER_REQUIRED])
                    . "' does not exists in the body.",
                    $this->structure
                );
            }

            if (count($body) > 0) {
                throw new NotMatchedException(
                    "The property(ies) '"
                    . implode(', ', array_keys($body))
                    . "' has not defined in '$name'",
                    $body
                );
            }
            return true;
        }

        /**
         * OpenApi 2.0 does not describe ANY object value
         * But there is hack that makes ANY object possible, described in link below
         * To make that hack works, we need such condition
         * @link https://stackoverflow.com/questions/32841298/swagger-2-0-what-schema-to-accept-any-complex-json-value
         */
        if ($schema === []) {
            return true;
        }

        throw new GenericSwaggerException("Not all cases are defined. Please open an issue about this. Schema: $name");
    }

    /**
     * @param $name
     * @param $type
     * @return bool
     * @throws NotMatchedException
     */
    protected function matchNull($name, $type)
    {
        if (false === $this->swaggerSchema->isAllowNullValues()) {
            throw new NotMatchedException(
                "Value of property '$name' is null, but should be of type '$type'",
                $this->structure
            );
        }

        return true;
    }
}
