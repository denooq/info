<?php
declare(strict_types=1);
test('source roles use only official codes and cover both reference shipments without filename aliases',function():void {
    $catalog=new Customs\Domain\Classifiers();
    foreach(Customs\Domain\SourceRoles::data()['roles'] as $role=>$row) foreach($row['codes'] as $code) truth($catalog->document($code)!==null,$code);
    foreach(['02015'=>'transport','02017'=>'transport','04021'=>'invoice','03011'=>'contract','04131'=>'packing','04031'=>'freight_invoice','09013'=>'transit','06014'=>'origin_certificate','01401'=>'conformity_certificate'] as $code=>$role) same($role,Customs\Domain\SourceRoles::forCode($code));
    same(null,Customs\Domain\SourceRoles::forCode('CMR'));same(null,Customs\Domain\SourceRoles::forCode('04032'));
    same(null,Customs\Domain\SourceRoles::forCode('01408'));
    same(1,Customs\Domain\SourceRoles::rank('35','02015'));same(2,Customs\Domain\SourceRoles::rank('35','04131'));same(3,Customs\Domain\SourceRoles::rank('35','04021'));
    same(1,Customs\Domain\SourceRoles::rank('38','04131'));same(null,Customs\Domain\SourceRoles::rank('38','01401'));
});
