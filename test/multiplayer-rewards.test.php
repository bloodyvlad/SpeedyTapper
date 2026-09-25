<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\CoinProgression;
use SpeedyTapper\MultiplayerRewardProgression;
use SpeedyTapper\MultiplayerV2ResultService;

require dirname(__DIR__) . '/server/autoload.php';
$assertions = 0;
$assert = static function (bool $ok, string $message) use (&$assertions): void {
    $assertions++;
    if (!$ok) throw new RuntimeException($message);
};
foreach ([[0,0,0,0], [0,30000,0,30000], [0,59999,0,59999], [59999,1,2,0],
    [45000,15000,2,0], [60000,59999,0,59999], [59999,900000,30,59999]] as [$before,$time,$coins,$remainder]) {
    $value = MultiplayerRewardProgression::accrue($before, $time);
    $assert($value->coinsEarned === $coins && $value->remainderMs === $remainder
        && $value->totalAliveMs === $before + $time, 'Exact independent multiplayer minute arithmetic.');
}
$assert(CoinProgression::accrue(59999,1)->coinsEarned === 1, 'Arcade still grants one coin per minute.');
foreach ([[-1,0],[0,-1],[0,900001],[PHP_INT_MAX,1]] as [$total,$delta]) {
    try { MultiplayerRewardProgression::accrue($total,$delta); $assert(false,'Invalid time accepted.'); }
    catch (InvalidArgumentException) { $assert(true,'Invalid and overflowing time rejected.'); }
}
$normalize = new ReflectionMethod(MultiplayerV2ResultService::class, 'normalize');
$seat = ['playerID'=>'11111111-1111-4111-8111-111111111111','seat'=>0,'score'=>100,
    'lives'=>0,'hits'=>1,'misses'=>3,'dodges'=>0,'reactionTotalMs'=>100,'fastestReactionMs'=>100];
$legacy = ['matchID'=>'33333333-3333-4333-8333-333333333333','protocolVersion'=>2,
    'ruleset'=>'multiplayer-shared-arcade-v2','durationMs'=>60000,'rankingEligible'=>false,
    'players'=>[$seat,[...$seat,'playerID'=>'22222222-2222-4222-8222-222222222222','seat'=>1]]];
$assert($normalize->invoke(null,$legacy) === $legacy, 'Legacy normalized payload/digest is byte-compatible.');
$modern = [...$legacy,'rankingEligible'=>true,'resultRevision'=>2,'gameplayRevision'=>3,
    'rewardPolicy'=>'multiplayer-alive-minute-v1','matchKind'=>'competitive','completionReason'=>'completed'];
$modern['players'] = array_map(static fn(array $p):array=>[...$p,'eligibleAliveMs'=>45000,
    'economyGeneration'=>0,'survivalMs'=>60000,'maxMultiplier'=>2],$legacy['players']);
$assert($normalize->invoke(null,$modern) === $modern, 'Exact new contract is preserved.');
$missingGeneration = $modern;
unset($missingGeneration['players'][0]['economyGeneration']);
$assert($normalize->invoke(null,$missingGeneration)['players'][0]['economyGeneration'] === null,
    'Missing generation remains explicitly unavailable, never zero.');
$missingGeneration['players'][0]['economyGeneration'] = null;
$assert($normalize->invoke(null,$missingGeneration)['players'][0]['economyGeneration'] === null,
    'Explicit null generation is accepted for an unrewarded seat.');
foreach ([['matchKind','tutorial'],['completionReason','aborted']] as [$field,$value]) {
    $ineligible = [...$modern,$field=>$value,'rankingEligible'=>false];
    $assert(!$normalize->invoke(null,$ineligible)['rankingEligible'], 'Tutorial/abort cannot rank or earn.');
}
$bad = [];
foreach ([['resultRevision',1],['resultRevision','2'],['gameplayRevision',2],['rewardPolicy','legacy'],
    ['rankingEligible',false],['matchKind','practice'],['completionReason','unknown'],['coinsEarned',2]] as [$key,$value]) {
    $bad[] = [...$modern,$key=>$value];
}
$partial=$modern; unset($partial['rewardPolicy']); $bad[]=$partial;
$partial=$legacy; $partial['rewardPolicy']='multiplayer-alive-minute-v1'; $bad[]=$partial;
$bad[]=[...$legacy,'rankingEligible'=>true];
foreach ([['eligibleAliveMs',60001],['eligibleAliveMs',-1],['eligibleAliveMs','45000'],
    ['survivalMs',60001],['maxMultiplier',0],['maxMultiplier',6],['economyGeneration',-1],
    ['economyGeneration',4294967296],['economyGeneration','0'],['coinsEarned',2]] as [$field,$value]) {
    $item=$modern; $item['players'][0][$field]=$value; $bad[]=$item;
}
foreach ($bad as $index=>$body) {
    try { $normalize->invoke(null,$body); $assert(false,'Malformed reward result accepted '.$index); }
    catch (ApiException $error) { $assert(in_array($error->status,[400,409],true),'Malformed exact contract rejected.'); }
}
echo "Multiplayer reward contract checks passed ({$assertions} assertions).\n";
