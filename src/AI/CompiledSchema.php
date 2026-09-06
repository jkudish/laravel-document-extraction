<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Illuminate\JsonSchema\Types\Type;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\ObjectSchema;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Throwable;

final readonly class CompiledSchema
{
    /** @param array<string, mixed> $requestSchema */
    private function __construct(
        public array $requestSchema,
        private Schema $validationSchema,
    ) {}

    /** @param array<string, Type> $properties */
    public static function fromNative(array $properties): self
    {
        try {
            $requestSchema = (new ObjectSchema($properties))->toSchema();
            self::rejectExternalReferences($requestSchema);

            $encoded = json_encode($requestSchema, JSON_THROW_ON_ERROR);
            $schemaObject = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);

            if (! is_object($schemaObject)) {
                throw new \UnexpectedValueException('The generated root schema is not an object.');
            }

            $parser = (new SchemaParser)->setDefaultDraftVersion('2020-12');
            $loader = new SchemaLoader($parser, null, false);
            $validationSchema = $loader->loadObjectSchema($schemaObject, draft: '2020-12');
        } catch (Throwable $exception) {
            throw ConfigurationException::make(
                'invalid_schema',
                'The generated extraction schema is invalid or contains an external reference.',
                $exception,
            );
        }

        return new self($requestSchema, $validationSchema);
    }

    /** @return array<string, mixed> */
    public function validate(string $json): array
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidAiOutputException('The provider returned malformed or truncated JSON.');
        }

        $validator = new Validator(new SchemaLoader(
            (new SchemaParser)->setDefaultDraftVersion('2020-12'),
            null,
            false,
        ));
        $result = $validator->validate($value, $this->validationSchema);

        if (! $result->isValid()) {
            $error = $result->error();
            $segments = $error?->data()->fullPath() ?? [];
            $path = $segments === []
                ? '$'
                : '$.'.implode('.', array_map(static fn (int|string $segment): string => (string) $segment, $segments));

            throw new InvalidAiOutputException(
                'The provider response did not match the requested extraction schema.',
                $path,
            );
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new InvalidAiOutputException('The provider response was not a structured object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<mixed> $node */
    private static function rejectExternalReferences(array $node): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && (! is_string($value) || ($value !== '#' && ! str_starts_with($value, '#/')))) {
                throw new \UnexpectedValueException('External JSON Schema references are disabled.');
            }

            if (is_array($value)) {
                self::rejectExternalReferences($value);
            }
        }
    }
}
