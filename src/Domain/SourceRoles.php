<?php
declare(strict_types=1);
namespace Customs\Domain;

final class SourceRoles
{
    public static function data(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__,2).'/resources/registry/source-roles.v2.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public static function forCode(?string $code): ?string
    {
        foreach(self::data()['roles'] as $role=>$item) if(in_array($code,$item['codes'],true)) return $role;
        return null;
    }
    public static function rank(string $graphId, ?string $code): ?int
    {
        $role=self::forCode($code);if($role===null)return null;
        $label=self::data()['roles'][$role]['sourceLabel'];
        $matrix=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/registry/graphs.sources.v2.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($matrix['graphs'] as $graph) if($graph['id']===$graphId) {
            foreach($graph['priorities'] as $i=>$source) if(mb_strtolower(trim($source??''))===$label)return $i+1;
        }
        return null;
    }
}
