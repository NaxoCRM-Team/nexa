<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/MarketingEmail/MarketingAllowance.php';
use Espo\Custom\Tools\MarketingEmail\MarketingAllowance;
foreach ([[1000,0,20000],[1000,10000,10000],[1000,30000,20000],[null,0,0],[0,100,0],[50000,0,1000000]] as [$tier,$cap,$expected]) {
    if (MarketingAllowance::calculate($tier,$cap) !== $expected) throw new RuntimeException('Marketing allowance mismatch.');
}
foreach ([[1000,0,1000],[1000,500,500],[1000,30000,1000],[null,0,0],[0,100,0],[-1,0,0]] as [$limit,$cap,$expected]) {
    if (MarketingAllowance::calculateTransactional($limit,$cap) !== $expected) throw new RuntimeException('Transactional message allowance mismatch.');
}
echo "Marketing and separate transactional allowance tests passed.\n";
