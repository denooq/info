<?php
declare(strict_types=1);
namespace Customs\Domain;

final class DeclarationDate
{
    public static function today(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Asia/Qyzylorda'))->format('Y-m-d');
    }

    public static function defaults(array $draft, int $timestamp): array
    {
        if (($draft['declaration']['signatureDate'] ?? null) === null) {
            $draft['declaration']['signatureDate']=self::today($timestamp);
            $draft['fieldStates']['declaration/_/signatureDate']=self::meta('current_date');
        }
        $date=$draft['declaration']['signatureDate'];
        if (($draft['declaration']['calculationDate'] ?? null)!==$date) {
            $draft['declaration']['calculationDate']=$date;
            $draft['fieldStates']['declaration/_/calculationDate']=self::meta('linked');
        }
        foreach ($draft['goods'] as &$goods) {
            $address='goods/'.$goods['id'].'/ratesDate';
            if (($goods['ratesDate']??null)===null || ($draft['fieldStates'][$address]['status']??'')==='linked') {
                $goods['ratesDate']=$date;$draft['fieldStates'][$address]=self::meta('linked');
            }
        }
        unset($goods);
        return $draft;
    }

    public static function meta(string $status): array
    {
        return ['status'=>$status,'candidateId'=>null,'evidenceIds'=>[],'conflict'=>false,'needsReview'=>false];
    }
}
