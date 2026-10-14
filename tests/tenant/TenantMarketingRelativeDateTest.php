<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison as Calendar;
use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison as Timestamp;

$checks=0;
$assert=static function (bool $ok,string $message) use (&$checks): void {$checks++; if (!$ok) throw new RuntimeException($message);};
$zone=date_default_timezone_get();
try {
    foreach (['UTC','Europe/London','America/New_York','Asia/Kolkata'] as $workerZone) {
        date_default_timezone_set($workerZone);
        $now=new DateTimeImmutable('2028-02-29T23:30:00.500000Z');
        foreach ([['2028-02-29','within_next_days','0',true],['2028-02-29','within_last_days','0',true],['2028-03-01','within_next_days','1',true],['2028-03-02','within_next_days','1',false],['2028-02-28','within_next_days','1',false],['2028-02-28','within_last_days','1',true],['2028-02-27','within_last_days','1',false],['2028-03-01','within_last_days','1',false],[null,'within_next_days','30',false],['','within_last_days','30',false]] as [$value,$operator,$days,$expected]) {
            $assert(Calendar::matches($value,$operator,$days,$now)===$expected,'Calendar windows must use inclusive UTC-day boundaries.');
        }
        foreach ([['2028-03-01 23:30:00.500000','within_next_days','1',true],['2028-03-01 23:30:00.500001','within_next_days','1',false],['2028-02-29 23:30:00.499999','within_next_days','1',false],['2028-02-28 23:30:00.500000','within_last_days','1',true],['2028-02-28 23:30:00.499999','within_last_days','1',false],['2028-02-29 23:30:00.500001','within_last_days','1',false],['2028-02-29 23:30:00.500000','within_next_days','0',true],[null,'within_last_days','1',false]] as [$value,$operator,$days,$expected]) {
            $assert(Timestamp::matches($value,$operator,$days,$now)===$expected,'Timestamp windows must preserve exact elapsed UTC and microseconds.');
        }
        $offsetNow=new DateTimeImmutable('2028-03-01T01:30:00+02:00');
        $assert(Calendar::matches('2028-02-29','within_next_days','0',$offsetNow),'Injected clocks must normalize to UTC before choosing today.');
        $dst=new DateTimeImmutable('2026-03-29T00:30:00Z');
        $assert(Timestamp::matches('2026-03-30 00:30:00','within_next_days','1',$dst),'Timestamp days must remain 24 hours across DST.');
        $assert(!Calendar::matches('2028-03-01','within_next_days','0',$now) && Calendar::matches('2028-03-01','within_next_days','0',$now->modify('+1 day')),'Relative calendar rules must change with the evaluation clock, not freeze to a saved date.');
        $assert(Timestamp::matches('2028-03-01 23:30:00.500000','within_next_days','1',$now) && !Timestamp::matches('2028-03-01 23:30:00.500000','within_next_days','1',$now->modify('+2 days')),'Relative timestamp rules must be reevaluated when the clock advances.');
    }
} finally {date_default_timezone_set($zone);}
foreach (['-1','366','01','1.5','',1,null,'30 days'] as $invalid) {
    foreach ([Calendar::class,Timestamp::class] as $helper) {
        $rejected=false; try {$helper::matches(null,'within_next_days',$invalid);} catch (BadRequest) {$rejected=true;}
        $assert($rejected,'Invalid relative windows must fail even with a missing recipient value.');
    }
}
echo "Relative date condition contract passed: {$checks} checks.\n";
