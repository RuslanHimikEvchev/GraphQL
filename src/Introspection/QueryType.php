<?php
/**
 * Date: 03.12.15
 *
 * @author Portey Vasil <portey@gmail.com>
 */

namespace Youshido\GraphQL\Introspection;

use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Field\Field;
use Youshido\GraphQL\Introspection\Field\SchemaField;
use Youshido\GraphQL\Introspection\Traits\TypeCollectorTrait;
use Youshido\GraphQL\Type\AbstractType;
use Youshido\GraphQL\Type\CompositeTypeInterface;
use Youshido\GraphQL\Type\Enum\AbstractEnumType;
use Youshido\GraphQL\Type\InputObject\AbstractInputObjectType;
use Youshido\GraphQL\Type\ListType\ListType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeMap;
use Youshido\GraphQL\Type\Union\AbstractUnionType;

class QueryType extends AbstractObjectType
{

    use TypeCollectorTrait;

    /**
     * @return String type name
     */
    public function getName()
    {
        return '__Type';
    }

    public static function resolveOfType(AbstractType $value)
    {
        if ($value instanceof CompositeTypeInterface) {
           return $value->getTypeOf();
        }

        return null;
    }

    public static function resolveInputFields($value)
    {
        if ($value instanceof AbstractInputObjectType) {
            /** @var AbstractObjectType $value */
            return $value->getConfig()->getFields();
        }

        return null;
    }

    public static function resolveEnumValues($value)
    {
        /** @var $value AbstractType|AbstractEnumType */
        if ($value && $value->getKind() == TypeMap::KIND_ENUM) {
            $data = [];
            foreach ($value->getValues() as $enumValue) {
                if (!array_key_exists('description', $enumValue)) {
                    $enumValue['description'] = '';
                }
                if (!array_key_exists('isDeprecated', $enumValue)) {
                    $enumValue['isDeprecated'] = false;
                }
                if (!array_key_exists('deprecationReason', $enumValue)) {
                    $enumValue['deprecationReason'] = '';
                }

                $data[] = $enumValue;
            }

            return $data;
        }

        return null;
    }

    public static function resolveFields($value)
    {
        /** @var AbstractType $value */
        if (!$value ||
            in_array($value->getKind(), [TypeMap::KIND_SCALAR, TypeMap::KIND_UNION, TypeMap::KIND_INPUT_OBJECT, TypeMap::KIND_ENUM])
        ) {
            return null;
        }

        /** @var AbstractObjectType $value */
        $fields = $value->getConfig()->getFields();

        foreach ($fields as $key => $field) {
            if (in_array($field->getName(), ['__type', '__schema'])) {
                unset($fields[$key]);
            }
        }

        return $fields;
    }

    public static function resolveInterfaces($value)
    {
        /** @var $value AbstractType */
        if ($value->getKind() == TypeMap::KIND_OBJECT) {
            /** @var $value AbstractObjectType */
            return $value->getConfig()->getInterfaces() ?: [];
        }

        return null;
    }

    public function resolvePossibleTypes($value, $args, ResolveInfo $info)
    {
        /** @var $value AbstractObjectType */
        if ($value->getKind() == TypeMap::KIND_INTERFACE) {
            $this->collectTypes(SchemaField::$schema->getQueryType());

            $possibleTypes = [];
            foreach ($this->types as $type) {
                /** @var $type AbstractObjectType */
                if ($type->getKind() == TypeMap::KIND_OBJECT) {
                    $interfaces = $type->getConfig()->getInterfaces();

                    if ($interfaces) {
                        foreach ($interfaces as $interface) {
                            if (get_class($interface) == get_class($value)) {
                                $possibleTypes[] = $type;
                            }
                        }
                    }
                }
            }

            return $possibleTypes ?: [];
        } elseif ($value->getKind() == TypeMap::KIND_UNION) {
            /** @var $value AbstractUnionType */
            return $value->getTypes();
        }

        return null;
    }

    public function build($config)
    {
        $config
            ->addField('name', TypeMap::TYPE_STRING)
            ->addField('kind', TypeMap::TYPE_STRING)
            ->addField('description', TypeMap::TYPE_STRING)
            ->addField('ofType', [
                'type'    => new QueryType(),
                'resolve' => [get_class($this), 'resolveOfType']
            ])
            ->addField(new Field([
                'name'    => 'inputFields',
                'type'    => new ListType(new InputValueType()),
                'resolve' => [get_class($this), 'resolveInputFields']
            ]))
            ->addField(new Field([
                'name'    => 'enumValues',
                'type'    => new ListType(new EnumValueType()),
                'resolve' => [get_class($this), 'resolveEnumValues']
            ]))
            ->addField(new Field([
                'name'    => 'fields',
                'type'    => new ListType(new FieldType()),
                'resolve' => [get_class($this), 'resolveFields']
            ]))
            ->addField(new Field([
                'name'    => 'interfaces',
                'type'    => new ListType(new QueryType()),
                'resolve' => [get_class($this), 'resolveInterfaces']
            ]))
            ->addField('possibleTypes', [
                'type'    => new ListType(new QueryType()),
                'resolve' => [$this, 'resolvePossibleTypes']
            ]);
    }

    public function isValidValue($value)
    {
        return $value instanceof AbstractType;
    }


}
