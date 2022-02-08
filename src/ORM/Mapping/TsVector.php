<?php
/**
 * @author: James Murray <jaimz@vertigolabs.org>
 * @copyright:
 * @date: 9/15/2015
 * @time: 3:20 PM
 */

namespace VertigoLabs\DoctrineFullTextPostgres\ORM\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class TsVector
{
    /*
     * @Annotation\Enum({"A","B","C","D"})
     */
    //public string $weight = 'D';

    /** @param string[] $weight */
    public function __construct(
        array $fields,
        public string $name,
        public string $weight = 'D',
        public string $language = 'english'
    ) {
        $this->fields = $fields;
        $this->name = $name;
        $this->weight = $weight;
        $this->language = $language;
    }
}
