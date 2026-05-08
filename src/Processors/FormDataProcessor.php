<?php

namespace Samody\PostmanGenerator\Processors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use ReflectionParameter;

class FormDataProcessor
{
    public function process($reflectionMethod): Collection
    {
        $rules = collect();

        /** @var ReflectionParameter $rulesParameter */
        $rulesParameter = collect($reflectionMethod->getParameters())
            ->first(function ($parameter) {
                $type = $parameter->getType();

                if (! $type) {
                    return false;
                }

                if ($type instanceof \ReflectionNamedType) {
                    return is_subclass_of($type->getName(), FormRequest::class);
                }

                if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                    foreach ($type->getTypes() as $subType) {
                        if ($subType instanceof \ReflectionNamedType && is_subclass_of($subType->getName(), FormRequest::class)) {
                            return true;
                        }
                    }
                }

                return false;
            });

        if ($rulesParameter) {
            $type = $rulesParameter->getType();
            $typeName = null;

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
            } elseif ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                foreach ($type->getTypes() as $subType) {
                    if ($subType instanceof \ReflectionNamedType && is_subclass_of($subType->getName(), FormRequest::class)) {
                        $typeName = $subType->getName();
                        break;
                    }
                }
            }

            if ($typeName) {
                /** @var FormRequest $class */
                $class = new $typeName();

                $classRules = method_exists($class, 'rules') ? $class->rules() : [];

                foreach ($classRules as $fieldName => $rule) {
                    $rules->put($fieldName, $rule);

                    if (is_array($rule) && in_array('confirmed', $rule)) {
                        $rules->put($fieldName.'_confirmation', $rule);
                    } elseif (is_string($rule) && str_contains($rule, 'confirmed')) {
                        $rules->put($fieldName.'_confirmation', $rule);
                    }
                }
            }
        }

        return $rules;
    }
}
