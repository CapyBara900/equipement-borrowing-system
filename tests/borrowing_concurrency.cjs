const {spawn,execFile}=require('node:child_process');
const {promisify}=require('node:util');
const assert=require('node:assert/strict');
const path=require('node:path');
const run=promisify(execFile);
const php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const script=path.join(__dirname,'borrowing_concurrency.php');
function worker(args,onLocked) {
  const child=spawn(php,[script,...args]); let stdout='',stderr='';
  const result=new Promise((resolve,reject)=>{
    child.stdout.on('data',chunk=>{stdout+=chunk; if(stdout.includes('LOCKED') && onLocked){const callback=onLocked;onLocked=null;callback();}});
    child.stderr.on('data',chunk=>{stderr+=chunk;});
    child.on('error',reject);
    child.on('close',code=>code===0?resolve(stdout):reject(new Error(stderr||stdout)));
  }); return result;
}
(async()=>{
  const fixture=JSON.parse((await run(php,[script,'setup'])).stdout);
  const ids=[String(fixture.user_id),String(fixture.equipment_id)];
  let first,second;
  try {
    const locked=new Promise(resolve=>{first=worker(['worker',...ids,'hold'],resolve);});
    await Promise.race([locked,first.then(()=>{throw new Error('Worker did not lock');})]);
    second=worker(['worker',...ids]);
    const results=await Promise.all([first,second]);
    assert.match(results[0],/SUBMITTED/); assert.match(results[1],/INSUFFICIENT_STOCK/);
    console.log((await run(php,[script,'verify',...ids])).stdout.trim());
  } finally {
    await Promise.allSettled([first,second].filter(Boolean));
    await run(php,[script,'cleanup',...ids]);
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
