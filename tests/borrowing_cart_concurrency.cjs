const {spawn,execFile}=require('node:child_process');
const {promisify}=require('node:util');
const assert=require('node:assert/strict');
const path=require('node:path');
const run=promisify(execFile);
const php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const script=path.join(__dirname,'borrowing_cart_concurrency.php');
function worker(args,onLocked) {
  const child=spawn(php,[script,...args]);let stdout='',stderr='';
  return new Promise((resolve,reject)=>{
    child.stdout.on('data',chunk=>{stdout+=chunk;if(stdout.includes('LOCKED')&&onLocked){const callback=onLocked;onLocked=null;callback();}});
    child.stderr.on('data',chunk=>{stderr+=chunk;});child.on('error',reject);
    child.on('close',code=>code===0?resolve(JSON.parse(stdout.trim().split('\n').at(-1))):reject(new Error(stderr||stdout)));
  });
}
(async()=>{
  for(const kind of ['same','competing']) {
    const fixture=(await run(php,[script,'setup'])).stdout;
    let first,second;
    try {
      const locked=new Promise(resolve=>{first=worker(['worker',fixture,'first',kind],resolve);});
      await Promise.race([locked,first.then(()=>{throw new Error('Worker did not lock stock');})]);
      second=worker(['worker',fixture,'second',kind]);
      const [one,two]=await Promise.all([first,second]);
      assert.equal(one.success,true);
      if(kind==='same') assert.deepEqual(two,one,'Concurrent same-key retry changed response');
      else {assert.equal(two.success,false);assert.equal(two.code,'INSUFFICIENT_STOCK');}
      assert.equal((await run(php,[script,'verify',fixture])).stdout,'PASS');
    } finally {
      await Promise.allSettled([first,second].filter(Boolean));await run(php,[script,'cleanup',fixture]);
    }
  }
  console.log('PASS: concurrent grouped checkouts across independent MySQL connections: same-key retries create one group; competing customers cannot exceed stock; failed carts remain intact.');
})().catch(error=>{console.error(error);process.exitCode=1;});
