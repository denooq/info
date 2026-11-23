<?php
declare(strict_types=1);
namespace Customs\Domain;

final class GraphSources
{
    /** null means this official source has no verified role for the input graph. */
    public static function rank(string $fieldId,array $row,?string $code):?int
    {
        if(str_starts_with($fieldId,'document.'))return $code===null?null:1;
        if(str_starts_with($fieldId,'cost.'))return in_array(SourceRoles::forCode($code),['freight_invoice','invoice','contract'],true)?1:null;
        $ranks=[];
        foreach((new GraphRegistry())->graphs() as $graph) {
            if(!in_array($fieldId,$graph['fieldBindings'],true))continue;
            if(isset($graph['entityRole'])&&($row['role']??$row['stage']??null)!==$graph['entityRole'])continue;
            $rank=SourceRoles::rank($graph['id'],$code);if($rank!==null)$ranks[]=$rank;
        }
        return $ranks?min($ranks):null;
    }
}
