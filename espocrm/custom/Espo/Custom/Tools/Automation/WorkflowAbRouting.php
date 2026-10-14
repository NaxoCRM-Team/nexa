<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\Exceptions\BadRequest;

final class WorkflowAbRouting
{
    /** Sticky per publication, Contact and step; intentionally not rerandomized on reenrollment. */
    public static function assignment(array $identity, string $publicationHash, int $weightA): array
    {
        if ($weightA<1 || $weightA>99 || count($identity)!==6 || !preg_match('/^[a-f0-9]{64}$/D',$publicationHash)) throw new BadRequest('A published workflow and a split from 1 to 99 percent are required.');
        $hash=hash_hmac('sha256',json_encode(array_values($identity),JSON_THROW_ON_ERROR),$publicationHash);
        $bucket=(int)hexdec(substr($hash,0,8))%10000;
        return ['branch'=>$bucket<$weightA*100?'A':'B','hash'=>$hash];
    }
}
