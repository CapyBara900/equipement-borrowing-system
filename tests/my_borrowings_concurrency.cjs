const {spawn,execFile}=require('node:child_process');
const {promisify}=require('node:util');
const assert=require('node:assert/strict');
const path=require('node:path');
const run=promisify(execFile),php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const script=path.join(__dirname,'my_borrowings_concurrency.php');
const firstRole=process.argv[2] || 'admin';
assert.ok(['admin','staff'].includes(firstRole));
function worker(args,onLocked){
  const child=spawn(php,[script,...args],{windowsHide:true});let stdout='',stderr='';
  return new Promise((resolve,reject)=>{
    child.stdout.on('data',chunk=>{stdout+=chunk;if(stdout.includes('LOCKED')&&onLocked){const callback=onLocked;onLocked=null;callback();}});
    child.stderr.on('data',chunk=>{stderr+=chunk;});child.on('error',reject);
    child.on('close',code=>code===0?resolve(JSON.parse(stdout.trim().split('\n').at(-1))):reject(new Error(stderr||stdout)));
  });
}
(async()=>{
  const fixture=(await run(php,[script,'setup',firstRole])).stdout;
  const setup=JSON.parse(fixture);
  if(setup.skip){console.log('SKIP: '+setup.reason);return;}
  let first,second;
  try{
    for(const kind of ['approve','pickup','return']){
      const locked=new Promise(resolve=>{first=worker(['worker',fixture,'first',kind],resolve);});
      await Promise.race([locked,first.then(()=>{throw Error('First worker did not lock request');})]);
      second=worker(['worker',fixture,'second',kind]);
      const [one,two]=await Promise.all([first,second]);
      assert.equal(one.status,kind==='return'?201:200);assert.equal(two.status,409);
      assert.equal((await run(php,[script,'verify',fixture,kind])).stdout,'PASS');
    }
  } finally {
    await Promise.allSettled([first,second].filter(Boolean));await run(php,[script,'cleanup',fixture]);
  }
  console.log('PASS: independent MySQL ' + firstRole + '/admin connections serialize duplicate approvals, pickups and returns; one audit/notification per transition and one stock restoration.');
})().catch(error=>{console.error(error);process.exitCode=1;});
