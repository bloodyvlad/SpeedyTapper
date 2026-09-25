<?php

declare(strict_types=1);

use SpeedyTapper\AccountDeletionService;
use SpeedyTapper\ApiException;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\LeaderboardModerationService;
use SpeedyTapper\MigrationRunner;
use SpeedyTapper\MultiplayerV2LeaderboardRepository;
use SpeedyTapper\MultiplayerV2ResultService;
use SpeedyTapper\Uuid;

require dirname(__DIR__) . '/server/autoload.php';
$dsn = getenv('SPEEDYTAPPER_MP29_DSN') ?: '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=speedytapper_mp29_rewards;charset=utf8mb4$/D', $dsn)) {
    throw new RuntimeException('Only the disposable loopback reward database is permitted.');
}
$db = new PDO($dsn,'root','root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone = '+00:00'");
if (($argv[1] ?? null) === '--store') {
    $body = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    fwrite(STDOUT, "ready\n");
    $worker = new MultiplayerV2ResultService($db, new MultiplayerV2LeaderboardRepository($db,'season-1','Season 1'));
    fwrite(STDOUT, json_encode($worker->store($body), JSON_THROW_ON_ERROR));
    exit;
}
if ((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('The disposable database must start empty.');
}
$assertions = 0;
$assert = static function(bool $ok,string $message)use(&$assertions):void {
    $assertions++; if(!$ok)throw new RuntimeException($message);
};
$throws = static function(int $status,callable $work,string $message)use($assert):void {
    try {$work();$assert(false,$message);}catch(ApiException $e){$assert($e->status===$status,$message);}
};
$query = static function(string $sql,array $values=[])use($db):PDOStatement {$s=$db->prepare($sql);$s->execute($values);return $s;};
$row = static function(string $id)use($query):array {return $query('SELECT * FROM players WHERE id=?',[$id])->fetch();};
$newPlayer = static function(string $name)use($query):string {
    $id=Uuid::v4();$query('INSERT INTO players(id,nickname,nickname_confirmed)VALUES(?,?,1)',[$id,$name]);return $id;
};
final class RewardBaselineReached extends RuntimeException {}
$migrations = new MigrationRunner($db,dirname(__DIR__).'/server/migrations');
try {
    $migrations->run(static function(string $name):void {
        if($name==='024_multiplayer_v2_heart_result_bounds.sql')throw new RewardBaselineReached();
    });
    throw new RuntimeException('Expected the pre-reward baseline boundary.');
} catch(RewardBaselineReached) {}
$assert((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===24,'Exact existing schema001–024 before upgrade.');
$a=$newPlayer('RewardOne');$b=$newPlayer('RewardTwo');$c=$newPlayer('RewardThree');
$query('UPDATE players SET coin_time_remainder_ms=45000,total_play_ms=45000,total_coins_collected=7 WHERE id=?',[$a]);
$baseSeat = static fn(string $id,int $seat):array => ['playerID'=>$id,'seat'=>$seat,'score'=>1000,
    'lives'=>0,'hits'=>5,'misses'=>3,'dodges'=>2,'reactionTotalMs'=>1000,'fastestReactionMs'=>150];
$legacy=['matchID'=>Uuid::v4(),'protocolVersion'=>2,'ruleset'=>'multiplayer-shared-arcade-v2',
    'durationMs'=>60000,'rankingEligible'=>false,'players'=>[$baseSeat($a,0),$baseSeat($b,1)]];
$oldService=new MultiplayerV2ResultService($db);
$assert($oldService->store($legacy)['state']==='stored_unranked','Old outbox works before025.');
$legacyHash=$query('SELECT payload_hash FROM multiplayer_v2_results WHERE match_id=?',[$legacy['matchID']])->fetchColumn();
$beforeUpgrade=$row($a);
$assert($migrations->run()===['025_multiplayer_v2_rewards_and_leaderboard.sql'],'Upgrade applies only additive025.');
$assert($migrations->run()===[],'Migration runner retry is a no-op.');
$sql=file_get_contents(dirname(__DIR__).'/server/migrations/025_multiplayer_v2_rewards_and_leaderboard.sql');
foreach(MigrationRunner::splitStatements($sql)as$statement)$db->exec($statement);
$assert($row($a)===$beforeUpgrade,'025 replay preserves every existing wallet/profile value.');
$assert($query('SELECT payload_hash FROM multiplayer_v2_results WHERE match_id=?',[$legacy['matchID']])->fetchColumn()===$legacyHash,
    '025 preserves legacy result digest exactly.');
$assert((int)$db->query('SELECT COUNT(*) FROM multiplayer_v2_reward_receipts')->fetchColumn()===0,'No historical reward backfill.');
$board=new MultiplayerV2LeaderboardRepository($db,'season-1','Season 1');
$service=new MultiplayerV2ResultService($db,$board);
$assert($board->payload(null)['totalEntries']===0,'Fresh global lane has no old testboard entries.');
$assert($service->store($legacy)['duplicate'],'Old result retry still stores no rewards after025.');
$assert((int)$db->query('SELECT COUNT(*) FROM coin_ledger')->fetchColumn()===0,'Old outbox remains economically inert.');
$modern = static function(array $ids,array $alive,array $scores=[],int $duration=90000)use($baseSeat):array {
    $players=[];
    foreach($ids as$seat=>$id)$players[]=[...$baseSeat($id,$seat),'score'=>$scores[$seat]??1000,
        'eligibleAliveMs'=>$alive[$seat],'survivalMs'=>$alive[$seat],'maxMultiplier'=>3,'economyGeneration'=>0];
    return ['matchID'=>Uuid::v4(),'protocolVersion'=>2,'ruleset'=>'multiplayer-shared-arcade-v2',
        'durationMs'=>$duration,'rankingEligible'=>true,'players'=>$players,'resultRevision'=>2,'gameplayRevision'=>3,
        'rewardPolicy'=>'multiplayer-alive-minute-v1','matchKind'=>'competitive','completionReason'=>'completed'];
};
$rewardFor=static function(array $receipt,string $id):array {
    foreach($receipt['rewards']as$reward)if($reward['playerID']===$id)return $reward;
    throw new RuntimeException('Missing expected reward receipt.');
};
$first=$modern([$a,$b],[45000,90000],[9000,1000]);
$receipt=$service->store($first);
$assert($receipt['rankingEligible']&&$receipt['state']==='stored_ranked'&&!$receipt['duplicate'],'New competitive match commits ranked receipt.');
$assert($rewardFor($receipt,$a)['coinsEarned']===0&&$rewardFor($receipt,$a)['remainderMs']===45000,'Partial MP minute carries without a fractional coin.');
$assert($rewardFor($receipt,$b)['coinsEarned']===2&&$rewardFor($receipt,$b)['remainderMs']===30000,'Ninety alive seconds mint2 with30s carry.');
$ownReceipt=$service->participantReceipt($first['matchID'],$a);
$expectedOwn=$rewardFor($receipt,$a);unset($expectedOwn['playerID']);
$assert($ownReceipt['matchID']===$first['matchID']&&$ownReceipt['reward']===$expectedOwn
    &&$ownReceipt['resultRevision']===2&&$ownReceipt['rewardPolicy']==='multiplayer-alive-minute-v1',
    'Private receipt binds exact match and returns only own immutable reward, not a current balance.');
foreach([$first['matchID'],Uuid::v4(),'invalid']as$id){
    $throws(404,fn()=>$service->participantReceipt($id,$c),'Unknown/nonparticipant/invalid IDs use the same private404.');
}
$throws(404,fn()=>$service->participantReceipt($legacy['matchID'],$a),'Old alpha result has no fabricated reward receipt.');
$assert((int)$row($a)['coin_time_remainder_ms']===45000&&(int)$row($a)['total_play_ms']===45000
    &&(int)$row($a)['total_coins_collected']===7,'Arcade carry/time/achievement counters untouched.');
$payload=$board->payload($a);$top=$payload['entries'][0];
$assert($payload['totalEntries']===2&&$top['name']==='RewardOne'&&$top['place']===1&&$top['survivalMs']===45000,
    'Higher score wins despite being eliminated earlier.');
$assert($top['verification']==='server_reported_v2'&&!isset($top['speedRatings'])&&!isset($top['playerID'])&&!isset($top['matchID']),
    'Public board reports correct trust without fabricated metrics/private IDs.');
$assert($top['maxMultiplier']===3&&$top['averageReactionMs']===200&&$top['isCurrentPlayer'],'Actual aggregate metrics retained.');
$stable=$row($a);$ledgerCount=(int)$db->query('SELECT COUNT(*) FROM coin_ledger')->fetchColumn();
$retry=$service->store($first);
$assert($retry['duplicate']&&$retry['rewards']===$receipt['rewards']&&$row($a)===$stable,'Exact retry preserves immutable receipt and wallet.');
$reordered=$first;$reordered['players']=array_reverse($reordered['players']);
$assert($service->store($reordered)['duplicate'],'Seat-order retry canonicalizes.');
$assert((int)$db->query('SELECT COUNT(*) FROM coin_ledger')->fetchColumn()===$ledgerCount&&$board->payload(null)['totalEntries']===2,
    'Outbox retries add neither rewards nor leaderboard duplicates.');
$changed=$first;$changed['players'][0]['eligibleAliveMs']++;
$changed['players'][0]['survivalMs']++;
$throws(409,fn()=>$service->store($changed),'Changed immutable result rejected.');
$upgraded=[...$first,'matchID'=>$legacy['matchID']];
$throws(409,fn()=>$service->store($upgraded),'Old immutable result cannot be upgraded to earn.');
$beforeInvalid=$row($a);
foreach(['rankingEligible'=>false,'coinsEarned'=>5000,'gameplayRevision'=>2]as$key=>$value){
    $invalid=[...$modern([$a,$b],[60000,60000]),$key=>$value];
    $throws($key==='gameplayRevision'?409:400,fn()=>$service->store($invalid),'Rejected reward capability cannot settle.');
    $assert($row($a)===$beforeInvalid&&(int)$query('SELECT COUNT(*) FROM multiplayer_v2_results WHERE match_id=?',[$invalid['matchID']])->fetchColumn()===0,
        'Rejected payload writes no aggregate or wallet value.');
}
$second=$modern([$a,$b],[15000,0],[500,200]);
$secondReceipt=$service->store($second);
$assert($rewardFor($secondReceipt,$a)['coinsEarned']===2&&$rewardFor($secondReceipt,$a)['remainderMs']===0,
    'Two separate matches combine only their multiplayer alive time.');
$assert((int)$row($a)['earned_coins']===2&&(int)$row($a)['coins']===2,'Gross reward enters the earned wallet.');
$assert((int)$db->query('SELECT COUNT(*) FROM player_achievements')->fetchColumn()===0,'Multiplayer awards no achievements.');
$assert((int)$db->query('SELECT COUNT(*) FROM game_center_publication_outbox')->fetchColumn()===0,'Multiplayer does not enqueue Game Center.');
foreach([['matchKind','tutorial'],['completionReason','aborted']]as[$key,$value]){
    $ineligible=[...$modern([$a,$b],[60000,60000]),$key=>$value,'rankingEligible'=>false];
    $balance=$row($a);$entries=$board->payload(null)['totalEntries'];
    $out=$service->store($ineligible);
    $assert(!$out['rankingEligible']&&$out['state']==='stored_unranked'&&$rewardFor($out,$a)['coinStatus']==='ineligible',
        'Tutorial/aborted context stores an explicitly ineligible receipt.');
    $assert($row($a)===$balance&&$board->payload(null)['totalEntries']===$entries,'Ineligible context changes no wallet, carry or board.');
}
$nullGeneration=$modern([$a,$b],[60000,60000]);$nullGeneration['players'][0]['economyGeneration']=null;
$nullReceipt=$service->store($nullGeneration);
$assert($rewardFor($nullReceipt,$a)['coinsEarned']===0&&$rewardFor($nullReceipt,$a)['coinStatus']==='missing_generation',
    'Unavailable generation never assumes zero or grants rewards.');
$assert($nullReceipt['rankingEligible'],'Reward generation does not withdraw an otherwise valid score.');
$ties=$modern([$a,$b,$c],[1000,2000,3000],[25000,25000,1000]);$service->store($ties);
$tiePlaces=$query('SELECT placement FROM multiplayer_v2_leaderboard_entries WHERE match_id=? ORDER BY seat',[$ties['matchID']])->fetchAll(PDO::FETCH_COLUMN);
$assert(array_map('intval',$tiePlaces)===[1,1,3],'Equal scores share competition place without survival tiebreak.');
$tieBoard=$board->payload(null)['entries'];
$assert($tieBoard[0]['rank']===1&&$tieBoard[1]['rank']===1&&$tieBoard[0]['position']!==$tieBoard[1]['position'],
    'Global score ties have equal rank and unique presentation identity.');

// Refund/earned debt provenance: the paid lot itself remains untouched.
$debtPlayer=$newPlayer('RewardDebt');
$query('UPDATE players SET purchased_coins=7,coins=7,earned_coin_debt=1,refund_coin_debt=2,coin_debt=3 WHERE id=?',[$debtPlayer]);
$transaction='Sandbox:mp29-refund';
$query('INSERT INTO storekit_transactions(transaction_id,apple_transaction_id,original_transaction_id,player_id,account_token_pseudonym, '
    . 'product_id,product_type,ownership_type,environment,bundle_id,signed_quantity,purchase_date_ms,signed_date_ms, '
    . 'lifecycle_signed_date_ms,status,refund_debt_created,refund_debt_outstanding,base_refund_debt_outstanding,payload_hash) '
    . "VALUES(?,?,?,?,?,'fixture.coins','consumable','PURCHASED','Sandbox','fixture.bundle',1,1,1,1,'refunded',2,2,2,?)",
    [$transaction,'mp29-refund',$transaction,$debtPlayer,str_repeat('p',32),str_repeat('h',32)]);
$query('INSERT INTO purchased_coin_lots(transaction_id,player_id,gross_coins,available_coins)VALUES(?,?,7,7)',[$transaction,$debtPlayer]);
$lotBefore=$query('SELECT * FROM purchased_coin_lots WHERE transaction_id=?',[$transaction])->fetch();
$debtMatch=$modern([$debtPlayer,$c],[60000,0]);$debtReceipt=$service->store($debtMatch);
$assert($rewardFor($debtReceipt,$debtPlayer)['coinsEarned']===2&&(int)$row($debtPlayer)['refund_coin_debt']===0
    &&(int)$row($debtPlayer)['earned_coin_debt']===1&&(int)$row($debtPlayer)['purchased_coins']===7,
    'Earned multiplayer credits clear exact refund debt first, preserving purchased value.');
$allocation=$query('SELECT * FROM storekit_refund_debt_allocations WHERE player_id=?',[$debtPlayer])->fetch();
$assert($allocation['source_type']==='earned_credit'&&$allocation['source_reference']==='mp2:'.$debtMatch['matchID'].':'.$debtPlayer
    &&(int)$allocation['amount']===2&&(int)$allocation['source_economy_generation']===0,'Refund repayment retains exact MP source/generation.');
$service->store($modern([$debtPlayer,$c],[60000,0]));
$assert((int)$row($debtPlayer)['earned_coins']===1&&(int)$row($debtPlayer)['earned_coin_debt']===0
    &&(int)$row($debtPlayer)['coins']===8,'Next reward repays earned debt before becoming spendable.');
$assert($query('SELECT * FROM purchased_coin_lots WHERE transaction_id=?',[$transaction])->fetch()===$lotBefore,'Paid lot is byte-identical after earned rewards.');

// Actual reset service must reopen exact MP-funded refund debt, preserve paid
// lots and prevent the next generation from inheriting any old MP time.
$arcadeBoard=new \SpeedyTapper\LeaderboardRepository($db,'season-1','Season 1');$arcadeBoard->ensureSeason();
$admin=$newPlayer('RewardAdmin');
$query("INSERT INTO player_roles(player_id,role,granted_by,reason)VALUES(?,'leaderboard_admin','fixture','Disposable reward reset')",[$admin]);
$resetEntry=Uuid::v4();
$query("INSERT INTO leaderboard_entries(id,season_id,player_id,mode,score,duration_ms,correct_taps, "
    . "dodge_count,godlike_count,perfect_count,great_count,good_count,verification_status) "
    . "VALUES(?,'season-1',?,'normal',1,1000,1,0,0,0,0,1,'quarantined')",[$resetEntry,$debtPlayer]);
$moderation=new LeaderboardModerationService($db);
$reset=$moderation->deleteAndReset($resetEntry,$admin,'Check generation-bound multiplayer earned reset.','quarantined',$debtPlayer);
$assert($reset['applied']&&(int)$row($debtPlayer)['economy_generation']===1&&(int)$row($debtPlayer)['earned_coins']===0,
    'Real moderation reset advances generation and clears only earned balance.');
$assert((int)$row($debtPlayer)['refund_coin_debt']===2&&(int)$row($debtPlayer)['purchased_coins']===7
    &&$query('SELECT * FROM purchased_coin_lots WHERE transaction_id=?',[$transaction])->fetch()===$lotBefore,
    'Reset reopens the exact2 refund debt paid with old MP credit and preserves paid lot.');
$assert($query('SELECT source_revoked_at FROM storekit_refund_debt_allocations WHERE player_id=?',[$debtPlayer])->fetchColumn()!==null,
    'Reset retains revoked settlement provenance instead of deleting it.');
$assert($moderation->deleteAndReset($resetEntry,$admin,'Exact repeated reset.','quarantined',$debtPlayer)['duplicate'],
    'Repeated reset does not reopen refund debt twice.');
$resetFresh=$modern([$debtPlayer,$c],[45000,0]);$resetFresh['players'][0]['economyGeneration']=1;
$resetFreshReceipt=$service->store($resetFresh);
$assert($rewardFor($resetFreshReceipt,$debtPlayer)['coinsEarned']===0&&$rewardFor($resetFreshReceipt,$debtPlayer)['totalAliveMs']===45000,
    'Actual reset fences independent MP time; old two-minute history does not return.');
$resetWallet=$row($debtPlayer);$service->store($debtMatch);
$assert($row($debtPlayer)===$resetWallet,'Old successful outbox retry cannot repay reset debt again.');

// Earned multiplayer value uses ordinary earned-first spending. A mixed-funded
// cosmetic must survive a later earned reset with its exact paid allocation.
$mixedCredit=$modern([$debtPlayer,$c],[225000,0],[],225000);$mixedCredit['players'][0]['economyGeneration']=1;
$service->store($mixedCredit);
$wallets=new CoinWalletRepository($db);
$pets=new \SpeedyTapper\PetShopService($db,new \SpeedyTapper\AchievementService($db,$wallets),$wallets);
$assert($pets->select($debtPlayer,'foka')['purchased'],'Existing shop accepts confirmed multiplayer earned coins.');
$pet=$query("SELECT * FROM player_pets WHERE player_id=? AND pet_id='foka'",[$debtPlayer])->fetch();
$funding=$query('SELECT source,amount FROM coin_spend_allocations WHERE spend_event_id=? ORDER BY source',[$pet['purchase_event_id']])->fetchAll();
$assert(array_column($funding,'source')===['earned','purchased']&&array_map('intval',array_column($funding,'amount'))===[6,4],
    'Mixed purchase spends six MP-earned coins before four purchased coins with exact allocation.');
$resetEntry2=Uuid::v4();
$query("INSERT INTO leaderboard_entries(id,season_id,player_id,mode,score,duration_ms,correct_taps, "
    . "dodge_count,godlike_count,perfect_count,great_count,good_count,verification_status) "
    . "VALUES(?,'season-1',?,'normal',1,1000,1,0,0,0,0,1,'quarantined')",[$resetEntry2,$debtPlayer]);
$moderation->deleteAndReset($resetEntry2,$admin,'Preserve multiplayer mixed-funded cosmetic.','quarantined',$debtPlayer);
$assert($query("SELECT * FROM player_pets WHERE player_id=? AND pet_id='foka'",[$debtPlayer])->fetch()===$pet
    &&(int)$row($debtPlayer)['purchased_coins']===3&&(int)$row($debtPlayer)['refund_coin_debt']===2,
    'Next reset preserves mixed-funded pet and residual purchased value while reopening exact refund debt.');

// Real Arcade reconciliation must retain unrelated MP gross credits and carry.
$recompute=new ReflectionMethod($moderation,'recomputePlayerCoins');
$beforeReconcile=$row($a);$mpTime=$query("SELECT SUM(play_ms_delta) FROM coin_ledger WHERE player_id=? AND event_type='multiplayer_credit'",[$a])->fetchColumn();
$db->beginTransaction();
$recompute->invoke($moderation,$a,Uuid::v4(),Uuid::v4(),'manual_reconcile','eligible','test-operator','Verify multiplayer preservation.');
$db->commit();
$assert((int)$row($a)['earned_coins']===(int)$beforeReconcile['earned_coins'],'Arcade-only reconciliation does not erase multiplayer earned coins.');
$assert($query("SELECT SUM(play_ms_delta) FROM coin_ledger WHERE player_id=? AND event_type='multiplayer_credit'",[$a])->fetchColumn()===$mpTime,
    'Arcade reconciliation leaves independent MP carry evidence unchanged.');

// Generation advance is the existing reward-reset fence. No old MP carry or
// delayed result may revive earned value after the reset; other seats still settle.
$query('UPDATE players SET economy_generation=1,earned_coins=0,coins=purchased_coins WHERE id=?',[$a]);
$stale=$modern([$a,$b],[60000,0]);$staleReceipt=$service->store($stale);
$assert($rewardFor($staleReceipt,$a)['coinStatus']==='stale_generation'&&$rewardFor($staleReceipt,$a)['coinsEarned']===0,
    'Queued pre-reset generation earns nothing.');
$staleWallet=$row($a);$staleRetry=$service->store($stale);
$assert($staleRetry['duplicate']&&$staleRetry['rewards']===$staleReceipt['rewards']&&$row($a)===$staleWallet,
    'Stale-generation receipt is final and retry cannot update wallet or become eligible.');
$fresh=$modern([$a,$b],[45000,0]);$fresh['players'][0]['economyGeneration']=1;
$freshReceipt=$service->store($fresh);
$assert($rewardFor($freshReceipt,$a)['coinsEarned']===0&&$rewardFor($freshReceipt,$a)['remainderMs']===45000,
    'New generation starts independent empty carry.');
$assert($service->store($first)['duplicate']&&(int)$row($a)['earned_coins']===0,'Pre-reset retry never restores old coins.');

// Cross-language fixture from Swift CompletedMatch, not a hand-authored PHP
// aggregate. Its earlier-out winner forfeits with zero misses legitimately.
$swift=json_decode(file_get_contents(__DIR__.'/fixtures/multiplayer-v2-revision3-swift.json'),true,32,JSON_THROW_ON_ERROR);
foreach($swift['players']as$seat){
    $query('INSERT INTO players(id,nickname,nickname_confirmed,economy_generation)VALUES(?,?,1,?)',
        [$seat['playerID'],'SwiftFixture'.$seat['seat'],$seat['economyGeneration']]);
}
$swiftReceipt=$service->store($swift);
$assert($swiftReceipt['state']==='stored_ranked'&&$rewardFor($swiftReceipt,$swift['players'][0]['playerID'])['coinsEarned']===2
    &&$rewardFor($swiftReceipt,$swift['players'][1]['playerID'])['coinsEarned']===2,'Actual Swift aggregate settles both generation-bound minutes.');
$swiftWinner=$query('SELECT * FROM multiplayer_v2_leaderboard_entries WHERE match_id=? AND seat=0',[$swift['matchID']])->fetch();
$assert((int)$swiftWinner['score']===251816&&(int)$swiftWinner['placement']===1&&(int)$swiftWinner['misses']===0
    &&(int)$swiftWinner['max_multiplier']===5&&(int)$swiftWinner['survival_ms']===65000,
    'Real Swift winner/forfeit/peak/survival metrics pass unchanged through PHP.');
$assert($service->store($swift)['duplicate'],'Real Swift outbox retry is idempotent.');

// Force a failure after wallets/receipts are written: the entire shared
// settlement, including its aggregate and all seats, must roll back.
$atomic=$modern([$b,$c],[60000,60000]);$atomicWallet=$row($b);
$db->exec("CREATE TRIGGER mp29_fail_board BEFORE INSERT ON multiplayer_v2_leaderboard_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Disposable atomicity check'");
try{$service->store($atomic);$assert(false,'Injected board error must fail.');}catch(PDOException){$assert(true,'Injected board error propagated.');}
$db->exec('DROP TRIGGER mp29_fail_board');
$assert($row($b)===$atomicWallet&&(int)$query('SELECT COUNT(*) FROM multiplayer_v2_results WHERE match_id=?',[$atomic['matchID']])->fetchColumn()===0
    &&(int)$query('SELECT COUNT(*) FROM multiplayer_v2_reward_receipts WHERE match_id=?',[$atomic['matchID']])->fetchColumn()===0,
    'Mid-settlement database failure rolls back aggregate, all receipts and wallet credits.');
$assert(!$service->store($atomic)['duplicate'],'Uncommitted failed result can safely retry.');

// Separate PHP processes concurrently settle an identical outbox result and
// then two distinct matches sharing a player's minute carry.
$parallel=static function(array $bodies)use($db,$assert):array{
    $id=$bodies[0]['players'][0]['playerID'];$db->beginTransaction();
    $lock=$db->prepare('SELECT id FROM players WHERE id=? FOR UPDATE');$lock->execute([$id]);
    $workers=[];
    try{
        foreach($bodies as$body){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--store'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not launch reward race worker.');
            fwrite($pipes[0],json_encode($body,JSON_THROW_ON_ERROR));fclose($pipes[0]);
            $assert(fgets($pipes[1])==="ready\n",'Concurrent worker is connected before releasing player lock.');
            $workers[]=[$process,$pipes];
        }
        $db->commit();$receipts=[];
        foreach($workers as[$process,$pipes]){
            $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            $assert(proc_close($process)===0,'Concurrent settlement succeeds: '.$error);
            $receipts[]=json_decode($output,true,32,JSON_THROW_ON_ERROR);
        }
        return $receipts;
    }finally{if($db->inTransaction())$db->rollBack();}
};
$racer=$newPlayer('RewardRace');$racePeer=$newPlayer('RewardRacePeer');
$race=$modern([$racer,$racePeer],[60000,60000]);$raceReceipts=$parallel([$race,$race]);
$assert(count(array_filter($raceReceipts,static fn(array$r):bool=>$r['duplicate']))===1&&(int)$row($racer)['earned_coins']===2,
    'Concurrent duplicate has exactly one first writer and one immutable retry, never double credit.');
$racer2=$newPlayer('RewardCarryRace');
$parallel([$modern([$racer2,$racePeer],[45000,0]),$modern([$racer2,$racePeer],[15000,0])]);
$assert((int)$row($racer2)['earned_coins']===2&&(int)$query("SELECT SUM(play_ms_delta) FROM coin_ledger WHERE player_id=? AND event_type='multiplayer_credit'",[$racer2])->fetchColumn()===60000,
    'Concurrent different matches serialize their shared minute remainder without lost time or duplicate rewards.');

// Equal global scores do not make the top window unbounded; the current player gets a
// bounded surrounding window and another season cannot bleed into it.
$windowBoard=new MultiplayerV2LeaderboardRepository($db,'mp29-window','Window fixture');
$windowService=new MultiplayerV2ResultService($db,$windowBoard);$windowPlayers=[];
for($index=0;$index<4;$index++)$windowPlayers[]=$newPlayer('Window'.$index);
for($index=0;$index<16;$index++)$windowService->store($modern($windowPlayers,[0,0,0,0],[10000,10000,10000,10000]));
$windowSelf=$newPlayer('WindowSelf');$windowService->store($modern([$windowSelf,$c],[0,0],[1,1]));
$anonymousWindow=$windowBoard->payload(null);$ownWindow=$windowBoard->payload($windowSelf);
$assert($anonymousWindow['totalEntries']===66&&count($anonymousWindow['entries'])===5,'Large tied population retains exact bounded top five.');
$assert($ownWindow['playerRank']===65&&count($ownWindow['entries'])<=10
    &&count(array_filter($ownWindow['entries'],static fn(array$r):bool=>$r['isCurrentPlayer']))===1,
    'Private context includes own low score and uses distinct tied row positions.');
$assert((new MultiplayerV2LeaderboardRepository($db,'empty-season','Empty'))->payload(null)['totalEntries']===0,
    'New global leaderboard respects explicit season isolation.');

// Exercise real HTTP routing/session authorization without writing a network
// server or exposing other participants' reward/account identifiers.
final class RewardCapturedResponse extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly array $body, public readonly array $headers) {}
    public static function send(int $status,array $body,array $headers=[]): never {throw new self($status,$body,$headers);}
}
class_alias(RewardCapturedResponse::class,\SpeedyTapper\JsonResponse::class);
$sessionDirectory=sys_get_temp_dir().'/speedytapper-mp29-session-'.bin2hex(random_bytes(8));
mkdir($sessionDirectory,0700);session_save_path($sessionDirectory);
$session=new \SpeedyTapper\SessionStore(false,new \SpeedyTapper\SessionRegistry($db));
$reflection=new ReflectionClass(\SpeedyTapper\App::class);$app=$reflection->newInstanceWithoutConstructor();
$legacyBoard=new \SpeedyTapper\MultiplayerLeaderboardRepository($db,'season-1','Season 1');
foreach(['session'=>$session,'multiplayerV2Results'=>$service,'multiplayerV2Leaderboard'=>$board,
    'multiplayerLeaderboard'=>$legacyBoard,'leaderboard'=>$arcadeBoard]as$property=>$value){
    $reflection->getProperty($property)->setValue($app,$value);
}
$dispatch=static function(string $path)use($app):RewardCapturedResponse{
    try{$app->dispatch(new \SpeedyTapper\HttpRequest('GET',$path,[],[],''));}
    catch(RewardCapturedResponse $response){return$response;}
};
$privatePath='/api/mobile/v2/multiplayer/results/'.$first['matchID'];
$throws(401,fn()=>$dispatch($privatePath),'Private result read requires cookie authentication.');
$publicRoute=$dispatch('/api/mobile/v2/multiplayer/leaderboard');
$assert($publicRoute->status===200&&isset($publicRoute->headers['Cache-Control']),'Anonymous v2 board route has bounded public caching.');
$session->login($a,'apple');$_COOKIE[session_name()]=session_id();
$privateRoute=$dispatch($privatePath);
$assert($privateRoute->status===200&&$privateRoute->body===$ownReceipt&&$privateRoute->headers===[],
    'Authenticated receipt route returns only stored own reward under default no-store policy.');
$throws(404,fn()=>$dispatch('/api/mobile/v2/multiplayer/results/not-a-uuid'),'Authenticated malformed match ID is private404.');
$ranks=$reflection->getMethod('rankings')->invoke($app,$a);
$assert(isset($ranks['normal'],$ranks['zen'],$ranks['multiplayer'],$ranks['multiplayerV2'])
    &&$ranks['multiplayer']['totalEntries']===0&&$ranks['multiplayerV2']['totalEntries']>0,
    'Session/profile adds distinct multiplayerV2 rank without changing retained v1/Arcade/Zen keys.');
$session->login($c,'apple');$_COOKIE[session_name()]=session_id();
$throws(404,fn()=>$dispatch($privatePath),'Authenticated nonparticipant cannot enumerate or read another seat reward.');
$session->logout();$session->close();unset($_COOKIE[session_name()]);
foreach(glob($sessionDirectory.'/sess_*')?:[]as$sessionFile)unlink($sessionFile);
rmdir($sessionDirectory);

// Account deletion removes only the owner's new rows; another player's receipt,
// leaderboard row and wallet survive the shared alpha aggregate purge.
$bWallet=$row($b);$bReceipts=$query('SELECT * FROM multiplayer_v2_reward_receipts WHERE player_id=? ORDER BY match_id',[$b])->fetchAll();
$bBoard=$query('SELECT * FROM multiplayer_v2_leaderboard_entries WHERE player_id=? ORDER BY entry_id',[$b])->fetchAll();
$deletion=new AccountDeletionService($db,str_repeat('disposable-retention-',3),null,$service);
$assert($deletion->delete($a)['deleted'],'Full account-deletion service succeeds with reward receipts.');
$assert((int)$query('SELECT COUNT(*) FROM multiplayer_v2_reward_receipts WHERE player_id=?',[$a])->fetchColumn()===0
    &&(int)$query('SELECT COUNT(*) FROM multiplayer_v2_leaderboard_entries WHERE player_id=?',[$a])->fetchColumn()===0,
    'Deleted account leaves no new reward or ranking rows.');
$assert($row($b)===$bWallet&&$query('SELECT * FROM multiplayer_v2_reward_receipts WHERE player_id=? ORDER BY match_id',[$b])->fetchAll()===$bReceipts
    &&$query('SELECT * FROM multiplayer_v2_leaderboard_entries WHERE player_id=? ORDER BY entry_id',[$b])->fetchAll()===$bBoard,
    'Peer deletion preserves other account value, provenance and global ranking.');
$assert($service->participantReceipt($first['matchID'],$b)['reward']['coinsEarned']===2,
    'Own confirmed receipt remains readable after a peer deletes the shared aggregate.');
$throws(409,fn()=>$service->store($first),'Deleted participant cannot be recreated by outbox retry.');

echo "Multiplayer rewards and fresh leaderboard persistence passed ({$assertions} assertions; MariaDB migrations001–025).\n";
