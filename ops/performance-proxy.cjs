const http=require('node:http');
const fs=require('node:fs');
const path=require('node:path');
const zlib=require('node:zlib');
// Opt-in lab tool: only loopback targets/listeners, never part of the production web app.
const options={baseline:18925,candidate:18926,'baseline-listen':18927,'candidate-listen':18928,latency:150,bandwidth:200000};
if(process.argv.includes('--help')){
  console.log('node ops/performance-proxy.cjs [--baseline=18925] [--candidate=18926] [--baseline-listen=18927] [--candidate-listen=18928] [--latency=150] [--bandwidth=200000]');
  process.exit(0);
}
for(const arg of process.argv.slice(2)){
  const match=/^--([a-z-]+)=(\d+)$/.exec(arg);
  if(!match||!Object.hasOwn(options,match[1]))throw new Error('Unknown benchmark option');
  options[match[1]]=Number(match[2]);
}
for(const key of ['baseline','candidate','baseline-listen','candidate-listen']){
  if(!Number.isInteger(options[key])||options[key]<1024||options[key]>65535)throw new Error('Use an unprivileged loopback port');
}
if(new Set([options.baseline,options.candidate,options['baseline-listen'],options['candidate-listen']]).size!==4)throw new Error('All benchmark ports must be distinct');
if(options.latency>10000||options.bandwidth<1000||options.bandwidth>1000000000)throw new Error('Invalid lab latency or bandwidth');
const BYTES_PER_SECOND=options.bandwidth, LATENCY_MS=options.latency, TICK_MS=10;
const probe=fs.readFileSync(path.join(__dirname,'performance-probe.js'));
function server(port,target){
  const queue=[];
  setInterval(()=>{
    let budget=BYTES_PER_SECOND*TICK_MS/1000;
    while(queue.length&&budget>0){
      const job=queue.shift();
      if(job.res.destroyed)continue;
      const n=Math.min(budget,job.body.length-job.offset);
      job.res.write(job.body.subarray(job.offset,job.offset+n));
      job.offset+=n;budget-=n;
      if(job.offset===job.body.length)job.res.end();else queue.push(job);
    }
  },TICK_MS).unref();
  function send(req,res,status,headers,body){
    if(/text\/html/.test(headers['content-type']||''))body=Buffer.from(body.toString().replace('</head>','<script defer src="/__perf/probe.js"></script></head>'));
    delete headers['transfer-encoding'];delete headers['connection'];delete headers['content-length'];
    if(/(?:text\/|javascript|json|svg|xml)/.test(headers['content-type']||'')&&/gzip/.test(req.headers['accept-encoding']||'')){
      body=zlib.gzipSync(body);headers['content-encoding']='gzip';
    }
    headers['cache-control']='private, no-store';headers['x-robots-tag']='noindex, nofollow';
    headers['content-length']=body.length;
    setTimeout(()=>{if(res.destroyed)return;res.writeHead(status,headers);if(req.method==='HEAD'||!body.length)res.end();else queue.push({res,body,offset:0});},LATENCY_MS);
  }
  http.createServer((req,res)=>{
    if(req.url==='/__perf/probe.js'){send(req,res,200,{'content-type':'application/javascript'},probe);return;}
    const headers={...req.headers,host:'127.0.0.1:'+port,'accept-encoding':'identity'};
    const upstream=http.request({hostname:'127.0.0.1',port:target,path:req.url,method:req.method,headers},incoming=>{
      const chunks=[];incoming.on('data',c=>chunks.push(c));incoming.on('end',()=>send(req,res,incoming.statusCode,{...incoming.headers},Buffer.concat(chunks)));
    });
    upstream.on('error',()=>send(req,res,502,{'content-type':'text/plain'},Buffer.from('Local benchmark upstream unavailable')));
    req.pipe(upstream);
  }).listen(port,'127.0.0.1');
}
server(options['baseline-listen'],options.baseline);server(options['candidate-listen'],options.candidate);
console.log(`Loopback benchmark: ${LATENCY_MS} ms added request latency, shared ${BYTES_PER_SECOND} bytes/s per origin, gzip text, no browser cache, same probe. No CPU throttling.`);
