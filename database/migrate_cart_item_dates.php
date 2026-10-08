<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this migration from the command line.'); }
require_once __DIR__.'/../config/database.php';
$db=(new Database())->getConnection();
if ((int)$db->query("SELECT GET_LOCK('ebs_borrowing_cart_v3_migration',10)")->fetchColumn()!==1) throw new RuntimeException('Another cart migration is running.');
try {
    $maria=str_contains($db->query('SELECT VERSION()')->fetchColumn(),'MariaDB');
    foreach (['borrowing_cart_items','borrowing_checkouts','borrowing_requests'] as $table) {
        $stmt=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$stmt->execute([$table]);
        if ($stmt->fetchColumn()!=='InnoDB') throw new RuntimeException('Apply the Part 3 base migration first.');
    }
    $dry=in_array('--dry-run',$argv,true);
    $source=preg_replace('/^--(?! @when ).*$/m','',file_get_contents(__DIR__.'/migration_cart_item_dates.sql'));
    foreach (explode(';',$source) as $statement) {
        $sql=trim(preg_replace('/--[^\r\n]*/','',$statement));if ($sql==='') continue;
        preg_match('/-- @when ([a-z-]+) ([a-z_]+) ([a-z_]+)/',$statement,$when);[, $kind,$table,$name]=$when;
        if ($kind==='primary') {
            $stmt=$db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");$stmt->execute([$table]);
            $skip=$stmt->fetchAll(PDO::FETCH_COLUMN)===[$name];
        } elseif ($kind==='same-day') {
            $stmt=$db->prepare('SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');$stmt->execute([$name]);
            $skip=str_contains((string)$stmt->fetchColumn(),'>=');
            if (!$maria) $sql=str_replace('DROP CONSTRAINT','DROP CHECK',$sql);
        } else {
            [$metadata,$field]=match($kind){'column'=>['COLUMNS','COLUMN_NAME'],'constraint'=>['TABLE_CONSTRAINTS','CONSTRAINT_NAME'],default=>['STATISTICS','INDEX_NAME']};
            $stmt=$db->prepare("SELECT COUNT(*) FROM information_schema.$metadata WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND $field=?");$stmt->execute([$table,$name]);$exists=(int)$stmt->fetchColumn()>0;
            $skip=$kind==='index-present'?!$exists:$exists;
        }
        if ($skip) { echo "SKIP $table.$name\n";continue; }
        if (!$dry) $db->exec($sql);
        echo ($dry?'PLAN ':'APPLIED ').preg_replace('/\s+/',' ',$sql)."\n";
    }
    echo $dry?"Dry run complete; no data changed.\n":"Dated cart migration complete; existing records preserved.\n";
} finally { $db->query("SELECT RELEASE_LOCK('ebs_borrowing_cart_v3_migration')"); }
